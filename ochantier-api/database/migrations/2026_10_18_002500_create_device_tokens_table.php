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
        Schema::create('device_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('owner_type', 20);
            $table->uuid('owner_id');
            $table->string('platform', 10);
            $table->text('push_token');
            $table->string('device_id', 100)->nullable();
            $table->timestampTz('last_seen_at')->useCurrent();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['push_token']);
        });

        DB::statement('ALTER TABLE device_tokens ADD CONSTRAINT device_tokens_owner_type_check CHECK (owner_type IN (\'admin\',\'user\'))');
        DB::statement('ALTER TABLE device_tokens ADD CONSTRAINT device_tokens_platform_check CHECK (platform IN (\'android\',\'ios\',\'web\'))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('device_tokens');
    }
};
