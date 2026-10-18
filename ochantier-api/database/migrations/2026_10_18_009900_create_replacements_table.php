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
        Schema::create('replacements', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('unavailability_request_id')->constrained('unavailability_requests');
            $table->foreignUuid('mission_id')->constrained('missions');
            $table->foreignUuid('original_assignment_id')->constrained('assignments');
            $table->foreignUuid('replacement_assignment_id')->nullable()->constrained('assignments');
            $table->foreignUuid('requested_by_user_id')->nullable()->constrained('users');
            $table->jsonb('requested_criteria')->default('{}')->comment('métier, expérience, coût, date souhaitée');
            $table->foreignUuid('proposed_suggestion_id')->nullable()->constrained('suggestions');
            $table->string('promoter_decision', 10)->default('en_attente');
            $table->text('promoter_decision_reason')->nullable();
            $table->timestampTz('promoter_decided_at')->nullable();
            $table->timestampTz('handover_at')->nullable()->comment('date et heure de relève');
            $table->text('handover_instructions')->nullable();
            $table->timestampTz('acknowledged_at')->nullable()->comment('accusé de réception du remplaçant');
            $table->jsonb('financial_impact')->nullable()->comment('jours réalisés, reliquat, différence à payer');
            $table->foreignUuid('amendment_document_id')->nullable()->comment('avenant (équipe contractuelle)')->constrained('documents');
            $table->string('status', 15)->default('signale');
            $table->text('cancellation_reason')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::statement('ALTER TABLE replacements ADD CONSTRAINT replacements_promoter_decision_check CHECK (promoter_decision IN (\'en_attente\',\'accepte\',\'refuse\'))');
        DB::statement('ALTER TABLE replacements ADD CONSTRAINT replacements_status_check CHECK (status IN (\'signale\',\'recherche\',\'propose\',\'valide\',\'prise_de_poste\',\'termine\',\'annule\'))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('replacements');
    }
};
