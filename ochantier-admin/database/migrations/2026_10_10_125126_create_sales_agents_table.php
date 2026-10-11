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
        Schema::create('sales_agents', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('code')->unique();
            $table->string('photo_path')->nullable();

            // Identité et coordonnées. Le téléphone principal sert d’identifiant dans l’Application Commercial.
            $table->string('first_name');
            $table->string('last_name');
            $table->string('gender')->nullable();
            $table->date('birth_date')->nullable();
            $table->string('nationality')->nullable();
            $table->string('phone')->unique();
            $table->string('phone_secondary')->nullable();
            $table->string('email')->unique();
            $table->string('address')->nullable();
            $table->string('commune')->nullable();
            $table->string('city')->nullable();
            $table->string('emergency_contact')->nullable();
            $table->string('emergency_phone')->nullable();

            // Poste et territoire
            $table->string('type');
            $table->string('job_title')->nullable();
            $table->string('agency')->nullable();
            $table->string('zone');
            $table->json('secondary_zones');
            $table->json('communes');
            $table->json('priority_trades');
            $table->json('specialties');
            $table->string('supervisor')->nullable();
            $table->date('started_at');
            $table->string('contract')->nullable();
            $table->unsignedSmallInteger('weekly_target')->default(0);
            $table->unsignedSmallInteger('monthly_target')->default(0);
            $table->text('internal_note')->nullable();

            // Pièce d’identité
            $table->string('id_type')->nullable();
            $table->string('id_number')->nullable();
            $table->date('id_issued_at')->nullable();
            $table->date('id_expires_at')->nullable();

            // Statuts
            $table->string('status')->index();
            $table->string('invitation_status');
            $table->string('availability');
            $table->string('previous_availability')->nullable();
            $table->text('suspension_reason')->nullable();
            $table->date('last_activity_on')->nullable();
            $table->unsignedInteger('recruited_total')->default(0);
            // Dernier mois où le commercial figure au classement (suspendu, désactivé ou archivé) ; null s’il est en activité.
            $table->string('ranking_until', 7)->nullable();

            // Rémunération (mode, fixe, commissions, bonus, plafond…) et accès (rôle, permissions, sessions…)
            $table->json('pay');
            $table->json('access');

            // Corrections administratives du nombre de profils validés, par mois (« 2026-09 » => -2)
            $table->json('score_adjustments')->nullable();
            // Statut des lignes de gain mensuelles modifié à la main (référence => statut)
            $table->json('payout_statuses')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales_agents');
    }
};
