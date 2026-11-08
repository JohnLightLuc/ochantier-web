<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Demande de devis d’une offre contractuelle (« Avez-vous un devis ? » → Non) : corps de métier demandés, offres
     * des techniciens (ouvriers validés du métier), une offre choisie par métier, paiement groupé « Commande des
     * devis », puis devis réalisé ajouté au chantier.
     *
     * Le chantier passe au statut « devis » le temps de la recherche et de la commande, puis revient au brouillon
     * quand tous les devis sont reçus (offre financière à définir).
     */
    public function up(): void
    {
        Schema::create('quote_requests', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('reference', 20)->unique();
            $table->foreignUuid('site_id')->constrained('sites');
            $table->string('status', 15)->default('recherche');
            $table->text('notes')->nullable()->comment('précisions pour le technicien');
            $table->bigInteger('total_amount')->nullable()->comment('total des offres choisies (XOF)');
            $table->foreignUuid('payment_transaction_id')->nullable()->constrained('payment_transactions');
            $table->foreignUuid('requested_by_user_id')->constrained('users');
            $table->timestampTz('submitted_at')->useCurrent();
            $table->timestampTz('ordered_at')->nullable();
            $table->timestampTz('received_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->comment('Demandes de devis du promoteur (offre contractuelle sans devis)');
        });

        DB::statement("ALTER TABLE quote_requests ADD CONSTRAINT quote_requests_status_check CHECK (status IN ('recherche','commande','recu','annule'))");
        DB::statement("CREATE UNIQUE INDEX ux_quote_requests_open ON quote_requests (site_id) WHERE status IN ('recherche','commande')");
        DB::statement('CREATE SEQUENCE quote_request_reference_seq START 1 OWNED BY quote_requests.reference');

        Schema::create('quote_request_trades', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('quote_request_id')->constrained('quote_requests')->cascadeOnDelete();
            $table->foreignUuid('trade_id')->constrained('trades');
            $table->string('status', 15)->default('recherche');
            $table->uuid('selected_bid_id')->nullable();
            $table->string('quote_number', 20)->nullable()->unique();
            $table->jsonb('quote_items')->nullable()->comment('lignes du devis : désignation, prix unitaire, quantité, total');
            $table->bigInteger('quote_total_amount')->nullable();
            $table->date('visited_on')->nullable();
            $table->date('issued_on')->nullable();
            $table->date('valid_until')->nullable();
            $table->foreignUuid('quote_document_id')->nullable()->comment('devis ajouté aux documents du chantier')->constrained('documents');
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->unique(['quote_request_id', 'trade_id']);
            $table->comment('Corps de métier d’une demande de devis, offre choisie et devis réalisé');
        });

        DB::statement('CREATE SEQUENCE site_quote_number_seq START 1 OWNED BY quote_request_trades.quote_number');
        DB::statement("ALTER TABLE quote_request_trades ADD CONSTRAINT quote_request_trades_status_check CHECK (status IN ('recherche','commande','realise'))");

        Schema::create('quote_bids', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('quote_request_trade_id')->constrained('quote_request_trades')->cascadeOnDelete();
            $table->foreignUuid('worker_id')->constrained('workers');
            $table->bigInteger('amount')->comment('prix du devis (XOF)');
            $table->date('visit_date')->comment('date d’exécution du devis');
            $table->decimal('distance_km', 6, 1)->nullable();
            $table->string('status', 15)->default('proposee');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->unique(['quote_request_trade_id', 'worker_id']);
            $table->comment('Offres des techniciens pour établir un devis');
        });

        DB::statement("ALTER TABLE quote_bids ADD CONSTRAINT quote_bids_status_check CHECK (status IN ('proposee','choisie','non_retenue'))");
        DB::statement('ALTER TABLE quote_bids ADD CONSTRAINT quote_bids_amount_check CHECK (amount > 0)');
        DB::statement('ALTER TABLE quote_request_trades ADD CONSTRAINT quote_request_trades_selected_bid_fk FOREIGN KEY (selected_bid_id) REFERENCES quote_bids (id)');

        DB::statement('ALTER TABLE sites DROP CONSTRAINT sites_status_check');
        DB::statement("ALTER TABLE sites ADD CONSTRAINT sites_status_check CHECK (status IN ('brouillon','devis','soumis','recherche','paiement','confirme','en_cours','suspendu','termine','annule'))");
        DB::statement('ALTER TABLE payment_transactions DROP CONSTRAINT payment_transactions_purpose_check');
        DB::statement("ALTER TABLE payment_transactions ADD CONSTRAINT payment_transactions_purpose_check CHECK (purpose IN ('acompte','echeance','reversement_ouvrier','gain_commercial','remboursement','abonnement','commande_devis'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("UPDATE sites SET status = 'brouillon' WHERE status = 'devis'");
        DB::statement('ALTER TABLE sites DROP CONSTRAINT sites_status_check');
        DB::statement("ALTER TABLE sites ADD CONSTRAINT sites_status_check CHECK (status IN ('brouillon','soumis','recherche','paiement','confirme','en_cours','suspendu','termine','annule'))");
        DB::statement('ALTER TABLE quote_request_trades DROP CONSTRAINT quote_request_trades_selected_bid_fk');
        Schema::dropIfExists('quote_bids');
        Schema::dropIfExists('quote_request_trades');
        Schema::dropIfExists('quote_requests');
        DB::statement('ALTER TABLE payment_transactions DROP CONSTRAINT payment_transactions_purpose_check');
        DB::statement("ALTER TABLE payment_transactions ADD CONSTRAINT payment_transactions_purpose_check CHECK (purpose IN ('acompte','echeance','reversement_ouvrier','gain_commercial','remboursement','abonnement'))");
    }
};
