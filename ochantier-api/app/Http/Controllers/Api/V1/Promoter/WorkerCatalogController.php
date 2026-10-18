<?php

namespace App\Http\Controllers\Api\V1\Promoter;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Promoter\CatalogWorkerResource;
use App\Support\Promoter\WorkerCatalog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * « Choisir des ouvriers » : ouvriers validés, filtrés par métier, tarif et disponibilité.
 */
class WorkerCatalogController extends Controller
{
    public function __construct(private WorkerCatalog $catalog) {}

    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'trade_id' => ['nullable', 'uuid', Rule::exists('trades', 'id')],
            'search' => ['nullable', 'string', 'max:80'],
            'max_rate' => ['nullable', 'integer', 'min:1000'],
            'available' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $workers = $this->catalog->query([
            ...$filters,
            'max_rate' => isset($filters['max_rate']) ? (int) $filters['max_rate'] : null,
            'available' => $request->boolean('available'),
        ])->paginate((int) ($filters['per_page'] ?? 20))->withQueryString();

        return CatalogWorkerResource::collection($workers);
    }
}
