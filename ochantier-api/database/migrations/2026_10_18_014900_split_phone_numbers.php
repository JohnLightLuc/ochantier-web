<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Colonnes de numéro découpées en indicatif (dial_code, « 225 ») et numéro national (phone_number, « 0708091011 »).
     * L’ancienne colonne devient le format E.164 calculé (« +2250708091011 ») : les index d’unicité et l’envoi des SMS
     * s’appuient toujours sur elle, et elle ne peut plus diverger des deux champs saisis.
     *
     * @var array<string, list<array{column: string, prefix: string}>>
     */
    private const COLUMNS = [
        'users' => [['column' => 'phone', 'prefix' => '']],
        'prospects' => [['column' => 'phone', 'prefix' => '']],
        'workers' => [
            ['column' => 'phone', 'prefix' => ''],
            ['column' => 'emergency_contact_phone', 'prefix' => 'emergency_contact_'],
        ],
    ];

    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS uq_users_type_phone');
        DB::statement('DROP INDEX IF EXISTS ix_prospects_phone');
        DB::statement('DROP INDEX IF EXISTS uq_workers_phone');
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_contact_check');

        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as ['column' => $column, 'prefix' => $prefix]) {
                $dialCode = "{$prefix}dial_code";
                $number = "{$prefix}phone_number";

                DB::statement("ALTER TABLE {$table} ADD COLUMN {$dialCode} varchar(4), ADD COLUMN {$number} varchar(15)");
                DB::statement("UPDATE {$table} SET {$dialCode} = '225', {$number} = substr({$column}, 5) WHERE {$column} LIKE '+225%'");
                DB::statement("ALTER TABLE {$table} DROP COLUMN {$column}");
                DB::statement("ALTER TABLE {$table} ADD COLUMN {$column} varchar(20) GENERATED ALWAYS AS ('+' || {$dialCode} || {$number}) STORED");
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$prefix}phone_parts_check CHECK (({$dialCode} IS NULL) = ({$number} IS NULL) AND {$dialCode} ~ '^[0-9]{1,4}$' AND {$number} ~ '^[0-9]{4,15}$')");
                DB::statement("COMMENT ON COLUMN {$table}.{$dialCode} IS 'indicatif pays sans +, ex. 225'");
                DB::statement("COMMENT ON COLUMN {$table}.{$number} IS 'numéro national, ex. 0708091011'");
                DB::statement("COMMENT ON COLUMN {$table}.{$column} IS 'format E.164 calculé'");
            }
        }

        DB::statement('ALTER TABLE prospects ALTER COLUMN dial_code SET NOT NULL, ALTER COLUMN phone_number SET NOT NULL');
        DB::statement('ALTER TABLE workers ALTER COLUMN dial_code SET NOT NULL, ALTER COLUMN phone_number SET NOT NULL');
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_contact_check CHECK (phone_number IS NOT NULL OR email IS NOT NULL)');
        DB::statement('CREATE UNIQUE INDEX uq_users_type_phone ON users (user_type, phone) WHERE deleted_at IS NULL');
        DB::statement('CREATE INDEX ix_prospects_phone ON prospects (phone)');
        DB::statement('CREATE UNIQUE INDEX uq_workers_phone ON workers (phone) WHERE deleted_at IS NULL AND merged_into_worker_id IS NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS uq_users_type_phone');
        DB::statement('DROP INDEX IF EXISTS ix_prospects_phone');
        DB::statement('DROP INDEX IF EXISTS uq_workers_phone');
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_contact_check');

        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as ['column' => $column, 'prefix' => $prefix]) {
                DB::statement("ALTER TABLE {$table} RENAME COLUMN {$column} TO {$column}_e164");
                DB::statement("ALTER TABLE {$table} ADD COLUMN {$column} varchar(20)");
                DB::statement("UPDATE {$table} SET {$column} = {$column}_e164");
                DB::statement("ALTER TABLE {$table} DROP COLUMN {$column}_e164, DROP COLUMN {$prefix}dial_code, DROP COLUMN {$prefix}phone_number");
            }
        }

        DB::statement('ALTER TABLE prospects ALTER COLUMN phone SET NOT NULL');
        DB::statement('ALTER TABLE workers ALTER COLUMN phone SET NOT NULL');
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_contact_check CHECK (phone IS NOT NULL OR email IS NOT NULL)');
        DB::statement('CREATE UNIQUE INDEX uq_users_type_phone ON users (user_type, phone) WHERE deleted_at IS NULL');
        DB::statement('CREATE INDEX ix_prospects_phone ON prospects (phone)');
        DB::statement('CREATE UNIQUE INDEX uq_workers_phone ON workers (phone) WHERE deleted_at IS NULL AND merged_into_worker_id IS NULL');
    }
};
