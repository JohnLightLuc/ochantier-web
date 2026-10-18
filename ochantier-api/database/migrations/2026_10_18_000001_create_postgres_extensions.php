<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Extensions PostgreSQL requises par le schéma.
     *
     * @var list<string>
     */
    private array $extensions = [
        'pgcrypto',   // gen_random_uuid()
        'citext',     // e-mails insensibles à la casse
        'postgis',    // distances (matching), contrôle GPS (pointage)
        'btree_gist', // contraintes d'exclusion (double affectation)
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach ($this->extensions as $extension) {
            DB::statement("CREATE EXTENSION IF NOT EXISTS {$extension}");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (array_reverse($this->extensions) as $extension) {
            DB::statement("DROP EXTENSION IF EXISTS {$extension}");
        }
    }
};
