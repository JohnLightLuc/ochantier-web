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
        Schema::create('ratings', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('rating_scope', 10);
            $table->foreignUuid('mission_id')->nullable()->comment('obligatoire pour un avis individuel')->constrained('missions');
            $table->foreignUuid('assignment_id')->nullable()->constrained('assignments');
            $table->foreignUuid('worker_id')->nullable()->constrained('workers');
            $table->foreignUuid('team_id')->nullable()->constrained('teams');
            $table->foreignUuid('author_user_id')->constrained('users');
            $table->smallInteger('overall_score');
            $table->jsonb('criteria_scores')->default('{}')->comment('ponctualite, qualite, consignes, comportement, productivite / delais, coordination, livrables');
            $table->text('comment')->nullable();
            $table->boolean('recommends')->nullable();
            $table->boolean('publish_consent')->default(true);
            $table->string('verification_status', 12)->default('non_verifie');
            $table->boolean('fraud_suspected')->default(false);
            $table->text('response')->nullable()->comment('réponse publique à l\'avis');
            $table->timestampTz('response_at')->nullable();
            $table->string('status', 10)->default('brouillon');
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('editable_until')->nullable();
            $table->integer('version')->default(1);
            $table->timestampTz('reported_at')->nullable();
            $table->string('moderation_status', 10)->nullable();
            $table->foreignUuid('moderated_by_admin_id')->nullable()->constrained('admins');
            $table->text('moderation_reason')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::statement('ALTER TABLE ratings ADD CONSTRAINT ratings_rating_scope_check CHECK (rating_scope IN (\'individuel\',\'equipe\'))');
        DB::statement('ALTER TABLE ratings ADD CONSTRAINT ratings_overall_score_check CHECK (overall_score BETWEEN 1 AND 5)');
        DB::statement('ALTER TABLE ratings ADD CONSTRAINT ratings_verification_status_check CHECK (verification_status IN (\'non_verifie\',\'verifie\',\'suspect\'))');
        DB::statement('ALTER TABLE ratings ADD CONSTRAINT ratings_status_check CHECK (status IN (\'brouillon\',\'publie\',\'masque\'))');
        DB::statement('ALTER TABLE ratings ADD CONSTRAINT ratings_moderation_status_check CHECK (moderation_status IN (\'signale\',\'maintenu\',\'masque\'))');
        DB::statement('ALTER TABLE ratings ADD CONSTRAINT ratings_check1 CHECK ((rating_scope = \'individuel\' AND mission_id IS NOT NULL AND assignment_id IS NOT NULL AND worker_id IS NOT NULL AND team_id IS NULL) OR (rating_scope = \'equipe\' AND team_id IS NOT NULL AND assignment_id IS NULL AND worker_id IS NULL))');
        DB::statement('CREATE UNIQUE INDEX uq_ratings_individual ON ratings (assignment_id, author_user_id) WHERE rating_scope = \'individuel\'');
        DB::statement('CREATE UNIQUE INDEX uq_ratings_team ON ratings (team_id, author_user_id) WHERE rating_scope = \'equipe\'');
        DB::statement('CREATE INDEX ix_ratings_worker ON ratings (worker_id) WHERE status = \'publie\'');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ratings');
    }
};
