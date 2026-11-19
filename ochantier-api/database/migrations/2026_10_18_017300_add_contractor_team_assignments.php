<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Équipe de l’entrepreneur sur un chantier attribué (« Affecter au chantier », « Remplacer l’ouvrier ») :
     * chaque affectation directe porte son besoin et sa mission « équipe de l’entrepreneur » (missions.contractor_id),
     * sans acompte ni échéance : l’entrepreneur paie ses ouvriers hors plateforme, au tarif journalier qu’il fixe.
     *
     * Une affectation directe attend l’accord de l’ouvrier (en_attente), donné ou refusé depuis le Back Office.
     */
    public function up(): void
    {
        Schema::table('missions', function (Blueprint $table) {
            $table->foreignUuid('contractor_id')->nullable()->comment('équipe de l’entrepreneur, payée hors plateforme')->constrained('contractors');
        });

        Schema::table('assignments', function (Blueprint $table) {
            $table->timestampTz('agreement_decided_at')->nullable()->comment('accord ou refus de l’ouvrier');
        });

        DB::statement('ALTER TABLE assignments DROP CONSTRAINT assignments_status_check');
        DB::statement("ALTER TABLE assignments ADD CONSTRAINT assignments_status_check CHECK (status IN ('en_attente','confirmee','active','terminee','remplacee','annulee','refusee'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("UPDATE assignments SET status = 'annulee' WHERE status IN ('en_attente','refusee')");
        DB::statement('ALTER TABLE assignments DROP CONSTRAINT assignments_status_check');
        DB::statement("ALTER TABLE assignments ADD CONSTRAINT assignments_status_check CHECK (status IN ('confirmee','active','terminee','remplacee','annulee'))");

        Schema::table('assignments', function (Blueprint $table) {
            $table->dropColumn('agreement_decided_at');
        });

        Schema::table('missions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('contractor_id');
        });
    }
};
