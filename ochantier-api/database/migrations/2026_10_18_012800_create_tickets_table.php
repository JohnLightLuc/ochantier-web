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
        Schema::create('tickets', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('reference', 25)->unique();
            $table->string('requester_type', 20)->comment('user, worker (V2)');
            $table->uuid('requester_id');
            $table->string('category', 30);
            $table->string('subject', 200);
            $table->text('description');
            $table->string('priority', 10)->default('normale');
            $table->string('status', 25)->default('ouvert');
            $table->foreignUuid('assigned_admin_id')->nullable()->constrained('admins');
            $table->smallInteger('escalation_level')->default(0);
            $table->timestampTz('sla_due_at')->nullable();
            $table->string('related_type', 30)->nullable();
            $table->uuid('related_id')->nullable();
            $table->uuid('client_uuid')->nullable()->unique();
            $table->timestampTz('closed_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->index(['status', 'priority', 'sla_due_at'], 'ix_tickets_queue');
        });

        DB::statement('ALTER TABLE tickets ADD CONSTRAINT tickets_priority_check CHECK (priority IN (\'basse\',\'normale\',\'haute\',\'critique\'))');
        DB::statement('ALTER TABLE tickets ADD CONSTRAINT tickets_status_check CHECK (status IN (\'ouvert\',\'en_cours\',\'en_attente_utilisateur\',\'escalade\',\'resolu\',\'ferme\'))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
