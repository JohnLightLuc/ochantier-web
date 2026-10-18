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
        Schema::create('worker_duplicate_candidates', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('worker_id')->constrained('workers');
            $table->foreignUuid('duplicate_worker_id')->constrained('workers');
            $table->rawColumn('match_criteria', 'varchar(20)[]')->comment('telephone, piece, identite');
            $table->decimal('score', 5, 2)->nullable();
            $table->string('status', 15)->default('a_verifier');
            $table->foreignUuid('resolved_by_admin_id')->nullable()->constrained('admins');
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['worker_id', 'duplicate_worker_id']);
        });

        DB::statement('ALTER TABLE worker_duplicate_candidates ADD CONSTRAINT worker_duplicate_candidates_status_check CHECK (status IN (\'a_verifier\',\'confirme\',\'faux_positif\',\'fusionne\'))');
        DB::statement('ALTER TABLE worker_duplicate_candidates ADD CONSTRAINT worker_duplicate_candidates_check1 CHECK (worker_id <> duplicate_worker_id)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('worker_duplicate_candidates');
    }
};
