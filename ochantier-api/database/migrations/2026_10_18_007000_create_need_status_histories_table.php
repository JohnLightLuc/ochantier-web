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
        Schema::create('need_status_histories', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('need_id')->constrained('needs')->cascadeOnDelete();
            $table->string('from_status', 25)->nullable();
            $table->string('to_status', 25);
            $table->text('reason')->nullable();
            $table->string('actor_type', 20);
            $table->uuid('actor_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['need_id', 'created_at'], 'ix_need_status_histories');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('need_status_histories');
    }
};
