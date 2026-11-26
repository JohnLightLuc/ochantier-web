<?php

namespace App\Http\Controllers\Api\V1\Admin\Quote;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Admin\Promoter\PromoterReasonRequest;
use App\Http\Requests\V1\Admin\Quote\QuoteRequestIndexRequest;
use App\Http\Resources\V1\Admin\QuoteRequestDetailResource;
use App\Http\Resources\V1\Admin\QuoteRequestListResource;
use App\Support\Admin\QuoteRequestDirectory;
use App\Support\Admin\QuoteRequestOperations;
use App\Support\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Demandes de devis du Back Office (O’CHANTIER PRO) : liste par corps de métier, fiche, prolongation, annulation et
 * relances. Chaque décision renvoie la fiche à jour.
 */
class QuoteRequestController extends Controller
{
    public function __construct(private QuoteRequestDirectory $directory, private QuoteRequestOperations $operations) {}

    /**
     * Liste paginée. meta : pagination, nombre de lignes par statut affiché, métiers et villes des filtres.
     */
    public function index(QuoteRequestIndexRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $lines = $this->directory->query($filters)->paginate((int) ($filters['per_page'] ?? 20))->withQueryString();

        return ApiResponse::success(QuoteRequestListResource::collection($lines->items())->resolve($request), meta: [
            'current_page' => $lines->currentPage(),
            'last_page' => $lines->lastPage(),
            'per_page' => $lines->perPage(),
            'total' => $lines->total(),
            'counts' => $this->directory->counts(),
            ...$this->directory->options(),
        ]);
    }

    public function show(Request $request, string $line): JsonResponse
    {
        return $this->fiche($request, $line);
    }

    public function extend(Request $request, string $line): JsonResponse
    {
        $data = $request->validate(['deadline' => ['required', 'date', 'after:now']], ['deadline.required' => 'Indiquez la nouvelle date limite.', 'deadline.after' => 'La date limite doit être dans le futur.']);
        $this->operations->extend($this->directory->find($line), Carbon::parse($data['deadline'])->endOfDay(), $request->user(), $request);

        return $this->fiche($request, $line, 'Date limite prolongée.');
    }

    public function cancel(PromoterReasonRequest $request, string $line): JsonResponse
    {
        $this->operations->cancel($this->directory->find($line), $request->user(), $request->reason(), $request);

        return $this->fiche($request, $line, 'Demande de devis annulée.');
    }

    public function decideVisit(PromoterReasonRequest $request, string $line): JsonResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:confirmer,annuler']], ['decision.required' => 'Confirmez ou annulez la visite.', 'decision.in' => 'Confirmez ou annulez la visite.']);
        $confirmed = $data['decision'] === 'confirmer';
        $this->operations->decideVisit($this->directory->find($line), $confirmed, $request->reason(), $request->user(), $request);

        return $this->fiche($request, $line, $confirmed ? 'Visite confirmée : les frais de visite sont dus au professionnel.' : 'Visite annulée : le professionnel doit la refaire.');
    }

    public function remind(Request $request, string $line): JsonResponse
    {
        $data = $request->validate(
            ['target' => ['required', 'in:promoter,contractor'], 'message' => ['nullable', 'string', 'min:5', 'max:500']],
            ['target.required' => 'Choisissez le destinataire de la relance.', 'target.in' => 'Destinataire inconnu.'],
        );
        $this->operations->remind($this->directory->find($line), $data['target'], isset($data['message']) ? trim($data['message']) : null, $request->user(), $request);

        return $this->fiche($request, $line, $data['target'] === 'promoter' ? 'Relance envoyée au promoteur.' : 'Relance envoyée au professionnel.');
    }

    private function fiche(Request $request, string $line, ?string $message = null): JsonResponse
    {
        return ApiResponse::success(QuoteRequestDetailResource::make($this->directory->find($line))->resolve($request), $message);
    }
}
