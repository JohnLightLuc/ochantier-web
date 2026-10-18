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
        Schema::create('approvals', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('subject_type', 40);
            $table->uuid('subject_id');
            $table->string('action', 60);
            $table->jsonb('payload')->default('{}');
            $table->foreignUuid('requested_by_admin_id')->constrained('admins');
            $table->foreignUuid('decided_by_admin_id')->nullable()->constrained('admins');
            $table->string('status', 10)->default('en_attente');
            $table->text('comment')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->comment('Validation à deux personnes (maker-checker) pour les actions sensibles');
        });

        DB::statement('ALTER TABLE approvals ADD CONSTRAINT approvals_status_check CHECK (status IN (\'en_attente\',\'approuve\',\'refuse\',\'expire\'))');
        DB::statement('ALTER TABLE approvals ADD CONSTRAINT approvals_check1 CHECK (decided_by_admin_id IS NULL OR decided_by_admin_id <> requested_by_admin_id)');
        DB::statement('CREATE INDEX ix_approvals_pending ON approvals (status, created_at) WHERE status = \'en_attente\'');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('approvals');
    }
};
