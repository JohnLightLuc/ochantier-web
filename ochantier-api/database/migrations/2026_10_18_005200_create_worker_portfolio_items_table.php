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
        Schema::create('worker_portfolio_items', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('worker_id')->constrained('workers')->cascadeOnDelete();
            $table->foreignUuid('media_file_id')->constrained('media_files');
            $table->string('caption', 255)->nullable();
            $table->boolean('diffusion_consent')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('deleted_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('worker_portfolio_items');
    }
};
