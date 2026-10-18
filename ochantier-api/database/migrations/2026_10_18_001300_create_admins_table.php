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
        Schema::create('admins', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('first_name', 80);
            $table->string('last_name', 80);
            $table->rawColumn('email', 'citext');
            $table->string('phone', 20)->nullable();
            $table->string('department', 40)->nullable()->comment('operations, finance, support, direction…');
            $table->string('password_hash', 255)->nullable();
            $table->string('status', 20)->default('invite');
            $table->boolean('mfa_enabled')->default(true);
            $table->text('mfa_secret_encrypted')->nullable();
            $table->boolean('must_change_password')->default(true);
            $table->smallInteger('failed_login_count')->default(0);
            $table->timestampTz('locked_until')->nullable();
            $table->timestampTz('access_expires_at')->nullable()->comment('accès à durée limitée');
            $table->timestampTz('last_login_at')->nullable();
            $table->ipAddress('last_login_ip')->nullable();
            $table->jsonb('preferences')->default('{}')->comment('widgets du tableau de bord');
            $table->uuid('invited_by_admin_id')->nullable();
            $table->timestampTz('suspended_at')->nullable();
            $table->text('suspended_reason')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->timestampTz('deleted_at')->nullable();
            $table->comment('Administrateurs du Back Office (séparés des utilisateurs)');
        });

        Schema::table('admins', function (Blueprint $table) {
            $table->foreign('invited_by_admin_id')->references('id')->on('admins');
        });

        DB::statement('ALTER TABLE admins ADD CONSTRAINT admins_status_check CHECK (status IN (\'invite\',\'actif\',\'suspendu\',\'desactive\'))');
        DB::statement('CREATE UNIQUE INDEX uq_admins_email ON admins (email) WHERE deleted_at IS NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admins');
    }
};
