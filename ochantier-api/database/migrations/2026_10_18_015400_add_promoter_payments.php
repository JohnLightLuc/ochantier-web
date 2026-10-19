<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Paiements du promoteur depuis l’application :
     * - payment_allocations : un paiement Mobile Money peut régler plusieurs échéances (l’acompte d’une équipe couvre
     *   une mission par métier) ; payment_transactions.payment_schedule_id reste renseigné pour un paiement simple ;
     * - payment_operators.collection_fee_percent : frais de l’opérateur facturés au promoteur ;
     * - proposals : coût des ouvriers et commission promoteur (Y) détaillés ;
     * - numérotation des missions, échéances, paiements et reçus.
     */
    public function up(): void
    {
        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('transaction_id')->constrained('payment_transactions')->cascadeOnDelete();
            $table->foreignUuid('payment_schedule_id')->constrained('payment_schedules');
            $table->bigInteger('amount');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['transaction_id', 'payment_schedule_id']);
            $table->comment('Répartition d’un paiement entre les échéances qu’il règle');
        });
        DB::statement('ALTER TABLE payment_allocations ADD CONSTRAINT payment_allocations_amount_check CHECK (amount > 0)');

        DB::statement('ALTER TABLE payment_transactions DROP CONSTRAINT payment_transactions_check2');
        DB::statement("ALTER TABLE payment_transactions ADD CONSTRAINT payment_transactions_check2 CHECK (purpose NOT IN ('acompte','echeance') OR payment_schedule_id IS NOT NULL OR jsonb_exists(metadata, 'allocations'))");

        Schema::table('payment_operators', function (Blueprint $table) {
            $table->decimal('collection_fee_percent', 5, 2)->default(1.5)->comment('frais d’encaissement facturés au promoteur (%)');
        });

        Schema::table('proposals', function (Blueprint $table) {
            $table->bigInteger('workers_amount')->default(0)->comment('coût des ouvriers sur la période (base)');
            $table->bigInteger('commission_amount')->default(0)->comment('commission promoteur Y');
        });

        foreach (['mission_reference_seq' => 'missions.reference', 'payment_schedule_reference_seq' => 'payment_schedules.reference', 'payment_transaction_reference_seq' => 'payment_transactions.reference', 'receipt_number_seq' => 'receipts.number'] as $sequence => $column) {
            DB::statement("CREATE SEQUENCE {$sequence} START 1 OWNED BY {$column}");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['mission_reference_seq', 'payment_schedule_reference_seq', 'payment_transaction_reference_seq', 'receipt_number_seq'] as $sequence) {
            DB::statement("DROP SEQUENCE IF EXISTS {$sequence}");
        }

        Schema::table('proposals', function (Blueprint $table) {
            $table->dropColumn(['workers_amount', 'commission_amount']);
        });

        Schema::table('payment_operators', function (Blueprint $table) {
            $table->dropColumn('collection_fee_percent');
        });

        DB::statement('ALTER TABLE payment_transactions DROP CONSTRAINT payment_transactions_check2');
        DB::statement("ALTER TABLE payment_transactions ADD CONSTRAINT payment_transactions_check2 CHECK (purpose NOT IN ('acompte','echeance') OR payment_schedule_id IS NOT NULL)");
        Schema::dropIfExists('payment_allocations');
    }
};
