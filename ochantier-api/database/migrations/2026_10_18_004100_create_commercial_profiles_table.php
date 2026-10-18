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
        Schema::create('commercial_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('user_id')->unique()->constrained('users');
            $table->string('employee_number', 30)->unique()->comment('matricule');
            $table->uuid('supervisor_id')->nullable();
            $table->boolean('is_supervisor')->default(false);
            $table->foreignUuid('photo_media_id')->nullable()->constrained('media_files');
            $table->text('address')->nullable();
            $table->string('secondary_phone', 20)->nullable();
            $table->string('verification_status', 20)->default('incomplet');
            $table->string('status', 20)->default('actif');
            $table->date('started_at')->nullable();
            $table->date('ended_at')->nullable();
            $table->foreignUuid('created_by_admin_id')->nullable()->constrained('admins');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->timestampTz('deleted_at')->nullable();
        });

        Schema::table('commercial_profiles', function (Blueprint $table) {
            $table->foreign('supervisor_id')->references('id')->on('commercial_profiles');
        });

        DB::statement('ALTER TABLE commercial_profiles ADD CONSTRAINT commercial_profiles_verification_status_check CHECK (verification_status IN (\'incomplet\',\'soumis\',\'a_corriger\',\'valide\',\'suspendu\'))');
        DB::statement('ALTER TABLE commercial_profiles ADD CONSTRAINT commercial_profiles_status_check CHECK (status IN (\'actif\',\'suspendu\',\'termine\'))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('commercial_profiles');
    }
};
