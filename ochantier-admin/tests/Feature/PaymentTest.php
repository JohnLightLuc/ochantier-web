<?php

namespace Tests\Feature;

use App\Enums\WorkerStatus;
use App\Models\Payment;
use App\Models\User;
use App\Models\Worker;
use App\Support\PaymentLedger;
use Database\Seeders\AttendanceSeeder;
use Database\Seeders\PaymentSeeder;
use Database\Seeders\PromoterSeeder;
use Database\Seeders\SiteSeeder;
use Database\Seeders\WorkerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['name' => 'N. Yao']));
    }

    private function calc(Payment $p): array
    {
        return PaymentLedger::calculate($p->fresh()->load(PaymentLedger::RELATIONS));
    }

    private function seedAll(): void
    {
        $this->seed([PromoterSeeder::class, WorkerSeeder::class, SiteSeeder::class, AttendanceSeeder::class, PaymentSeeder::class]);
    }

    public function test_amounts_are_split_between_promoter_and_worker(): void
    {
        $c = $this->calc(Payment::factory()->create());

        $this->assertSame(50000, $c['gross']);
        $this->assertSame(4000, $c['comP']);
        $this->assertSame(5000, $c['comO']);
        $this->assertSame(540, $c['fees']);
        $this->assertSame(54540, $c['promoterTotal']);
        $this->assertSame(45000, $c['net']);
        $this->assertSame(0, $c['check']);
        $this->assertSame('Encaissée', $c['comStatus']);

        $deposit = Payment::factory()->state(['worker_id' => null, 'type' => 'Acompte', 'fixed_amount' => 1000000, 'days' => 0, 'assignment_reference' => null, 'method' => 'Virement bancaire'])->create();
        $c = $this->calc($deposit);
        $this->assertSame(180000, $c['comP']);
        $this->assertSame(0, $c['net']);
        $this->assertSame(500, $c['fees']);
        $this->assertSame(0, $c['check']);
    }

    public function test_pages_render_with_seeded_operations(): void
    {
        $this->seedAll();

        $this->get('/paiements')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Payments/Index')
                ->where('period.key', 'mois')
                ->has('kpis', 4)
                ->has('groups', 3)
                ->has('segments', 2)
                ->has('todo', 4)
                ->has('payments.data', 8)
                ->has('filterDefs', 6)
            );
        $this->get('/paiements?periode=annee&plus=1')->assertInertia(fn (Assert $page) => $page->has('filterDefs', 19));
        $this->get('/paiements?periode=annee&raccourci=revE')->assertInertia(fn (Assert $page) => $page->where('payments.data.0.payoutStatus.label', 'Échoué'));

        foreach (['operations' => 'Operations', 'a-reverser' => 'Operations', 'reversements' => 'Operations', 'lots' => 'Batches', 'commissions' => 'Commissions', 'commissions/operations' => 'CommissionOperations', 'remboursements' => 'Refunds', 'rapprochements' => 'Reconciliations', 'anomalies' => 'Anomalies', 'rapports' => 'Reports'] as $path => $component) {
            $this->get("/paiements/{$path}?periode=annee")->assertOk()->assertInertia(fn (Assert $page) => $page->component("Payments/{$component}"));
        }

        $this->get('/paiements/commissions?periode=annee&ventilation=chantier')->assertInertia(fn (Assert $page) => $page
            ->where('dimension', 'chantier')
            ->where('breakdown', fn ($b): bool => collect($b['rows'])->sum('net') === $b['total']['net'])
        );
        $this->get('/paiements/remboursements?periode=annee')->assertInertia(fn (Assert $page) => $page->where('refunds', fn ($rows): bool => collect($rows)->contains(fn ($r) => $r['status']['label'] === 'Demandé')));
        $this->get('/paiements/anomalies?periode=annee')->assertInertia(fn (Assert $page) => $page->where('anomalies', fn ($rows): bool => collect($rows)->contains('type', 'Référence externe dupliquée')));
    }

    public function test_operation_tabs_and_exports(): void
    {
        $this->seedAll();
        $payment = Payment::query()->whereNotNull('worker_id')->where('pay_status', 'Réussi')->firstOrFail();
        $deposit = Payment::query()->whereNull('worker_id')->firstOrFail();

        $this->get("/paiements/operations/{$payment->reference}")->assertOk()->assertInertia(fn (Assert $page) => $page->component('Payments/Show')->has('tabs', 8)->has('checklist', 13)->has('payoutChecks', 8));
        foreach (['calcul', 'commission', 'paiement', 'rapprochement', 'reversement', 'remboursements', 'pointages', 'documents', 'historique'] as $tab) {
            $this->get("/paiements/operations/{$payment->reference}/{$tab}")->assertOk();
        }
        $this->get("/paiements/operations/{$deposit->reference}/reversement")->assertNotFound();
        $this->get("/paiements/operations/{$deposit->reference}")->assertInertia(fn (Assert $page) => $page->has('tabs', 7));

        $csv = $this->get('/paiements/export/operations?periode=annee')->assertOk()->streamedContent();
        $this->assertStringContainsString('Commission O’CHANTIER', $csv);
        $this->get('/paiements/rapports/commissions/pdf?periode=annee')->assertOk()->assertSee('Rapport de commissions O’CHANTIER', false);
        $this->get("/paiements/operations/{$payment->reference}/documents/releve-commission")->assertOk()->assertSee($payment->reference);
        $this->assertDatabaseHas('payment_activities', ['payment_id' => $payment->id, 'action' => 'a généré un document']);
    }

    public function test_payout_is_executed_after_checks(): void
    {
        $paid = Payment::factory()->create();
        $unpaid = Payment::factory()->unpaid()->create();
        $suspended = Payment::factory()->create(['worker_id' => Worker::factory()->state(['status' => WorkerStatus::Suspended])]);

        $this->post("/paiements/operations/{$unpaid->reference}/reversement")->assertStatus(422);
        $this->post("/paiements/operations/{$suspended->reference}/reversement")->assertStatus(422);
        $this->post("/paiements/operations/{$paid->reference}/reversement")->assertSessionHasNoErrors();

        $paid = $paid->fresh();
        $this->assertSame('Réussi', $paid->payout_status);
        $this->assertStringStartsWith('REV-', $paid->payout_reference);
        $this->assertSame('Reçu de reversement', collect($paid->documents)->last()['type']);
        $this->post("/paiements/operations/{$paid->reference}/reversement")->assertStatus(422);
    }

    public function test_batch_executes_eligible_operations_and_blocks_others(): void
    {
        $a = Payment::factory()->create();
        $b = Payment::factory()->create();
        $blocked = Payment::factory()->unpaid()->create();

        $this->post('/paiements/lots', ['refs' => [$a->reference, $b->reference, $blocked->reference]])->assertSessionHasNoErrors();

        $this->assertSame('Réussi', $a->fresh()->payout_status);
        $this->assertSame('LOT-2026-0041', $a->fresh()->batch_reference);
        $this->assertDatabaseHas('payout_batches', ['reference' => 'LOT-2026-0041', 'status' => 'Terminé', 'net' => 90000]);
        $this->post('/paiements/lots', ['refs' => [$blocked->reference]])->assertStatus(422);
    }

    public function test_refund_request_and_approval_reduce_net_commission(): void
    {
        $p = Payment::factory()->create();
        $base = "/paiements/operations/{$p->reference}";

        $this->post("{$base}/remboursements", ['type' => 'Remboursement partiel', 'amount' => 999999, 'motif' => 'Journée contestée', 'responsibility' => 'Ouvrier'])->assertSessionHasErrors('amount');
        $this->post("{$base}/remboursements", ['type' => 'Remboursement partiel', 'amount' => 10908, 'motif' => 'Journée contestée', 'responsibility' => 'Ouvrier'])->assertSessionHasNoErrors();
        $refund = $p->refunds()->firstOrFail();
        $this->assertSame('Demandé', $refund->status);

        $this->post("{$base}/remboursements/{$refund->id}/approbation", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->post("{$base}/remboursements/{$refund->id}/approbation", ['reason' => 'Contestation fondée'])->assertSessionHasNoErrors();

        $c = $this->calc($p);
        $this->assertSame(1800, $c['comRefunded']);
        $this->assertSame(7200, $c['comNet']);
        $this->assertSame('Partiellement remboursé', $p->fresh()->pay_status);
        $this->assertSame('Partiellement remboursée', $c['comStatus']);

        $this->post("{$base}/remboursements", ['type' => 'Remboursement total', 'motif' => 'Prestation annulée', 'responsibility' => 'O’CHANTIER'])->assertSessionHasNoErrors();
        $this->assertSame(54540 - 10908, $p->refunds()->where('status', 'Demandé')->firstOrFail()->amount);
        $this->post("{$base}/remboursements", ['type' => 'Remboursement total', 'motif' => 'Encore', 'responsibility' => 'O’CHANTIER'])->assertStatus(422);
    }

    public function test_items_deduction_approval_reconciliation_retry_and_reporting(): void
    {
        $unpaid = Payment::factory()->unpaid()->create();
        $base = "/paiements/operations/{$unpaid->reference}";

        $this->post("{$base}/elements/bonus", ['type' => 'Prime de chantier', 'amount' => 10000, 'motif' => 'Objectif atteint'])->assertSessionHasNoErrors();
        $this->post("{$base}/elements/deduction", ['type' => 'Avance antérieure', 'amount' => 5000, 'motif' => 'Avance'])->assertSessionHasErrors(['base', 'proof']);
        $this->post("{$base}/elements/deduction", ['type' => 'Avance antérieure', 'amount' => 5000, 'motif' => 'Avance', 'base' => 'Avance du 01/09', 'proof' => 'avance.pdf'])->assertSessionHasNoErrors();
        $this->assertSame(55000, $this->calc($unpaid)['net']);
        $deduction = $unpaid->items()->where('kind', 'deduction')->firstOrFail();
        $this->post("{$base}/elements/{$deduction->id}/approbation", ['reason' => 'Avance signée'])->assertSessionHasNoErrors();
        $this->assertSame(50000, $this->calc($unpaid)['net']);

        $paid = Payment::factory()->create(['received_amount' => 53040, 'reconciliation' => 'Écart détecté']);
        $this->post("/paiements/operations/{$paid->reference}/elements/bonus", ['type' => 'Prime de chantier', 'amount' => 1000, 'motif' => 'Trop tard'])->assertStatus(422);
        $this->post("/paiements/operations/{$paid->reference}/rapprochement", ['motif' => 'Frais prélevés par l’opérateur', 'comment' => 'Frais opérateur', 'proof' => 'releve.pdf'])->assertSessionHasNoErrors();
        $this->assertSame('Rapproché manuellement', $paid->fresh()->reconciliation);

        $failed = Payment::factory()->create(['payout_status' => 'Échoué', 'payout_message' => 'Numéro inconnu']);
        $this->post("/paiements/operations/{$failed->reference}/relance")->assertSessionHasNoErrors();
        $this->assertSame('À reprendre', $failed->fresh()->payout_status);

        $this->post("/paiements/operations/{$paid->reference}/anomalies", ['type' => 'Commission incorrecte', 'level' => 'Modérée', 'detail' => 'Taux erroné'])->assertSessionHasNoErrors();
        $this->assertSame('Commission incorrecte', $paid->fresh()->reported_anomalies[0]['type']);
    }
}
