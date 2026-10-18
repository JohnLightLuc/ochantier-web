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
        Schema::create('notification_rules', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('event_code', 80);
            $table->string('recipient_role', 40)->comment('promoteur, commercial, ouvrier, operations, finance…');
            $table->string('channel', 10);
            $table->foreignUuid('template_id')->nullable()->constrained('notification_templates');
            $table->integer('delay_minutes')->default(0);
            $table->boolean('is_critical')->default(false)->comment('non désactivable par l\'utilisateur');
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['event_code', 'recipient_role', 'channel']);
        });

        DB::statement('ALTER TABLE notification_rules ADD CONSTRAINT notification_rules_channel_check CHECK (channel IN (\'push\',\'sms\',\'email\',\'in_app\'))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_rules');
    }
};
