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
        Schema::create('documents', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('owner_type', 30)->comment('worker, promoter_profile, organization, commercial_profile, site, need, mission…');
            $table->uuid('owner_id');
            $table->foreignUuid('document_type_id')->constrained('document_types');
            $table->string('label', 200)->nullable();
            $table->text('number_encrypted')->nullable()->comment('numéro de pièce chiffré');
            $table->string('number_last4', 4)->nullable();
            $table->string('issuing_authority', 160)->nullable();
            $table->date('issued_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->integer('version')->default(1);
            $table->uuid('replaces_document_id')->nullable();
            $table->string('verification_status', 20)->default('en_attente');
            $table->foreignUuid('verified_by_admin_id')->nullable()->constrained('admins');
            $table->timestampTz('verified_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->string('visibility', 20)->default('interne');
            $table->string('uploaded_by_type', 20)->nullable();
            $table->uuid('uploaded_by_id')->nullable();
            $table->uuid('client_uuid')->nullable()->unique();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->timestampTz('deleted_at')->nullable();
            $table->comment('Documents typés et versionnés (KYC, plans, devis, preuves…)');
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->foreign('replaces_document_id')->references('id')->on('documents');
        });

        DB::statement('ALTER TABLE documents ADD CONSTRAINT documents_verification_status_check CHECK (verification_status IN (\'en_attente\',\'valide\',\'rejete\',\'expire\',\'remplace\'))');
        DB::statement('ALTER TABLE documents ADD CONSTRAINT documents_visibility_check CHECK (visibility IN (\'interne\',\'promoteur_mission\',\'public\'))');
        DB::statement('ALTER TABLE documents ADD CONSTRAINT documents_check1 CHECK (expires_at IS NULL OR issued_at IS NULL OR expires_at >= issued_at)');
        DB::statement('CREATE INDEX ix_documents_owner ON documents (owner_type, owner_id) WHERE deleted_at IS NULL');
        DB::statement('CREATE INDEX ix_documents_expiry ON documents (expires_at) WHERE expires_at IS NOT NULL AND deleted_at IS NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
