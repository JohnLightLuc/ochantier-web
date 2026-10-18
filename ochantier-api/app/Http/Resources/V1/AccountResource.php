<?php

namespace App\Http\Resources\V1;

use App\Models\Promoter;
use App\Models\SalesAgent;
use App\Models\Worker;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Compte connecté : délègue à la ressource du profil.
 */
class AccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return match (true) {
            $this->resource instanceof Worker => WorkerResource::make($this->resource)->toArray($request),
            $this->resource instanceof Promoter => PromoterResource::make($this->resource)->toArray($request),
            $this->resource instanceof SalesAgent => SalesAgentResource::make($this->resource)->toArray($request),
        };
    }
}
