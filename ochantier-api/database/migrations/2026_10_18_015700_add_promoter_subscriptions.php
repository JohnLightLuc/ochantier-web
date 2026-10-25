<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Abonnement et paiement flexible depuis l’application Promoteur :
     * - payment_installments : « Paiement flexible » des abonnés, un acompte ou une échéance réglé en 2 ou 3
     *   versements espacés de 7 jours ; le 1er versement valide la réservation ;
     * - payment_transactions_check4 : un paiement d’abonnement n’a sa facture qu’une fois réussi (l’abonnement et sa
     *   facture sont créés au succès du paiement de la formule) ;
     * - numérotation des abonnements et de leurs factures.
     */
    public function up(): void
    {
        Schema::create('payment_installments', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('group_id')->comment('versements d’un même paiement fractionné');
            $table->foreignUuid('promoter_profile_id')->constrained('promoter_profiles');
            $table->foreignUuid('site_id')->constrained('sites');
            $table->string('kind', 10)->comment('acompte, echeance');
            $table->foreignUuid('proposal_id')->nullable()->constrained('proposals');
            $table->jsonb('schedule_ids')->default('[]')->comment('échéances réglées par ces versements');
            $table->string('label', 200);
            $table->smallInteger('sequence');
            $table->smallInteger('installments_count');
            $table->bigInteger('amount');
            $table->date('due_date');
            $table->string('status', 10)->default('a_payer');
            $table->foreignUuid('transaction_id')->nullable()->constrained('payment_transactions');
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->unique(['group_id', 'sequence']);
            $table->index(['promoter_profile_id', 'status'], 'ix_payment_installments_profile');
            $table->comment('Versement d’un paiement flexible (abonnés) : acompte ou échéance réglé en 2 ou 3 fois');
        });

        DB::statement("ALTER TABLE payment_installments ADD CONSTRAINT payment_installments_kind_check CHECK (kind IN ('acompte','echeance'))");
        DB::statement("ALTER TABLE payment_installments ADD CONSTRAINT payment_installments_status_check CHECK (status IN ('a_payer','payee','annulee'))");
        DB::statement('ALTER TABLE payment_installments ADD CONSTRAINT payment_installments_count_check CHECK (installments_count IN (2, 3) AND sequence BETWEEN 1 AND installments_count)');
        DB::statement('ALTER TABLE payment_installments ADD CONSTRAINT payment_installments_amount_check CHECK (amount > 0)');

        DB::statement('ALTER TABLE payment_transactions DROP CONSTRAINT payment_transactions_check4');
        DB::statement("ALTER TABLE payment_transactions ADD CONSTRAINT payment_transactions_check4 CHECK (purpose <> 'abonnement' OR subscription_invoice_id IS NOT NULL OR status <> 'reussie')");

        foreach (['subscription_reference_seq' => 'subscriptions.reference', 'subscription_invoice_reference_seq' => 'subscription_invoices.reference'] as $sequence => $column) {
            DB::statement("CREATE SEQUENCE {$sequence} START 1 OWNED BY {$column}");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['subscription_reference_seq', 'subscription_invoice_reference_seq'] as $sequence) {
            DB::statement("DROP SEQUENCE IF EXISTS {$sequence}");
        }

        DB::statement('ALTER TABLE payment_transactions DROP CONSTRAINT payment_transactions_check4');
        DB::statement("ALTER TABLE payment_transactions ADD CONSTRAINT payment_transactions_check4 CHECK (purpose <> 'abonnement' OR subscription_invoice_id IS NOT NULL)");

        Schema::dropIfExists('payment_installments');
    }
};
