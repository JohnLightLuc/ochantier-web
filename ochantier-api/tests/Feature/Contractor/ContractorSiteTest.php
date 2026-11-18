<?php

namespace Tests\Feature\Contractor;

use App\Enums\ContractorVerificationStatus;
use App\Enums\PaymentScheduleStatus;
use App\Enums\SiteStatus;
use App\Models\Notification;
use App\Models\Offer;
use App\Models\PaymentSchedule;
use App\Models\Site;
use Illuminate\Support\Facades\DB;

class ContractorSiteTest extends ContractorMarketTestCase
{
    private Offer $offer;

    private string $start;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contractor->update(['verification_status' => ContractorVerificationStatus::Valide]);
        $this->offer = $this->publishedContract('Résidence Les Acacias', 'Cocody');
        $this->start = now()->addDays(10)->toDateString();

        $bid = $this->asContractor()->postJson("/api/v1/contractor/opportunities/offers/{$this->offer->id}/response", [
            'mode' => 'contre_proposition', 'amount' => 3000000, 'start_date' => $this->start, 'end_date' => now()->addDays(39)->toDateString(), 'team_size' => 6,
        ])->assertCreated()->json('data.id');

        $this->asPromoter()->postJson("/api/v1/promoter/sites/{$this->offer->site_id}/contractor-offers/{$bid}/choose")->assertOk();
        $this->postJson('/api/v1/promoter/payments', ['contractor_bid_id' => $bid, 'operator_code' => 'orange_money', 'dial_code' => '225', 'phone_number' => '0712349999'], ['Idempotency-Key' => 'acompte-chantier'])->assertCreated();
        $this->asContractor();
    }

    public function test_attributed_sites_are_listed_by_stage(): void
    {
        $this->getJson('/api/v1/contractor/sites')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->offer->site_id)
            ->assertJsonPath('data.0.title', 'Résidence Les Acacias')
            ->assertJsonPath('data.0.place', 'Cocody, Abidjan')
            ->assertJsonPath('data.0.stage', ['value' => 'a_venir', 'label' => 'À venir'])
            ->assertJsonPath('data.0.start_date', $this->start)
            ->assertJsonPath('data.0.duration_days', 30)
            ->assertJsonPath('data.0.amount', 3000000)
            ->assertJsonPath('data.0.team.label', 'Équipe à constituer')
            ->assertJsonPath('meta.tabs.1', ['key' => 'a_venir', 'label' => 'À venir', 'count' => 1])
            ->assertJsonPath('meta.tabs.2.count', 0);

        $this->assertGreaterThan(0, $this->getJson('/api/v1/contractor/sites')->json('data.0.countdown_seconds'));

        DB::table('sites')->where('id', $this->offer->site_id)->update(['status' => SiteStatus::EnCours->value]);
        $this->travel(14)->days();

        $this->getJson('/api/v1/contractor/sites?tab=en_cours')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.day', 5)
            ->assertJsonPath('data.0.progress_label', 'Jour 5 sur 30 · fin le '.now()->subDays(14)->addDays(39)->format('d/m/Y'));
        $this->getJson('/api/v1/contractor/sites?tab=termines')->assertJsonCount(0, 'data');
    }

    public function test_the_overview_reveals_the_promoter_and_the_next_payment(): void
    {
        $this->getJson("/api/v1/contractor/sites/{$this->offer->site_id}")
            ->assertOk()
            ->assertJsonPath('data.info.address', '12 Rue des Jardins')
            ->assertJsonPath('data.info.contract_amount', 3000000)
            ->assertJsonPath('data.promoter', ['role' => 'Promoteur · maître d’ouvrage', 'name' => 'Awa Kouamé', 'phone' => '+225 07 12 34 99 99'])
            ->assertJsonPath('data.next_payment.label', 'Étape 1')
            ->assertJsonPath('data.payments_summary.paid_percent', 30)
            ->assertJsonPath('data.chronology.count_label', 'Aucune étape')
            ->assertJsonPath('data.documents.count', 2);
    }

    public function test_payments_show_the_contractor_net_share(): void
    {
        $payments = $this->getJson("/api/v1/contractor/sites/{$this->offer->site_id}/payments")
            ->assertOk()
            ->assertJsonPath('data.contract_amount', 3000000)
            ->assertJsonPath('data.paid_amount', 900000)
            ->assertJsonPath('data.remaining_amount', 2100000)
            ->assertJsonPath('data.items.0.label', 'Acompte à l’attribution')
            ->assertJsonPath('data.items.0.state.value', 'paye')
            ->assertJsonPath('data.items.1.state.value', 'a_venir')
            ->assertJsonPath('data.settled', false)
            ->json('data');

        $this->assertCount(4, $payments['items']);
        $this->assertSame(3000000, array_sum(array_column($payments['items'], 'amount')));

        $next = PaymentSchedule::query()->where('status', '!=', PaymentScheduleStatus::Payee->value)->orderBy('due_date')->first();
        $this->asPromoter()->postJson('/api/v1/promoter/payments', ['schedule_ids' => [$next->id], 'operator_code' => 'orange_money', 'dial_code' => '225', 'phone_number' => '0712349999'], ['Idempotency-Key' => 'etape-1'])
            ->assertCreated()
            ->assertJsonPath('data.status.value', 'reussie');

        $this->assertTrue(Notification::query()->where('recipient_id', $this->user->id)->where('event_code', 'payment.received')->exists());
        $this->asContractor()->getJson("/api/v1/contractor/sites/{$this->offer->site_id}/payments")
            ->assertJsonPath('data.items.1.state.value', 'paye')
            ->assertJsonPath('data.paid_amount', 900000 + $payments['items'][1]['amount']);
    }

    public function test_the_contractor_manages_the_shared_chronology(): void
    {
        $site = $this->offer->site_id;

        $this->postJson("/api/v1/contractor/sites/{$site}/steps", ['title' => ''])->assertUnprocessable()->assertJsonPath('errors.title.0', 'Saisissez l’intitulé de l’étape.');
        $first = $this->postJson("/api/v1/contractor/sites/{$site}/steps", ['title' => 'Installation de chantier'])
            ->assertCreated()
            ->assertJsonPath('data.steps.steps.0.actions', ['demarrer', 'retirer'])
            ->json('data.steps.steps.0.id');
        $second = $this->postJson("/api/v1/contractor/sites/{$site}/steps", ['title' => 'Réception des travaux'])->json('data.steps.steps.1.id');

        $this->postJson("/api/v1/contractor/sites/{$site}/steps/{$first}/start")->assertOk()->assertJsonPath('data.steps.steps.0.status.value', 'en_cours');
        $this->postJson("/api/v1/contractor/sites/{$site}/steps/{$second}/start")
            ->assertUnprocessable()
            ->assertJsonPath('errors.step.0', 'Validez d’abord « Installation de chantier ».');
        $this->postJson("/api/v1/contractor/sites/{$site}/steps/{$first}/complete")
            ->assertOk()
            ->assertJsonPath('data.steps.count_label', '1/2 étapes validées');
        $this->deleteJson("/api/v1/contractor/sites/{$site}/steps/{$second}")->assertOk()->assertJsonPath('data.steps.total', 1);

        $journal = $this->getJson("/api/v1/contractor/sites/{$site}/timeline")->assertOk()->json('data.journal');
        $titles = array_column($journal, 'title');
        $this->assertContains('Étape validée : Installation de chantier', $titles);
        $this->assertContains('Acompte confirmé — chantier attribué', $titles);
        $this->assertContains('Paiement confirmé : Acompte à l’attribution', $titles);

        $this->asPromoter()->getJson("/api/v1/promoter/sites/{$site}/steps")->assertJsonPath('data.steps.0.title', 'Installation de chantier');
    }

    public function test_documents_and_access_are_limited_to_attributed_sites(): void
    {
        $this->getJson("/api/v1/contractor/sites/{$this->offer->site_id}/documents")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.count', 2);

        $other = $this->publishedContract('Entrepôt Treichville', 'Cocody');
        $this->asContractor()->getJson("/api/v1/contractor/sites/{$other->site_id}")
            ->assertNotFound()
            ->assertJsonPath('message', 'Chantier non accessible : il ne vous est pas attribué.');
        $this->getJson('/api/v1/contractor/sites/'.Site::query()->where('label', 'Entrepôt Treichville')->value('id').'/timeline')->assertNotFound();
    }
}
