<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Numérotation des réclamations, déposées dans l’application Promoteur ou saisies au Back Office.
     */
    public function up(): void
    {
        DB::statement('CREATE SEQUENCE claim_reference_seq START 1 OWNED BY claims.reference');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP SEQUENCE IF EXISTS claim_reference_seq');
    }
};
