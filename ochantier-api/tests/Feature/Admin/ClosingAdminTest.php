<?php

namespace Tests\Feature\Admin;

use App\Enums\FinancialPeriodClosingStatus;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\FinancialPeriodClosing;
use App\Models\PaymentTransaction;
use App\Support\Auth\AdminSessions;
use App\Support\Auth\DeviceContext;
use Carbon\CarbonImmutable;
use Tests\Feature\Promoter\ConfirmedSite;
use Tests\Feature\Promoter\PromoterTestCase;

/**
 * API du Back Office, module Finance : clôtures mensuelles.
 */
class ClosingAdminTest extends PromoterTestCase
{
    use ConfirmedSite;

    private Admin $preparer;

    private Admin $closer;

    private Admin $direction;

    private string $month;

    private int $collected;

    protected function setUp(): void
    {
        parent::setUp();

        $this->confirmSite();
        $this->preparer = Admin::factory()->role('finance')->create();
        $this->closer = Admin::factory()->role('finance')->create();
        $this->direction = Admin::factory()->role('direction')->create();
        $this->month = CarbonImmutable::today()->format('Y-m');
        $this->collected = (int) PaymentTransaction::query()->where('status', 'reussie')->sum('amount');
        $this->travelTo(CarbonImmutable::today()->addMonthNoOverflow()->startOfMonth()->addDays(2));
    }

    private function as(Admin $admin): static
    {
        return $this->withAccessToken(app(AdminSessions::class)->open($admin, new DeviceContext('Chrome', null, 'web', null, '127.0.0.1', 'phpunit'))->accessToken);
    }

    private function url(string $action = ''): string
    {
        return "/api/v1/admin/finance/closings/{$this->month}".($action ? "/{$action}" : '');
    }

    public function test_one_person_prepares_and_another_closes_what_was_prepared(): void
    {
        $this->as($this->preparer)->getJson('/api/v1/admin/finance/closings')->assertOk()->assertJsonPath('data.0.month', $this->month)->assertJsonPath('data.0.status.value', 'ouverte');
        $this->postJson($this->url('close'))->assertUnprocessable()->assertJsonValidationErrors('closing');

        $this->postJson($this->url('prepare'))
            ->assertOk()
            ->assertJsonPath('data.status.value', 'en_cloture')
            ->assertJsonPath('data.prepared_by', $this->preparer->fullName())
            ->assertJsonPath('data.figures.inflows.total', $this->collected)
            ->assertJsonPath('data.can.close', true);

        $this->postJson($this->url('close'))->assertUnprocessable()->assertJsonPath('errors.closing.0', 'Une autre personne doit clôturer ce mois.');

        $this->as($this->closer)->postJson($this->url('close'))
            ->assertOk()
            ->assertJsonPath('data.status.value', 'cloturee')
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.closed_by', $this->closer->fullName())
            ->assertJsonPath('data.can.reopen', true);
        $this->postJson($this->url('prepare'))->assertUnprocessable();
    }

    public function test_a_change_after_preparation_refuses_the_closing(): void
    {
        $this->as($this->preparer)->postJson($this->url('prepare'))->assertOk();
        PaymentTransaction::query()->where('status', 'reussie')->first()->increment('amount', 1000);

        $this->as($this->closer)->postJson($this->url('close'))->assertUnprocessable()->assertJsonPath('errors.closing.0', 'Les chiffres ont changé depuis la préparation : préparez de nouveau ce mois.');

        $this->as($this->preparer)->postJson($this->url('prepare'))->assertOk()->assertJsonPath('data.figures.inflows.total', $this->collected + 1000);
        $this->as($this->closer)->postJson($this->url('close'))->assertOk();
    }

    public function test_figures_stay_frozen_and_late_changes_appear_as_a_gap(): void
    {
        $this->as($this->preparer)->postJson($this->url('prepare'))->assertOk();
        $this->as($this->closer)->postJson($this->url('close'))->assertOk();
        PaymentTransaction::query()->where('status', 'reussie')->first()->increment('amount', 1000);

        $this->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('data.figures.inflows.total', $this->collected)
            ->assertJsonPath('data.drift.0.label', 'Total reçu')
            ->assertJsonPath('data.drift.0.frozen', $this->collected)
            ->assertJsonPath('data.drift.0.live', $this->collected + 1000);

        $csv = $this->get($this->url('export'))->assertOk()->streamedContent();
        $this->assertStringContainsString('"Total reçu";'.$this->collected, $csv);
    }

    public function test_only_direction_reopens_with_a_reason_and_the_old_figures_are_kept(): void
    {
        $this->as($this->preparer)->postJson($this->url('prepare'))->assertOk();
        $this->as($this->closer)->postJson($this->url('close'))->assertOk();

        $this->postJson($this->url('reopen'), ['reason' => 'Erreur de saisie'])->assertForbidden();
        $this->as($this->direction)->postJson($this->url('reopen'), ['reason' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson($this->url('reopen'), ['reason' => 'Relevé Orange corrigé'])->assertOk()->assertJsonPath('data.status.value', 'reouverte')->assertJsonPath('data.reopen_reason', 'Relevé Orange corrigé');

        $this->assertNotNull(AuditLog::query()->where('action', 'finance.closing_reopened')->sole()->before_values['figures']);

        $this->as($this->preparer)->postJson($this->url('prepare'))->assertOk();
        $this->as($this->closer)->postJson($this->url('close'))->assertOk()->assertJsonPath('data.version', 2);
    }

    public function test_the_current_month_cannot_be_closed(): void
    {
        $month = CarbonImmutable::today()->format('Y-m');

        $this->as($this->preparer)->postJson("/api/v1/admin/finance/closings/{$month}/prepare")->assertUnprocessable()->assertJsonPath('errors.closing.0', 'Ce mois ne peut pas être préparé.');
        $this->assertSame(0, FinancialPeriodClosing::query()->count());
    }

    public function test_reconciliation_warnings_do_not_block(): void
    {
        $response = $this->as($this->preparer)->postJson($this->url('prepare'))->assertOk();

        $this->assertStringContainsString('non rapprochée', $response->json('data.warnings.0'));
        $this->as($this->closer)->postJson($this->url('close'))->assertOk();
        $this->assertSame(FinancialPeriodClosingStatus::Cloturee, FinancialPeriodClosing::query()->sole()->status);
    }

    public function test_rights_are_enforced(): void
    {
        $this->as(Admin::factory()->role('support')->create())->getJson('/api/v1/admin/finance/closings')->assertForbidden();
        $this->as($this->direction)->getJson($this->url())->assertOk()->assertJsonPath('data.status.value', 'ouverte');
        $this->postJson($this->url('prepare'))->assertForbidden();
    }
}
