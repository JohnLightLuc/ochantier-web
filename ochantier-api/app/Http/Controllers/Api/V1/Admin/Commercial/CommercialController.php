<?php

namespace App\Http\Controllers\Api\V1\Admin\Commercial;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Admin\Commercial\CommercialIndexRequest;
use App\Http\Resources\V1\Admin\CommercialDetailResource;
use App\Http\Resources\V1\Admin\CommercialListResource;
use App\Models\CommercialProfile;
use App\Support\Admin\AdminAudit;
use App\Support\Admin\CommercialDirectory;
use App\Support\ApiResponse;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Commerciaux du Back Office : liste filtrée avec compteurs, export CSV et fiche.
 */
class CommercialController extends Controller
{
    public function __construct(private CommercialDirectory $directory) {}

    /**
     * Liste paginée. meta : pagination, compteurs (statuts, listes « à traiter », ouvriers, commissions à verser),
     * communes et superviseurs des filtres.
     */
    public function index(CommercialIndexRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $commercials = $this->directory->query($filters)->paginate((int) ($filters['per_page'] ?? 20))->withQueryString();

        return ApiResponse::success(CommercialListResource::collection($commercials->items())->resolve($request), meta: [
            'current_page' => $commercials->currentPage(),
            'last_page' => $commercials->lastPage(),
            'per_page' => $commercials->perPage(),
            'total' => $commercials->total(),
            'counts' => $this->directory->counts(),
            ...$this->directory->options(),
        ]);
    }

    /**
     * Export CSV (séparateur « ; », UTF-8 avec BOM) des commerciaux filtrés ou sélectionnés (ids).
     */
    public function export(CommercialIndexRequest $request): StreamedResponse
    {
        $filters = $request->validated();
        $query = $this->directory->query($filters)->when($filters['ids'] ?? null, fn ($query, array $ids) => $query->whereIn('commercial_profiles.id', $ids));

        AdminAudit::record($request->user(), 'commercials', 'commercials.exported', $request, null, null, ['filters' => $filters]);

        return response()->streamDownload(function () use ($query): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Matricule', 'Nom', 'Téléphone', 'E-mail', 'Zone principale', 'Superviseur', 'Ouvriers enregistrés', 'Validés ce mois', 'Taux de validation (%)', 'Commissions du mois (FCFA)', 'Commissions à verser (FCFA)', 'Statut'], ';');

            foreach ($query->get() as $commercial) {
                /** @var CommercialProfile $commercial */
                $supervisor = $commercial->supervisor?->user;

                fputcsv($out, [
                    $commercial->employee_number,
                    trim($commercial->user->first_name.' '.$commercial->user->last_name),
                    PhoneNumber::of($commercial->user)?->formatted(),
                    $commercial->user->email,
                    $commercial->primary_zone,
                    $supervisor ? trim($supervisor->first_name.' '.$supervisor->last_name) : null,
                    $commercial->total_workers,
                    $commercial->month_validated,
                    $commercial->validation_rate,
                    $commercial->month_commissions,
                    $commercial->unpaid_commissions,
                    CommercialDirectory::STATUSES[$commercial->account_status],
                ], ';');
            }

            fclose($out);
        }, 'commerciaux-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function show(Request $request, string $commercial): JsonResponse
    {
        return ApiResponse::success(CommercialDetailResource::make(self::find($commercial))->resolve($request));
    }

    public static function find(string $id): CommercialProfile
    {
        abort_unless(str($id)->isUuid(), 404);

        return app(CommercialDirectory::class)->query([])->where('commercial_profiles.id', $id)->with(['createdByAdmin', 'user.suspendedByAdmin'])->firstOrFail();
    }
}
