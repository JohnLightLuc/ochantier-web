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
        'pgcrypto',   // gen_random_uuid() (natif depuis PostgreSQL 13)
        'citext',     // e-mails insensibles à la casse
        'postgis',    // distances (matching), contrôle GPS (pointage)
        'btree_gist', // contraintes d'exclusion (double affectation)
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $available = collect(DB::select('SELECT name FROM pg_available_extensions'))
            ->pluck('name');

        foreach ($this->extensions as $extension) {
            if ($available->contains($extension)) {
                DB::statement("CREATE EXTENSION IF NOT EXISTS \"{$extension}\"");
            } else {
                echo "  ⚠ Extension « {$extension} » indisponible sur ce serveur : ignorée." . PHP_EOL;
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (array_reverse($this->extensions) as $extension) {
            DB::statement("DROP EXTENSION IF EXISTS \"{$extension}\"");
        }
    }
};