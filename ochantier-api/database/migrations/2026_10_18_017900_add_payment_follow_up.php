<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Suivi d’un encaissement dont l’issue n’est pas immédiate (validation sur le téléphone, prestataire injoignable) :
     * date d’expiration, prochaine vérification auprès du prestataire, nombre de vérifications. Un paiement encaissé
     * qui n’a pas pu être appliqué (metadata.settlement) reste « réussi » : l’argent a bien été reçu. Les webhooks
     * sont aussi dédoublonnés par l’empreinte de leur contenu.
     */
    public function up(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->timestampTz('expires_at')->nullable()->comment('au-delà, une demande restée sans réponse est expirée');
            $table->timestampTz('next_check_at')->nullable()->comment('prochaine vérification auprès du prestataire ; nul une fois l’issue connue');
            $table->smallInteger('check_attempts')->default(0);
        });

        DB::statement("CREATE INDEX ix_payment_transactions_next_check ON payment_transactions (next_check_at) WHERE status IN ('initiee','en_attente') AND next_check_at IS NOT NULL");

        DB::statement('ALTER TABLE payment_transactions DROP CONSTRAINT payment_transactions_check4');
        DB::statement("ALTER TABLE payment_transactions ADD CONSTRAINT payment_transactions_check4 CHECK (purpose <> 'abonnement' OR subscription_invoice_id IS NOT NULL OR status <> 'reussie' OR jsonb_exists(metadata, 'settlement'))");

        Schema::table('payment_webhook_events', function (Blueprint $table) {
            $table->char('payload_hash', 64)->nullable()->comment('empreinte du contenu reçu');
            $table->unique(['provider', 'payload_hash']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_webhook_events', function (Blueprint $table) {
            $table->dropUnique(['provider', 'payload_hash']);
            $table->dropColumn('payload_hash');
        });

        DB::statement('ALTER TABLE payment_transactions DROP CONSTRAINT payment_transactions_check4');
        DB::statement("ALTER TABLE payment_transactions ADD CONSTRAINT payment_transactions_check4 CHECK (purpose <> 'abonnement' OR subscription_invoice_id IS NOT NULL OR status <> 'reussie')");

        DB::statement('DROP INDEX IF EXISTS ix_payment_transactions_next_check');

        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->dropColumn(['expires_at', 'next_check_at', 'check_attempts']);
        });
    }
};
