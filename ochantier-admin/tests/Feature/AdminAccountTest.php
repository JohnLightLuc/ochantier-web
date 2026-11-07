<?php

namespace Tests\Feature;

use App\Support\Api\ApiTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Utilisateurs du Back Office et journal d’audit branchés sur l’API.
 */
class AdminAccountTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'http://localhost:8001/api/v1/admin';

    private const ADMIN = '01a16ab6-0000-7000-8000-0000000000b9';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.ochantier_api.url' => self::API]);
        $this->withSession([]);
        $this->startSession();
        app(ApiTokens::class)->store(['access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'expires_in' => 900, 'session_id' => '9a0e0000-0000-4000-8000-000000000001', 'admin' => ['id' => '1', 'full_name' => 'Super Admin', 'email' => 'admin@ochantier.ci', 'roles' => [], 'permissions' => ['admins.read', 'admins.create', 'admins.update', 'admins.delete', 'audit.read', 'audit.export']]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function admin(): array
    {
        return ['id' => self::ADMIN, 'full_name' => 'Awa Koné', 'email' => 'awa@ochantier.ci', 'department' => null, 'status' => ['value' => 'invite', 'label' => 'Invité'], 'roles' => [['code' => 'support', 'label' => 'Support']], 'last_login_at' => null];
    }

    public function test_the_list_and_the_card_show_accounts_and_actions(): void
    {
        Http::fake([
            self::API.'/admins?*' => Http::response(['data' => [$this->admin()], 'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'total' => 1, 'roles' => [['code' => 'support', 'label' => 'Support']]]]),
            self::API.'/admins/'.self::ADMIN => Http::response(['data' => [...$this->admin(), 'phone' => null, 'suspended_reason' => null, 'invited_by' => 'Super Admin', 'created_at' => '2026-11-07T08:00:00+00:00', 'sessions' => 0, 'invite' => ['pending' => true, 'expires_at' => '2026-11-10T08:00:00+00:00'], 'can' => ['resend' => true, 'revoke' => true, 'roles' => true, 'suspend' => false, 'reactivate' => false, 'deactivate' => true]]]),
        ]);

        $this->get('/parametres/utilisateurs?statut=invite')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Settings/Admins')->where('admins.data.0.status.tone', 'a')->where('roles.0.value', 'support')->where('can.create', true));
        Http::assertSent(fn (ClientRequest $request) => str_contains($request->url(), '/admins?') && $request['status'] === 'invite');

        $this->get('/parametres/utilisateurs/'.self::ADMIN)->assertOk()->assertInertia(fn (Assert $page) => $page->component('Settings/Admin')->where('admin.invite.pending', true)->where('admin.actions.resend', true));
    }

    public function test_an_invitation_keeps_field_errors_and_refusals_become_messages(): void
    {
        Http::fake([
            self::API.'/admins' => Http::sequence()
                ->push(['message' => 'Refusé.', 'errors' => ['email' => ['Un compte existe déjà avec cette adresse.']]], 422)
                ->push(['message' => 'Invitation envoyée.', 'data' => []], 201),
            self::API.'/admins/'.self::ADMIN.'/suspension' => Http::response(['message' => 'Refusé.', 'errors' => ['admin' => ['Il doit rester au moins un super administrateur actif.']]], 422),
        ]);
        $url = '/parametres/utilisateurs';
        $body = ['first_name' => 'Awa', 'last_name' => 'Koné', 'email' => 'awa@ochantier.ci', 'role' => 'support'];

        $this->from($url)->post($url, $body)->assertRedirect($url)->assertSessionHasErrors('email');
        $this->from($url)->post($url, $body)->assertRedirect($url)->assertSessionHasNoErrors();
        Http::assertSent(fn (ClientRequest $request) => str_ends_with($request->url(), '/admins') && $request['roles'] === ['support']);

        $this->from($url)->post("{$url}/".self::ADMIN.'/suspension', ['reason' => 'Départ'])->assertRedirect($url)->assertSessionHasNoErrors();
    }

    public function test_roles_are_sent_as_a_list(): void
    {
        Http::fake([self::API.'/admins/'.self::ADMIN.'/roles' => Http::response(['message' => 'Rôles mis à jour.', 'data' => []])]);
        $url = '/parametres/utilisateurs/'.self::ADMIN;

        $this->from($url)->put("{$url}/roles", ['roles' => ['support', 'finance']])->assertRedirect($url);

        Http::assertSent(fn (ClientRequest $request) => $request->method() === 'PUT' && $request['roles'] === ['support', 'finance']);
    }

    public function test_the_audit_log_passes_filters_and_exports(): void
    {
        Http::fake([
            self::API.'/audit?*' => Http::response(['data' => [['id' => '1', 'date' => '2026-11-07T08:00:00+00:00', 'actor' => 'Super Admin', 'module' => 'admins', 'action' => 'admins.invited', 'subject' => 'admin', 'before' => null, 'after' => null, 'ip' => '127.0.0.1']], 'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'total' => 1, 'modules' => ['admins']]]),
            self::API.'/audit/export*' => Http::response('Date;Auteur'),
        ]);

        $this->get('/parametres/audit?module=admins&du=2026-11-01')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Settings/Audit')->where('logs.data.0.action', 'admins.invited')->where('can.export', true));
        Http::assertSent(fn (ClientRequest $request) => str_contains($request->url(), '/audit?') && $request['module'] === 'admins' && $request['from'] === '2026-11-01');

        $this->get('/parametres/audit/export?module=admins')->assertOk()->assertHeader('content-disposition', 'attachment; filename="journal-audit.csv"');
    }
}
