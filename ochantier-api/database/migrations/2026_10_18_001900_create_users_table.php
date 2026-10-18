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
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('user_type', 20);
            $table->string('first_name', 80);
            $table->string('last_name', 80);
            $table->string('phone', 20)->comment('format E.164');
            $table->timestampTz('phone_verified_at')->nullable();
            $table->rawColumn('email', 'citext')->nullable();
            $table->timestampTz('email_verified_at')->nullable();
            $table->string('password_hash', 255)->nullable();
            $table->string('status', 25)->default('en_attente_activation');
            $table->boolean('must_change_password')->default(false);
            $table->smallInteger('failed_login_count')->default(0);
            $table->timestampTz('locked_until')->nullable();
            $table->string('locale', 5)->default('fr');
            $table->jsonb('preferences')->default('{}');
            $table->timestampTz('last_login_at')->nullable();
            $table->foreignUuid('created_by_admin_id')->nullable()->comment('comptes commerciaux créés par le Back Office')->constrained('admins');
            $table->timestampTz('suspended_at')->nullable();
            $table->foreignUuid('suspended_by_admin_id')->nullable()->constrained('admins');
            $table->text('suspended_reason')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->timestampTz('deleted_at')->nullable();
            $table->comment('Utilisateurs des applications (promoteurs, commerciaux, ouvriers en V2)');
        });

        DB::statement('ALTER TABLE users ADD CONSTRAINT users_user_type_check CHECK (user_type IN (\'promoter\',\'commercial\',\'worker\'))');
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_status_check CHECK (status IN (\'en_attente_activation\',\'actif\',\'suspendu\',\'desactive\'))');
        DB::statement('CREATE UNIQUE INDEX uq_users_type_phone ON users (user_type, phone) WHERE deleted_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX uq_users_type_email ON users (user_type, email) WHERE email IS NOT NULL AND deleted_at IS NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
