<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Point PostGIS (geography(Point, 4326)) converti en ['latitude' => …, 'longitude' => …].
 *
 * PostgreSQL renvoie la colonne au format EWKB hexadécimal ; l’enregistrement passe par ST_MakePoint.
 */
class GeoPoint implements CastsAttributes
{
    /**
     * Cast the given value.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{latitude: float, longitude: float}|null
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null || $value instanceof Expression) {
            return null;
        }

        $binary = hex2bin((string) $value);

        if ($binary === false || strlen($binary) < 21) {
            return null;
        }

        $little = ord($binary[0]) === 1;
        $type = unpack($little ? 'V' : 'N', substr($binary, 1, 4))[1];
        $offset = ($type & 0x20000000) ? 9 : 5;
        $coordinates = substr($binary, $offset, 16);

        if (! $little) {
            $coordinates = strrev(substr($coordinates, 0, 8)).strrev(substr($coordinates, 8, 8));
        }

        [, $longitude, $latitude] = unpack('e2', $coordinates) + [0, 0, 0];

        return ['latitude' => round($latitude, 7), 'longitude' => round($longitude, 7)];
    }

    /**
     * Prepare the given value for storage.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?Expression
    {
        if ($value === null) {
            return null;
        }

        if (! is_array($value) || ! isset($value['latitude'], $value['longitude'])) {
            throw new InvalidArgumentException("{$key} attend ['latitude' => …, 'longitude' => …].");
        }

        return DB::raw(sprintf('ST_SetSRID(ST_MakePoint(%F, %F), 4326)::geography', (float) $value['longitude'], (float) $value['latitude']));
    }
}
