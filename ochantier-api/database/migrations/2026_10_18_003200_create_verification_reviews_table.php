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
        Schema::create('verification_reviews', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('subject_type', 30);
            $table->uuid('subject_id');
            $table->integer('submission_number')->default(1);
            $table->string('submitted_by_type', 20);
            $table->uuid('submitted_by_id');
            $table->timestampTz('submitted_at')->useCurrent();
            $table->foreignUuid('assigned_admin_id')->nullable()->constrained('admins');
            $table->string('status', 20)->default('soumis');
            $table->jsonb('checklist')->default('{}');
            $table->jsonb('snapshot')->nullable()->comment('état du dossier au moment de la soumission');
            $table->text('decision_reason')->nullable();
            $table->foreignUuid('decided_by_admin_id')->nullable()->constrained('admins');
            $table->timestampTz('decided_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->unique(['subject_type', 'subject_id', 'submission_number']);
            $table->index(['status', 'submitted_at'], 'ix_verification_reviews_queue');
            $table->comment('Cycle de contrôle générique : ouvrier, promoteur, organisation, chantier, besoin');
        });

        DB::statement('ALTER TABLE verification_reviews ADD CONSTRAINT verification_reviews_subject_type_check CHECK (subject_type IN (\'worker\',\'promoter_profile\',\'organization\',\'commercial_profile\',\'site\',\'offer\',\'need\'))');
        DB::statement('ALTER TABLE verification_reviews ADD CONSTRAINT verification_reviews_status_check CHECK (status IN (\'soumis\',\'en_controle\',\'a_corriger\',\'valide\',\'refuse\',\'annule\'))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('verification_reviews');
    }
};
