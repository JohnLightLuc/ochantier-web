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
        Schema::create('worker_sanctions', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('worker_id')->constrained('workers');
            $table->string('sanction_type', 15);
            $table->foreignUuid('reason_id')->nullable()->constrained('reason_codes');
            $table->text('comment')->nullable();
            $table->timestampTz('starts_at')->useCurrent();
            $table->timestampTz('ends_at')->nullable();
            $table->foreignUuid('decided_by_admin_id')->constrained('admins');
            $table->string('appeal_status', 15)->nullable();
            $table->timestampTz('lifted_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement('ALTER TABLE worker_sanctions ADD CONSTRAINT worker_sanctions_sanction_type_check CHECK (sanction_type IN (\'avertissement\',\'suspension\',\'exclusion\'))');
        DB::statement('ALTER TABLE worker_sanctions ADD CONSTRAINT worker_sanctions_appeal_status_check CHECK (appeal_status IN (\'aucun\',\'demande\',\'accepte\',\'rejete\'))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('worker_sanctions');
    }
};
