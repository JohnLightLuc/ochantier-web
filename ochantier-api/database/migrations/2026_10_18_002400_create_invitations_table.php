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
        Schema::create('invitations', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('authenticatable_type', 20);
            $table->uuid('authenticatable_id');
            $table->string('channel', 10);
            $table->char('token_hash', 64)->unique();
            $table->foreignUuid('invited_by_admin_id')->nullable()->constrained('admins');
            $table->foreignUuid('invited_by_user_id')->nullable()->comment('collaborateur invité par un promoteur')->constrained('users');
            $table->timestampTz('sent_at')->useCurrent();
            $table->timestampTz('expires_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
        });

        DB::statement('ALTER TABLE invitations ADD CONSTRAINT invitations_authenticatable_type_check CHECK (authenticatable_type IN (\'admin\',\'user\'))');
        DB::statement('ALTER TABLE invitations ADD CONSTRAINT invitations_channel_check CHECK (channel IN (\'sms\',\'email\'))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};
