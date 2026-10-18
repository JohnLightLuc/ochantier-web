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
        Schema::create('matching_weight_sets', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->integer('version')->unique();
            $table->jsonb('weights')->comment('{"metier":0.3,"disponibilite":0.2,"proximite":0.15,...}');
            $table->jsonb('exclusion_rules')->default('{}');
            $table->jsonb('fairness_rules')->default('{}');
            $table->text('reason')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestampTz('effective_from')->useCurrent();
            $table->foreignUuid('created_by_admin_id')->constrained('admins');
            $table->timestampTz('created_at')->useCurrent();
            $table->comment('Barème versionné (poids w1..w6, exclusions, règles d\'équité)');
        });

        DB::statement('CREATE UNIQUE INDEX uq_matching_weight_sets_active ON matching_weight_sets ((TRUE)) WHERE is_active');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('matching_weight_sets');
    }
};
