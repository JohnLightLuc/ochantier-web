<?php

namespace Tests\Feature;

use App\Support\Api\AdminApi;
use App\Support\Api\ApiTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Client de l’API : jeton transmis, rafraîchissement après un refus 401 et présentation des erreurs.
 */
class AdminApiTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'http://api.test/api/v1/admin';

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('web')->group(function () {
            Route::get('/_test/promoteurs', fn (AdminApi $api) => $api->get('promoters', ['page' => 2])->json());
            Route::post('/_test/promoteurs', function (AdminApi $api) {
                $api->post('promoters', request()->all());

                return back();
            });
        });
    }

    private function withApiSession(): void
    {
        $this->withSession([]);
        $this->startSession();

        app(ApiTokens::class)->store([
            'access_token' => 'access-1',
            'refresh_token' => 'refresh-1',
            'expires_in' => 900,
            'session_id' => '9a0e0000-0000-4000-8000-000000000001',
            'admin' => ['id' => '1', 'full_name' => 'Awa Koné', 'email' => 'a.kone@ochantier.ci', 'roles' => [], 'permissions' => [], 'must_change_password' => false],
        ]);
    }

    public function test_calls_carry_the_access_token_and_the_query(): void
    {
        $this->withApiSession();
        Http::fake([self::API.'/promoters*' => Http::response(['message' => null, 'data' => [['id' => 'p1']]])]);

        $this->get('/_test/promoteurs')->assertOk()->assertJsonPath('data.0.id', 'p1');

        Http::assertSent(fn (ClientRequest $request) => $request->url() === self::API.'/promoters?page=2'
            && $request->hasHeader('Authorization', 'Bearer access-1')
            && $request->hasHeader('Accept', 'application/json'));
    }

    public function test_a_rejected_token_is_refreshed_once_then_the_call_is_replayed(): void
    {
        $this->withApiSession();
        Http::fake([
            self::API.'/promoters*' => Http::sequence()->push(['message' => 'Session expirée : reconnectez-vous.'], 401)->push(['message' => null, 'data' => []]),
            self::API.'/auth/refresh' => Http::response(['message' => null, 'data' => [
                'access_token' => 'access-2', 'refresh_token' => 'refresh-2', 'expires_in' => 900, 'session_id' => '9a0e0000-0000-4000-8000-000000000001',
                'admin' => ['id' => '1', 'full_name' => 'Awa Koné', 'email' => 'a.kone@ochantier.ci', 'roles' => [], 'permissions' => []],
            ]]),
        ]);

        $this->get('/_test/promoteurs')->assertOk();

        Http::assertSentCount(3);
        Http::assertSent(fn (ClientRequest $request) => str_starts_with($request->url(), self::API.'/promoters') && $request->hasHeader('Authorization', 'Bearer access-2'));
        $this->assertSame('refresh-2', app(ApiTokens::class)->refreshToken());
    }

    public function test_without_an_api_session_the_administrator_is_sent_to_the_login_screen(): void
    {
        $this->get('/_test/promoteurs')->assertRedirect('/connexion');

        Http::assertNothingSent();
    }

    public function test_validation_errors_become_form_errors(): void
    {
        $this->withApiSession();
        Http::fake([self::API.'/promoters' => Http::response(['message' => 'The given data was invalid.', 'errors' => ['company_name' => ['Ce champ est obligatoire.']]], 422)]);

        $this->from('/promoteurs')->post('/_test/promoteurs')
            ->assertRedirect('/promoteurs')
            ->assertSessionHasErrors(['company_name' => 'Ce champ est obligatoire.']);
    }

    public function test_a_refused_action_is_shown_as_a_notification(): void
    {
        $this->withApiSession();
        Http::fake([self::API.'/promoters' => Http::response(['message' => 'Vous n’avez pas la permission d’effectuer cette action.', 'code' => 'permission_denied'], 403)]);

        $this->from('/promoteurs')->post('/_test/promoteurs')
            ->assertRedirect('/promoteurs')
            ->assertSessionHas('inertia.flash_data.toast', 'Vous n’avez pas la permission d’effectuer cette action.');
    }

    public function test_a_page_refused_or_unavailable_renders_the_matching_error_page(): void
    {
        $this->withApiSession();
        Http::fake([self::API.'/promoters*' => Http::sequence()
            ->push(['message' => 'Ressource introuvable.'], 404)
            ->pushFailedConnection(),
        ]);

        $this->get('/_test/promoteurs')->assertNotFound();
        $this->get('/_test/promoteurs')->assertStatus(503);
    }

    public function test_a_temporary_password_redirects_to_the_change_screen(): void
    {
        $this->withApiSession();
        Http::fake([self::API.'/promoters*' => Http::response(['message' => 'Choisissez un nouveau mot de passe pour continuer.', 'code' => 'password_change_required'], 403)]);

        $this->get('/_test/promoteurs')->assertRedirect('/mot-de-passe/nouveau');
    }
}
