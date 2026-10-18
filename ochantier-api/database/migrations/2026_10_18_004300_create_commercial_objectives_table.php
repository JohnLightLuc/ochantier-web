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
        Schema::create('commercial_objectives', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('commercial_id')->constrained('commercial_profiles');
            $table->date('period_start');
            $table->date('period_end');
            $table->string('indicator', 30);
            $table->integer('target_value');
            $table->integer('achieved_value')->default(0)->comment('valeur calculée (cache)');
            $table->text('comment')->nullable();
            $table->foreignUuid('set_by_admin_id')->nullable()->constrained('admins');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->unique(['commercial_id', 'period_start', 'period_end', 'indicator']);
        });

        DB::statement('ALTER TABLE commercial_objectives ADD CONSTRAINT commercial_objectives_indicator_check CHECK (indicator IN (\'prospects\',\'dossiers_complets\',\'ouvriers_valides\',\'ouvriers_affectes\'))');
        DB::statement('ALTER TABLE commercial_objectives ADD CONSTRAINT commercial_objectives_target_value_check CHECK (target_value > 0)');
        DB::statement('ALTER TABLE commercial_objectives ADD CONSTRAINT commercial_objectives_check1 CHECK (period_end >= period_start)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('commercial_objectives');
    }
};
