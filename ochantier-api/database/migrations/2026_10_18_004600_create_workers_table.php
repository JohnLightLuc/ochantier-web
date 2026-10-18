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
        Schema::create('workers', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('reference', 20)->unique()->comment('identifiant lisible (OUV-000123)');
            $table->foreignUuid('user_id')->nullable()->unique()->constrained('users');
            $table->foreignUuid('origin_commercial_id')->constrained('commercial_profiles');
            $table->foreignUuid('current_commercial_id')->constrained('commercial_profiles');
            $table->foreignUuid('prospect_id')->nullable()->constrained('prospects');
            $table->string('first_name', 80);
            $table->string('last_name', 80);
            $table->date('birth_date')->nullable();
            $table->string('birth_place', 120)->nullable();
            $table->char('gender', 1)->nullable();
            $table->foreignUuid('nationality_country_id')->nullable()->constrained('countries');
            $table->foreignUuid('photo_media_id')->nullable()->constrained('media_files');
            $table->string('phone', 20);
            $table->timestampTz('phone_verified_at')->nullable();
            $table->string('secondary_phone', 20)->nullable();
            $table->string('emergency_contact_name', 160)->nullable();
            $table->string('emergency_contact_relation', 60)->nullable();
            $table->string('emergency_contact_phone', 20)->nullable();
            $table->foreignUuid('residence_commune_id')->nullable()->constrained('communes');
            $table->foreignUuid('residence_neighborhood_id')->nullable()->constrained('neighborhoods');
            $table->text('residence_address')->nullable();
            $table->text('residence_landmark')->nullable();
            $table->geography('residence_location', subtype: 'point', srid: 4326)->nullable();
            $table->smallInteger('mobility_radius_km')->nullable();
            $table->boolean('accepts_long_travel')->default(false);
            $table->boolean('needs_accommodation')->default(false);
            $table->string('transport_mode', 30)->nullable();
            $table->boolean('works_solo')->default(true);
            $table->boolean('works_in_team')->default(true);
            $table->string('acquisition_channel', 30)->nullable()->comment('terrain, recommandation, reseau_social, campagne…');
            $table->bigInteger('estimated_daily_rate')->nullable()->comment('XOF, prix fixé par l\'ouvrier');
            $table->date('estimated_daily_rate_at')->nullable();
            $table->rawColumn('accepted_offer_types', 'varchar(20)[]')->default(DB::raw('ARRAY[\'ponctuelle\',\'temporaire\',\'contractuelle\']::VARCHAR(20)[]'));
            $table->rawColumn('accepted_payment_frequencies', 'varchar(20)[]')->default(DB::raw('ARRAY[\'journalier\',\'hebdomadaire\',\'mensuel\',\'jalon\']::VARCHAR(20)[]'));
            $table->jsonb('preferred_schedule')->default('{}')->comment('jours, plages, nuit, week-end');
            $table->string('verification_status', 20)->default('brouillon');
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('validated_at')->nullable();
            $table->foreignUuid('validated_by_admin_id')->nullable()->constrained('admins');
            $table->timestampTz('suspended_until')->nullable();
            $table->string('availability_status', 15)->default('disponible');
            $table->date('available_from')->nullable();
            $table->decimal('rating_avg', 3, 2)->nullable();
            $table->integer('rating_count')->default(0);
            $table->integer('missions_completed')->default(0);
            $table->decimal('attendance_rate', 5, 2)->nullable();
            $table->decimal('acceptance_rate', 5, 2)->nullable();
            $table->integer('replacement_count')->default(0);
            $table->timestampTz('last_assignment_at')->nullable()->comment('utilisé pour la rotation équitable');
            $table->uuid('merged_into_worker_id')->nullable();
            $table->boolean('identity_mismatch')->default(false)->comment('écart pièce / saisie signalé au contrôle KYC');
            $table->uuid('client_uuid')->nullable()->unique();
            $table->integer('lock_version')->default(1);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->timestampTz('deleted_at')->nullable();
            $table->index(['current_commercial_id'], 'ix_workers_commercial');
            $table->index(['last_name', 'first_name'], 'ix_workers_name');
            $table->comment('Ouvrier : sans compte en V1 (user_id nul), compte de l\'application Ouvrier en V2');
        });

        Schema::table('workers', function (Blueprint $table) {
            $table->foreign('merged_into_worker_id')->references('id')->on('workers');
        });

        DB::statement('ALTER TABLE workers ADD CONSTRAINT workers_gender_check CHECK (gender IN (\'M\',\'F\'))');
        DB::statement('ALTER TABLE workers ADD CONSTRAINT workers_mobility_radius_km_check CHECK (mobility_radius_km >= 0)');
        DB::statement('ALTER TABLE workers ADD CONSTRAINT workers_estimated_daily_rate_check CHECK (estimated_daily_rate > 0)');
        DB::statement('ALTER TABLE workers ADD CONSTRAINT workers_verification_status_check CHECK (verification_status IN (\'brouillon\',\'soumis\',\'en_controle\',\'a_corriger\',\'valide\',\'refuse\',\'suspendu\',\'exclu\'))');
        DB::statement('ALTER TABLE workers ADD CONSTRAINT workers_availability_status_check CHECK (availability_status IN (\'disponible\',\'occupe\',\'indisponible\'))');
        DB::statement('ALTER TABLE workers ADD CONSTRAINT workers_rating_avg_check CHECK (rating_avg BETWEEN 1 AND 5)');
        DB::statement('CREATE UNIQUE INDEX uq_workers_phone ON workers (phone) WHERE deleted_at IS NULL AND merged_into_worker_id IS NULL');
        DB::statement('CREATE INDEX ix_workers_matching ON workers (verification_status, availability_status) WHERE deleted_at IS NULL');
        DB::statement('CREATE INDEX ix_workers_location ON workers USING GIST (residence_location)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workers');
    }
};
