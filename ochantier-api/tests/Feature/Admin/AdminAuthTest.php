<?php

namespace Tests\Feature\Admin;

use App\Enums\AdminStatus;
use App\Models\Admin;
use App\Models\AdminIpAllowlist;
use App\Models\AuditLog;
use App\Models\AuthSession;
use App\Models\LoginAttempt;
use App\Models\User;
use Database\Factories\AdminFactory;
use Database\Seeders\AdminAccessSeeder;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Mime\Email;
use Tests\Feature\Auth\AuthTestCase;

class AdminAuthTest extends AuthTestCase
{
    private const PASSWORD = AdminFactory::PASSWORD;

    public function test_an_admin_signs_in_with_password_then_email_code(): void
    {
        $admin = Admin::factory()->role('operations')->create(['email' => 'a.kone@ochantier.ci']);

        $this->postJson('/api/v1/admin/auth/login', ['email' => ' A.Kone@ochantier.ci ', 'password' => self::PASSWORD])
            ->assertAccepted()
            ->assertJsonPath('data.mfa_required', true)
            ->assertJsonPath('data.channel', 'email')
            ->assertJsonPath('data.destination', 'a•••@ochantier.ci');

        $this->assertStringContainsString('123456', $this->sentMails()[0]->getTextBody());

        $data = $this->postJson('/api/v1/admin/auth/login/verify', ['email' => 'a.kone@ochantier.ci', 'code' => '123456', 'device_name' => 'Chrome · macOS'])
            ->assertOk()
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.admin.email', 'a.kone@ochantier.ci')
            ->assertJsonPath('data.admin.roles.0.code', 'operations')
            ->json('data');

        $session = AuthSession::query()->findOrFail($data['session_id']);
        $this->assertSame(['admin', 'backoffice', 'web'], [$session->authenticatable_type, $session->app_name, $session->platform]);
        $this->assertNotNull($session->mfa_verified_at);
        $this->assertTrue(LoginAttempt::query()->where('authenticatable_type', 'admin')->where('success', true)->exists());
        $this->assertTrue(AuditLog::query()->where('actor_id', $admin->id)->where('action', 'login')->exists());

        $this->withAccessToken($data['access_token'])->getJson('/api/v1/admin/me')
            ->assertOk()
            ->assertJsonPath('data.id', $admin->id)
            ->assertJsonPath('data.status.value', 'actif');
    }

    public function test_a_wrong_password_or_unknown_email_gets_the_same_answer_without_a_code(): void
    {
        Admin::factory()->create(['email' => 'a.kone@ochantier.ci']);

        $this->postJson('/api/v1/admin/auth/login', ['email' => 'a.kone@ochantier.ci', 'password' => 'mauvais'])
            ->assertJsonValidationErrors(['password' => 'E-mail ou mot de passe incorrect.']);

        $this->postJson('/api/v1/admin/auth/login', ['email' => 'inconnu@ochantier.ci', 'password' => self::PASSWORD])
            ->assertJsonValidationErrors(['password' => 'E-mail ou mot de passe incorrect.']);

        $this->assertEmpty($this->sentMails());
        $this->assertSame(2, LoginAttempt::query()->where('authenticatable_type', 'admin')->where('success', false)->count());
    }

    public function test_the_account_is_locked_after_five_wrong_passwords(): void
    {
        Admin::factory()->create(['email' => 'a.kone@ochantier.ci']);

        foreach (range(1, 4) as $attempt) {
            $this->postJson('/api/v1/admin/auth/login', ['email' => 'a.kone@ochantier.ci', 'password' => 'mauvais'])->assertUnprocessable();
        }

        $this->postJson('/api/v1/admin/auth/login', ['email' => 'a.kone@ochantier.ci', 'password' => 'mauvais'])
            ->assertStatus(423)
            ->assertJsonPath('code', 'account_locked');

        $this->postJson('/api/v1/admin/auth/login', ['email' => 'a.kone@ochantier.ci', 'password' => self::PASSWORD])->assertStatus(423);

        $this->travel(16)->minutes();
        $this->postJson('/api/v1/admin/auth/login', ['email' => 'a.kone@ochantier.ci', 'password' => self::PASSWORD])->assertAccepted();
    }

