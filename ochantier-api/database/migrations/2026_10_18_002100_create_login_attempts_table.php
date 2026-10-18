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
        Schema::create('login_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('authenticatable_type', 20);
            $table->uuid('authenticatable_id')->nullable()->comment('nul si l\'identifiant est inconnu');
            $table->string('identifier', 160);
            $table->boolean('success');
            $table->string('failure_reason', 60)->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->string('device_id', 100)->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement('ALTER TABLE login_attempts ADD CONSTRAINT login_attempts_authenticatable_type_check CHECK (authenticatable_type IN (\'admin\',\'user\'))');
        DB::statement('CREATE INDEX ix_login_attempts_identifier ON login_attempts (identifier, created_at DESC)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('login_attempts');
    }
};
