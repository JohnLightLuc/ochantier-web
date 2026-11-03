<?php

namespace App\Http\Controllers\Api\V1\Admin\Promoter;

use App\Enums\SiteStatus;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Dispute;
use App\Models\Offer;
use App\Models\Site;
use App\Support\Admin\PromoterRelations;
use App\Support\ApiResponse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Onglets en lecture seule de la fiche promoteur : chantiers, offres, échéancier, avis, litiges et activité.
 */
class PromoterRelationController extends Controller
{
    public function __construct(private PromoterRelations $relations) {}

    /**
     * Chantiers du compte. meta : pagination et compteurs par statut.
     */
    public function sites(Request $request, string $promoter): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(SiteStatus::class)],
            'search' => ['nullable', 'string', 'max:80'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $profile = PromoterController::find($promoter);
        $sites = $this->relations->sites($profile, $filters, (int) ($filters['per_page'] ?? 20));

        return ApiResponse::success(
            array_map(fn (Site $site) => $this->relations->site($site), $sites->items()),
            meta: [...$this->pagination($sites), 'counts' => $this->relations->siteCounts($profile)],
        );
    }

    /**
     * Offres des chantiers du compte, filtrables par chantier.
     */
    public function offers(Request $request, string $promoter): JsonResponse
    {
        $filters = $request->validate(['site_id' => ['nullable', 'uuid'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $offers = $this->relations->offers(PromoterController::find($promoter), $filters['site_id'] ?? null, (int) ($filters['per_page'] ?? 20));

        return ApiResponse::success(array_map(fn (Offer $offer) => $this->relations->offer($offer), $offers->items()), meta: $this->pagination($offers));
    }

    /**
     * Échéancier du compte (acomptes, échéances, versements) et totaux.
     */
    public function payments(Request $request, string $promoter): JsonResponse
    {
        $filters = $request->validate(['site_id' => ['nullable', 'uuid']]);
        $payments = $this->relations->payments(PromoterController::find($promoter), $filters['site_id'] ?? null);

        return ApiResponse::success($payments['data'], meta: $payments['meta']);
    }

    /**
     * Avis publiés ou masqués sur les chantiers du compte.
     */
    public function ratings(string $promoter): JsonResponse
    {
        $ratings = $this->relations->ratings(PromoterController::find($promoter));

        return ApiResponse::success($ratings['data'], meta: $ratings['meta']);
    }

    /**
     * Litiges des chantiers du compte.
     */
    public function disputes(Request $request, string $promoter): JsonResponse
    {
        $filters = $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $profile = PromoterController::find($promoter);
        $disputes = $this->relations->disputes($profile, (int) ($filters['per_page'] ?? 20));

        return ApiResponse::success(array_map(fn (Dispute $dispute) => $this->relations->dispute($dispute, $profile), $disputes->items()), meta: $this->pagination($disputes));
    }

    /**
     * Journal d’activité, filtrable par auteur (admin ou promoter).
     */
    public function activity(Request $request, string $promoter): JsonResponse
    {
        $filters = $request->validate([
            'author' => ['nullable', Rule::in(['admin', 'promoter'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ], ['author.in' => 'Filtre inconnu.']);
        $events = $this->relations->activity(PromoterController::find($promoter), $filters['author'] ?? null, (int) ($filters['per_page'] ?? 30));

        return ApiResponse::success(array_map(fn (AuditLog $log) => $this->relations->event($log), $events->items()), meta: $this->pagination($events));
    }

    /**
     * @param  LengthAwarePaginator<int, mixed>  $page
     * @return array{current_page: int, last_page: int, per_page: int, total: int}
     */
    private function pagination(LengthAwarePaginator $page): array
    {
        return ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()];
    }
}
