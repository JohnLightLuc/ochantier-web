<?php

namespace Tests\Feature\Promoter;

use App\Enums\AvailabilityStatus;
use App\Enums\NeedStatus;
use App\Enums\OfferStatus;
use App\Enums\OfferType;
use App\Enums\PriceOrigin;
use App\Enums\WorkerVerificationStatus;
use App\Models\Need;
use App\Models\Offer;
use App\Models\Setting;
use App\Models\Site;

class SiteOfferTest extends PromoterTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Setting::query()->create(['key' => 'pricing.reference_daily_rates', 'value' => ['macon' => 25000, 'plombier' => 20000, 'electricien' => 30000]]);
    }

    public function test_the_form_lists_trades_with_their_reference_rate(): void
    {
        $this->getJson('/api/v1/promoter/sites/form-options')
            ->assertOk()
            ->assertJsonPath('data.trades.0.code', 'macon')
            ->assertJsonPath('data.trades.0.reference_rate', 25000)
            ->assertJsonPath('data.deposit_percent', 30)
            ->assertJsonPath('data.low_rate_percent', 70)
            ->assertJsonPath('data.payment_modes.*.value', ['hebdomadaire', 'par_etape'])
            ->assertJsonPath('data.quote_trades.*.code', ['macon', 'electricien', 'plombier', 'peintre', 'menuisier', 'carreleur', 'coffreur', 'ferrailleur', 'soudeur', 'etancheiste'])
            ->assertJsonPath('data.quote_document_type', 'site_quote');
    }

    public function test_the_catalogue_shows_validated_workers_with_the_promoter_rate(): void
    {
        $this->worker('Kouamé Koffi', 'macon', 22000, rating: 4.7);
        $this->worker('Yao Koffi', 'macon', 20000, AvailabilityStatus::Occupe, 4.9);
        $this->worker('Awa Traoré', 'electricien', 27000, rating: 4.8);
        $this->worker('Bamba Seydou', 'macon', 21000, status: WorkerVerificationStatus::Soumis);

        $this->getJson('/api/v1/promoter/workers')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.*.name', ['Awa Traoré', 'Kouamé Koffi', 'Yao Koffi'])
            ->assertJsonPath('data.1.daily_rate', 24200)
            ->assertJsonPath('data.1.trade.label', 'Maçon')
            ->assertJsonPath('data.1.experience_years', 5)
            ->assertJsonPath('data.1.commune.name', 'Cocody')
            ->assertJsonPath('data.1.rating', ['average' => 4.7, 'count' => 12])
            ->assertJsonMissingPath('data.1.phone')
            ->assertJsonPath('data.2.available', false);

        $macon = $this->trade('macon')->id;
        $this->getJson("/api/v1/promoter/workers?trade_id={$macon}&available=1")->assertJsonPath('data.*.name', ['Kouamé Koffi']);
        $this->getJson('/api/v1/promoter/workers?max_rate=24000')->assertJsonPath('data.*.name', ['Yao Koffi']);
        $this->getJson('/api/v1/promoter/workers?search=électri')->assertJsonPath('data.*.name', ['Awa Traoré']);
    }

    public function test_a_daily_offer_draft_is_summarised_and_checked_before_publication(): void
    {
        $site = $this->createSite();
        $awa = $this->worker('Awa Traoré', 'electricien', 27000);

        $draft = $this->putJson("/api/v1/promoter/sites/{$site['id']}/offer", [
            'type' => 'journaliere',
            'needs' => [
                ['trade_id' => $this->trade('macon')->id, 'headcount' => 3, 'daily_rate' => 15000],
                ['trade_id' => $this->trade('plombier')->id, 'headcount' => 2],
            ],
            'worker_ids' => [$awa->id],
            'lock_version' => $site['lock_version'],
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Brouillon enregistré')
            ->assertJsonPath('data.offer.draft.type.value', 'journaliere')
            ->assertJsonPath('data.offer.draft.lines.0.rate_hint.value', 'bas')
            ->assertJsonPath('data.offer.draft.lines.1.rate_hint', ['value' => 'manquant', 'label' => 'Proposez un tarif pour ce métier'])
            ->assertJsonPath('data.offer.draft.lines.2.trade.code', 'electricien')
            ->assertJsonPath('data.offer.draft.lines.2.requested_workers.0.daily_rate', 29700)
            ->assertJsonPath('data.offer.draft.workers_count', 6)
            ->assertJsonPath('data.offer.draft.to_search_count', 5)
            ->assertJsonPath('data.offer.draft.chosen_count', 1)
            ->assertJsonPath('data.offer.draft.daily_cost', 3 * 15000 + 2 * 20000 + 29700)
            ->assertJsonPath('data.offer_summary.workers_count', 6)
            ->json('data');

        $this->postJson("/api/v1/promoter/sites/{$site['id']}/publish")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Proposez un tarif journalier pour « Plombier » (et 3 autres erreurs)')
            ->assertJsonPath('errors.publication', [
                'Proposez un tarif journalier pour « Plombier »',
                'Ajoutez une photo du chantier',
                'Indiquez la localisation',
                'Renseignez les dates de début et de fin',
            ]);

        $this->putJson("/api/v1/promoter/sites/{$site['id']}/offer", ['type' => 'journaliere', 'needs' => [], 'lock_version' => $site['lock_version']])->assertStatus(409);
        $this->putJson("/api/v1/promoter/sites/{$site['id']}/offer", ['type' => 'journaliere', 'contract' => ['amount' => 500000]])
            ->assertJsonValidationErrors(['contract' => 'Le forfait ne concerne que l’offre contractuelle.']);
        $this->assertSame(3, $draft['offer']['draft']['lines'][0]['headcount']);
    }

    public function test_a_daily_offer_is_published_to_the_back_office(): void
    {
        $site = $this->readySite();
        $awa = $this->worker('Awa Traoré', 'electricien', 27000);
        $kouame = $this->worker('Kouamé Koffi', 'macon', 22000);

        $this->putJson("/api/v1/promoter/sites/{$site['id']}/offer", [
            'type' => 'journaliere',
            'needs' => [['trade_id' => $this->trade('macon')->id, 'headcount' => 2, 'daily_rate' => 25000]],
            'worker_ids' => [$awa->id, $kouame->id],
        ])->assertOk()->assertJsonPath('data.missing_for_publication', []);

        $this->postJson("/api/v1/promoter/sites/{$site['id']}/publish")
            ->assertOk()
            ->assertJsonPath('message', 'Chantier publié — recherche d’ouvriers lancée')
            ->assertJsonPath('data.status.value', 'soumis')
            ->assertJsonPath('data.display_status.label', 'Recherche d’ouvriers en cours')
            ->assertJsonPath('data.can_edit', false)
            ->assertJsonPath('data.offer.published.type.value', 'ponctuelle')
            ->assertJsonPath('data.offer.published.status.value', 'soumise')
            ->assertJsonPath('data.offer.published.needs.*.trade.code', ['macon', 'electricien'])
            ->assertJsonPath('data.offer.published.needs.*.headcount', [3, 1])
            ->assertJsonPath('data.offer.published.needs.*.daily_rate', [25000, 29700]);

        $offer = Offer::query()->sole();
        $this->assertMatchesRegularExpression('/^OF-\d{4}-\d{4}$/', $offer->reference);
        $this->assertSame(PriceOrigin::Ouvrier, $offer->price_origin);
        $this->assertSame(OfferStatus::Soumise, $offer->statusHistories()->sole()->to_status);
        $macon = Need::query()->whereBelongsTo($this->trade('macon'))->sole();
        $this->assertMatchesRegularExpression('/^BS-\d{4}-\d{4}$/', $macon->reference);
        $this->assertSame(NeedStatus::Soumis, $macon->status);
        $this->assertSame(10, $macon->duration_days);
        $this->assertSame([$kouame->id], $macon->requestedWorkers()->pluck('worker_id')->all());
        $this->assertSame(24200, $macon->requestedWorkers()->sole()->promoter_daily_rate);

        $this->postJson("/api/v1/promoter/sites/{$site['id']}/publish")
            ->assertJsonValidationErrors(['site' => 'Ce chantier est publié : il ne peut plus être modifié depuis l’application.']);
        $this->putJson("/api/v1/promoter/sites/{$site['id']}/offer", ['type' => 'journaliere'])->assertJsonValidationErrors(['site']);
    }

    public function test_an_offer_with_only_chosen_workers_needs_no_rate(): void
    {
        $site = $this->readySite(days: 45);
        $kouame = $this->worker('Kouamé Koffi', 'macon', 22000);

        $this->putJson("/api/v1/promoter/sites/{$site['id']}/offer", ['type' => 'journaliere', 'worker_ids' => [$kouame->id]])
            ->assertOk()
            ->assertJsonPath('data.offer.draft.publish_hint', 'Les ouvriers choisis reçoivent votre demande directement.')
            ->assertJsonPath('data.offer.draft.estimate', 24200 * 45);

        $this->postJson("/api/v1/promoter/sites/{$site['id']}/publish")->assertOk();

        $this->assertSame(OfferType::Temporaire, Offer::query()->sole()->offer_type);
        $this->assertSame(24200, Need::query()->sole()->indicative_daily_budget);
    }

    public function test_a_daily_offer_needs_workers(): void
    {
        $site = $this->readySite();

        $this->putJson("/api/v1/promoter/sites/{$site['id']}/offer", ['type' => 'journaliere', 'needs' => [['trade_id' => $this->trade()->id, 'headcount' => 0]]])->assertOk();

        $this->postJson("/api/v1/promoter/sites/{$site['id']}/publish")
            ->assertJsonPath('errors.publication', ['Ajoutez des besoins en ouvriers ou sélectionnez des ouvriers']);
    }

    public function test_a_contract_offer_is_published_with_its_payment_schedule(): void
    {
        $site = $this->readySite(days: 30);

        $this->putJson("/api/v1/promoter/sites/{$site['id']}/offer", ['type' => 'contractuelle', 'contract' => ['has_quote' => true, 'amount' => 3000000, 'payment_mode' => 'par_etape']])
            ->assertOk()
            ->assertJsonPath('data.offer.draft.has_quote', true)
            ->assertJsonPath('data.offer.draft.submit_label', 'Publier')
            ->assertJsonPath('data.offer.draft.deposit', 900000)
            ->assertJsonPath('data.offer.draft.balance', 2100000)
            ->assertJsonPath('data.offer.draft.installments', ['count' => 3, 'label' => '3 échéances par étape'])
            ->assertJsonPath('data.offer.draft.daily_equivalent', 100000)
            ->assertJsonPath('data.offer.draft.documents_hint', 'Joignez vos plans et le cahier des charges (facultatif)')
            ->assertJsonPath('data.offer_summary.amount', 3000000);

        $this->postJson("/api/v1/promoter/sites/{$site['id']}/publish")
            ->assertJsonPath('errors.publication', ['Téléchargez votre devis']);

        // Les autres documents ne suffisent plus : seul le devis est obligatoire.
        $this->post("/api/v1/promoter/sites/{$site['id']}/documents", ['document_type' => 'site_specifications', 'file' => $this->pdf('Cahier des charges.pdf')], ['Accept' => 'application/json'])->assertCreated();
        $this->postJson("/api/v1/promoter/sites/{$site['id']}/publish")
            ->assertJsonPath('errors.publication', ['Téléchargez votre devis']);

        $quote = $this->post("/api/v1/promoter/sites/{$site['id']}/documents", ['document_type' => 'site_quote', 'file' => $this->image()], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        $this->getJson("/api/v1/promoter/sites/{$site['id']}")
            ->assertJsonPath('data.offer.draft.quotes.*.id', [$quote]);

        $this->postJson("/api/v1/promoter/sites/{$site['id']}/publish")
            ->assertOk()
            ->assertJsonPath('data.offer.published.type.value', 'contractuelle')
            ->assertJsonPath('data.offer.published.contract_amount', 3000000)
            ->assertJsonPath('data.offer.published.payment_mode.value', 'par_etape')
            ->assertJsonPath('data.offer.published.needs', [])
            ->assertJsonPath('data.offer.published.milestones.*.label', ['Étape 1/3', 'Étape 2/3', 'Étape 3/3'])
            ->assertJsonPath('data.offer.published.milestones.*.amount', [700000, 700000, 700000])
            ->assertJsonPath('data.offer.published.milestones.2.due_date', $site['end_date']);

        $this->assertSame(PriceOrigin::Promoteur, Offer::query()->sole()->price_origin);
        $this->assertSame($quote, Offer::query()->sole()->quote_document_id);
    }

    public function test_the_contract_amount_is_required_to_publish(): void
    {
        $site = $this->readySite();
        $this->post("/api/v1/promoter/sites/{$site['id']}/documents", ['document_type' => 'site_plan', 'file' => $this->pdf()], ['Accept' => 'application/json'])->assertCreated();

        $this->post("/api/v1/promoter/sites/{$site['id']}/documents", ['document_type' => 'site_quote', 'file' => $this->pdf('Devis.pdf')], ['Accept' => 'application/json'])->assertCreated();

        $this->putJson("/api/v1/promoter/sites/{$site['id']}/offer", ['type' => 'contractuelle', 'contract' => ['payment_mode' => 'hebdomadaire']])
            ->assertOk()
            ->assertJsonPath('data.offer.draft.has_quote', null);

        $this->postJson("/api/v1/promoter/sites/{$site['id']}/publish")
            ->assertJsonPath('errors.publication', ['Indiquez si vous avez un devis']);

        $this->putJson("/api/v1/promoter/sites/{$site['id']}/offer", ['type' => 'contractuelle', 'contract' => ['has_quote' => true, 'payment_mode' => 'hebdomadaire']])->assertOk();

        $this->postJson("/api/v1/promoter/sites/{$site['id']}/publish")
            ->assertJsonPath('errors.publication', ['Indiquez le montant de votre offre financière']);
    }

    public function test_the_balance_can_no_longer_be_paid_at_the_end(): void
    {
        $site = $this->readySite();

        $this->putJson("/api/v1/promoter/sites/{$site['id']}/offer", ['type' => 'contractuelle', 'contract' => ['has_quote' => true, 'amount' => 3000000, 'payment_mode' => 'fin_de_chantier']])
            ->assertJsonValidationErrors(['contract.payment_mode' => 'Paiement du solde attendu : hebdomadaire ou par étape.']);

        // Brouillon enregistré avant la mise à jour.
        Site::query()->whereKey($site['id'])->update(['offer_draft' => json_encode(['type' => 'contractuelle', 'contract' => ['has_quote' => true, 'amount' => 3000000, 'payment_mode' => 'fin_de_chantier']])]);
        $this->post("/api/v1/promoter/sites/{$site['id']}/documents", ['document_type' => 'site_quote', 'file' => $this->pdf('Devis.pdf')], ['Accept' => 'application/json'])->assertCreated();

        $this->postJson("/api/v1/promoter/sites/{$site['id']}/publish")
            ->assertJsonPath('errors.publication', ['Choisissez le paiement du solde : hebdomadaire ou par étape']);
    }

    public function test_without_a_quote_the_promoter_requests_one_by_trade(): void
    {
        $site = $this->createSite();
        $macon = $this->trade('macon')->id;
        $plombier = $this->trade('plombier')->id;

        $this->putJson("/api/v1/promoter/sites/{$site['id']}/offer", ['type' => 'contractuelle', 'contract' => ['has_quote' => false, 'quote_request' => ['trade_ids' => [$this->trade('manoeuvre')->id]]]])
            ->assertJsonValidationErrors(['contract.quote_request.trade_ids.0' => 'Corps de métier inconnu.']);

        $this->putJson("/api/v1/promoter/sites/{$site['id']}/offer", ['type' => 'contractuelle', 'contract' => ['has_quote' => false, 'quote_request' => ['trade_ids' => []]]])
            ->assertOk()
            ->assertJsonPath('data.offer.draft.submit_label', 'Rechercher un technicien');

        // Sans photo ni dates : seuls le titre, la localisation et un corps de métier sont exigés.
        $this->postJson("/api/v1/promoter/sites/{$site['id']}/publish")
            ->assertJsonPath('errors.publication', ['Indiquez la localisation', 'Choisissez au moins un corps de métier pour le devis']);

        $this->patchJson("/api/v1/promoter/sites/{$site['id']}", ['address' => 'Angré, Abidjan', 'commune_id' => $this->commune()])->assertOk();
        $this->putJson("/api/v1/promoter/sites/{$site['id']}/offer", ['type' => 'contractuelle', 'contract' => ['has_quote' => false, 'quote_request' => ['trade_ids' => [$plombier, $macon], 'notes' => '  Villa R+1, accès par la rue pavée. ']]])
            ->assertOk()
            ->assertJsonPath('data.offer.draft.quote_request.trades.*.code', ['macon', 'plombier'])
            ->assertJsonPath('data.offer.draft.quote_request.notes', 'Villa R+1, accès par la rue pavée.');

        $this->getJson("/api/v1/promoter/sites/{$site['id']}")->assertJsonPath('data.missing_for_publication', []);

        $this->postJson("/api/v1/promoter/sites/{$site['id']}/publish")
            ->assertUnprocessable()
            ->assertJsonPath('errors.publication', ['La demande de devis n’est pas encore disponible. Joignez votre devis pour publier.']);
        $this->assertSame(0, Offer::query()->count());
    }

    /**
     * @return array<string, mixed>
     */
    private function createSite(): array
    {
        return $this->postJson('/api/v1/promoter/sites', ['title' => 'Résidence Les Acacias'])->assertCreated()->json('data');
    }

    /**
     * Brouillon prêt à publier, hors besoins : photo, localisation et dates.
     *
     * @return array<string, mixed>
     */
    private function readySite(int $days = 10): array
    {
        $site = $this->createSite();
        $this->post("/api/v1/promoter/sites/{$site['id']}/photo", ['photo' => $this->image()], ['Accept' => 'application/json'])->assertOk();

        return $this->patchJson("/api/v1/promoter/sites/{$site['id']}", [
            'address' => 'Angré, Abidjan',
            'commune_id' => $this->commune(),
            'planned_start_date' => now()->addDays(5)->toDateString(),
            'planned_end_date' => now()->addDays(4 + $days)->toDateString(),
        ])->assertOk()->json('data');
    }
}
