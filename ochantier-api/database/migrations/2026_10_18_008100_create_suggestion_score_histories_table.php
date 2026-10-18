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
        Schema::create('suggestion_score_histories', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('suggestion_id')->constrained('suggestions')->cascadeOnDelete();
            $table->decimal('old_score', 7, 3)->nullable();
            $table->decimal('new_score', 7, 3);
            $table->foreignUuid('old_weight_set_id')->nullable()->constrained('matching_weight_sets');
            $table->foreignUuid('new_weight_set_id')->nullable()->constrained('matching_weight_sets');
            $table->text('reason');
            $table->foreignUuid('recalculated_by_admin_id')->nullable()->constrained('admins');
            $table->timestampTz('created_at')->useCurrent();
            $table->comment('Recalcul d\'un score (nouveau barème ou donnée corrigée)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('suggestion_score_histories');
    }
};
