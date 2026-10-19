<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Proposition d’équipe (« Offre reçue ») : les ouvriers retenus par le matching pour une offre, présentés
     * ensemble au promoteur, qui les accepte (puis paie l’acompte) ou les refuse avec un motif. Chaque suggestion
     * présentée au promoteur est rattachée à sa proposition.
     */
    public function up(): void
    {
        Schema::create('proposals', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('reference', 20)->unique()->comment('PR-2026-0001');
            $table->foreignUuid('offer_id')->constrained('offers');
            $table->foreignUuid('site_id')->constrained('sites');
            $table->smallInteger('sequence')->comment('rang de la proposition pour l’offre');
            $table->string('status', 15)->default('a_valider');
            $table->integer('requested_count')->comment('ouvriers demandés');
            $table->integer('proposed_count')->comment('ouvriers proposés');
            $table->boolean('is_partial')->default(false);
            $table->bigInteger('daily_amount')->comment('coût journalier promoteur des ouvriers proposés (XOF)');
            $table->integer('days');
            $table->bigInteger('total_amount');
            $table->bigInteger('deposit_amount');
            $table->timestampTz('published_at')->useCurrent();
            $table->foreignUuid('published_by_admin_id')->nullable()->comment('NULL : publication automatique ou simulation')->constrained('admins');
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->foreignUuid('decided_by_user_id')->nullable()->constrained('users');
            $table->foreignUuid('refusal_reason_id')->nullable()->constrained('reason_codes');
            $table->text('refusal_comment')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->unique(['offer_id', 'sequence']);
            $table->comment('Proposition d’équipe présentée au promoteur pour une offre');
        });

        DB::statement("ALTER TABLE proposals ADD CONSTRAINT proposals_status_check CHECK (status IN ('a_valider','acceptee','refusee','expiree','annulee'))");
        DB::statement('ALTER TABLE proposals ADD CONSTRAINT proposals_counts_check CHECK (proposed_count > 0 AND proposed_count <= requested_count)');
        DB::statement("CREATE UNIQUE INDEX uq_proposals_pending ON proposals (offer_id) WHERE status = 'a_valider'");
        DB::statement('CREATE INDEX ix_proposals_site ON proposals (site_id, status)');
        DB::statement('CREATE SEQUENCE proposal_reference_seq START 1 OWNED BY proposals.reference');

        Schema::table('suggestions', function (Blueprint $table) {
            $table->foreignUuid('proposal_id')->nullable()->comment('proposition dans laquelle l’ouvrier a été présenté au promoteur')->constrained('proposals');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('suggestions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('proposal_id');
        });

        Schema::dropIfExists('proposals');
    }
};
