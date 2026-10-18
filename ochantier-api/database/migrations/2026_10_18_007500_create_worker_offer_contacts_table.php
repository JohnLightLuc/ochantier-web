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
        Schema::create('worker_offer_contacts', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('suggestion_id')->constrained('suggestions');
            $table->foreignUuid('worker_id')->constrained('workers');
            $table->foreignUuid('commercial_id')->nullable()->constrained('commercial_profiles');
            $table->string('channel', 15);
            $table->string('contacted_by_type', 20)->comment('user (commercial), admin, systeme');
            $table->uuid('contacted_by_id')->nullable();
            $table->timestampTz('contacted_at')->useCurrent();
            $table->timestampTz('response_deadline_at')->nullable();
            $table->smallInteger('reminder_count')->default(0);
            $table->timestampTz('last_reminder_at')->nullable();
            $table->string('status', 15)->default('en_attente');
            $table->uuid('client_uuid')->nullable()->unique();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['commercial_id', 'status'], 'ix_worker_offer_contacts_commercial');
            $table->comment('Contact de l\'ouvrier : appel du commercial ou notification (pas d\'application en V1)');
        });

        DB::statement('ALTER TABLE worker_offer_contacts ADD CONSTRAINT worker_offer_contacts_channel_check CHECK (channel IN (\'appel\',\'sms\',\'notification\',\'whatsapp\',\'visite\',\'application\'))');
        DB::statement('ALTER TABLE worker_offer_contacts ADD CONSTRAINT worker_offer_contacts_status_check CHECK (status IN (\'en_attente\',\'repondu\',\'expire\',\'annule\'))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('worker_offer_contacts');
    }
};
