<?php

namespace Tests\Feature\Contractor;

use App\Enums\ContractorKind;
use App\Enums\ContractorStatus;
use App\Enums\ContractorVerificationStatus;
use App\Models\Commune;
use App\Models\Contractor;
use App\Models\Offer;
use App\Models\QuoteRequestTrade;
use App\Models\Trade;
use App\Models\User;
use Database\Seeders\AdminAccessSeeder;
use Database\Seeders\CommercialOfferSeeder;
use Database\Seeders\PlatformRulesSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Base des tests où un promoteur publie des demandes de devis ou des offres contractuelles auxquelles répond
 * l’entrepreneur connecté (indépendant électricien d’Abidjan, dossier en brouillon).
 */
abstract class ContractorMarketTestCase extends ContractorTestCase
{
    protected User $promoter;

    protected Contractor $contractor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([AdminAccessSeeder::class, PlatformRulesSeeder::class, CommercialOfferSeeder::class]);
        $this->promoter = User::factory()->promoter()->create(['first_name' => 'Awa', 'last_name' => 'Kouamé', 'dial_code' => '225', 'phone_number' => '0712349999']);

        $this->contractor = $this->user->contractor()->create([
            'reference' => 'ENT-10001',
            'kind' => ContractorKind::Independant,
            'name' => 'Yao Serge Koffi',
            'city_id' => $this->city('Abidjan'),
            'status' => ContractorStatus::Actif,
            'verification_status' => ContractorVerificationStatus::Brouillon,
        ]);
        $this->contractor->trades()->attach($this->trade('electricien'));
    }

    /**
     * @return $this
     */
    protected function asContractor(): static
    {
        return $this->actingAsContractor($this->user);
    }

    /**
     * @return $this
     */
    protected function asPromoter(): static
    {
        return $this->actingAsContractor($this->promoter);
    }

    /**
     * Chantier du promoteur, prêt à publier, dans la commune donnée.
     *
     * @return array<string, mixed>
     */
    protected function site(string $title, string $commune): array
    {
        $site = $this->asPromoter()->postJson('/api/v1/promoter/sites', ['title' => $title])->assertCreated()->json('data');
        $this->post("/api/v1/promoter/sites/{$site['id']}/photo", ['photo' => $this->image()], ['Accept' => 'application/json'])->assertOk();
        $this->patchJson("/api/v1/promoter/sites/{$site['id']}", [
            'address' => '12 Rue des Jardins',
            'commune_id' => Commune::query()->where('name', $commune)->value('id'),
            'description' => 'Villa de 5 pièces, gros œuvre achevé.',
            'access_instructions' => 'Accès par la piste latérale.',
            'planned_start_date' => now()->addDays(5)->toDateString(),
            'planned_end_date' => now()->addDays(34)->toDateString(),
        ])->assertOk();
        $this->post("/api/v1/promoter/sites/{$site['id']}/documents", ['document_type' => 'site_plan', 'file' => $this->pdf('Plan.pdf')], ['Accept' => 'application/json'])->assertCreated();

        return $site;
    }

    /**
     * Demande de devis envoyée (« Rechercher un technicien »).
     *
     * @param  list<string>  $trades
     * @return array<string, mixed>
     */
    protected function quoteRequest(string $title, string $commune, array $trades): array
    {
        $site = $this->site($title, $commune);
        $this->putJson("/api/v1/promoter/sites/{$site['id']}/offer", ['type' => 'contractuelle', 'contract' => ['has_quote' => false, 'quote_request' => [
            'trade_ids' => array_map(fn (string $code) => Trade::query()->where('code', $code)->value('id'), $trades),
            'notes' => 'Villa R+1 : tableau, prises et éclairage.',
        ]]])->assertOk();
        $this->postJson("/api/v1/promoter/sites/{$site['id']}/publish")->assertOk();

        return $site;
    }

    /**
     * Offre contractuelle publiée avec le devis du promoteur.
     */
    protected function publishedContract(string $title, string $commune): Offer
    {
        return $this->contractOn($this->site($title, $commune));
    }

    /**
     * @param  array<string, mixed>  $site
     */
    protected function contractOn(array $site): Offer
    {
        DB::table('quote_requests')->where('site_id', $site['id'])->update(['status' => 'recu']);
        DB::table('sites')->where('id', $site['id'])->update(['status' => 'brouillon']);

        $this->asPromoter()->post("/api/v1/promoter/sites/{$site['id']}/documents", ['document_type' => 'site_quote', 'file' => $this->pdf('Devis.pdf')], ['Accept' => 'application/json'])->assertCreated();
        $this->putJson("/api/v1/promoter/sites/{$site['id']}/offer", ['type' => 'contractuelle', 'contract' => ['has_quote' => true, 'amount' => 3300000, 'payment_mode' => 'par_etape']])->assertOk();
        $this->postJson("/api/v1/promoter/sites/{$site['id']}/publish")->assertOk();

        return Offer::query()->where('site_id', $site['id'])->sole();
    }

    /**
     * @param  array<string, mixed>  $site
     */
    protected function line(array $site, string $trade): QuoteRequestTrade
    {
        return QuoteRequestTrade::query()->whereHas('quoteRequest', fn ($query) => $query->where('site_id', $site['id']))->whereHas('trade', fn ($query) => $query->where('code', $trade))->sole();
    }
}
