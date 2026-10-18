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
        Schema::create('matching_derogations', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('need_id')->constrained('needs');
            $table->foreignUuid('worker_id')->constrained('workers');
            $table->string('criterion', 40);
            $table->text('justification');
            $table->foreignUuid('requested_by_admin_id')->constrained('admins');
            $table->foreignUuid('approved_by_admin_id')->nullable()->constrained('admins');
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['need_id', 'worker_id', 'criterion']);
            $table->comment('Dérogation à un critère pour retenir malgré tout un ouvrier (double validation)');
        });

        DB::statement('ALTER TABLE matching_derogations ADD CONSTRAINT matching_derogations_check1 CHECK (approved_by_admin_id IS NULL OR approved_by_admin_id <> requested_by_admin_id)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('matching_derogations');
    }
};
