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
        Schema::create('domain_events', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('event_type', 80);
            $table->string('aggregate_type', 40);
            $table->uuid('aggregate_id');
            $table->jsonb('payload');
            $table->uuid('correlation_id')->nullable();
            $table->timestampTz('occurred_at')->useCurrent();
            $table->timestampTz('published_at')->nullable();
            $table->smallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->comment('Boîte d\'envoi des événements métier (notifications, agrégats, intégrations)');
        });

        DB::statement('CREATE INDEX ix_domain_events_pending ON domain_events (occurred_at) WHERE published_at IS NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('domain_events');
    }
};
