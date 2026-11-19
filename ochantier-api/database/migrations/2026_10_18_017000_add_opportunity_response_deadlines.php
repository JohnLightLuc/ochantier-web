<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Opportunités des entrepreneurs (« Temps restant pour répondre ») : date limite de réponse d’une demande de devis,
     * et de l’offre contractuelle (offers.application_deadline). Les demandes et offres déjà ouvertes reçoivent le
     * délai par défaut (72 h) à partir de leur envoi ; le délai se règle ensuite dans les paramètres
     * quotes.response_hours et contractor_offers.response_hours.
     */
    public function up(): void
    {
        Schema::table('quote_requests', function (Blueprint $table) {
            $table->timestampTz('response_deadline')->nullable()->comment('date limite des offres des techniciens');
        });

        DB::statement("UPDATE quote_requests SET response_deadline = submitted_at + interval '72 hours'");
        DB::statement("UPDATE offers SET application_deadline = coalesce(published_at, submitted_at, created_at) + interval '72 hours' WHERE offer_type = 'contractuelle' AND application_deadline IS NULL AND status <> 'brouillon'");

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('quote_requests', function (Blueprint $table) {
            $table->dropColumn('response_deadline');
        });
    }
};
