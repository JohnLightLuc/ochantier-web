<?php

namespace App\Http\Resources\V1;

use BackedEnum;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Base des ressources v1 : les statuts sont exposés avec leur code stable et leur libellé français.
 */
abstract class Resource extends JsonResource
{
    /**
     * @return array{value: string, label: string}|null
     */
    protected function status(?BackedEnum $status): ?array
    {
        return $status ? ['value' => (string) $status->value, 'label' => method_exists($status, 'label') ? $status->label() : (string) $status->value] : null;
    }

    protected function date(mixed $date): ?string
    {
        return $date?->format('Y-m-d');
    }
}
