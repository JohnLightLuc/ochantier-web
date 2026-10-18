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
        Schema::create('offer_milestones', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('offer_id')->constrained('offers')->cascadeOnDelete();
            $table->smallInteger('sequence');
            $table->string('label', 200);
            $table->text('description')->nullable();
            $table->bigInteger('amount')->nullable();
            $table->decimal('percent_of_total', 7, 4)->nullable();
            $table->date('due_date')->nullable();
            $table->string('status', 15)->default('prevu');
            $table->timestampTz('reached_at')->nullable();
            $table->foreignUuid('proof_document_id')->nullable()->constrained('documents');
            $table->foreignUuid('validated_by_admin_id')->nullable()->constrained('admins');
            $table->timestampTz('validated_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->unique(['offer_id', 'sequence']);
            $table->comment('Jalons d\'une offre contractuelle (paiement par jalon)');
        });

        DB::statement('ALTER TABLE offer_milestones ADD CONSTRAINT offer_milestones_amount_check CHECK (amount > 0)');
        DB::statement('ALTER TABLE offer_milestones ADD CONSTRAINT offer_milestones_percent_of_total_check CHECK (percent_of_total > 0 AND percent_of_total <= 100)');
        DB::statement('ALTER TABLE offer_milestones ADD CONSTRAINT offer_milestones_status_check CHECK (status IN (\'prevu\',\'atteint\',\'valide\',\'paye\',\'annule\'))');
        DB::statement('ALTER TABLE offer_milestones ADD CONSTRAINT offer_milestones_check1 CHECK (amount IS NOT NULL OR percent_of_total IS NOT NULL)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('offer_milestones');
    }
};
