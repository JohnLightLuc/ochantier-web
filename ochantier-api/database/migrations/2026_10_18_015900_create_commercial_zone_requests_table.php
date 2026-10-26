<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * « Demander un changement » de zone d’activité depuis l’application Commercial : communes souhaitées, traitées
     * par le responsable dans le Back office. Une seule demande en attente par commercial.
     */
    public function up(): void
    {
        Schema::create('commercial_zone_requests', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('commercial_id')->constrained('commercial_profiles');
            $table->jsonb('commune_ids')->comment('communes demandées');
            $table->string('status', 10)->default('en_attente');
            $table->text('decision_comment')->nullable();
            $table->foreignUuid('decided_by_admin_id')->nullable()->constrained('admins');
            $table->timestampTz('decided_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->comment('Demande de changement de zone d’activité d’un commercial');
        });

        DB::statement("ALTER TABLE commercial_zone_requests ADD CONSTRAINT commercial_zone_requests_status_check CHECK (status IN ('en_attente','acceptee','refusee','annulee'))");
        DB::statement("CREATE UNIQUE INDEX uq_commercial_zone_requests_pending ON commercial_zone_requests (commercial_id) WHERE status = 'en_attente'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('commercial_zone_requests');
    }
};
