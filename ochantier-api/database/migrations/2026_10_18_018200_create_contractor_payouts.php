<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reversements aux professionnels O’CHANTIER PRO de leur part nette (frais de visite d’un devis après la visite,
     * acompte et échéances d’un forfait payés par le promoteur). Une personne prépare le versement d’un professionnel,
     * une autre le valide, ce qui l’envoie par Mobile Money. Chaque part nette n’est reversée qu’une fois.
     */
    public function up(): void
    {
        Schema::create('contractor_payouts', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('reference', 25)->unique();
            $table->foreignUuid('contractor_id')->constrained('contractors');
            $table->bigInteger('total_amount');
            $table->foreignUuid('payment_method_id')->nullable()->constrained('payment_methods');
            $table->string('status', 15)->default('a_payer');
            $table->text('cancellation_reason')->nullable();
            $table->foreignUuid('created_by_admin_id')->nullable()->constrained('admins');
            $table->foreignUuid('approved_by_admin_id')->nullable()->constrained('admins');
            $table->timestampTz('paid_at')->nullable();
            $table->timestampsTz();
            $table->index(['contractor_id', 'status']);
            $table->comment('Versements aux professionnels O’CHANTIER PRO de leur part nette');
        });

        DB::statement("ALTER TABLE contractor_payouts ADD CONSTRAINT contractor_payouts_status_check CHECK (status IN ('a_payer','en_traitement','paye','rejete','annule'))");
        DB::statement('CREATE SEQUENCE contractor_payout_reference_seq START 1 OWNED BY contractor_payouts.reference');

        Schema::create('contractor_payout_items', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('contractor_payout_id')->constrained('contractor_payouts')->cascadeOnDelete();
            $table->string('source_key', 60)->unique()->comment('ligne du registre des commissions (devis:…, forfait:…) : une part nette n’est reversée qu’une fois');
            $table->string('reference', 30);
            $table->string('label', 120);
            $table->string('site_label', 255)->nullable();
            $table->bigInteger('amount');
            $table->timestampTz('created_at')->useCurrent();
            $table->comment('Parts nettes réunies dans un versement à un professionnel');
        });

        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->foreignUuid('contractor_payout_id')->nullable()->constrained('contractor_payouts');
        });

        DB::statement('ALTER TABLE payment_transactions DROP CONSTRAINT payment_transactions_purpose_check');
        DB::statement("ALTER TABLE payment_transactions ADD CONSTRAINT payment_transactions_purpose_check CHECK (purpose IN ('acompte','echeance','reversement_ouvrier','gain_commercial','remboursement','abonnement','commande_devis','effectifs','reversement_professionnel'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE payment_transactions DROP CONSTRAINT payment_transactions_purpose_check');
        DB::statement("ALTER TABLE payment_transactions ADD CONSTRAINT payment_transactions_purpose_check CHECK (purpose IN ('acompte','echeance','reversement_ouvrier','gain_commercial','remboursement','abonnement','commande_devis','effectifs'))");

        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('contractor_payout_id');
        });

        Schema::dropIfExists('contractor_payout_items');
        Schema::dropIfExists('contractor_payouts');
    }
};