    public function test_suspended_invited_or_expired_accounts_cannot_sign_in(): void
    {
        Admin::factory()->create(['email' => 'suspendu@ochantier.ci', 'status' => AdminStatus::Suspendu]);
        Admin::factory()->create(['email' => 'invite@ochantier.ci', 'status' => AdminStatus::Invite]);
        Admin::factory()->create(['email' => 'temporaire@ochantier.ci', 'access_expires_at' => now()->subDay()]);

        $this->postJson('/api/v1/admin/auth/login', ['email' => 'suspendu@ochantier.ci', 'password' => self::PASSWORD])
            ->assertForbidden()
            ->assertJsonPath('message', 'Votre compte est suspendu. Contactez un super administrateur.');

        $this->postJson('/api/v1/admin/auth/login', ['email' => 'invite@ochantier.ci', 'password' => self::PASSWORD])
            ->assertForbidden()
            ->assertJsonPath('code', 'account_unavailable');

        $this->postJson('/api/v1/admin/auth/login', ['email' => 'temporaire@ochantier.ci', 'password' => self::PASSWORD])
            ->assertForbidden()
            ->assertJsonPath('message', 'Votre accès temporaire a expiré. Contactez un super administrateur.');

        $this->assertEmpty($this->sentMails());
    }

    public function test_the_code_is_checked_and_cannot_come_from_a_mobile_account_with_the_same_email(): void
    {
        Admin::factory()->create(['email' => 'n.yao@ochantier.ci']);
        User::factory()->promoter()->create(['email' => 'n.yao@ochantier.ci']);

        // Code de connexion promoteur demandé pour la même adresse : il ne vaut pas pour le Back Office.
        $this->postJson('/api/v1/auth/promoter/login', ['email' => 'n.yao@ochantier.ci'])->assertAccepted();

        $this->postJson('/api/v1/admin/auth/login/verify', ['email' => 'n.yao@ochantier.ci', 'code' => '123456'])
            ->assertJsonValidationErrors(['code' => 'Code incorrect ou expiré.']);

        $this->postJson('/api/v1/admin/auth/login', ['email' => 'n.yao@ochantier.ci', 'password' => self::PASSWORD])->assertAccepted();

        $this->postJson('/api/v1/admin/auth/login/verify', ['email' => 'n.yao@ochantier.ci', 'code' => '000000'])
            ->assertJsonValidationErrors(['code' => 'Code incorrect ou expiré.']);

        $this->postJson('/api/v1/admin/auth/login/verify', ['email' => 'n.yao@ochantier.ci', 'code' => '123456'])->assertOk();
        $this->postJson('/api/v1/admin/auth/login/verify', ['email' => 'n.yao@ochantier.ci', 'code' => '123456'])->assertUnprocessable();
    }

    public function test_the_code_can_be_resent_only_after_the_password_was_checked(): void
    {
        Admin::factory()->create(['email' => 'a.kone@ochantier.ci']);

        $this->postJson('/api/v1/admin/auth/login/resend', ['email' => 'a.kone@ochantier.ci'])
            ->assertJsonValidationErrors(['email' => 'Reconnectez-vous avec votre mot de passe pour recevoir un nouveau code.']);

        $this->postJson('/api/v1/admin/auth/login', ['email' => 'a.kone@ochantier.ci', 'password' => self::PASSWORD])->assertAccepted();

        $this->postJson('/api/v1/admin/auth/login/resend', ['email' => 'a.kone@ochantier.ci'])->assertStatus(429);

        $this->travel(46)->seconds();
        $this->postJson('/api/v1/admin/auth/login/resend', ['email' => 'a.kone@ochantier.ci'])
            ->assertAccepted()
            ->assertJsonPath('message', 'Un nouveau code vous a été envoyé.');

        $this->assertCount(2, $this->sentMails());
    }

