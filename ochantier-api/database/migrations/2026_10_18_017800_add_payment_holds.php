<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Garde-fous de l’encaissement : une seule demande de paiement en cours par élément payé (acompte, échéance,
     * versement, abonnement, commande de devis), et l’empreinte de la requête d’un paiement pour refuser qu’une même
     * clé d’idempotence serve à payer autre chose.
     */
    public function up(): void
    {
        Schema::create('payment_holds', function (Blueprint $table) {
            $table->string('target_type', 30)->comment('proposal, contractor_bid, payment_schedule, payment_installment, promoter_subscription, quote_request');
            $table->uuid('target_id');
            $table->char('holder_key', 64)->comment('empreinte du payeur et de sa clé d’idempotence');
            $table->foreignUuid('transaction_id')->nullable()->constrained('payment_transactions')->nullOnDelete();
            $table->timestampTz('expires_at');
            $table->timestampTz('created_at')->useCurrent();
            $table->primary(['target_type', 'target_id']);
            $table->index('expires_at', 'ix_payment_holds_expires_at');
            $table->comment('Élément en cours de paiement : interdit deux encaissements simultanés du même élément');
        });

        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->char('request_hash', 64)->nullable()->comment('empreinte de la requête de paiement (cible, opérateur, numéro, versements)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->dropColumn('request_hash');
        });

        Schema::dropIfExists('payment_holds');
    }
};
