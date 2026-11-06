<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Litiges du Back Office : numérotation, et remboursement décidé par un litige.
     */
    public function up(): void
    {
        DB::statement('CREATE SEQUENCE dispute_reference_seq START 1 OWNED BY disputes.reference');
        DB::statement('ALTER TABLE refunds DROP CONSTRAINT refunds_origin_check');
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_origin_check CHECK (origin IN ('regularisation','manuel','litige'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("UPDATE refunds SET origin = 'manuel' WHERE origin = 'litige'");
        DB::statement('ALTER TABLE refunds DROP CONSTRAINT refunds_origin_check');
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_origin_check CHECK (origin IN ('regularisation','manuel'))");
        DB::statement('DROP SEQUENCE IF EXISTS dispute_reference_seq');
    }
};
