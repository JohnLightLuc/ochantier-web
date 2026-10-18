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
        Schema::create('verification_corrections', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('review_id')->constrained('verification_reviews')->cascadeOnDelete();
            $table->string('target_field', 80)->nullable()->comment('champ concerné');
            $table->foreignUuid('document_id')->nullable()->constrained('documents');
            $table->text('reason');
            $table->string('priority', 10)->default('normale');
            $table->timestampTz('due_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->text('resolution_comment')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement('ALTER TABLE verification_corrections ADD CONSTRAINT verification_corrections_priority_check CHECK (priority IN (\'basse\',\'normale\',\'haute\'))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('verification_corrections');
    }
};
