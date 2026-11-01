<?php

namespace App\Http\Controllers\Api\V1\Admin\Promoter;

use App\Enums\PromoterProfileKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Admin\Promoter\PromoterIndexRequest;
use App\Http\Resources\V1\Admin\PromoterDetailResource;
use App\Http\Resources\V1\Admin\PromoterListResource;
use App\Models\PromoterProfile;
use App\Support\Admin\AdminAudit;
use App\Support\Admin\PromoterDirectory;
use App\Support\ApiResponse;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Promoteurs du Back Office : liste filtrée avec compteurs, export CSV et fiche.
 */
class PromoterController extends Controller
{
    public function __construct(private PromoterDirectory $directory) {}

    /**
     * Liste paginée. meta : pagination, compteurs par statut et listes « à traiter », communes du filtre « Localisation ».
     */
    public function index(PromoterIndexRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $promoters = $this->directory->query($filters)->paginate((int) ($filters['per_page'] ?? 20))->withQueryString();

        return ApiResponse::success(PromoterListResource::collection($promoters->items())->resolve($request), meta: [
            'current_page' => $promoters->currentPage(),
            'last_page' => $promoters->lastPage(),
            'per_page' => $promoters->perPage(),
            'total' => $promoters->total(),
            'counts' => $this->directory->counts(),
            'communes' => $this->directory->communes(),
        ]);
    }

    /**
     * Export CSV (séparateur « ; », UTF-8 avec BOM pour Excel) des promoteurs filtrés ou sélectionnés (ids).
     */
    public function export(PromoterIndexRequest $request): StreamedResponse
    {
        $filters = $request->validated();
        $query = $this->directory->query($filters)->when($filters['ids'] ?? null, fn ($query, array $ids) => $query->whereIn('promoter_profiles.id', $ids));

        AdminAudit::record($request->user(), 'promoters', 'promoters.exported', $request, null, null, ['filters' => $filters]);

        return response()->streamDownload(function () use ($query): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Nom', 'Téléphone', 'E-mail', 'Type', 'Entreprise', 'RCCM', 'Commune', 'Statut', 'Pièces expirées', 'Litiges ouverts', 'Inscription', 'Validation'], ';');

            $query->lazyById(200, 'promoter_profiles.id', 'id')->each(function (PromoterProfile $profile) use ($out): void {
                $user = $profile->user;
                $organization = $profile->profile_kind === PromoterProfileKind::Entreprise ? $user->ownedOrganizations->first() : null;

                fputcsv($out, [
                    trim($user->first_name.' '.$user->last_name),
                    PhoneNumber::of($user)?->toArray()['phone'],
                    $user->email,
                    $profile->profile_kind->label(),
                    $organization?->legal_name,
                    $organization?->rccm,
                    $profile->commune?->name,
                    PromoterListResource::displayStatus($profile)['label'],
                    (int) $profile->expired_documents_count,
                    (int) $profile->open_disputes_count,
                    $profile->created_at?->format('d/m/Y'),
                    $profile->validated_at?->format('d/m/Y'),
                ], ';');
            });

            fclose($out);
        }, 'promoteurs-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function show(Request $request, string $promoter): JsonResponse
    {
        return ApiResponse::success(PromoterDetailResource::make(self::find($promoter))->resolve($request));
    }

    /**
     * Promoteur avec ses compteurs (pièces expirées, litiges ouverts), ou 404.
     */
    public static function find(string $id): PromoterProfile
    {
        abort_unless(str($id)->isUuid(), 404);

        return app(PromoterDirectory::class)->query([])->where('promoter_profiles.id', $id)->firstOrFail();
    }
}
