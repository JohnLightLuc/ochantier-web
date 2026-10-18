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
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('recipient_type', 20)->comment('admin, user, worker (SMS sans compte en V1)');
            $table->uuid('recipient_id');
            $table->string('event_code', 80);
            $table->string('channel', 10);
            $table->foreignUuid('template_id')->nullable()->constrained('notification_templates');
            $table->string('title', 200)->nullable();
            $table->text('body');
            $table->jsonb('data')->default('{}');
            $table->string('related_type', 30)->nullable();
            $table->uuid('related_id')->nullable();
            $table->string('priority', 10)->default('normale');
            $table->string('status', 10)->default('en_attente');
            $table->text('failure_reason')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('read_at')->nullable();
            $table->string('dedup_key', 150)->nullable()->unique()->comment('évite les notifications en double (ex. seuil R)');
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement('ALTER TABLE notifications ADD CONSTRAINT notifications_channel_check CHECK (channel IN (\'push\',\'sms\',\'email\',\'in_app\'))');
        DB::statement('ALTER TABLE notifications ADD CONSTRAINT notifications_priority_check CHECK (priority IN (\'basse\',\'normale\',\'haute\',\'critique\'))');
        DB::statement('ALTER TABLE notifications ADD CONSTRAINT notifications_status_check CHECK (status IN (\'en_attente\',\'envoyee\',\'livree\',\'echouee\'))');
        DB::statement('CREATE INDEX ix_notifications_recipient ON notifications (recipient_type, recipient_id, created_at DESC)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
