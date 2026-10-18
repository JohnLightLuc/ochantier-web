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
        Schema::create('commercial_ranking_entries', function (Blueprint $table) {
            $table->foreignUuid('ranking_id')->constrained('commercial_rankings')->cascadeOnDelete();
            $table->foreignUuid('commercial_id')->constrained('commercial_profiles');
            $table->integer('rank');
            $table->decimal('score', 8, 2);
            $table->jsonb('metrics')->default('{}')->comment('ouvriers créés, validés, affectés…');
            $table->primary(['ranking_id', 'commercial_id']);
            $table->unique(['ranking_id', 'rank']);
        });

        DB::statement('ALTER TABLE commercial_ranking_entries ADD CONSTRAINT commercial_ranking_entries_rank_check CHECK (rank > 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('commercial_ranking_entries');
    }
};
