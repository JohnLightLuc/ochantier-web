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
        Schema::create('prospect_followups', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('prospect_id')->constrained('prospects')->cascadeOnDelete();
            $table->foreignUuid('commercial_id')->constrained('commercial_profiles');
            $table->string('channel', 15);
            $table->string('subject', 200)->nullable();
            $table->timestampTz('planned_at');
            $table->timestampTz('done_at')->nullable();
            $table->text('result')->nullable();
            $table->uuid('client_uuid')->nullable()->unique();
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement('ALTER TABLE prospect_followups ADD CONSTRAINT prospect_followups_channel_check CHECK (channel IN (\'appel\',\'sms\',\'whatsapp\',\'visite\'))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('prospect_followups');
    }
};
