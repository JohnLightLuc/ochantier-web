<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Tableau PostgreSQL (text[], varchar[], smallint[], uuid[]) converti en tableau PHP.
 *
 * Paramètre facultatif `int` pour les tableaux d'entiers : `PostgresArray::class.':int'`.
 */
class PostgresArray implements CastsAttributes
{
    public function __construct(private ?string $type = null) {}

    /**
     * Cast the given value.
     *
     * @param  array<string, mixed>  $attributes
     * @return list<string|int|null>|null
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null) {
            return null;
        }

        $items = $this->parse((string) $value);

        return $this->type === 'int'
            ? array_map(fn (?string $item): ?int => $item === null ? null : (int) $item, $items)
            : $items;
    }

    /**
     * Prepare the given value for storage.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $items = array_map(function (mixed $item): string {
            if ($item === null) {
                return 'NULL';
            }

            if ($this->type === 'int') {
                return (string) (int) $item;
            }

            return '"'.addcslashes((string) $item, '"\\').'"';
        }, array_values((array) $value));

        return '{'.implode(',', $items).'}';
    }

    /**
     * Découpe un littéral de tableau à une dimension (`{a,"b c",NULL}`).
     *
     * @return list<string|null>
     */
    private function parse(string $literal): array
    {
        $body = substr(trim($literal), 1, -1);

        if ($body === '') {
            return [];
        }

        $items = [];
        $current = '';
        $quoted = false;
        $wasQuoted = false;

        for ($i = 0, $length = strlen($body); $i < $length; $i++) {
            $char = $body[$i];

            if ($quoted && $char === '\\') {
                $current .= $body[++$i] ?? '';
            } elseif ($char === '"') {
                $quoted = ! $quoted;
                $wasQuoted = true;
            } elseif ($char === ',' && ! $quoted) {
                $items[] = ! $wasQuoted && $current === 'NULL' ? null : $current;
                $current = '';
                $wasQuoted = false;
            } else {
                $current .= $char;
            }
        }

        $items[] = ! $wasQuoted && $current === 'NULL' ? null : $current;

        return $items;
    }
}
