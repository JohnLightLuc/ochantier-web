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
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('category', 40);
            $table->string('channel', 10);
            $table->boolean('enabled')->default(true);
            $table->primary(['user_id', 'category', 'channel']);
        });

        DB::statement('ALTER TABLE notification_preferences ADD CONSTRAINT notification_preferences_channel_check CHECK (channel IN (\'push\',\'sms\',\'email\'))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
