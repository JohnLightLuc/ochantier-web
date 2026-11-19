<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Propositions de devis des entrepreneurs (« Proposer ma prestation de devis ») : frais de visite ou d’étude de
     * l’entrepreneur, frais de service O’CHANTIER (pourcentage figé à l’envoi), créneau et durée de la visite. Une
     * fois la commande payée, la proposition devient une mission de visite (planifiée, report proposé au promoteur,
     * réalisée), puis l’entrepreneur rédige le devis, en versions successives (brouillon, envoyé, reçu).
     *
     * Les offres déjà enregistrées d’ouvriers simulés sont conservées ; les nouvelles viennent des entrepreneurs.
     * quote_bids.amount reste le prix payé par le promoteur (frais + frais de service).
     */
    public function up(): void
    {
        Schema::table('quote_bids', function (Blueprint $table) {
            $table->uuid('worker_id')->nullable()->change();
            $table->foreignUuid('contractor_id')->nullable()->after('worker_id')->constrained('contractors');
            $table->bigInteger('fee_amount')->nullable()->comment('frais de visite ou d’étude de l’entrepreneur, net (XOF)');
            $table->decimal('service_fee_percent', 5, 2)->nullable()->comment('frais de service O’CHANTIER figés à l’envoi (%)');
            $table->bigInteger('service_fee_amount')->default(0);
            $table->string('visit_slot', 20)->nullable()->comment('créneau de visite : 08:00 – 10:00…');
            $table->string('visit_duration', 20)->nullable();
            $table->text('comment')->nullable();
            $table->timestampTz('withdrawn_at')->nullable();
            $table->string('visit_status', 20)->nullable()->comment('mission de visite après la commande');
            $table->timestampTz('visited_at')->nullable();
            $table->date('reschedule_date')->nullable();
            $table->string('reschedule_slot', 20)->nullable();
            $table->text('reschedule_reason')->nullable();
            $table->timestampTz('reschedule_requested_at')->nullable();
            $table->unique(['quote_request_trade_id', 'contractor_id']);
        });

        DB::statement('ALTER TABLE quote_bids ADD CONSTRAINT quote_bids_author_check CHECK ((worker_id IS NULL) <> (contractor_id IS NULL))');
        DB::statement('ALTER TABLE quote_bids DROP CONSTRAINT quote_bids_status_check');
        DB::statement("ALTER TABLE quote_bids ADD CONSTRAINT quote_bids_status_check CHECK (status IN ('proposee','choisie','non_retenue','retiree'))");
        DB::statement("ALTER TABLE quote_bids ADD CONSTRAINT quote_bids_visit_status_check CHECK (visit_status IN ('planifiee','report_propose','realisee'))");

        Schema::create('quote_versions', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('quote_request_trade_id')->constrained('quote_request_trades')->cascadeOnDelete();
            $table->foreignUuid('quote_bid_id')->constrained('quote_bids');
            $table->string('number', 20)->comment('numéro du devis, commun à toutes ses versions');
            $table->integer('version');
            $table->string('status', 15)->default('brouillon');
            $table->jsonb('items')->default('[]')->comment('lignes : désignation, unité, quantité, prix unitaire, total');
            $table->boolean('vat_applied')->default(false);
            $table->decimal('vat_rate', 5, 2)->default(18);
            $table->bigInteger('total_ht')->default(0);
            $table->bigInteger('vat_amount')->default(0);
            $table->bigInteger('total_ttc')->default(0);
            $table->string('works_duration', 60)->nullable();
            $table->text('conditions')->nullable();
            $table->integer('validity_days')->default(30);
            $table->text('observations')->nullable();
            $table->timestampTz('signed_at')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('received_at')->nullable();
            $table->foreignUuid('document_id')->nullable()->comment('PDF ajouté aux documents du chantier')->constrained('documents');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->unique(['quote_request_trade_id', 'version']);
            $table->comment('Versions du devis rédigé par l’entrepreneur après la visite');
        });

        DB::statement("ALTER TABLE quote_versions ADD CONSTRAINT quote_versions_status_check CHECK (status IN ('brouillon','envoye','recu'))");
        DB::statement('ALTER TABLE quote_versions ADD CONSTRAINT quote_versions_validity_days_check CHECK (validity_days IN (15,30,60,90))');
        DB::statement("CREATE UNIQUE INDEX ux_quote_versions_draft ON quote_versions (quote_request_trade_id) WHERE status = 'brouillon'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quote_versions');

        DB::statement("UPDATE quote_bids SET status = 'non_retenue' WHERE status = 'retiree'");
        DB::statement('DELETE FROM quote_bids WHERE contractor_id IS NOT NULL');
        DB::statement('ALTER TABLE quote_bids DROP CONSTRAINT quote_bids_visit_status_check');
        DB::statement('ALTER TABLE quote_bids DROP CONSTRAINT quote_bids_status_check');
        DB::statement("ALTER TABLE quote_bids ADD CONSTRAINT quote_bids_status_check CHECK (status IN ('proposee','choisie','non_retenue'))");
        DB::statement('ALTER TABLE quote_bids DROP CONSTRAINT quote_bids_author_check');

        Schema::table('quote_bids', function (Blueprint $table) {
            $table->dropUnique(['quote_request_trade_id', 'contractor_id']);
            $table->dropConstrainedForeignId('contractor_id');
            $table->dropColumn(['fee_amount', 'service_fee_percent', 'service_fee_amount', 'visit_slot', 'visit_duration', 'comment', 'withdrawn_at', 'visit_status', 'visited_at', 'reschedule_date', 'reschedule_slot', 'reschedule_reason', 'reschedule_requested_at']);
            $table->uuid('worker_id')->nullable(false)->change();
        });
    }
};
