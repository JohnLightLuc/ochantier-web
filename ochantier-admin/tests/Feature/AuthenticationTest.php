<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Api\AdminUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'http://api.test/api/v1/admin';

    /**
     * @return array<string, mixed>
     */
    private function admin(array $overrides = []): array
    {
        return [
            'id' => '9a0e0000-0000-4000-8000-000000000001',
            'first_name' => 'Awa',
            'last_name' => 'Koné',
            'full_name' => 'Awa Koné',
            'email' => 'a.kone@ochantier.ci',
            'status' => ['value' => 'actif', 'label' => 'Actif'],
            'must_change_password' => false,
            'roles' => [['code' => 'operations', 'label' => 'Responsable des opérations']],
            'permissions' => ['dashboard.view'],
            ...$overrides,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function tokens(string $suffix = '1', array $admin = []): array
    {
        return [
            'message' => null,
            'data' => [
                'token_type' => 'Bearer',
                'access_token' => "access-{$suffix}",
                'expires_in' => 900,
                'refresh_token' => "refresh-{$suffix}",
                'refresh_expires_at' => now()->addHours(12)->toIso8601String(),
                'session_id' => '9a0e0000-0000-4000-8000-00000000000'.$suffix,
                'admin' => $this->admin($admin),
            ],
        ];
    }

    private function codeSent(): array
    {
        return ['message' => 'Code de vérification envoyé par e-mail.', 'data' => ['mfa_required' => true, 'channel' => 'email', 'destination' => 'a•••@ochantier.ci', 'expires_in' => 600, 'resend_in' => 45, 'code_length' => 6]];
    }

    private function signIn(array $admin = []): void
    {
        Http::fake([
            self::API.'/auth/login' => Http::response($this->codeSent(), 202),
            self::API.'/auth/login/verify' => Http::response($this->tokens('1', $admin)),
        ]);

        $this->post('/connexion', ['email' => 'a.kone@ochantier.ci', 'password' => 'motdepasse1']);
        $this->post('/connexion/verification', ['code' => '123456']);
    }

    public function test_login_screen_can_be_rendered(): void
    {
        $this->get('/connexion')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Auth/Login')->where('step', 'login')->where('email', null));
    }

    public function test_valid_credentials_lead_to_the_otp_step_without_logging_in(): void
    {
        Http::fake([self::API.'/auth/login' => Http::response($this->codeSent(), 202)]);

        $this->post('/connexion', ['email' => 'a.kone@ochantier.ci', 'password' => 'motdepasse1', 'remember' => true])
            ->assertRedirect('/connexion/verification')
            ->assertCookie('och_email', 'a.kone@ochantier.ci')
            ->assertCookie('och_device');

        $this->assertGuest();

        Http::assertSent(fn (ClientRequest $request) => $request->url() === self::API.'/auth/login'
            && $request['email'] === 'a.kone@ochantier.ci'
            && $request['password'] === 'motdepasse1'
            && $request['device_name'] === 'Navigateur'
            && $request->hasHeader('X-Forwarded-For', '127.0.0.1'));

        $this->get('/connexion/verification')
            ->assertInertia(fn (Assert $page) => $page->where('step', 'otp')->where('maskedEmail', 'a•••@ochantier.ci')->where('resendIn', 45));
    }

    public function test_api_refusals_are_shown_on_the_login_form(): void
    {
        Http::fake([self::API.'/auth/login' => Http::sequence()
            ->push(['message' => 'The given data was invalid.', 'errors' => ['password' => ['E-mail ou mot de passe incorrect.']]], 422)
            ->push(['message' => 'Trop de tentatives. Réessayez à 10:15.', 'code' => 'account_locked'], 423)
            ->push(['message' => 'Server Error'], 500),
        ]);

        $this->post('/connexion', ['email' => 'a.kone@ochantier.ci', 'password' => 'mauvais'])
            ->assertSessionHasErrors(['password' => 'E-mail ou mot de passe incorrect.']);

        $this->post('/connexion', ['email' => 'a.kone@ochantier.ci', 'password' => 'mauvais'])
            ->assertSessionHasErrors(['email' => 'Trop de tentatives. Réessayez à 10:15.']);

        $this->post('/connexion', ['email' => 'a.kone@ochantier.ci', 'password' => 'mauvais'])
            ->assertSessionHasErrors(['email' => 'Le service O’CHANTIER est momentanément indisponible. Réessayez dans quelques instants.']);

        $this->assertGuest();
    }

    public function test_otp_step_requires_pending_credentials(): void
    {
        $this->get('/connexion/verification')->assertRedirect('/connexion');
        $this->post('/connexion/verification', ['code' => '123456'])->assertRedirect('/connexion');
        $this->post('/connexion/verification/renvoi')->assertRedirect('/connexion');
    }

    public function test_the_code_is_verified_by_the_api_and_opens_the_session(): void
    {
        Http::fake([
            self::API.'/auth/login' => Http::response($this->codeSent(), 202),
            self::API.'/auth/login/verify' => Http::sequence()
                ->push(['message' => 'The given data was invalid.', 'errors' => ['code' => ['Code incorrect ou expiré.']]], 422)
                ->push($this->tokens()),
        ]);

        $this->post('/connexion', ['email' => 'a.kone@ochantier.ci', 'password' => 'motdepasse1']);

        $this->post('/connexion/verification', ['code' => '12345'])->assertSessionHasErrors('code');
        $this->post('/connexion/verification', ['code' => '000000'])->assertSessionHasErrors(['code' => 'Code incorrect ou expiré.']);
        $this->assertGuest();

        $this->post('/connexion/verification', ['code' => '123456'])->assertRedirect('/');

        Http::assertSent(fn (ClientRequest $request) => $request->url() === self::API.'/auth/login/verify' && $request['email'] === 'a.kone@ochantier.ci' && $request['code'] === '123456');

        $this->assertAuthenticated();
        $this->assertSame('Awa Koné', auth()->user()->name);
        $this->assertSame('Responsable des opérations', auth()->user()->role);
        $this->assertNull(session('login.pending'));
        $this->assertNotSame('access-1', session('ochantier_api.access_token'), 'Le jeton est chiffré en session.');
    }

    public function test_the_code_can_be_sent_again(): void
    {
        Http::fake([
            self::API.'/auth/login' => Http::response($this->codeSent(), 202),
            self::API.'/auth/login/resend' => Http::sequence()
                ->push(['message' => 'Patientez 30 s avant de demander un nouveau code.', 'retry_after' => 30], 429)
                ->push(['message' => 'Un nouveau code vous a été envoyé.', 'data' => $this->codeSent()['data']], 202),
        ]);

        $this->post('/connexion', ['email' => 'a.kone@ochantier.ci', 'password' => 'motdepasse1']);

        $this->post('/connexion/verification/renvoi')
            ->assertSessionHasErrors(['code' => 'Patientez 30 s avant de demander un nouveau code.']);

        $this->post('/connexion/verification/renvoi')
            ->assertRedirect('/connexion/verification')
            ->assertSessionHas('status', 'Un nouveau code vous a été envoyé.');
    }

    public function test_password_reset_request_is_sent_to_the_api(): void
    {
        Http::fake([self::API.'/auth/password/forgot' => Http::response(['message' => 'Si un compte…', 'data' => ['expires_in' => 1800]], 202)]);

        $this->post('/mot-de-passe-oublie', ['email' => 'a.kone@ochantier.ci'])
            ->assertRedirect('/mot-de-passe-oublie');

        Http::assertSent(fn (ClientRequest $request) => $request['email'] === 'a.kone@ochantier.ci');

        $this->get('/mot-de-passe-oublie')
            ->assertInertia(fn (Assert $page) => $page->where('step', 'sent')->where('sentTo', 'a.kone@ochantier.ci'));
    }

    public function test_the_reset_link_sets_a_new_password(): void
    {
        $token = str_repeat('a', 64);

        Http::fake([self::API.'/auth/password/reset' => Http::sequence()
            ->push(['message' => 'The given data was invalid.', 'errors' => ['token' => ['Ce lien a expiré ou a déjà été utilisé. Faites une nouvelle demande.']]], 422)
            ->push(['message' => 'Mot de passe mis à jour. Connectez-vous avec votre nouveau mot de passe.', 'data' => null]),
        ]);

        $this->get('/reinitialiser-mot-de-passe')->assertRedirect('/mot-de-passe-oublie');
        $this->get("/reinitialiser-mot-de-passe?token={$token}&email=a.kone@ochantier.ci")
            ->assertInertia(fn (Assert $page) => $page->where('step', 'reset')->where('token', $token)->where('email', 'a.kone@ochantier.ci'));

        $payload = ['token' => $token, 'email' => 'a.kone@ochantier.ci', 'password' => 'Nouveau#Secret26', 'password_confirmation' => 'Nouveau#Secret26'];

        $this->post('/reinitialiser-mot-de-passe', $payload)->assertSessionHasErrors('token');

        $this->post('/reinitialiser-mot-de-passe', $payload)
            ->assertRedirect('/connexion')
            ->assertSessionHas('status', 'Mot de passe mis à jour. Connectez-vous avec votre nouveau mot de passe.');

        Http::assertSent(fn (ClientRequest $request) => $request->url() === self::API.'/auth/password/reset' && $request['password_confirmation'] === 'Nouveau#Secret26');
    }

    public function test_a_temporary_password_must_be_changed_first(): void
    {
        $this->signIn(['must_change_password' => true]);

        $this->get('/')->assertRedirect('/mot-de-passe/nouveau');
        $this->get('/mot-de-passe/nouveau')->assertInertia(fn (Assert $page) => $page->where('step', 'change'));

        Http::fake([self::API.'/auth/password' => Http::response(['message' => 'Mot de passe mis à jour', 'data' => $this->admin()])]);

        $this->put('/mot-de-passe', ['current_password' => 'motdepasse1', 'password' => 'Nouveau#Secret26', 'password_confirmation' => 'Nouveau#Secret26'])
            ->assertRedirect('/');

        Http::assertSent(fn (ClientRequest $request) => $request->method() === 'PUT' && $request->hasHeader('Authorization', 'Bearer access-1'));

        $this->app['auth']->forgetGuards();
        $this->get('/')->assertOk();
    }

    public function test_the_access_token_is_refreshed_when_it_expires(): void
    {
        $this->signIn();

        Http::fake([
            self::API.'/auth/refresh' => Http::response($this->tokens('2')),
            self::API.'/me' => Http::response(['message' => null, 'data' => $this->admin()]),
        ]);

        $this->travel(16)->minutes();
        $this->get('/')->assertOk();

        Http::assertSent(fn (ClientRequest $request) => $request->url() === self::API.'/auth/refresh' && $request['refresh_token'] === 'refresh-1');
        Http::assertSent(fn (ClientRequest $request) => $request->url() === self::API.'/me' && $request->hasHeader('Authorization', 'Bearer access-2'));
    }

    public function test_a_session_closed_in_the_api_logs_the_administrator_out(): void
    {
        $this->signIn();

        Http::fake([
            self::API.'/me' => Http::response(['message' => 'Session expirée : reconnectez-vous.'], 401),
            self::API.'/auth/refresh' => Http::response(['message' => 'Session expirée : reconnectez-vous.'], 401),
        ]);

        $this->travel(2)->minutes();
        $this->app['auth']->forgetGuards();

        $this->get('/')->assertRedirect('/connexion');
        $this->assertGuest();
        $this->assertNull(session('ochantier_api'));
    }

    public function test_the_profile_is_kept_when_the_api_is_unavailable(): void
    {
        $this->signIn();

        Http::fake([self::API.'/me' => Http::response(['message' => 'Server Error'], 503)]);

        $this->travel(2)->minutes();
        $this->app['auth']->forgetGuards();

        $this->get('/')->assertOk();
        $this->assertInstanceOf(AdminUser::class, auth()->user());
    }

    public function test_users_can_logout(): void
    {
        $this->signIn();

        Http::fake([self::API.'/auth/logout' => Http::response(['message' => 'Vous êtes déconnecté.', 'data' => null])]);

        $this->post('/deconnexion')->assertRedirect('/connexion');

        Http::assertSent(fn (ClientRequest $request) => $request->url() === self::API.'/auth/logout' && $request->hasHeader('Authorization', 'Bearer access-1'));
        $this->assertGuest();
    }

    public function test_logout_works_without_an_api_session(): void
    {
        $this->actingAs(User::factory()->create());

        $this->post('/deconnexion')->assertRedirect('/connexion');

        $this->assertGuest();
    }
}
