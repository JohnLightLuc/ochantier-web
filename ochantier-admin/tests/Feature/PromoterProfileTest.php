<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\DocumentStatus;
use App\Enums\PromoterStatus;
use App\Models\Promoter;
use App\Models\User;
use Database\Seeders\PromoterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PromoterProfileTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function validPayload(array $overrides = []): array
    {
        return [
            'name' => 'Lagune Habitat',
            'legal_form' => 'SARL',
            'rccm' => 'CI-ABJ-2026-B-12345',
            'ncc' => '',
            'sector' => 'Aménagement',
            'head_office' => 'Rue du Commerce, Abidjan-Plateau',
            'representative' => 'Awa Diallo',
            'representative_role' => 'Gérante',
            'phone' => '+225 07 01 02 03 04',
            'email' => 'awa@lagune.ci',
            'account_type' => 'company',
            'location' => 'Abidjan, Plateau',
            'note' => '',
            ...$overrides,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PromoterSeeder::class);
        $this->actingAs(User::factory()->create(['name' => 'N. Yao']));
    }

    public function test_profile_overview_has_kpis_alerts_and_breadcrumbs(): void
    {
        $this->get('/promoteurs/sicogi')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Promoters/Show')
                ->where('tab', '')
                ->where('promoter.kyc.label', 'Expiré')
                ->has('kpis', 4)
                ->where('kpis.3.value', '1')
                ->has('documents', 5)
                ->where('alerts.0.title', 'Justificatif d’adresse expiré')
                ->where('header.crumbs.1.label', 'SICOGI')
                ->where('header.crumbs.2.label', 'Vue générale')
            );
    }

    public function test_tabs_and_drawers_are_routable(): void
    {
        $document = Promoter::firstWhere('slug', 'sicogi')->documents()->where('type', 'proof_of_address')->first();

        $this->get('/promoteurs/sicogi/litiges')->assertInertia(fn (Assert $page) => $page->where('tab', 'litiges')->has('relations.disputes', 2));
        $this->get("/promoteurs/sicogi/kyc/{$document->id}")->assertInertia(fn (Assert $page) => $page->where('drawer.kind', 'document')->where('drawer.document.status.label', 'Expiré'));
        $this->get('/promoteurs/sicogi/offres/of3')->assertInertia(fn (Assert $page) => $page->where('drawer.kind', 'offer')->has('drawer.candidates', 3));
        $this->get('/promoteurs/sicogi/inconnu')->assertNotFound();
        $this->get('/promoteurs/introuvable')->assertNotFound();
    }

    public function test_create_form_validates_fields(): void
    {
        $this->get('/promoteurs/nouveau')->assertInertia(fn (Assert $page) => $page->component('Promoters/Form')->where('mode', 'create'));

        $this->post('/promoteurs', $this->validPayload(['name' => '', 'rccm' => 'ABC', 'phone' => '+225 07 01', 'email' => 'pas-un-email']))
            ->assertSessionHasErrors([
                'name' => 'Ce champ est obligatoire.',
                'rccm' => 'Format attendu : CI-ABJ-2026-B-00000.',
                'phone' => 'Le numéro doit contenir 10 chiffres.',
                'email' => 'Adresse e-mail invalide.',
            ]);
    }

    public function test_new_promoter_is_pending_with_an_empty_kyc_file(): void
    {
        $this->post('/promoteurs', $this->validPayload())->assertRedirect('/promoteurs/lagune-habitat');

        $promoter = Promoter::firstWhere('slug', 'lagune-habitat');
        $this->assertSame(PromoterStatus::Pending, $promoter->status);
        $this->assertSame(AccountType::Company, $promoter->account_type);
        $this->assertSame(5, $promoter->documents()->where('status', DocumentStatus::Missing)->count());
        $this->assertSame('a créé le compte promoteur', $promoter->activities()->first()->action);
        $this->assertSame('N. Yao', $promoter->activities()->first()->author);
    }

    public function test_duplicate_names_get_a_unique_slug(): void
    {
        $this->post('/promoteurs', $this->validPayload(['name' => 'SICOGI']))->assertRedirect('/promoteurs/sicogi-2');
    }

    public function test_company_edit_only_changes_company_and_representative(): void
    {
        $this->get('/promoteurs/sicogi/modifier/entreprise')->assertInertia(fn (Assert $page) => $page->where('mode', 'company')->where('values.rccm', 'CI-ABJ-1962-B-2104'));

        $payload = collect($this->validPayload(['name' => 'SICOGI SA', 'location' => 'Bouaké']))->except(['account_type', 'note'])->all();

        $this->put('/promoteurs/sicogi', [...$payload, 'mode' => 'company'])->assertRedirect('/promoteurs/sicogi/kyc');

        $promoter = Promoter::firstWhere('slug', 'sicogi');
        $this->assertSame('SICOGI SA', $promoter->name);
        $this->assertSame('Abidjan, Plateau', $promoter->location);
        $this->assertSame(AccountType::Institution, $promoter->account_type);
    }

    public function test_sheet_is_downloaded_and_logged(): void
    {
        $this->get('/promoteurs/sicogi/fiche')
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="fiche-sicogi.html"')
            ->assertSee('CI-ABJ-1962-B-2104');

        $this->assertSame('a exporté la fiche', Promoter::firstWhere('slug', 'sicogi')->activities()->first()->action);
    }
}
