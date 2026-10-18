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
        Schema::create('auth_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('authenticatable_type', 20);
            $table->uuid('authenticatable_id');
            $table->char('access_token_hash', 64)->unique();
            $table->char('refresh_token_hash', 64)->nullable()->unique();
            $table->jsonb('abilities')->default('["*"]');
            $table->string('device_id', 100)->nullable();
            $table->string('device_name', 120)->nullable();
            $table->string('platform', 20)->nullable()->comment('android, ios, web');
            $table->string('app_name', 20)->nullable()->comment('promoteur, commercial, ouvrier, backoffice');
            $table->string('app_version', 20)->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestampTz('mfa_verified_at')->nullable();
            $table->timestampTz('last_activity_at')->useCurrent();
            $table->timestampTz('expires_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->string('revoked_reason', 80)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->comment('Sessions, tentatives, OTP, invitations (admins et utilisateurs)');
        });

        DB::statement('ALTER TABLE auth_sessions ADD CONSTRAINT auth_sessions_authenticatable_type_check CHECK (authenticatable_type IN (\'admin\',\'user\'))');
        DB::statement('CREATE INDEX ix_auth_sessions_owner ON auth_sessions (authenticatable_type, authenticatable_id) WHERE revoked_at IS NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('auth_sessions');
    }
};
