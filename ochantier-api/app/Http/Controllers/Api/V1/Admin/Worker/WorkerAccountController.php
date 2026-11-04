<?php

namespace App\Http\Controllers\Api\V1\Admin\Worker;

use App\Enums\CommercialProfileStatus;
use App\Enums\DocumentAudience;
use App\Enums\Gender;
use App\Enums\OfferType;
use App\Enums\PaymentFrequency;
use App\Enums\WorkerVerificationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Admin\Worker\WorkerFormRequest;
use App\Http\Requests\V1\Commercial\ProspectDocumentRequest;
use App\Http\Resources\V1\Admin\WorkerDetailResource;
use App\Models\City;
use App\Models\CommercialProfile;
use App\Models\DocumentType;
use App\Models\Trade;
use App\Models\Worker;
use App\Support\Admin\WorkerAdministration;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * Création et modification d’un ouvrier par le Back Office, ajout d’une pièce à son dossier, listes du formulaire.
 */
class WorkerAccountController extends Controller
{
    public function __construct(private WorkerAdministration $administration) {}

    /**
     * Listes du formulaire : commerciaux actifs, métiers avec leurs spécialités et compétences, villes, communes et
     * quartiers, types de pièces, sexes, types de mission et rythmes de paiement.
     */
    public function formOptions(): JsonResponse
    {
        $cities = City::query()
            ->where('is_active', true)
            ->with(['communes' => fn ($query) => $query->where('is_active', true)->orderBy('name')->with(['neighborhoods' => fn ($query) => $query->where('is_active', true)->orderBy('name')])])
            ->orderByRaw("name <> 'Abidjan'")
            ->orderBy('name')
            ->get();

        return ApiResponse::success([
            'commercials' => CommercialProfile::query()->where('status', CommercialProfileStatus::Actif)->with('user')->get()
                ->map(fn (CommercialProfile $commercial) => ['id' => $commercial->id, 'full_name' => trim($commercial->user?->first_name.' '.$commercial->user?->last_name) ?: 'Commercial sans nom'])
                ->sortBy('full_name')->values(),
            'trades' => Trade::query()->where('is_active', true)->orderBy('sort_order')->orderBy('label')
                ->with(['specialties' => fn ($query) => $query->where('is_active', true)->orderBy('label'), 'skills' => fn ($query) => $query->where('is_active', true)->orderBy('sort_order')->orderBy('label')])
                ->get()
                ->map(fn (Trade $trade) => [
                    'id' => $trade->id,
                    'code' => $trade->code,
                    'label' => $trade->label,
                    'specialties' => $trade->specialties->map->only(['id', 'label'])->values(),
                    'skills' => $trade->skills->map->only(['id', 'label'])->values(),
                ]),
            'cities' => $cities->map(fn (City $city) => [
                'id' => $city->id,
                'name' => $city->name,
                'communes' => $city->communes->map(fn ($commune) => [
                    'id' => $commune->id,
                    'name' => $commune->name,
                    'neighborhoods' => $commune->neighborhoods->map->only(['id', 'name'])->values(),
                ])->values(),
            ]),
            'document_types' => DocumentType::query()->where('audience', DocumentAudience::Worker)->where('is_active', true)->orderBy('created_at')->get()
                ->map(fn (DocumentType $type) => [
                    'code' => $type->code,
                    'label' => $type->label,
                    'is_identity' => $type->is_identity,
                    'is_required' => $type->is_required,
                    'has_back_side' => $type->has_back_side,
                    'allowed_mime' => $type->allowed_mime,
                    'max_size_kb' => $type->max_size_kb,
                ]),
            'genders' => array_map(fn (Gender $gender) => ['value' => $gender->value, 'label' => $gender->label()], Gender::cases()),
            'offer_types' => array_map(fn (OfferType $type) => ['value' => $type->value, 'label' => $type->label()], OfferType::cases()),
            'payment_frequencies' => array_map(fn (PaymentFrequency $frequency) => ['value' => $frequency->value, 'label' => $frequency->label()], PaymentFrequency::cases()),
        ]);
    }

    public function store(WorkerFormRequest $request): JsonResponse
    {
        $worker = $this->administration->create($request->validated(), $request->user(), $request);

        return ApiResponse::success(
            WorkerDetailResource::make(WorkerController::find($worker->id))->resolve($request),
            $worker->verification_status === WorkerVerificationStatus::Soumis ? 'Ouvrier enregistré : le dossier est prêt pour le contrôle.' : 'Ouvrier enregistré : ajoutez les pièces manquantes pour transmettre le dossier.',
            201,
        );
    }

    public function update(WorkerFormRequest $request, string $worker): JsonResponse
    {
        $model = $this->editable($worker);
        $this->administration->update($model, $request->validated(), $request->user(), $request);

        return ApiResponse::success(WorkerDetailResource::make(WorkerController::find($worker))->resolve($request), 'Ouvrier mis à jour.');
    }

    public function storeDocument(ProspectDocumentRequest $request, string $worker): JsonResponse
    {
        $model = $this->editable($worker);
        $document = $this->administration->attachDocument($model, $request->documentType(), $request->sides(), $request->input('number'), $request->input('expires_at'), $request->user(), $request);

        return ApiResponse::success(WorkerDetailResource::make(WorkerController::find($worker))->resolve($request), $document->documentType->label.' ajoutée au dossier.', 201);
    }

    /**
     * Un ouvrier exclu ne se modifie plus.
     */
    private function editable(string $id): Worker
    {
        $worker = WorkerController::find($id);

        if ($worker->verification_status === WorkerVerificationStatus::Exclu) {
            throw ValidationException::withMessages(['worker' => 'Cet ouvrier est exclu : sa fiche ne peut plus être modifiée.']);
        }

        return $worker;
    }
}
