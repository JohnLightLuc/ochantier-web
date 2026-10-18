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
        Schema::create('suggestions', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('matching_run_id')->constrained('matching_runs');
            $table->foreignUuid('need_id')->constrained('needs');
            $table->foreignUuid('worker_id')->constrained('workers');
            $table->integer('rank')->nullable();
            $table->decimal('score', 7, 3)->nullable();
            $table->jsonb('score_factors')->default('{}')->comment('critères déterminants et écarts');
            $table->decimal('distance_km', 7, 2)->nullable();
            $table->bigInteger('worker_daily_rate')->nullable()->comment('tarif déclaré par l\'ouvrier');
            $table->bigInteger('promoter_daily_rate')->nullable()->comment('tarif affiché au promoteur (avec Z)');
            $table->bigInteger('net_daily_rate')->nullable()->comment('net ouvrier');
            $table->boolean('is_manual')->default(false)->comment('ajout manuel par un opérateur');
            $table->foreignUuid('added_by_admin_id')->nullable()->constrained('admins');
            $table->string('status', 20)->default('suggere');
            $table->timestampTz('published_to_promoter_at')->nullable();
            $table->foreignUuid('published_by_admin_id')->nullable()->constrained('admins');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->unique(['matching_run_id', 'worker_id']);
            $table->index(['need_id', 'status'], 'ix_suggestions_need');
            $table->index(['worker_id', 'status'], 'ix_suggestions_worker');
            $table->comment('Suggestion = un ouvrier classé pour un besoin, avec son explication');
        });

        DB::statement('ALTER TABLE suggestions ADD CONSTRAINT suggestions_status_check CHECK (status IN (\'suggere\',\'contacte\',\'accepte\',\'refuse\',\'indisponible\',\'expire\', \'propose_promoteur\',\'selectionne\',\'ecarte\'))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('suggestions');
    }
};
