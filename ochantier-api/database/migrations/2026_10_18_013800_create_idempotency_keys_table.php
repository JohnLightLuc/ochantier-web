<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->string('scope', 60)->comment('endpoint ou opération');
            $table->string('key', 100);
            $table->string('actor_type', 20);
            $table->uuid('actor_id')->nullable();
            $table->char('request_hash', 64);
            $table->smallInteger('response_status')->nullable();
            $table->jsonb('response_body')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('expires_at');
            $table->primary(['scope', 'key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
