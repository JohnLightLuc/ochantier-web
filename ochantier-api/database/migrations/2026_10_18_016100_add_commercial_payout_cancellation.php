<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE commercial_payouts DROP CONSTRAINT commercial_payouts_status_check');
        DB::statement("ALTER TABLE commercial_payouts ADD CONSTRAINT commercial_payouts_status_check CHECK (status IN ('a_payer','en_traitement','paye','rejete','annule'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE commercial_payouts DROP CONSTRAINT commercial_payouts_status_check');
        DB::statement("ALTER TABLE commercial_payouts ADD CONSTRAINT commercial_payouts_status_check CHECK (status IN ('a_payer','en_traitement','paye','rejete'))");
    }
};
