<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\PaymentOperator;
use App\Models\PaymentTransaction;
use App\Support\Auth\AdminSessions;
use App\Support\Auth\DeviceContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Promoter\ConfirmedSite;
use Tests\Feature\Promoter\PromoterTestCase;

/**
 * API du Back Office, module Finance : rapprochement d’un relevé d’opérateur.
 */
class ReconciliationAdminTest extends PromoterTestCase
{
    use ConfirmedSite;

    private Admin $finance;

    private PaymentOperator $operator;

    private PaymentTransaction $transaction;

    protected function setUp(): void
    {
        parent::setUp();

        $this->confirmSite();
        $this->finance = Admin::factory()->role('finance')->create();
        $this->transaction = PaymentTransaction::query()->where('status', 'reussie')->firstOrFail();
        $this->operator = PaymentOperator::query()->findOrFail($this->transaction->operator_id);
    }

    private function as(Admin $admin): static
    {
        return $this->withAccessToken(app(AdminSessions::class)->open($admin, new DeviceContext('Chrome', null, 'web', null, '127.0.0.1', 'phpunit'))->accessToken);
    }

    private function total(): int
    {
        return $this->transaction->amount + $this->transaction->fees_amount;
    }

    private function import(string $csv): TestResponse
    {
        return $this->post('/api/v1/admin/finance/reconciliations', [
            'operator_id' => $this->operator->id,
            'from' => now()->subDay()->toDateString(),
            'to' => now()->addDay()->toDateString(),
            'file' => UploadedFile::fake()->createWithContent('releve.csv', $csv),
        ], ['Accept' => 'application/json']);
    }

    public function test_a_matching_statement_reconciles_the_transaction(): void
    {
        $this->as($this->finance)->import("\xEF\xBB\xBFRéférence;Montant;Statut\n{$this->transaction->provider_reference};{$this->total()};Réussi\n")
            ->assertCreated()
            ->assertJsonPath('data.summary.matched', 1)
            ->assertJsonPath('data.summary.discrepancies', 0)
            ->assertJsonPath('data.items', []);

        $this->assertNotNull($this->transaction->fresh()->reconciled_at);
        $this->getJson('/api/v1/admin/finance/reconciliations')->assertOk()->assertJsonPath('meta.counts.open_items', 0)->assertJsonPath('meta.counts.unreconciled', 0)->assertJsonPath('data.0.provider', $this->operator->provider);
    }

    public function test_each_kind_of_gap_is_listed_and_nothing_changes_amounts(): void
    {
        $other = $this->transaction->replicate()->forceFill(['reference' => 'TX-MANQUANTE', 'idempotency_key' => 'tx-manquante', 'provider_reference' => 'SIM-MANQUANTE']);
        $other->save();
        $failed = $this->transaction->replicate()->forceFill(['reference' => 'TX-ECHEC', 'idempotency_key' => 'tx-echec', 'provider_reference' => 'SIM-ECHEC', 'status' => 'echouee']);
        $failed->save();
        $amount = $this->transaction->amount;
        $wrong = $this->total() + 500;

        $items = $this->as($this->finance)->import("reference,amount,status\n{$this->transaction->provider_reference},\"{$wrong} FCFA\",success\nINCONNUE,1000,success\nDOUBLE,2000,success\nDOUBLE,2000,success\nSIM-ECHEC,3000,success\n")
            ->assertCreated()
            ->assertJsonPath('data.summary.file_rows', 5)
            ->assertJsonPath('data.summary.matched', 0)
            ->assertJsonPath('data.summary.discrepancies', 5)
            ->assertJsonPath('data.open_items', 5)
            ->collect('data.items');

        $this->assertEqualsCanonicalizing(['ecart_montant', 'absent_plateforme', 'doublon', 'statut_different', 'absent_prestataire'], $items->pluck('type.value')->all());
        $gap = $items->firstWhere('type.value', 'ecart_montant');
        $this->assertSame([$wrong, $this->total()], [$gap['provider_amount'], $gap['platform_amount']]);
        $this->assertSame($other->id, $items->firstWhere('type.value', 'absent_prestataire')['transaction']['id']);
        $this->assertSame($amount, $this->transaction->fresh()->amount);
        $this->assertNull($this->transaction->fresh()->reconciled_at);
    }

    public function test_an_overlap_waits_for_open_gaps_which_need_a_reason(): void
    {
        $run = $this->as($this->finance)->import("reference;montant\nINCONNUE;1000\n")->assertCreated()->json('data');
        $this->import("reference;montant\nAUTRE;1000\n")->assertUnprocessable()->assertJsonValidationErrors('reconciliation');

        $items = collect($run['items']);
        $unknown = $items->firstWhere('type.value', 'absent_plateforme');
        $missing = $items->firstWhere('type.value', 'absent_prestataire');
        $base = "/api/v1/admin/finance/reconciliations/{$run['id']}/items";

        $this->postJson("{$base}/{$unknown['id']}/accept", ['reason' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson("{$base}/{$unknown['id']}/accept", ['reason' => 'Opération de test de l’opérateur'])->assertOk()->assertJsonPath('data.open_items', 1);
        $this->postJson("{$base}/{$unknown['id']}/accept", ['reason' => 'Opération de test de l’opérateur'])->assertUnprocessable()->assertJsonValidationErrors('reconciliation');
        $this->postJson("{$base}/{$missing['id']}/resolve", ['reason' => 'Présente sur le relevé suivant'])->assertOk()->assertJsonPath('data.open_items', 0);

        $this->assertNotNull($this->transaction->fresh()->reconciled_at);
        $this->assertSame(3, AuditLog::query()->where('module', 'finance')->count());
        $this->import("reference;montant\nAUTRE;1000\n")->assertCreated();
    }

    public function test_an_unreadable_statement_is_refused_entirely(): void
    {
        $this->as($this->finance)->import("nom;valeur\nA;1\n")->assertUnprocessable()->assertJsonPath('errors.file.0', 'Le relevé doit contenir une colonne « reference » et une colonne « montant ».');
        $this->import("reference;montant\n{$this->transaction->provider_reference};{$this->total()}\nSANS-MONTANT;\n")->assertUnprocessable()->assertJsonPath('errors.file.0', 'Ligne 3 illisible : référence ou montant manquant.');
        $this->assertNull($this->transaction->fresh()->reconciled_at);
        $this->import("reference;montant\n")->assertUnprocessable()->assertJsonPath('errors.file.0', 'Ce relevé ne contient aucune ligne.');
    }

    public function test_rights_are_enforced(): void
    {
        $direction = Admin::factory()->role('direction')->create();

        $this->as($direction)->getJson('/api/v1/admin/finance/reconciliations')->assertOk();
        $this->import("reference;montant\nA;1\n")->assertForbidden();
    }
}
