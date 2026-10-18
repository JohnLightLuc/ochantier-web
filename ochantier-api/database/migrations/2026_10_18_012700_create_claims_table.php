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
        Schema::create('claims', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('reference', 25)->unique();
            $table->string('claimant_type', 30);
            $table->uuid('claimant_id');
            $table->string('other_party_type', 30)->nullable();
            $table->uuid('other_party_id')->nullable();
            $table->foreignUuid('site_id')->nullable()->constrained('sites');
            $table->foreignUuid('mission_id')->nullable()->constrained('missions');
            $table->foreignUuid('assignment_id')->nullable()->constrained('assignments');
            $table->foreignUuid('rating_id')->nullable()->constrained('ratings');
            $table->string('category', 30);
            $table->string('subject', 200);
            $table->text('description');
            $table->string('priority', 10)->default('normale');
            $table->foreignUuid('owner_admin_id')->nullable()->constrained('admins');
            $table->timestampTz('due_at')->nullable();
            $table->string('status', 15)->default('ouverte');
            $table->text('resolution')->nullable();
            $table->foreignUuid('dispute_id')->nullable()->constrained('disputes');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->index(['status', 'priority', 'due_at'], 'ix_claims_queue');
            $table->comment('Réclamation : demande simple traitée par le support ; escaladée en litige si besoin');
        });

        DB::statement('ALTER TABLE claims ADD CONSTRAINT claims_priority_check CHECK (priority IN (\'basse\',\'normale\',\'haute\',\'critique\'))');
        DB::statement('ALTER TABLE claims ADD CONSTRAINT claims_status_check CHECK (status IN (\'ouverte\',\'en_traitement\',\'resolue\',\'escaladee\',\'rejetee\',\'close\'))');
        DB::statement('ALTER TABLE claims ADD CONSTRAINT claims_check1 CHECK (status <> \'escaladee\' OR dispute_id IS NOT NULL)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('claims');
    }
};
