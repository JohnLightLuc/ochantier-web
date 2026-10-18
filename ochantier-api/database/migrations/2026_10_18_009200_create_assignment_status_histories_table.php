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
        Schema::create('assignment_status_histories', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('assignment_id')->constrained('assignments')->cascadeOnDelete();
            $table->string('from_status', 15)->nullable();
            $table->string('to_status', 15);
            $table->text('reason')->nullable();
            $table->string('actor_type', 20);
            $table->uuid('actor_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['assignment_id', 'created_at'], 'ix_assignment_status_histories');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assignment_status_histories');
    }
};
