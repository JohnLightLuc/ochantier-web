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
        Schema::create('financial_exceptions', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->char('parameter_code', 1);
            $table->string('subject_type', 20)->comment('need, site, promoter_profile, organization');
            $table->uuid('subject_id');
            $table->decimal('override_value', 7, 4);
            $table->text('reason');
            $table->timestampTz('valid_from');
            $table->timestampTz('valid_to')->nullable();
            $table->string('status', 20)->default('demandee');
            $table->foreignUuid('requested_by_admin_id')->constrained('admins');
            $table->foreignUuid('approved_by_admin_id')->nullable()->constrained('admins');
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->comment('Dérogations ponctuelles (plafonds, exceptions) avec approbation renforcée');
        });

        DB::statement('ALTER TABLE financial_exceptions ADD CONSTRAINT financial_exceptions_parameter_code_check CHECK (parameter_code IN (\'X\',\'Y\',\'Z\',\'R\'))');
        DB::statement('ALTER TABLE financial_exceptions ADD CONSTRAINT financial_exceptions_override_value_check CHECK (override_value >= 0 AND override_value <= 100)');
        DB::statement('ALTER TABLE financial_exceptions ADD CONSTRAINT financial_exceptions_status_check CHECK (status IN (\'demandee\',\'approuvee\',\'refusee\',\'expiree\'))');
        DB::statement('ALTER TABLE financial_exceptions ADD CONSTRAINT financial_exceptions_check1 CHECK (approved_by_admin_id IS NULL OR approved_by_admin_id <> requested_by_admin_id)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('financial_exceptions');
    }
};
