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
        Schema::create('worker_commercial_transfers', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('worker_id')->constrained('workers');
            $table->foreignUuid('from_commercial_id')->constrained('commercial_profiles');
            $table->foreignUuid('to_commercial_id')->constrained('commercial_profiles');
            $table->text('reason');
            $table->timestampTz('effective_at')->useCurrent();
            $table->foreignUuid('transferred_by_admin_id')->constrained('admins');
            $table->timestampTz('created_at')->useCurrent();
            $table->comment('Transfert de portefeuille entre commerciaux');
        });

        DB::statement('ALTER TABLE worker_commercial_transfers ADD CONSTRAINT worker_commercial_transfers_check1 CHECK (from_commercial_id <> to_commercial_id)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('worker_commercial_transfers');
    }
};
