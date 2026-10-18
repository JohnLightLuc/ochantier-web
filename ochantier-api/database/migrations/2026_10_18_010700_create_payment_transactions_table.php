<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('reference', 30)->unique();
            $table->string('direction', 15);
            $table->string('purpose', 25);
            $table->string('payer_type', 30);
            $table->uuid('payer_id')->nullable();
            $table->string('payee_type', 30);
            $table->uuid('payee_id')->nullable();
            $table->foreignUuid('payment_schedule_id')->nullable()->constrained('payment_schedules');
            $table->foreignUuid('worker_payout_id')->nullable()->constrained('worker_payouts');
            $table->foreignUuid('refund_id')->nullable()->constrained('refunds');
            $table->uuid('commercial_payout_id')->nullable()->comment('FK ajoutée après commercial_payouts');
            $table->foreignUuid('subscription_invoice_id')->nullable()->constrained('subscription_invoices');
            $table->foreignUuid('payment_method_id')->nullable()->constrained('payment_methods');
            $table->foreignUuid('operator_id')->nullable()->constrained('payment_operators');
            $table->string('provider', 40);
            $table->string('provider_reference', 120)->nullable();
            $table->string('idempotency_key', 100)->unique();
            $table->bigInteger('amount');
            $table->bigInteger('fees_amount')->default(0);
            $table->char('currency', 3)->default('XOF');
            $table->string('status', 10)->default('initiee');
            $table->string('failure_code', 60)->nullable();
            $table->text('failure_message')->nullable();
            $table->uuid('retry_of_transaction_id')->nullable();
            $table->string('initiated_by_type', 20);
            $table->uuid('initiated_by_id')->nullable();
            $table->timestampTz('initiated_at')->useCurrent();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('reconciled_at')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->index(['payment_schedule_id'], 'ix_payment_transactions_schedule');
            $table->index(['status', 'initiated_at'], 'ix_payment_transactions_status');
            $table->comment('Toute opération auprès du prestataire : encaissement, décaissement, remboursement');
        });

        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->foreign('retry_of_transaction_id')->references('id')->on('payment_transactions');
        });

        DB::statement('ALTER TABLE payment_transactions ADD CONSTRAINT payment_transactions_direction_check CHECK (direction IN (\'encaissement\',\'decaissement\',\'remboursement\'))');
        DB::statement('ALTER TABLE payment_transactions ADD CONSTRAINT payment_transactions_purpose_check CHECK (purpose IN (\'acompte\',\'echeance\',\'reversement_ouvrier\',\'gain_commercial\',\'remboursement\',\'abonnement\'))');
        DB::statement('ALTER TABLE payment_transactions ADD CONSTRAINT payment_transactions_amount_check CHECK (amount > 0)');
        DB::statement('ALTER TABLE payment_transactions ADD CONSTRAINT payment_transactions_currency_check CHECK (currency = \'XOF\')');
        DB::statement('ALTER TABLE payment_transactions ADD CONSTRAINT payment_transactions_status_check CHECK (status IN (\'initiee\',\'en_attente\',\'reussie\',\'echouee\',\'expiree\',\'annulee\'))');
        DB::statement('ALTER TABLE payment_transactions ADD CONSTRAINT payment_transactions_check1 CHECK (purpose <> \'reversement_ouvrier\' OR worker_payout_id IS NOT NULL)');
        DB::statement('ALTER TABLE payment_transactions ADD CONSTRAINT payment_transactions_check2 CHECK (purpose NOT IN (\'acompte\',\'echeance\') OR payment_schedule_id IS NOT NULL)');
        DB::statement('ALTER TABLE payment_transactions ADD CONSTRAINT payment_transactions_check3 CHECK (purpose <> \'remboursement\' OR refund_id IS NOT NULL)');
        DB::statement('ALTER TABLE payment_transactions ADD CONSTRAINT payment_transactions_check4 CHECK (purpose <> \'abonnement\' OR subscription_invoice_id IS NOT NULL)');
        DB::statement('CREATE UNIQUE INDEX uq_payment_transactions_provider_ref ON payment_transactions (provider, provider_reference) WHERE provider_reference IS NOT NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
    }
};
