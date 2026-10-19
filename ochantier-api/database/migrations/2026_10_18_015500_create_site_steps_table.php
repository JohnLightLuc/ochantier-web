<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * « Chronologie » du chantier : étapes des travaux saisies par le promoteur, démarrées puis validées une à une
     * (une seule en cours à la fois). Ouverte après le paiement de l’acompte, close à la fin du chantier.
     */
    public function up(): void
    {
        Schema::create('site_steps', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('site_id')->constrained('sites')->cascadeOnDelete();
            $table->smallInteger('sequence');
            $table->string('title', 160);
            $table->string('status', 10)->default('a_faire');
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->foreignUuid('created_by_user_id')->constrained('users');
            $table->foreignUuid('completed_by_user_id')->nullable()->constrained('users');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->unique(['site_id', 'sequence']);
            $table->comment('Étape des travaux d’un chantier (chronologie)');
        });

        DB::statement("ALTER TABLE site_steps ADD CONSTRAINT site_steps_status_check CHECK (status IN ('a_faire','en_cours','validee'))");
        DB::statement("CREATE UNIQUE INDEX uq_site_steps_running ON site_steps (site_id) WHERE status = 'en_cours'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('site_steps');
    }
};
