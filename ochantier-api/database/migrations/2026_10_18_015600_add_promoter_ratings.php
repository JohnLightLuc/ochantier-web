<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Évaluations depuis l’application Promoteur :
     * - ratings.site_id : chantier évalué ; la note « Équipe globale » d’un chantier en offre journalière n’a pas
     *   d’équipe contractuelle (teams), elle porte alors seulement sur le chantier ;
     * - satisfaction_tag : satisfaction choisie avec la note (« Travail soigné », « Délais non respectés »…),
     *   conservée aussi dans l’historique des versions.
     */
    public function up(): void
    {
        Schema::table('ratings', function (Blueprint $table) {
            $table->foreignUuid('site_id')->nullable()->comment('chantier évalué')->constrained('sites');
            $table->string('satisfaction_tag', 60)->nullable()->comment('satisfaction choisie avec la note');
        });

        Schema::table('rating_versions', function (Blueprint $table) {
            $table->string('satisfaction_tag', 60)->nullable();
        });

        DB::statement('ALTER TABLE ratings DROP CONSTRAINT ratings_check1');
        DB::statement("ALTER TABLE ratings ADD CONSTRAINT ratings_check1 CHECK ((rating_scope = 'individuel' AND mission_id IS NOT NULL AND assignment_id IS NOT NULL AND worker_id IS NOT NULL AND team_id IS NULL) OR (rating_scope = 'equipe' AND (team_id IS NOT NULL OR site_id IS NOT NULL) AND assignment_id IS NULL AND worker_id IS NULL))");
        DB::statement("CREATE UNIQUE INDEX uq_ratings_site_team ON ratings (site_id, author_user_id) WHERE rating_scope = 'equipe'");
        DB::statement('CREATE INDEX ix_ratings_author ON ratings (author_user_id, created_at)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS ix_ratings_author');
        DB::statement('DROP INDEX IF EXISTS uq_ratings_site_team');
        DB::statement("DELETE FROM ratings WHERE rating_scope = 'equipe' AND team_id IS NULL");
        DB::statement('ALTER TABLE ratings DROP CONSTRAINT ratings_check1');
        DB::statement("ALTER TABLE ratings ADD CONSTRAINT ratings_check1 CHECK ((rating_scope = 'individuel' AND mission_id IS NOT NULL AND assignment_id IS NOT NULL AND worker_id IS NOT NULL AND team_id IS NULL) OR (rating_scope = 'equipe' AND team_id IS NOT NULL AND assignment_id IS NULL AND worker_id IS NULL))");

        Schema::table('rating_versions', function (Blueprint $table) {
            $table->dropColumn('satisfaction_tag');
        });

        Schema::table('ratings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('site_id');
            $table->dropColumn('satisfaction_tag');
        });
    }
};
