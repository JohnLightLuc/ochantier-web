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
        Schema::create('matching_cycles', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('reference', 25)->unique();
            $table->string('scope', 10);
            $table->jsonb('filters')->default('{}');
            $table->foreignUuid('weight_set_id')->constrained('matching_weight_sets');
            $table->foreignUuid('triggered_by_admin_id')->nullable()->comment('NULL si planifié')->constrained('admins');
            $table->string('status', 10)->default('en_cours');
            $table->timestampTz('started_at')->useCurrent();
            $table->timestampTz('finished_at')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->jsonb('stats')->default('{}')->comment('besoins, ouvriers, recommandations, exclus, avertissements, erreurs');
            $table->comment('Cycle de matching global (plusieurs besoins, une zone ou un métier), avec ses statistiques');
        });

        DB::statement('ALTER TABLE matching_cycles ADD CONSTRAINT matching_cycles_scope_check CHECK (scope IN (\'global\',\'zone\',\'metier\',\'offre\'))');
        DB::statement('ALTER TABLE matching_cycles ADD CONSTRAINT matching_cycles_status_check CHECK (status IN (\'en_cours\',\'termine\',\'echoue\',\'annule\'))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('matching_cycles');
    }
};
