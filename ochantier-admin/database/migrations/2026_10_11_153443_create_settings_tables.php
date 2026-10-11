<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('value');
            $table->timestamps();
        });

        Schema::create('setting_changes', function (Blueprint $table) {
            $table->id();
            $table->dateTime('occurred_at')->index();
            $table->string('author');
            $table->string('section');
            $table->string('action');
            $table->string('old_value')->nullable();
            $table->string('new_value')->nullable();
            $table->timestamps();
        });

        Schema::create('integrations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type');
            $table->string('status');
            $table->string('environment');
            $table->string('endpoint');
            $table->string('client_id');
            $table->text('secret');
            $table->date('expires_on');
            $table->unsignedInteger('latency')->default(0);
            $table->decimal('error_rate', 5, 1)->default(0);
            $table->dateTime('tested_at')->nullable();
            $table->boolean('test_ok')->default(true);
            $table->string('last_result')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('phone')->nullable()->after('email');
            $table->string('role')->default('Super administrateur')->after('phone');
            $table->string('status')->default('Actif')->after('role');
            $table->string('modules')->nullable()->after('status');
            $table->boolean('two_factor')->default(true)->after('modules');
            $table->dateTime('last_login_at')->nullable()->after('two_factor');
            $table->date('invitation_expires_on')->nullable()->after('last_login_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone', 'role', 'status', 'modules', 'two_factor', 'last_login_at', 'invitation_expires_on']);
        });
        Schema::dropIfExists('integrations');
        Schema::dropIfExists('setting_changes');
        Schema::dropIfExists('settings');
    }
};
