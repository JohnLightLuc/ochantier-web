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
        Schema::create('ticket_escalations', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->smallInteger('from_level');
            $table->smallInteger('to_level');
            $table->foreignUuid('to_admin_id')->nullable()->constrained('admins');
            $table->text('reason');
            $table->timestampTz('due_at')->nullable();
            $table->foreignUuid('created_by_admin_id')->constrained('admins');
            $table->timestampTz('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ticket_escalations');
    }
};
