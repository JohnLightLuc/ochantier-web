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
        Schema::create('selections', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('need_id')->constrained('needs');
            $table->foreignUuid('suggestion_id')->constrained('suggestions');
            $table->foreignUuid('worker_id')->constrained('workers');
            $table->foreignUuid('selected_by_user_id')->constrained('users');
            $table->timestampTz('selected_at')->useCurrent();
            $table->foreignUuid('validated_by_admin_id')->nullable()->constrained('admins');
            $table->timestampTz('validated_at')->nullable();
            $table->string('status', 15)->default('selectionne');
            $table->timestampTz('deselected_at')->nullable();
            $table->text('deselection_reason')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->comment('Sélection par le promoteur');
        });

        DB::statement('ALTER TABLE selections ADD CONSTRAINT selections_status_check CHECK (status IN (\'selectionne\',\'reserve\',\'confirme\',\'desiste\',\'remplace\',\'expire\'))');
        DB::statement('CREATE UNIQUE INDEX uq_selections_active ON selections (need_id, worker_id) WHERE status IN (\'selectionne\',\'reserve\',\'confirme\')');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('selections');
    }
};
