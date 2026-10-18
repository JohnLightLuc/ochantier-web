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
        Schema::create('consents', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('subject_type', 30)->comment('user, worker, prospect');
            $table->uuid('subject_id');
            $table->string('consent_type', 30);
            $table->foreignUuid('legal_document_id')->nullable()->constrained('legal_documents');
            $table->boolean('granted');
            $table->timestampTz('granted_at')->useCurrent();
            $table->timestampTz('revoked_at')->nullable();
            $table->string('recorded_by_type', 20)->nullable()->comment('user (commercial), admin, worker…');
            $table->uuid('recorded_by_id')->nullable();
            $table->string('channel', 20)->nullable()->comment('application, terrain, appel');
            $table->foreignUuid('proof_media_id')->nullable()->constrained('media_files');
            $table->foreignUuid('signature_media_id')->nullable()->constrained('media_files');
            $table->string('context_type', 30)->nullable()->comment('ex. mission (acceptation des conditions financières)');
            $table->uuid('context_id')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->string('device_id', 100)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['subject_type', 'subject_id', 'consent_type'], 'ix_consents_subject');
            $table->comment('Consentements horodatés et versionnés (utilisateurs ET ouvriers sans compte)');
        });

        DB::statement('ALTER TABLE consents ADD CONSTRAINT consents_consent_type_check CHECK (consent_type IN (\'cgu\',\'privacy\',\'data_processing\',\'photo_usage\',\'financial_terms\', \'contact\',\'geolocation\',\'reference_contact\'))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('consents');
    }
};
