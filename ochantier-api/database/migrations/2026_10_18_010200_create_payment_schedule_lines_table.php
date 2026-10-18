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
        Schema::create('payment_schedule_lines', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('payment_schedule_id')->constrained('payment_schedules')->cascadeOnDelete();
            $table->foreignUuid('assignment_id')->constrained('assignments');
            $table->foreignUuid('worker_id')->constrained('workers');
            $table->decimal('days_count', 6, 2)->default(0);
            $table->bigInteger('gross_amount')->comment('montant facturé pour l\'ouvrier');
            $table->bigInteger('worker_commission_amount')->comment('Z');
            $table->bigInteger('net_amount')->comment('net ouvrier');
            $table->jsonb('details')->default('{}');
            $table->unique(['payment_schedule_id', 'assignment_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_schedule_lines');
    }
};
