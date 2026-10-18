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
        Schema::create('broadcast_campaigns', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('title', 200);
            $table->jsonb('audience_filter');
            $table->string('channel', 10);
            $table->text('message');
            $table->string('status', 15)->default('brouillon');
            $table->foreignUuid('created_by_admin_id')->constrained('admins');
            $table->foreignUuid('approved_by_admin_id')->nullable()->constrained('admins');
            $table->timestampTz('scheduled_at')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->integer('recipients_count')->nullable();
            $table->integer('delivered_count')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement('ALTER TABLE broadcast_campaigns ADD CONSTRAINT broadcast_campaigns_channel_check CHECK (channel IN (\'push\',\'sms\',\'email\',\'in_app\'))');
        DB::statement('ALTER TABLE broadcast_campaigns ADD CONSTRAINT broadcast_campaigns_status_check CHECK (status IN (\'brouillon\',\'a_valider\',\'programmee\',\'envoyee\',\'annulee\'))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('broadcast_campaigns');
    }
};
