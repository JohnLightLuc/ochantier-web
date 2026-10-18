<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Numérotation des références lisibles des ouvriers (OUV-000123), sans doublon entre requêtes concurrentes.
     * Rattachée à workers.reference : elle est supprimée avec la table.
     */
    public function up(): void
    {
        DB::statement('CREATE SEQUENCE worker_reference_seq START 1 OWNED BY workers.reference');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP SEQUENCE IF EXISTS worker_reference_seq');
    }
};
