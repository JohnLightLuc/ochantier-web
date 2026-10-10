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
        Schema::create('workers', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('code')->unique();
            $table->string('photo_path')->nullable();

            // Identité et coordonnées
            $table->string('first_name');
            $table->string('last_name');
            $table->string('gender')->nullable();
            $table->date('birth_date')->nullable();
            $table->string('nationality')->nullable();
            $table->string('phone')->index();
            $table->string('phone_secondary')->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('commune')->nullable();
            $table->string('city')->nullable();
            $table->string('country')->nullable();
            $table->string('emergency_contact')->nullable();
            $table->string('emergency_phone')->nullable();

            // Métier et compétences
            $table->string('trade');
            $table->json('secondary_trades');
            $table->json('specialties');
            $table->json('skills');
            $table->json('certifications');
            $table->string('qualification')->nullable();
            $table->unsignedTinyInteger('experience_years')->default(0);
            $table->text('experience_description')->nullable();
            $table->unsignedInteger('daily_rate')->default(0);
            $table->string('mobility')->nullable();
            $table->json('zones');
            $table->boolean('works_solo')->default(true);
            $table->boolean('works_in_team')->default(true);
            $table->boolean('accepts_contracts')->default(false);

            // Pièce d’identité
            $table->string('id_type')->nullable();
            $table->string('id_number')->nullable();
            $table->date('id_issued_at')->nullable();
            $table->date('id_expires_at')->nullable();

            // Suivi commercial (le module Commerciaux reliera ces champs à un compte)
            $table->string('recruiter')->nullable();
            $table->string('sales_zone')->nullable();
            $table->string('acquisition_channel')->nullable();
            $table->text('sales_note')->nullable();

            // Statuts
            $table->string('status')->index();
            $table->string('availability')->index();
            $table->string('previous_availability')->nullable();
            $table->text('suspension_reason')->nullable();
            $table->json('rejection')->nullable();
            $table->boolean('identity_mismatch')->default(false);
            $table->boolean('possible_duplicate')->default(false);
            $table->decimal('rating', 2, 1)->default(0);
            $table->unsignedInteger('reviews_count')->default(0);
            $table->date('registered_at');

            // Affectations, pointages, paiements, avis et remplacements de démonstration, en attendant les modules dédiés.
            $table->json('demo_relations')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workers');
    }
};
