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
        Schema::create('matching_exclusions', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('need_id')->constrained('needs');
            $table->foreignUuid('worker_id')->constrained('workers');
            $table->foreignUuid('matching_run_id')->nullable()->constrained('matching_runs');
            $table->string('nature', 15);
            $table->string('criterion', 40)->nullable()->comment('metier, disponibilite, distance, tarif, experience, kyc…');
            $table->foreignUuid('reason_id')->nullable()->constrained('reason_codes');
            $table->text('comment')->nullable();
            $table->text('corrective_action')->nullable();
            $table->string('decided_by_type', 10);
            $table->foreignUuid('decided_by_admin_id')->nullable()->constrained('admins');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['need_id', 'worker_id'], 'ix_matching_exclusions_need');
            $table->comment('Ouvrier écarté d\'un besoin, avec la nature et le motif de l\'exclusion');
        });

        DB::statement('ALTER TABLE matching_exclusions ADD CONSTRAINT matching_exclusions_nature_check CHECK (nature IN (\'reglementaire\',\'operationnelle\',\'manuelle\'))');
        DB::statement('ALTER TABLE matching_exclusions ADD CONSTRAINT matching_exclusions_decided_by_type_check CHECK (decided_by_type IN (\'systeme\',\'admin\'))');
        DB::statement('ALTER TABLE matching_exclusions ADD CONSTRAINT matching_exclusions_check1 CHECK (decided_by_type = \'systeme\' OR decided_by_admin_id IS NOT NULL)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('matching_exclusions');
    }
};
