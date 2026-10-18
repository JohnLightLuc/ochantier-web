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
        Schema::create('data_retention_policies', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('data_category', 60)->unique()->comment('pieces_kyc, photos_pointage, logs_acces…');
            $table->integer('retention_days');
            $table->string('action', 15);
            $table->foreignUuid('updated_by_admin_id')->nullable()->constrained('admins');
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::statement('ALTER TABLE data_retention_policies ADD CONSTRAINT data_retention_policies_retention_days_check CHECK (retention_days > 0)');
        DB::statement('ALTER TABLE data_retention_policies ADD CONSTRAINT data_retention_policies_action_check CHECK (action IN (\'supprimer\',\'anonymiser\',\'archiver\'))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('data_retention_policies');
    }
};
