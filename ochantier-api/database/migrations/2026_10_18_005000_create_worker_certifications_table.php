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
        Schema::create('worker_certifications', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('worker_id')->constrained('workers')->cascadeOnDelete();
            $table->foreignUuid('trade_id')->nullable()->constrained('trades');
            $table->string('title', 200);
            $table->string('organization', 200)->nullable();
            $table->date('obtained_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->foreignUuid('document_id')->nullable()->constrained('documents');
            $table->timestampTz('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('worker_certifications');
    }
};
