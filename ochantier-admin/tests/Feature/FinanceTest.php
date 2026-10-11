<?php

namespace Tests\Feature;

use App\Models\FinanceArchive;
use App\Models\FinanceAudit;
use App\Models\FinanceClosing;
use App\Models\FinanceReport;
use App\Models\FinanceResolution;
use App\Models\FinanceView;
use App\Models\Payment;
use App\Models\User;
use App\Support\FinanceInsights;
use App\Support\FinanceLedger;
use Database\Seeders\AttendanceSeeder;
use Database\Seeders\FinanceSeeder;
use Database\Seeders\PaymentSeeder;
use Database\Seeders\PromoterSeeder;
use Database\Seeders\SiteSeeder;
use Database\Seeders\WorkerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FinanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['name' => 'N. Yao']));
    }

    private function seedAll(): void
    {
        $this->seed([PromoterSeeder::class, WorkerSeeder::class, SiteSeeder::class, AttendanceSeeder::class, PaymentSeeder::class, FinanceSeeder::class]);
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(string $reference): array
    {
        return (new FinanceLedger)->find($reference);
    }

    public function test_archived_commission_amounts_and_aggregates(): void
    {
        $paid = FinanceArchive::factory()->create();
        $failed = FinanceArchive::factory()->failed()->create(['service_date' => '2026-03-10']);
        FinanceArchive::factory()->create(['is_test' => true]);

        $x = $this->entry($paid->reference);
        $this->assertSame(50000, $x['gross']);
        $this->assertSame(9000, $x['com']);
        $this->assertSame(54540, $x['promoterTotal']);
        $this->assertSame(45000, $x['net']);
        $this->assertSame(9000, $x['comNet']);
        $this->assertSame('Reversé', $x['revSt']);
        $this->assertSame('Rapproché', $x['recSt']);
        $this->assertSame(450, $x['feeOut']);

        $a = FinanceLedger::aggregate((new FinanceLedger)->entries());
        $this->assertSame(2, $a['n']);
        $this->assertSame(1, $a['nOk']);
        $this->assertSame(1, $a['exclTest']);
        $this->assertSame(9000, $a['comNet']);
        $this->assertSame(9000, $a['comPend']);
        $this->assertSame(54540, $a['creance']);
        $this->assertSame(1080, $a['comm']);
        $this->assertSame(9000 - 450 - 1080 - 150, $a['marge']);
        $this->assertSame('À encaisser', $this->entry($failed->reference)['comStatus']);
    }

    public function test_duplicate_payments_are_excluded_until_confirmed(): void
    {
        $first = Payment::factory()->create(['external_reference' => 'ORA-1234567']);
        $second = Payment::factory()->create(['external_reference' => 'ORA-1234567']);

        $dup = $this->entry('COM-'.substr($second->reference, 3));
        $this->assertTrue($dup['dup']);
        $this->assertFalse($dup['elig']);
        $this->assertStringContainsString($first->reference, $dup['dupWhy']);

        $this->post("/finance-reporting/rapprochements/{$dup['ref']}/doublon", ['reason' => 'Même transaction Orange Money'])->assertRedirect();
        $this->assertDatabaseHas('finance_resolutions', ['reference' => $dup['ref'], 'kind' => FinanceResolution::DUPLICATE]);
        $this->assertTrue($this->entry($dup['ref'])['dupOk']);
        $this->post("/finance-reporting/rapprochements/{$dup['ref']}/doublon", ['reason' => 'Même transaction Orange Money'])->assertStatus(422);
    }

    public function test_pages_render_with_seeded_data(): void
    {
        $this->seedAll();

        $this->get('/finance-reporting')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Finance/Page')
                ->where('page', 'home')
                ->where('period.key', 'mois')
                ->has('kpis', 4)
                ->has('chips', 6)
                ->has('charts', 6)
                ->has('tiles', 6)
                ->has('panels', 3)
                ->has('tables', 2)
                ->has('flow.steps', 6)
                ->has('selects', 7)
                ->has('views', 2)
                ->has('sheets.com_enc')
            );
        $this->get('/finance-reporting?tous=1')->assertInertia(fn (Assert $page) => $page->has('selects', 20));

        foreach (['commissions', 'chantiers', 'promoteurs', 'ouvriers', 'metiers', 'geographie', 'creances', 'reversements', 'tresorerie', 'remboursements', 'frais-operateurs', 'rapprochements', 'anomalies', 'objectifs', 'previsions', 'clotures', 'rapports', 'definitions', 'audit'] as $path) {
            $this->get("/finance-reporting/{$path}?periode=annee")->assertOk()->assertInertia(fn (Assert $page) => $page->component('Finance/Page')->where('missing', null));
        }
        $this->get('/finance-reporting/geographie?axe=zone')->assertOk();
        $this->get('/finance-reporting/inconnu')->assertNotFound();
    }

    public function test_journal_totals_match_the_collected_commissions_kpi(): void
    {
        $this->seedAll();
        $ins = new FinanceInsights(new FinanceLedger, ['periode' => 'annee']);

        $this->get('/finance-reporting/commissions?periode=annee')
            ->assertInertia(fn (Assert $page) => $page
                ->where('tiles.7.value', 'Conforme au KPI')
                ->where('tables.0.pagination.total', $ins->current->filter(fn (array $x): bool => $x['elig'] || $x['dup'])->count())
                ->has('tables.0.rows.0.cells', 22)
            );

        $groups = FinanceInsights::group($ins->current, fn ($x) => $x['promoter']);
        $this->assertSame($ins->a['comNet'], $groups->sum(fn (array $g): int => $g['a']['comNet']));
    }

    public function test_filters_and_basis_restrict_entries(): void
    {
        $this->seedAll();
        $all = new FinanceInsights(new FinanceLedger, ['periode' => '12m']);
        $promoter = $all->current->first()['promoter'];
        $filtered = new FinanceInsights(new FinanceLedger, ['periode' => '12m', 'promoteur' => $promoter, 'base' => 'enc']);

        $this->assertTrue($filtered->current->every(fn (array $x): bool => $x['promoter'] === $promoter && $x['d']['enc'] !== null));
        $this->assertLessThan($all->current->count(), $filtered->current->count());

        $this->get('/finance-reporting?periode=12m&promoteur='.urlencode($promoter))
            ->assertInertia(fn (Assert $page) => $page->where('alerts.0.title', 'Filtres appliqués (1)'));
    }

    public function test_commission_detail_and_analysis_pages(): void
    {
        $this->seedAll();
        $x = (new FinanceLedger)->entries()->firstWhere('archived', false);

        $this->get("/finance-reporting/commissions/{$x['ref']}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('page', 'commission')->has('tiles', 7)->has('panels', 3)->has('flow.steps', 6)->has('timeline'));
        $this->assertDatabaseHas('finance_audits', ['action' => 'a consulté le détail d’une commission', 'new_value' => $x['ref']]);
        $this->get('/finance-reporting/commissions/COM-INCONNUE')->assertNotFound();

        $this->get("/finance-reporting/chantiers/{$x['siteSlug']}?periode=annee")->assertOk()->assertInertia(fn (Assert $page) => $page->where('header.title', $x['site'])->has('tiles', 9));
        $this->get('/finance-reporting/promoteurs/'.rawurlencode($x['promoter']).'?periode=annee')->assertOk();
        $this->get("/finance-reporting/ouvriers/{$x['workerSlug']}?periode=annee")->assertOk();
        $this->get('/finance-reporting/chantiers/inconnu')->assertNotFound();
    }

    public function test_closing_cycle_creates_versioned_snapshots(): void
    {
        $this->seedAll();
        $months = FinanceLedger::months();
        $previous = $months[count($months) - 2];
        FinanceClosing::query()->where('month', $previous)->update(['status' => 'Ouverte']);

        $this->post("/finance-reporting/clotures/{$previous}/cloture")->assertSessionHasErrors('status');
        $this->post("/finance-reporting/clotures/{$previous}/preparation")->assertRedirect();
        $this->post("/finance-reporting/clotures/{$previous}/controle")->assertRedirect();
        $this->assertSame('À contrôler', FinanceClosing::query()->where('month', $previous)->value('status'));

        $closed = $months[0];
        $this->post("/finance-reporting/clotures/{$closed}/reouverture", ['reason' => 'trop court'])->assertSessionHasErrors('reason');
        $this->post("/finance-reporting/clotures/{$closed}/reouverture", ['reason' => 'Remboursement tardif à intégrer'])->assertRedirect();
        $this->post("/finance-reporting/clotures/{$closed}/cloture")->assertRedirect()->assertSessionHasNoErrors();

        $c = FinanceClosing::query()->where('month', $closed)->first();
        $this->assertSame('Clôturée', $c->status);
        $this->assertSame(2, $c->version);
        $this->assertCount(1, $c->history);
        $this->assertSame(2, $c->snapshot['v']);
        $this->assertSame(3, FinanceAudit::query()->where('category', 'statut')->where('occurred_at', '>=', now()->subMinute())->whereIn('action', ['a rouvert la période', 'a clôturé la période', 'a soumis la période au contrôle'])->count());

        $this->get("/finance-reporting/clotures/{$closed}/instantane")->assertOk()->assertHeader('Content-Type', 'application/vnd.ms-excel; charset=UTF-8');
    }

    public function test_reports_are_generated_and_recorded(): void
    {
        $this->seedAll();

        $csv = $this->get('/finance-reporting/rapports/generer?periode=annee&rapport=commissions&format=CSV');
        $csv->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('Réf. commission;Réf. paiement', $csv->getContent());
        $this->assertStringContainsString('Base temporelle;Date d’opération', $csv->getContent());

        $this->get('/finance-reporting/rapports/generer?rapport=synthese&format=PDF&regroupement=Par+chantier')->assertOk()->assertSee('Rapport de rentabilité par chantier')->assertSee('window.print', false);
        $this->get('/finance-reporting/rapports/generer?rapport=inconnu&format=CSV')->assertSessionHasErrors('rapport');

        $this->assertSame(2, FinanceReport::query()->where('author', 'N. Yao')->count());
        $this->assertDatabaseHas('finance_audits', ['action' => 'a généré « Rapport des commissions »', 'new_value' => 'CSV']);
    }

    public function test_views_sync_and_gap_actions_are_audited(): void
    {
        $this->seedAll();

        $this->post('/finance-reporting/vues', ['name' => 'Cocody', 'filtres' => ['commune' => 'Cocody', 'inconnu' => 'x'], 'base' => 'enc', 'periode' => 'trim'])->assertRedirect();
        $view = FinanceView::query()->where('name', 'Cocody')->first();
        $this->assertSame(['commune' => 'Cocody'], $view->filters);
        $this->post('/finance-reporting/vues', ['name' => 'ab'])->assertSessionHasErrors('name');
        $this->post('/finance-reporting/vues/partage', ['to' => 'Direction générale'])->assertRedirect();
        $this->post('/finance-reporting/synchronisation')->assertRedirect();
        $this->assertDatabaseHas('finance_audits', ['action' => 'a relancé la synchronisation']);

        $payment = Payment::factory()->create(['received_amount' => 54540 - 1500, 'reconciliation' => 'Écart détecté']);
        $gap = $this->entry('COM-'.substr($payment->reference, 3));
        $this->assertSame('Avec écart', $gap['recSt']);
        $this->post("/finance-reporting/rapprochements/{$gap['ref']}/ecart", ['reason' => 'Frais retenus par l’opérateur'])->assertRedirect();
        $this->assertSame('Écart traité', $this->entry($gap['ref'])['recSt']);

        $this->post("/finance-reporting/commissions/{$gap['ref']}/ecart", ['reason' => 'Montant reçu inférieur au relevé'])->assertRedirect();
        $this->assertDatabaseHas('payment_notifications', ['type' => 'Écart signalé', 'recipient' => 'Responsable paiements']);
    }

    public function test_restricted_role_masks_amounts_and_blocks_actions(): void
    {
        $this->seedAll();
        config(['admin.finance.role' => 'Lecture seule']);

        $this->get('/finance-reporting')->assertInertia(fn (Assert $page) => $page->where('tiles.4.value', FinanceInsights::HIDDEN));
        $this->get('/finance-reporting/creances')->assertInertia(fn (Assert $page) => $page->where('missing.title', 'Accès non autorisé'));
        $this->get('/finance-reporting/rapports/generer?rapport=commissions&format=CSV')->assertForbidden();
        $this->post('/finance-reporting/clotures/'.FinanceLedger::months()[0].'/reouverture', ['reason' => 'Remboursement tardif à intégrer'])->assertForbidden();
    }
}
