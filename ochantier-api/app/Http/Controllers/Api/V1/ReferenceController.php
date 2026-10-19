<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DocumentAudience;
use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\DocumentType;
use App\Models\ReasonCode;
use App\Models\Trade;
use Illuminate\Http\JsonResponse;

/**
 * Référentiels des formulaires des applications : communes et quartiers, métiers, spécialités et compétences,
 * pièces acceptées et motifs. Mis en cache par l’application (une heure).
 */
class ReferenceController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $cities = City::query()
            ->where('is_active', true)
            ->with(['communes' => fn ($query) => $query->where('is_active', true)->orderBy('name')->with(['neighborhoods' => fn ($query) => $query->where('is_active', true)->orderBy('name')])])
            ->orderByRaw("name <> 'Abidjan'")
            ->orderBy('name')
            ->get();

        $trades = Trade::query()
            ->where('is_active', true)
            ->with([
                'specialties' => fn ($query) => $query->where('is_active', true)->orderBy('label'),
                'skills' => fn ($query) => $query->where('is_active', true)->orderBy('sort_order'),
            ])
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'cities' => $cities->map(fn (City $city) => [
                'id' => $city->id,
                'name' => $city->name,
                'communes' => $city->communes->map(fn ($commune) => [
                    'id' => $commune->id,
                    'name' => $commune->name,
                    'neighborhoods' => $commune->neighborhoods->map->only(['id', 'name'])->values(),
                ])->values(),
            ]),
            'trades' => $trades->map(fn (Trade $trade) => [
                'id' => $trade->id,
                'code' => $trade->code,
                'label' => $trade->label,
                'specialties' => $trade->specialties->map->only(['id', 'code', 'label'])->values(),
                'skills' => $trade->skills->map->only(['id', 'code', 'label'])->values(),
            ]),
            'worker_document_types' => DocumentType::query()
                ->where('audience', DocumentAudience::Worker)
                ->where('is_active', true)
                ->orderBy('created_at')
                ->get()
                ->map(fn (DocumentType $type) => [
                    'code' => $type->code,
                    'label' => $type->label,
                    'is_identity' => $type->is_identity,
                    'is_required' => $type->is_required,
                    'has_back_side' => $type->has_back_side,
                    'requires_number' => $type->is_identity,
                    'trade_id' => $type->trade_id,
                    'allowed_mime' => $type->allowed_mime,
                    'max_size_kb' => $type->max_size_kb,
                ]),
            'reasons' => ReasonCode::query()
                ->where('is_active', true)
                ->whereIn('category', ['abandon_prospect', 'refus_proposition'])
                ->orderBy('category')
                ->orderBy('created_at')
                ->get(['category', 'code', 'label'])
                ->groupBy('category')
                ->map(fn ($reasons) => $reasons->map->only(['code', 'label'])->values()),
        ])->header('Cache-Control', 'private, max-age=3600');
    }
}
