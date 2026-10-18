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
        Schema::create('otp_codes', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('authenticatable_type', 20);
            $table->uuid('authenticatable_id')->nullable();
            $table->string('channel', 10);
            $table->string('destination', 160);
            $table->string('purpose', 30);
            $table->string('code_hash', 255);
            $table->smallInteger('attempts')->default(0);
            $table->smallInteger('max_attempts')->default(5);
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement('ALTER TABLE otp_codes ADD CONSTRAINT otp_codes_authenticatable_type_check CHECK (authenticatable_type IN (\'admin\',\'user\'))');
        DB::statement('ALTER TABLE otp_codes ADD CONSTRAINT otp_codes_channel_check CHECK (channel IN (\'sms\',\'email\'))');
        DB::statement('ALTER TABLE otp_codes ADD CONSTRAINT otp_codes_purpose_check CHECK (purpose IN (\'activation\',\'login\',\'password_reset\',\'sensitive_action\',\'phone_change\',\'worker_phone_check\'))');
        DB::statement('CREATE INDEX ix_otp_codes_destination ON otp_codes (destination, purpose, created_at DESC)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('otp_codes');
    }
};
