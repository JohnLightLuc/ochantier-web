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
        Schema::create('sites', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('reference', 20)->unique()->comment('CHT-2026-000123');
            $table->string('internal_reference', 60)->nullable()->comment('référence interne du promoteur');
            $table->foreignUuid('promoter_profile_id')->constrained('promoter_profiles');
            $table->foreignUuid('organization_id')->nullable()->comment('NULL pour un particulier')->constrained('organizations');
            $table->foreignUuid('created_by_user_id')->constrained('users');
            $table->string('label', 200);
            $table->text('description')->nullable();
            $table->foreignUuid('construction_type_id')->nullable()->constrained('construction_types');
            $table->foreignUuid('main_image_media_id')->nullable()->constrained('media_files');
            $table->string('priority', 10)->default('normale');
            $table->bigInteger('budget_amount')->nullable()->comment('budget global indicatif (XOF)');
            $table->text('address')->nullable();
            $table->foreignUuid('commune_id')->nullable()->constrained('communes');
            $table->foreignUuid('neighborhood_id')->nullable()->constrained('neighborhoods');
            $table->text('access_instructions')->nullable();
            $table->geography('location', subtype: 'point', srid: 4326)->nullable();
            $table->integer('geofence_radius_m')->default(200);
            $table->date('planned_start_date')->nullable();
            $table->integer('planned_duration_days')->nullable();
            $table->date('planned_end_date')->nullable();
            $table->time('work_start_time')->nullable();
            $table->time('work_end_time')->nullable();
            $table->string('status', 20)->default('brouillon');
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('validated_at')->nullable();
            $table->foreignUuid('validated_by_admin_id')->nullable()->constrained('admins');
            $table->foreignUuid('assigned_admin_id')->nullable()->constrained('admins');
            $table->timestampTz('closed_at')->nullable();
            $table->foreignUuid('closed_by_admin_id')->nullable()->constrained('admins');
            $table->text('cancellation_reason')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->uuid('client_uuid')->nullable()->unique();
            $table->integer('lock_version')->default(1);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->timestampTz('deleted_at')->nullable();
            $table->index(['status', 'submitted_at'], 'ix_sites_status');
        });

        DB::statement('ALTER TABLE sites ADD CONSTRAINT sites_priority_check CHECK (priority IN (\'basse\',\'normale\',\'haute\',\'critique\'))');
        DB::statement('ALTER TABLE sites ADD CONSTRAINT sites_budget_amount_check CHECK (budget_amount >= 0)');
        DB::statement('ALTER TABLE sites ADD CONSTRAINT sites_geofence_radius_m_check CHECK (geofence_radius_m > 0)');
        DB::statement('ALTER TABLE sites ADD CONSTRAINT sites_planned_duration_days_check CHECK (planned_duration_days > 0)');
        DB::statement('ALTER TABLE sites ADD CONSTRAINT sites_status_check CHECK (status IN (\'brouillon\',\'soumis\',\'recherche\',\'paiement\',\'confirme\',\'en_cours\', \'suspendu\',\'termine\',\'annule\'))');
        DB::statement('ALTER TABLE sites ADD CONSTRAINT sites_check1 CHECK (planned_end_date IS NULL OR planned_start_date IS NULL OR planned_end_date >= planned_start_date)');
        DB::statement('CREATE INDEX ix_sites_promoter ON sites (promoter_profile_id, status) WHERE deleted_at IS NULL');
        DB::statement('CREATE INDEX ix_sites_organization ON sites (organization_id) WHERE organization_id IS NOT NULL');
        DB::statement('CREATE INDEX ix_sites_location ON sites USING GIST (location)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sites');
    }
};
