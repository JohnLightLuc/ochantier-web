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
        Schema::create('payment_transaction_status_histories', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('transaction_id')->constrained('payment_transactions')->cascadeOnDelete();
            $table->string('from_status', 10)->nullable();
            $table->string('to_status', 10);
            $table->string('source', 15);
            $table->uuid('webhook_event_id')->nullable();
            $table->uuid('actor_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement('ALTER TABLE payment_transaction_status_histories ADD CONSTRAINT payment_transaction_status_histories_source_check CHECK (source IN (\'api\',\'webhook\',\'rapprochement\',\'admin\',\'systeme\'))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_transaction_status_histories');
    }
};
