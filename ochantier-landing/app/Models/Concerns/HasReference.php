<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/**
 * Attribue une référence lisible communiquée au visiteur, ex. OC-2026-4F7K2.
 * Le modèle définit la constante REFERENCE_PREFIX.
 */
trait HasReference
{
    protected static function bootHasReference(): void
    {
        static::creating(function (self $model) {
            do {
                $ref = static::REFERENCE_PREFIX.'-'.now()->year.'-'.Str::upper(Str::random(5));
            } while (static::where('reference', $ref)->exists());

            $model->reference ??= $ref;
        });
    }
}
