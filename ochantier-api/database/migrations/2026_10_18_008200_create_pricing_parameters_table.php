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
        Schema::create('pricing_parameters', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->char('code', 1);
            $table->string('scope', 20)->default('global');
            $table->string('scope_value', 20)->nullable()->comment('ex. \'contractuelle\' si scope = offer_type');
            $table->uuid('scope_id')->nullable()->comment('trade / site / need');
            $table->decimal('value', 7, 4);
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_to')->nullable();
            $table->text('reason');
            $table->string('status', 25)->default('en_attente_approbation');
            $table->foreignUuid('created_by_admin_id')->constrained('admins');
            $table->foreignUuid('approved_by_admin_id')->nullable()->constrained('admins');
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->comment('Variables X (acompte), Y (commission promoteur), Z (commission ouvrier), R (seuil calendaire) : une ligne par valeur, jamais modifiée');
        });

        DB::statement('ALTER TABLE pricing_parameters ADD CONSTRAINT pricing_parameters_code_check CHECK (code IN (\'X\',\'Y\',\'Z\',\'R\'))');
        DB::statement('ALTER TABLE pricing_parameters ADD CONSTRAINT pricing_parameters_scope_check CHECK (scope IN (\'global\',\'offer_type\',\'subscription_plan\',\'organization\',\'trade\',\'site\',\'need\'))');
        DB::statement('ALTER TABLE pricing_parameters ADD CONSTRAINT pricing_parameters_value_check CHECK (value >= 0 AND value <= 100)');
        DB::statement('ALTER TABLE pricing_parameters ADD CONSTRAINT pricing_parameters_status_check CHECK (status IN (\'en_attente_approbation\',\'actif\',\'expire\',\'refuse\'))');
        DB::statement('ALTER TABLE pricing_parameters ADD CONSTRAINT pricing_parameters_check1 CHECK (effective_to IS NULL OR effective_to > effective_from)');
        DB::statement('ALTER TABLE pricing_parameters ADD CONSTRAINT pricing_parameters_check2 CHECK (approved_by_admin_id IS NULL OR approved_by_admin_id <> created_by_admin_id)');
        DB::statement('ALTER TABLE pricing_parameters ADD CONSTRAINT pricing_parameters_check3 CHECK ((scope = \'global\' AND scope_value IS NULL AND scope_id IS NULL) OR (scope = \'offer_type\' AND scope_value IS NOT NULL) OR (scope IN (\'subscription_plan\',\'organization\',\'trade\',\'site\',\'need\') AND scope_id IS NOT NULL))');
        DB::statement('CREATE INDEX ix_pricing_parameters_lookup ON pricing_parameters (code, scope, effective_from DESC) WHERE status = \'actif\'');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pricing_parameters');
    }
};
