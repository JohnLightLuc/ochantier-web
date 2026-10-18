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
        Schema::create('payment_webhook_events', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('provider', 40);
            $table->string('provider_event_id', 120);
            $table->string('event_type', 60)->nullable();
            $table->boolean('signature_valid');
            $table->jsonb('payload');
            $table->timestampTz('received_at')->useCurrent();
            $table->string('processing_status', 10)->default('recu');
            $table->timestampTz('processed_at')->nullable();
            $table->smallInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->foreignUuid('transaction_id')->nullable()->constrained('payment_transactions');
            $table->unique(['provider', 'provider_event_id']);
            $table->comment('Webhooks persistés avant traitement, traités de façon idempotente');
        });

        DB::statement('ALTER TABLE payment_webhook_events ADD CONSTRAINT payment_webhook_events_processing_status_check CHECK (processing_status IN (\'recu\',\'traite\',\'ignore\',\'erreur\'))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_events');
    }
};
