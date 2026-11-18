<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Réponses des entrepreneurs aux offres contractuelles (« Accepter les conditions », « Contre-proposition ») :
     * type de réponse, dates de travaux proposées, brouillon (jamais montré au promoteur) et date d’envoi.
     * L’échéancier reste celui de la plateforme (acompte X, puis solde au rythme de l’offre du promoteur).
     *
     * Les offres déjà enregistrées (entrepreneurs simulés) sont des contre-propositions envoyées.
     */
    public function up(): void
    {
        Schema::table('contractor_bids', function (Blueprint $table) {
            $table->string('response_mode', 20)->default('contre_proposition')->comment('conforme ou contre_proposition');
            $table->date('start_date')->nullable()->comment('début des travaux proposé');
            $table->date('end_date')->nullable();
            $table->timestampTz('sent_at')->nullable()->comment('envoi au promoteur ; null pour un brouillon');
        });

        DB::statement('UPDATE contractor_bids SET sent_at = created_at');
        DB::statement("ALTER TABLE contractor_bids ADD CONSTRAINT contractor_bids_response_mode_check CHECK (response_mode IN ('conforme','contre_proposition'))");
        DB::statement('ALTER TABLE contractor_bids ADD CONSTRAINT contractor_bids_dates_check CHECK (end_date IS NULL OR start_date IS NULL OR end_date >= start_date)');
        DB::statement('ALTER TABLE contractor_bids DROP CONSTRAINT contractor_bids_status_check');
        DB::statement("ALTER TABLE contractor_bids ADD CONSTRAINT contractor_bids_status_check CHECK (status IN ('brouillon','proposee','choisie','retenue','non_retenue','annulee'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("DELETE FROM contractor_bids WHERE status = 'brouillon'");
        DB::statement('ALTER TABLE contractor_bids DROP CONSTRAINT contractor_bids_status_check');
        DB::statement("ALTER TABLE contractor_bids ADD CONSTRAINT contractor_bids_status_check CHECK (status IN ('proposee','choisie','retenue','non_retenue','annulee'))");
        DB::statement('ALTER TABLE contractor_bids DROP CONSTRAINT contractor_bids_dates_check');
        DB::statement('ALTER TABLE contractor_bids DROP CONSTRAINT contractor_bids_response_mode_check');

        Schema::table('contractor_bids', function (Blueprint $table) {
            $table->dropColumn(['response_mode', 'start_date', 'end_date', 'sent_at']);
        });
    }
};
