<?php

namespace Tests\Feature;

use App\Support\Api\ApiTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Paramètres branchés sur l’API (api/v1/admin/settings).
 */
class SettingsTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'http://localhost:8001/api/v1/admin';

    private const RATE = '01a16ab6-0000-7000-8000-0000000000c9';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.ochantier_api.url' => self::API]);
        $this->withSession([]);
        $this->startSession();
        app(ApiTokens::class)->store(['access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'expires_in' => 900, 'session_id' => '9a0e0000-0000-4000-8000-000000000001', 'admin' => ['id' => '1', 'full_name' => 'Direction', 'email' => 'direction@ochantier.ci', 'roles' => [], 'permissions' => ['settings.read', 'settings.approve']]]);
    }

    public function test_the_page_shows_what_applies(): void
    {
        Http::fake([self::API.'/settings' => Http::response(['data' => [
            'groups' => [['title' => 'Paiements', 'fields' => [['key' => 'payments.dual_validation_threshold', 'label' => 'Seuil', 'type' => 'int', 'min' => 1, 'max' => 2, 'unit' => 'FCFA', 'value' => 1000000]]]],
            'pricing' => ['current' => [['code' => 'Y', 'label' => 'Commission promoteur (Y)', 'value' => 8.0]], 'pending' => [['id' => self::RATE, 'code' => 'Y', 'label' => 'Commission promoteur (Y)', 'value' => 9.5, 'status' => ['value' => 'en_attente_approbation', 'label' => 'En attente'], 'effective_from' => '2026-12-01T00:00:00+00:00', 'effective_to' => null, 'reason' => 'Grille', 'by' => 'Super', 'by_id' => '2', 'approved_by' => null]], 'history' => []],
            'matching' => ['version' => 1, 'weights' => [['key' => 'metier', 'label' => 'Métier', 'value' => 25]], 'radius' => 20, 'radius_max' => 40, 'tolerance_max' => 25, 'reason' => null],
            'trades' => [['id' => 't1', 'code' => 'macon', 'label' => 'Maçon', 'active' => true, 'workers' => 3]],
            'integrations' => ['runtime' => [['label' => 'Passerelle de paiement', 'value' => 'Simulée : aucun argent ne circule', 'warning' => true]], 'providers' => []],
            'history' => [],
        ]])]);

        $this->get('/parametres')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings/Index')
                ->where('pricing.pending.0.value', 9.5)
                ->where('integrations.runtime.0.warning', true)
                ->where('can.approve', true)
                ->where('can.update', false));
    }

    public function test_values_and_rates_reach_the_api_and_refusals_become_messages(): void
    {
        Http::fake([
            self::API.'/settings/values' => Http::response(['message' => 'Réglage enregistré.', 'data' => []]),
            self::API.'/settings/rates/'.self::RATE.'/approve' => Http::response(['message' => 'Refusé.', 'errors' => ['pricing' => ['Une autre personne doit valider ce taux.']]], 422),
            self::API.'/settings/rates' => Http::response(['message' => 'Refusé.', 'errors' => ['value' => ['Saisissez un pourcentage entre 0 et 100.']]], 422),
        ]);
        $url = '/parametres';

        $this->from($url)->post('/parametres/valeurs', ['key' => 'support.hours', 'value' => 'Tous les jours', 'reason' => 'Nouveaux horaires'])->assertRedirect($url);
        Http::assertSent(fn (ClientRequest $request) => str_ends_with($request->url(), '/settings/values') && $request['key'] === 'support.hours');

        $this->from($url)->post('/parametres/taux/'.self::RATE.'/validation')->assertRedirect($url)->assertSessionHasNoErrors();
        $this->from($url)->post('/parametres/taux', ['code' => 'Y', 'value' => '200', 'effective_from' => '2026-12-01', 'reason' => 'Nouvelle grille'])->assertRedirect($url)->assertSessionHasErrors('value');
    }

    public function test_matching_weights_are_sent_as_integers(): void
    {
        Http::fake([self::API.'/settings/matching' => Http::response(['message' => 'Nouveau barème.', 'data' => []])]);

        $this->from('/parametres')->post('/parametres/matching', ['weight_metier' => '60', 'weight_note' => '40', 'radius' => '20', 'radius_max' => '40', 'tolerance_max' => '25', 'reason' => 'Réglage de test'])->assertRedirect('/parametres');

        Http::assertSent(fn (ClientRequest $request) => $request['weights'] === ['metier' => 60, 'note' => 40]);
    }

    public function test_old_sections_lead_to_the_page(): void
    {
        $this->get('/parametres/commissions')->assertRedirect('/parametres');
        $this->get('/parametres/securite')->assertRedirect('/parametres');
    }
}
