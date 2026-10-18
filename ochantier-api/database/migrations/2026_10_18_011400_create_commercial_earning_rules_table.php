<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('commercial_earning_rules', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('event_type', 30)->default('ouvrier_valide');
            $table->string('calculation', 15)->default('montant_fixe');
            $table->bigInteger('amount')->nullable();
            $table->decimal('rate', 7, 4)->nullable();
            $table->bigInteger('cap_amount')->nullable();
            $table->string('cap_period', 10)->nullable();
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_to')->nullable();
            $table->string('status', 25)->default('en_attente_approbation');
            $table->foreignUuid('created_by_admin_id')->constrained('admins');
            $table->foreignUuid('approved_by_admin_id')->nullable()->constrained('admins');
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement('ALTER TABLE commercial_earning_rules ADD CONSTRAINT commercial_earning_rules_event_type_check CHECK (event_type IN (\'ouvrier_valide\'))');
        DB::statement('ALTER TABLE commercial_earning_rules ADD CONSTRAINT commercial_earning_rules_calculation_check CHECK (calculation IN (\'montant_fixe\',\'pourcentage\'))');
        DB::statement('ALTER TABLE commercial_earning_rules ADD CONSTRAINT commercial_earning_rules_amount_check CHECK (amount > 0)');
        DB::statement('ALTER TABLE commercial_earning_rules ADD CONSTRAINT commercial_earning_rules_rate_check CHECK (rate > 0 AND rate <= 100)');
        DB::statement('ALTER TABLE commercial_earning_rules ADD CONSTRAINT commercial_earning_rules_cap_period_check CHECK (cap_period IN (\'semaine\',\'mois\'))');
        DB::statement('ALTER TABLE commercial_earning_rules ADD CONSTRAINT commercial_earning_rules_status_check CHECK (status IN (\'en_attente_approbation\',\'actif\',\'expire\',\'refuse\'))');
        DB::statement('ALTER TABLE commercial_earning_rules ADD CONSTRAINT commercial_earning_rules_check1 CHECK ((calculation = \'montant_fixe\' AND amount IS NOT NULL) OR (calculation = \'pourcentage\' AND rate IS NOT NULL))');
        DB::statement('ALTER TABLE commercial_earning_rules ADD CONSTRAINT commercial_earning_rules_check2 CHECK (approved_by_admin_id IS NULL OR approved_by_admin_id <> created_by_admin_id)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('commercial_earning_rules');
    }
};
