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
        Schema::table('refunds', function (Blueprint $table) {
            $table->foreignUuid('payment_method_id')->nullable()->after('beneficiary_id')->constrained('payment_methods');
            $table->string('origin', 15)->default('manuel')->comment('regularisation, manuel');
            $table->text('refusal_reason')->nullable();
            $table->timestampTz('executed_at')->nullable();
        });

        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_origin_check CHECK (origin IN ('regularisation','manuel'))");
        DB::statement("CREATE UNIQUE INDEX uq_refunds_regularisation ON refunds (mission_id) WHERE origin = 'regularisation'");
        DB::statement('CREATE SEQUENCE refund_reference_seq START 1 OWNED BY refunds.reference');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP SEQUENCE IF EXISTS refund_reference_seq');
        DB::statement('DROP INDEX IF EXISTS uq_refunds_regularisation');
        DB::statement('ALTER TABLE refunds DROP CONSTRAINT IF EXISTS refunds_origin_check');

        Schema::table('refunds', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_method_id');
            $table->dropColumn(['origin', 'refusal_reason', 'executed_at']);
        });
    }
};
