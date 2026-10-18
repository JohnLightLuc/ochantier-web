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
        Schema::create('needs', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('reference', 20)->unique();
            $table->uuid('offer_id');
            $table->foreignUuid('site_id')->constrained('sites');
            $table->string('offer_type', 15);
            $table->foreignUuid('trade_id')->constrained('trades');
            $table->foreignUuid('specialty_id')->nullable()->constrained('specialties');
            $table->string('role_label', 120)->nullable()->comment('rôle dans l\'équipe (offre contractuelle)');
            $table->boolean('is_team_lead')->default(false);
            $table->integer('headcount');
            $table->smallInteger('min_experience_years')->nullable();
            $table->string('required_level', 15)->nullable();
            $table->string('title', 200);
            $table->text('description');
            $table->text('expected_results')->nullable();
            $table->text('instructions')->nullable();
            $table->text('equipment_required')->nullable();
            $table->text('constraints')->nullable();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->integer('duration_days');
            $table->rawColumn('work_days', 'smallint[]')->default(DB::raw('ARRAY[1,2,3,4,5,6]::SMALLINT[]'))->comment('ISO : 1 = lundi');
            $table->time('work_start_time')->nullable();
            $table->time('work_end_time')->nullable();
            $table->string('payment_frequency', 15);
            $table->bigInteger('indicative_daily_budget')->nullable()->comment('indicatif, non contractuel');
            $table->bigInteger('salary_amount')->nullable()->comment('contractuel : salaire par période (XOF)');
            $table->string('salary_period', 10)->nullable();
            $table->string('status', 25)->default('brouillon');
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->foreignUuid('assigned_admin_id')->nullable()->constrained('admins');
            $table->uuid('duplicated_from_need_id')->nullable();
            $table->foreignUuid('created_by_user_id')->constrained('users');
            $table->text('cancellation_reason')->nullable();
            $table->uuid('client_uuid')->nullable()->unique();
            $table->integer('lock_version')->default(1);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->timestampTz('deleted_at')->nullable();
            $table->foreign(['offer_id', 'site_id', 'offer_type'])->references(['id', 'site_id', 'offer_type'])->on('offers')->cascadeOnUpdate();
            $table->index(['status', 'submitted_at'], 'ix_needs_queue');
            $table->index(['trade_id'], 'ix_needs_trade');
            $table->comment('Besoin métier : une ligne de l\'offre (un métier, un effectif, une rémunération)');
        });

        Schema::table('needs', function (Blueprint $table) {
            $table->foreign('duplicated_from_need_id')->references('id')->on('needs');
        });

        DB::statement('ALTER TABLE needs ADD CONSTRAINT needs_headcount_check CHECK (headcount > 0)');
        DB::statement('ALTER TABLE needs ADD CONSTRAINT needs_min_experience_years_check CHECK (min_experience_years >= 0)');
        DB::statement('ALTER TABLE needs ADD CONSTRAINT needs_required_level_check CHECK (required_level IN (\'debutant\',\'intermediaire\',\'confirme\',\'expert\'))');
        DB::statement('ALTER TABLE needs ADD CONSTRAINT needs_duration_days_check CHECK (duration_days > 0)');
        DB::statement('ALTER TABLE needs ADD CONSTRAINT needs_payment_frequency_check CHECK (payment_frequency IN (\'journalier\',\'hebdomadaire\',\'mensuel\',\'jalon\'))');
        DB::statement('ALTER TABLE needs ADD CONSTRAINT needs_indicative_daily_budget_check CHECK (indicative_daily_budget > 0)');
        DB::statement('ALTER TABLE needs ADD CONSTRAINT needs_salary_amount_check CHECK (salary_amount > 0)');
        DB::statement('ALTER TABLE needs ADD CONSTRAINT needs_salary_period_check CHECK (salary_period IN (\'jour\',\'semaine\',\'mois\',\'forfait\'))');
        DB::statement('ALTER TABLE needs ADD CONSTRAINT needs_status_check CHECK (status IN (\'brouillon\',\'soumis\',\'en_controle\',\'a_corriger\',\'matching\',\'contact_ouvriers\', \'proposition_promoteur\',\'selection\',\'paiement_en_attente\',\'confirme\', \'en_cours\',\'suspendu\',\'termine\',\'annule\'))');
        DB::statement('ALTER TABLE needs ADD CONSTRAINT needs_check1 CHECK (end_date IS NULL OR end_date >= start_date)');
        DB::statement('ALTER TABLE needs ADD CONSTRAINT needs_check2 CHECK (offer_type <> \'contractuelle\' OR (salary_amount IS NOT NULL AND salary_period IS NOT NULL))');
        DB::statement('ALTER TABLE needs ADD CONSTRAINT needs_check3 CHECK (offer_type = \'contractuelle\' OR (salary_amount IS NULL AND payment_frequency <> \'jalon\'))');
        DB::statement('CREATE INDEX ix_needs_offer ON needs (offer_id) WHERE deleted_at IS NULL');
        DB::statement('CREATE INDEX ix_needs_site ON needs (site_id) WHERE deleted_at IS NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('needs');
    }
};
