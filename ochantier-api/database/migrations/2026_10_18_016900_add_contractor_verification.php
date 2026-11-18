<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dossier de vérification d’un entrepreneur (« Dossier entreprise (KYB) », « Dossier indépendant (KYC) ») :
     * identité ou entreprise, coordonnées, activité (métiers, zone, expérience), pièces justificatives et statut de
     * contrôle par le Back Office. Les entrepreneurs déjà référencés sans compte (simulation des offres) sont
     * considérés comme vérifiés.
     */
    public function up(): void
    {
        Schema::table('contractors', function (Blueprint $table) {
            $table->string('legal_form', 40)->nullable()->comment('forme juridique (SARL, SA, GIE…) d’une entreprise');
            $table->string('rccm', 60)->nullable();
            $table->string('tax_identifier', 60)->nullable()->comment('identifiant fiscal (NCC)');
            $table->date('birth_date')->nullable()->comment('indépendant : date de naissance (majeur)');
            $table->foreignUuid('city_id')->nullable()->comment('ville principale d’intervention')->constrained('cities');
            $table->text('address')->nullable()->comment('adresse de l’indépendant ou du siège');
            $table->integer('experience_years')->nullable();
            $table->text('description')->nullable()->comment('présentation de l’activité');
            $table->string('verification_status', 20)->default('brouillon');
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('validated_at')->nullable();
            $table->foreignUuid('validated_by_admin_id')->nullable()->constrained('admins');
            $table->text('verification_reason')->nullable()->comment('motif du refus ou des corrections demandées');
            $table->text('suspended_reason')->nullable();
        });

        DB::statement("ALTER TABLE contractors ADD CONSTRAINT contractors_verification_status_check CHECK (verification_status IN ('brouillon','soumis','en_controle','a_corriger','valide','refuse'))");
        DB::statement('ALTER TABLE contractors ADD CONSTRAINT contractors_experience_years_check CHECK (experience_years BETWEEN 0 AND 60)');
        DB::statement("UPDATE contractors SET verification_status = 'valide', validated_at = created_at WHERE user_id IS NULL");

        Schema::create('contractor_trades', function (Blueprint $table) {
            $table->foreignUuid('contractor_id')->constrained('contractors')->cascadeOnDelete();
            $table->foreignUuid('trade_id')->constrained('trades');
            $table->primary(['contractor_id', 'trade_id']);
            $table->comment('Métiers exercés par un entrepreneur');
        });

        Schema::create('contractor_zones', function (Blueprint $table) {
            $table->foreignUuid('contractor_id')->constrained('contractors')->cascadeOnDelete();
            $table->foreignUuid('city_id')->constrained('cities');
            $table->primary(['contractor_id', 'city_id']);
            $table->comment('Villes d’intervention en plus de la ville principale');
        });

        DB::statement('ALTER TABLE document_types DROP CONSTRAINT document_types_audience_check');
        DB::statement("ALTER TABLE document_types ADD CONSTRAINT document_types_audience_check CHECK (audience IN ('worker','promoter','organization','commercial','contractor','site','offer','need','mission','subscription','dispute','claim','incident','ticket','other'))");
        DB::statement('ALTER TABLE verification_reviews DROP CONSTRAINT verification_reviews_subject_type_check');
        DB::statement("ALTER TABLE verification_reviews ADD CONSTRAINT verification_reviews_subject_type_check CHECK (subject_type IN ('worker','promoter_profile','organization','commercial_profile','contractor','site','offer','need'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("DELETE FROM verification_reviews WHERE subject_type = 'contractor'");
        DB::statement('ALTER TABLE verification_reviews DROP CONSTRAINT verification_reviews_subject_type_check');
        DB::statement("ALTER TABLE verification_reviews ADD CONSTRAINT verification_reviews_subject_type_check CHECK (subject_type IN ('worker','promoter_profile','organization','commercial_profile','site','offer','need'))");
        DB::statement("UPDATE document_types SET is_active = false, audience = 'other' WHERE audience = 'contractor'");
        DB::statement('ALTER TABLE document_types DROP CONSTRAINT document_types_audience_check');
        DB::statement("ALTER TABLE document_types ADD CONSTRAINT document_types_audience_check CHECK (audience IN ('worker','promoter','organization','commercial','site','offer','need','mission','subscription','dispute','claim','incident','ticket','other'))");

        Schema::dropIfExists('contractor_zones');
        Schema::dropIfExists('contractor_trades');

        Schema::table('contractors', function (Blueprint $table) {
            $table->dropConstrainedForeignId('validated_by_admin_id');
            $table->dropConstrainedForeignId('city_id');
            $table->dropColumn(['legal_form', 'rccm', 'tax_identifier', 'birth_date', 'address', 'experience_years', 'description', 'verification_status', 'submitted_at', 'validated_at', 'verification_reason', 'suspended_reason']);
        });
    }
};