    public function test_signing_in_again_right_after_reuses_the_code_already_sent(): void
    {
        Admin::factory()->create(['email' => 'a.kone@ochantier.ci']);

        $this->postJson('/api/v1/admin/auth/login', ['email' => 'a.kone@ochantier.ci', 'password' => self::PASSWORD])->assertAccepted();

        $this->travel(10)->seconds();
        $this->postJson('/api/v1/admin/auth/login', ['email' => 'a.kone@ochantier.ci', 'password' => self::PASSWORD])
            ->assertAccepted()
            ->assertJsonPath('message', 'Un code vous a déjà été envoyé par e-mail.')
            ->assertJsonPath('data.resend_in', 35);

        $this->assertCount(1, $this->sentMails());
        $this->postJson('/api/v1/admin/auth/login/verify', ['email' => 'a.kone@ochantier.ci', 'code' => '123456'])->assertOk();
    }

    public function test_admin_and_mobile_tokens_are_not_interchangeable(): void
    {
        $admin = $this->signIn(Admin::factory()->create());
        $user = $this->openSession(User::factory()->promoter()->create());

        $this->withAccessToken($admin['access_token'])->getJson('/api/v1/me')->assertUnauthorized();
        $this->withAccessToken($user->accessToken)->getJson('/api/v1/admin/me')->assertUnauthorized();

        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $admin['refresh_token']])->assertUnauthorized();
        $this->postJson('/api/v1/admin/auth/refresh', ['refresh_token' => $user->refreshToken])->assertUnauthorized();
    }

    public function test_refreshing_rotates_both_tokens(): void
    {
        $tokens = $this->signIn(Admin::factory()->create());

        $refreshed = $this->postJson('/api/v1/admin/auth/refresh', ['refresh_token' => $tokens['refresh_token']])
            ->assertOk()
            ->assertJsonPath('data.session_id', $tokens['session_id'])
            ->json('data');

        $this->postJson('/api/v1/admin/auth/refresh', ['refresh_token' => $tokens['refresh_token']])->assertUnauthorized();
        $this->withAccessToken($tokens['access_token'])->getJson('/api/v1/admin/me')->assertUnauthorized();
        $this->withAccessToken($refreshed['access_token'])->getJson('/api/v1/admin/me')->assertOk();
    }

    public function test_a_session_closes_after_thirty_minutes_without_activity(): void
    {
        $tokens = $this->signIn(Admin::factory()->create());

        $this->travel(31)->minutes();

        $this->postJson('/api/v1/admin/auth/refresh', ['refresh_token' => $tokens['refresh_token']])->assertUnauthorized();
        $this->assertSame('idle_timeout', AuthSession::query()->findOrFail($tokens['session_id'])->revoked_reason);
    }

    public function test_a_session_lasts_at_most_twelve_hours_even_when_refreshed(): void
    {
        $tokens = $this->signIn(Admin::factory()->create());

        foreach (range(1, 35) as $step) {
            $this->travel(20)->minutes();
            $tokens = $this->postJson('/api/v1/admin/auth/refresh', ['refresh_token' => $tokens['refresh_token']])->assertOk()->json('data');
        }

        $this->travel(61)->minutes();
        $this->postJson('/api/v1/admin/auth/refresh', ['refresh_token' => $tokens['refresh_token']])->assertUnauthorized();
    }

    public function test_the_oldest_session_is_closed_beyond_two_simultaneous_sessions(): void
    {
        $admin = Admin::factory()->create();

        $first = $this->signIn($admin, 'Chrome');
        $this->travel(1)->minutes();
        $second = $this->signIn($admin, 'Firefox');
        $this->travel(1)->minutes();
        $third = $this->signIn($admin, 'Safari');

        $this->assertSame('session_limit', AuthSession::query()->findOrFail($first['session_id'])->revoked_reason);

        $this->withAccessToken($third['access_token'])->getJson('/api/v1/admin/auth/sessions')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.device_name', 'Safari')
            ->assertJsonPath('data.0.current', true);

        $this->withAccessToken($third['access_token'])->deleteJson("/api/v1/admin/auth/sessions/{$second['session_id']}")->assertOk();
        $this->withAccessToken($second['access_token'])->getJson('/api/v1/admin/me')->assertUnauthorized();
    }

    public function test_the_provisional_password_must_be_replaced_by_a_strong_one(): void
    {
        $admin = Admin::factory()->create(['must_change_password' => true]);
        $other = $this->signIn($admin, 'Firefox');
        $tokens = $this->signIn($admin, 'Chrome');

        $this->withAccessToken($tokens['access_token'])->getJson('/api/v1/admin/me')->assertOk()->assertJsonPath('data.must_change_password', true);
        $this->withAccessToken($tokens['access_token'])->getJson('/api/v1/admin/auth/sessions')
            ->assertForbidden()
            ->assertJsonPath('code', 'password_change_required');

        $this->withAccessToken($tokens['access_token'])->putJson('/api/v1/admin/auth/password', ['current_password' => self::PASSWORD, 'password' => 'motdepasse12', 'password_confirmation' => 'motdepasse12'])
            ->assertJsonValidationErrors(['password']);

        $this->withAccessToken($tokens['access_token'])->putJson('/api/v1/admin/auth/password', ['current_password' => 'mauvais', 'password' => 'Nouveau#Secret26', 'password_confirmation' => 'Nouveau#Secret26'])
            ->assertJsonValidationErrors(['current_password' => 'Mot de passe actuel incorrect.']);

        $this->withAccessToken($tokens['access_token'])->putJson('/api/v1/admin/auth/password', ['current_password' => self::PASSWORD, 'password' => 'Nouveau#Secret26', 'password_confirmation' => 'Nouveau#Secret26'])
            ->assertOk()
            ->assertJsonPath('data.must_change_password', false);

        $this->withAccessToken($tokens['access_token'])->getJson('/api/v1/admin/auth/sessions')->assertOk();
        $this->withAccessToken($other['access_token'])->getJson('/api/v1/admin/me')->assertUnauthorized();
        $this->assertTrue(AuditLog::query()->where('actor_id', $admin->id)->where('action', 'password_changed')->exists());
    }

    public function test_a_forgotten_password_is_reset_from_the_emailed_link(): void
    {
        $admin = Admin::factory()->create(['email' => 'm.diabate@ochantier.ci']);
        $tokens = $this->signIn($admin);

        $this->postJson('/api/v1/admin/auth/password/forgot', ['email' => 'inconnu@ochantier.ci'])->assertAccepted();
        $this->assertCount(1, $this->sentMails()); // seul le code de connexion

        $this->postJson('/api/v1/admin/auth/password/forgot', ['email' => 'm.diabate@ochantier.ci'])
            ->assertAccepted()
            ->assertJsonPath('data.expires_in', 1800);

        preg_match('/token=([A-Za-z0-9]{64})/', $this->sentMails()[1]->getTextBody(), $match);
        $token = $match[1];

        $this->postJson('/api/v1/admin/auth/password/reset', ['email' => 'm.diabate@ochantier.ci', 'token' => $token, 'password' => 'Nouveau#Secret26', 'password_confirmation' => 'Nouveau#Secret26'])
            ->assertOk();

        $this->withAccessToken($tokens['access_token'])->getJson('/api/v1/admin/me')->assertUnauthorized();

        $this->postJson('/api/v1/admin/auth/password/reset', ['email' => 'm.diabate@ochantier.ci', 'token' => $token, 'password' => 'Autre#Secret2026', 'password_confirmation' => 'Autre#Secret2026'])
            ->assertJsonValidationErrors(['token']);

        $this->travel(46)->seconds();
        $this->postJson('/api/v1/admin/auth/login', ['email' => 'm.diabate@ochantier.ci', 'password' => 'Nouveau#Secret26'])->assertAccepted();
    }

    public function test_a_reset_link_expires_after_thirty_minutes(): void
    {
        Admin::factory()->create(['email' => 'm.diabate@ochantier.ci']);

        $this->postJson('/api/v1/admin/auth/password/forgot', ['email' => 'm.diabate@ochantier.ci'])->assertAccepted();
        preg_match('/token=([A-Za-z0-9]{64})/', $this->sentMails()[0]->getTextBody(), $match);

        $this->travel(31)->minutes();

        $this->postJson('/api/v1/admin/auth/password/reset', ['email' => 'm.diabate@ochantier.ci', 'token' => $match[1], 'password' => 'Nouveau#Secret26', 'password_confirmation' => 'Nouveau#Secret26'])
            ->assertJsonValidationErrors(['token' => 'Ce lien a expiré ou a déjà été utilisé. Faites une nouvelle demande.']);
    }

    public function test_routes_require_a_permission_of_the_admin_roles(): void
    {
        $this->seed(AdminAccessSeeder::class);

        Route::middleware(['api', 'auth:admin', 'admin.can:workers.validate'])->get('/api/v1/admin/test/workers', fn () => ['ok' => true]);
        Route::middleware(['api', 'auth:admin', 'admin.can:payouts.approve,finance.approve'])->get('/api/v1/admin/test/payouts', fn () => ['ok' => true]);

        $operations = $this->signIn(Admin::factory()->role('operations')->create());
        $finance = $this->signIn(Admin::factory()->role('finance')->create());

        $this->withAccessToken($operations['access_token'])->getJson('/api/v1/admin/me')
            ->assertJsonFragment(['workers.validate'])
            ->assertJsonMissing(['payouts.approve']);

        $this->withAccessToken($operations['access_token'])->getJson('/api/v1/admin/test/workers')->assertOk();
        $this->withAccessToken($operations['access_token'])->getJson('/api/v1/admin/test/payouts')
            ->assertForbidden()
            ->assertJsonPath('code', 'permission_denied');

        $this->withAccessToken($finance['access_token'])->getJson('/api/v1/admin/test/payouts')->assertOk();
        $this->withAccessToken($finance['access_token'])->getJson('/api/v1/admin/test/workers')->assertForbidden();
    }

    public function test_connections_outside_the_ip_allowlist_are_blocked_or_reported(): void
    {
        $admin = Admin::factory()->create(['email' => 'a.kone@ochantier.ci']);
        AdminIpAllowlist::query()->create(['cidr' => '41.207.0.0/16', 'label' => 'Siège']);

        config(['auth.admin.ip_restriction' => 'block']);

        $this->postJson('/api/v1/admin/auth/login', ['email' => 'a.kone@ochantier.ci', 'password' => self::PASSWORD])
            ->assertForbidden()
            ->assertJsonPath('code', 'ip_not_allowed');

        $tokens = $this->withServerVariables(['REMOTE_ADDR' => '41.207.12.34'])->signIn($admin);
        $this->withServerVariables(['REMOTE_ADDR' => '41.207.12.34'])->withAccessToken($tokens['access_token'])->getJson('/api/v1/admin/me')->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '102.67.1.1'])->withAccessToken($tokens['access_token'])->getJson('/api/v1/admin/me')->assertUnauthorized();

        config(['auth.admin.ip_restriction' => 'alert']);

        $this->withServerVariables(['REMOTE_ADDR' => '102.67.1.1'])->signIn($admin);
        $this->assertSame('102.67.1.1', AuditLog::query()->where('action', 'login_unknown_ip')->sole()->after_values['ip_address']);
    }

    /**
     * Connexion complète (mot de passe puis code), données de la réponse.
     *
     * @return array<string, mixed>
     */
    private function signIn(Admin $admin, string $device = 'Chrome · macOS'): array
    {
        $this->travel(46)->seconds(); // délai de renvoi du code entre deux connexions

        $this->postJson('/api/v1/admin/auth/login', ['email' => $admin->email, 'password' => self::PASSWORD])->assertAccepted();

        return $this->postJson('/api/v1/admin/auth/login/verify', ['email' => $admin->email, 'code' => '123456', 'device_name' => $device])
            ->assertOk()
            ->json('data');
    }

    /**
     * E-mails envoyés (transport array), dans l’ordre.
     *
     * @return list<Email>
     */
    private function sentMails(): array
    {
        return app('mailer')->getSymfonyTransport()->messages()->map(fn ($sent) => $sent->getOriginalMessage())->values()->all();
    }
}
