<?php

namespace Tests\Feature\Admin;

use App\Enums\AdminStatus;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\PasswordReset;
use App\Support\Admin\AdminAccounts;
use App\Support\Auth\AdminSessions;
use App\Support\Auth\AuthSessions;
use App\Support\Auth\DeviceContext;
use Database\Seeders\AdminAccessSeeder;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Auth\AuthTestCase;

/**
 * API du Back Office, module Paramètres : administrateurs, invitations et journal d’audit.
 */
class AdminAccountsTest extends AuthTestCase
{
    private Admin $super;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AdminAccessSeeder::class);
        $this->super = Admin::factory()->role('super_admin')->create();
    }

    private function as(Admin $admin): static
    {
        return $this->withAccessToken(app(AdminSessions::class)->open($admin, new DeviceContext('Chrome', null, 'web', null, '127.0.0.1', 'phpunit'))->accessToken);
    }

    private function invite(array $body = []): array
    {
        return $this->as($this->super)->postJson('/api/v1/admin/admins', ['first_name' => 'Awa', 'last_name' => 'Koné', 'email' => 'awa.kone@ochantier.ci', 'roles' => ['support'], ...$body])->assertCreated()->json('data');
    }

    private function url(string $id, string $action = ''): string
    {
        return "/api/v1/admin/admins/{$id}".($action ? "/{$action}" : '');
    }

    /**
     * Remplace le lien en cours par un lien au jeton connu : le vrai ne sort que par e-mail.
     */
    private function linkFor(string $id): string
    {
        $link = PasswordReset::query()->where('authenticatable_id', $id)->whereNull('used_at')->latest()->firstOrFail();
        $token = str_repeat('a', 64);
        $link->update(['token_hash' => AuthSessions::hash($token)]);

        return $token;
    }

    public function test_an_invited_admin_activates_the_account_with_the_link(): void
    {
        Event::fake([MessageSending::class]);
        $data = $this->invite();

        $this->assertSame('invite', $data['status']['value']);
        $this->assertTrue($data['invite']['pending']);
        Event::assertDispatched(MessageSending::class, fn (MessageSending $event) => $event->message->getTo()[0]->getAddress() === 'awa.kone@ochantier.ci' && str_contains($event->message->getSubject(), 'Back Office'));

        $admin = Admin::query()->findOrFail($data['id']);
        $this->assertFalse($admin->canSignIn());
        $token = $this->linkFor($admin->id);

        $this->postJson('/api/v1/admin/auth/password/reset', ['email' => $admin->email, 'token' => str_repeat('b', 64), 'password' => 'MotDePasse#2026!', 'password_confirmation' => 'MotDePasse#2026!'])->assertUnprocessable();
        $this->postJson('/api/v1/admin/auth/password/reset', ['email' => $admin->email, 'token' => $token, 'password' => 'MotDePasse#2026!', 'password_confirmation' => 'MotDePasse#2026!'])->assertOk();

        $admin->refresh();
        $this->assertSame(AdminStatus::Actif, $admin->status);
        $this->assertTrue($admin->canSignIn());
        $this->postJson('/api/v1/admin/auth/password/reset', ['email' => $admin->email, 'token' => $token, 'password' => 'MotDePasse#2026!', 'password_confirmation' => 'MotDePasse#2026!'])->assertUnprocessable();
    }

    public function test_an_invitation_can_be_resent_and_revoked_and_expires(): void
    {
        $id = $this->invite()['id'];

        $this->postJson($this->url($id, 'invitation'))->assertOk();
        $this->assertSame(1, PasswordReset::query()->where('authenticatable_id', $id)->where('expires_at', '>', now())->count());

        $this->deleteJson($this->url($id, 'invitation'))->assertOk()->assertJsonPath('data.invite.pending', false);
        $this->deleteJson($this->url($id, 'invitation'))->assertUnprocessable()->assertJsonValidationErrors('admin');

        $this->postJson($this->url($id, 'invitation'))->assertOk();
        $this->travel(73)->hours();
        $this->as($this->super)->getJson($this->url($id))->assertOk()->assertJsonPath('data.invite.pending', false)->assertJsonPath('data.can.resend', true);
    }

    public function test_an_invitation_is_refused_for_a_used_email_or_no_role(): void
    {
        $this->as($this->super)->postJson('/api/v1/admin/admins', ['first_name' => 'Awa', 'last_name' => 'Koné', 'email' => $this->super->email, 'roles' => ['support']])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson('/api/v1/admin/admins', ['first_name' => 'Awa', 'last_name' => 'Koné', 'email' => 'a@ochantier.ci', 'roles' => []])->assertUnprocessable()->assertJsonValidationErrors('roles');
    }

    public function test_changing_roles_closes_sessions_and_is_traced(): void
    {
        $target = Admin::factory()->role('support')->create();
        app(AdminSessions::class)->open($target, new DeviceContext('Chrome', null, 'web', null, '127.0.0.1', 'phpunit'));

        $this->as($this->super)->putJson($this->url($target->id, 'roles'), ['roles' => ['finance']])->assertOk()->assertJsonPath('data.roles.0.code', 'finance')->assertJsonPath('data.sessions', 0);

        $this->assertSame(['support'], AuditLog::query()->where('action', 'admins.roles_updated')->sole()->before_values['roles']);
    }

    public function test_nobody_changes_their_own_access_and_one_super_admin_always_remains(): void
    {
        $this->as($this->super)->postJson($this->url($this->super->id, 'suspension'), ['reason' => 'Test de sécurité'])->assertUnprocessable()->assertJsonPath('errors.admin.0', 'Vous ne pouvez pas modifier votre propre accès.');
        $this->putJson($this->url($this->super->id, 'roles'), ['roles' => ['support']])->assertUnprocessable();

        $second = Admin::factory()->role('super_admin')->create();
        $this->as($second)->postJson($this->url($this->super->id, 'suspension'), ['reason' => 'Départ de l’entreprise'])->assertOk();
        $this->postJson($this->url($this->super->id, 'reactivation'))->assertOk();
    }

    public function test_the_last_super_admin_cannot_be_removed(): void
    {
        Admin::query()->whereKeyNot($this->super->id)->update(['status' => 'desactive']);
        $operator = Admin::factory()->role('super_admin')->create();
        $this->as($operator)->postJson($this->url($this->super->id, 'deactivation'), ['reason' => 'Compte en double'])->assertOk();

        $last = app(AdminAccounts::class);
        $this->expectException(ValidationException::class);
        $last->suspend($operator, 'Test de sécurité', $this->super, request());
    }

    public function test_a_suspended_admin_loses_access_at_once(): void
    {
        $target = Admin::factory()->role('support')->create();
        $token = app(AdminSessions::class)->open($target, new DeviceContext('Chrome', null, 'web', null, '127.0.0.1', 'phpunit'))->accessToken;
        $this->withAccessToken($token)->getJson('/api/v1/admin/me')->assertOk();

        $this->as($this->super)->postJson($this->url($target->id, 'suspension'), ['reason' => 'Accès à revoir'])->assertOk()->assertJsonPath('data.suspended_reason', 'Accès à revoir');

        $this->withAccessToken($token)->getJson('/api/v1/admin/me')->assertUnauthorized();
    }

    public function test_the_role_list_shows_permissions_and_other_admins_have_no_access(): void
    {
        $this->as($this->super)->getJson('/api/v1/admin/admins/roles')->assertOk()->assertJsonFragment(['code' => 'finance']);
        $this->getJson('/api/v1/admin/admins?search='.urlencode($this->super->email))->assertOk()->assertJsonPath('meta.total', 1);

        $this->as(Admin::factory()->role('support')->create())->getJson('/api/v1/admin/admins')->assertForbidden();
        $this->as(Admin::factory()->role('direction')->create())->postJson('/api/v1/admin/admins', [])->assertForbidden();
    }

    public function test_the_audit_log_is_filtered_exported_and_private(): void
    {
        $this->invite();

        $this->getJson('/api/v1/admin/audit?module=admins')->assertOk()->assertJsonPath('data.0.action', 'admins.invited')->assertJsonPath('data.0.actor', $this->super->fullName());
        $this->getJson('/api/v1/admin/audit?search=invited')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/admin/audit?from=2020-01-01&to=2020-01-02')->assertOk()->assertJsonCount(0, 'data');
        $this->assertStringContainsString('admins.invited', $this->get('/api/v1/admin/audit/export?module=admins')->assertOk()->streamedContent());

        $this->as(Admin::factory()->role('lecture_seule')->create())->getJson('/api/v1/admin/audit')->assertForbidden();
    }
}
