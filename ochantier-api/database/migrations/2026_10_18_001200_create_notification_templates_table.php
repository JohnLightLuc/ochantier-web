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
        Schema::create('notification_templates', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('event_code', 80);
            $table->string('channel', 10);
            $table->string('locale', 5)->default('fr');
            $table->string('subject', 200)->nullable();
            $table->text('body');
            $table->jsonb('variables')->default('[]');
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->unique(['event_code', 'channel', 'locale']);
        });

        DB::statement('ALTER TABLE notification_templates ADD CONSTRAINT notification_templates_channel_check CHECK (channel IN (\'push\',\'sms\',\'email\',\'in_app\'))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_templates');
    }
};
