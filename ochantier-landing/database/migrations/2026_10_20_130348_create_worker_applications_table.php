<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('worker_applications', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->string('prenom');
            $table->string('nom');
            $table->string('telephone', 30);
            $table->string('email')->nullable();
            $table->date('date_naissance')->nullable();
            $table->string('genre', 10)->nullable();
            $table->string('photo_path')->nullable();
            $table->string('cni_path');
            $table->string('metier');
            $table->unsignedInteger('tarif_journalier')->nullable();
            $table->unsignedTinyInteger('experience_annees')->default(0);
            $table->string('niveau');
            $table->json('competences')->nullable();
            $table->json('certifications')->nullable();
            $table->text('presentation')->nullable();
            $table->string('commune');
            $table->string('quartier')->nullable();
            $table->string('rayon_deplacement');
            $table->string('disponibilite');
            $table->json('jours_disponibles')->nullable();
            $table->string('moyen_paiement');
            $table->string('numero_paiement', 30)->nullable();
            $table->string('chantier_cible')->nullable();
            $table->string('chantier_commune')->nullable();
            $table->string('statut')->default('en_attente');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('worker_applications');
    }
};
