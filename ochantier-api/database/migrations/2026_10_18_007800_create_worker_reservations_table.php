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
        Schema::create('worker_reservations', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('worker_id')->constrained('workers');
            $table->foreignUuid('need_id')->constrained('needs');
            $table->foreignUuid('selection_id')->constrained('selections');
            $table->rawColumn('period', 'daterange');
            $table->timestampTz('reserved_at')->useCurrent();
            $table->timestampTz('expires_at');
            $table->timestampTz('released_at')->nullable();
            $table->string('status', 10)->default('active');
            $table->comment('Réservation provisoire pendant le paiement de l\'acompte');
        });

        DB::statement('ALTER TABLE worker_reservations ADD CONSTRAINT worker_reservations_status_check CHECK (status IN (\'active\',\'convertie\',\'expiree\',\'liberee\'))');
        DB::statement('ALTER TABLE worker_reservations ADD CONSTRAINT worker_reservations_overlap_excl EXCLUDE USING gist (worker_id WITH =, period WITH &&) WHERE (status = \'active\')');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('worker_reservations');
    }
};
