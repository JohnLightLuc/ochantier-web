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
        Schema::create('rating_versions', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('rating_id')->constrained('ratings')->cascadeOnDelete();
            $table->integer('version');
            $table->smallInteger('overall_score');
            $table->jsonb('criteria_scores');
            $table->text('comment')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['rating_id', 'version']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rating_versions');
    }
};
