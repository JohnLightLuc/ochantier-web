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
        Schema::create('site_incidents', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('reference', 25)->unique();
            $table->foreignUuid('site_id')->constrained('sites');
            $table->foreignUuid('mission_id')->nullable()->constrained('missions');
            $table->timestampTz('occurred_at');
            $table->string('incident_type', 20);
            $table->string('severity', 10);
            $table->text('description');
            $table->string('reported_by_type', 20);
            $table->uuid('reported_by_id');
            $table->string('status', 15)->default('ouvert');
            $table->text('resolution')->nullable();
            $table->foreignUuid('resolved_by_admin_id')->nullable()->constrained('admins');
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::statement('ALTER TABLE site_incidents ADD CONSTRAINT site_incidents_incident_type_check CHECK (incident_type IN (\'accident\',\'securite\',\'materiel\',\'retard\',\'conflit\',\'qualite\',\'autre\'))');
        DB::statement('ALTER TABLE site_incidents ADD CONSTRAINT site_incidents_severity_check CHECK (severity IN (\'mineure\',\'moderee\',\'grave\',\'critique\'))');
        DB::statement('ALTER TABLE site_incidents ADD CONSTRAINT site_incidents_status_check CHECK (status IN (\'ouvert\',\'en_traitement\',\'resolu\',\'clos\'))');
        DB::statement('CREATE INDEX ix_site_incidents_site ON site_incidents (site_id, occurred_at DESC)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('site_incidents');
    }
};
