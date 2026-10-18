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
        Schema::create('organizations', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('owner_user_id')->constrained('users');
            $table->string('legal_name', 200);
            $table->string('trade_name', 200)->nullable();
            $table->string('legal_form', 40)->nullable()->comment('SARL, SA, SAS, EI…');
            $table->string('rccm', 60)->nullable()->comment('registre du commerce');
            $table->string('tax_identifier', 60)->nullable()->comment('NCC');
            $table->text('address')->nullable();
            $table->foreignUuid('commune_id')->nullable()->constrained('communes');
            $table->string('phone', 20)->nullable();
            $table->rawColumn('email', 'citext')->nullable();
            $table->string('legal_representative', 160)->nullable();
            $table->foreignUuid('logo_media_id')->nullable()->constrained('media_files');
            $table->string('billing_name', 200)->nullable();
            $table->text('billing_address')->nullable();
            $table->string('verification_status', 20)->default('brouillon');
            $table->timestampTz('validated_at')->nullable();
            $table->foreignUuid('validated_by_admin_id')->nullable()->constrained('admins');
            $table->text('suspended_reason')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->timestampTz('deleted_at')->nullable();
            $table->comment('Organisation promotrice (entreprise) : facultative');
        });

        DB::statement('ALTER TABLE organizations ADD CONSTRAINT organizations_verification_status_check CHECK (verification_status IN (\'brouillon\',\'soumis\',\'en_controle\',\'a_corriger\',\'valide\',\'refuse\',\'suspendu\'))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
