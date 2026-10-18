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
        Schema::create('promoter_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('user_id')->unique()->constrained('users');
            $table->string('profile_kind', 20);
            $table->foreignUuid('photo_media_id')->nullable()->constrained('media_files');
            $table->text('address')->nullable();
            $table->foreignUuid('commune_id')->nullable()->constrained('communes');
            $table->foreignUuid('neighborhood_id')->nullable()->constrained('neighborhoods');
            $table->string('billing_name', 200)->nullable();
            $table->text('billing_address')->nullable();
            $table->string('tax_identifier', 60)->nullable();
            $table->string('verification_status', 20)->default('brouillon');
            $table->timestampTz('validated_at')->nullable();
            $table->foreignUuid('validated_by_admin_id')->nullable()->constrained('admins');
            $table->text('suspended_reason')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->timestampTz('deleted_at')->nullable();
            $table->comment('Profil promoteur : un particulier n\'a pas besoin d\'organisation');
        });

        DB::statement('ALTER TABLE promoter_profiles ADD CONSTRAINT promoter_profiles_profile_kind_check CHECK (profile_kind IN (\'particulier\',\'technicien\',\'entreprise\'))');
        DB::statement('ALTER TABLE promoter_profiles ADD CONSTRAINT promoter_profiles_verification_status_check CHECK (verification_status IN (\'brouillon\',\'soumis\',\'en_controle\',\'a_corriger\',\'valide\',\'refuse\',\'suspendu\'))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('promoter_profiles');
    }
};
