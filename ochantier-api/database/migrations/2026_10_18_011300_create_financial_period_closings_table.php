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
        Schema::create('financial_period_closings', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->date('period_month')->unique();
            $table->string('status', 12)->default('ouverte');
            $table->integer('version')->default(0);
            $table->jsonb('snapshot')->nullable();
            $table->foreignUuid('closed_by_admin_id')->nullable()->constrained('admins');
            $table->timestampTz('closed_at')->nullable();
            $table->foreignUuid('reopened_by_admin_id')->nullable()->constrained('admins');
            $table->timestampTz('reopened_at')->nullable();
            $table->text('reopen_reason')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->comment('Clôture comptable mensuelle : fige les montants de la période');
        });

        DB::statement('ALTER TABLE financial_period_closings ADD CONSTRAINT financial_period_closings_period_month_check CHECK (EXTRACT(DAY FROM period_month) = 1)');
        DB::statement('ALTER TABLE financial_period_closings ADD CONSTRAINT financial_period_closings_status_check CHECK (status IN (\'ouverte\',\'en_cloture\',\'cloturee\',\'reouverte\'))');
        DB::statement('ALTER TABLE financial_period_closings ADD CONSTRAINT financial_period_closings_check1 CHECK (status <> \'reouverte\' OR reopen_reason IS NOT NULL)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('financial_period_closings');
    }
};
