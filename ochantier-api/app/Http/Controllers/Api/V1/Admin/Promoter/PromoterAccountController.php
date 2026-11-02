<?php

namespace App\Http\Controllers\Api\V1\Admin\Promoter;

use App\Enums\DocumentAudience;
use App\Enums\PromoterProfileKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Admin\Promoter\PromoterFormRequest;
use App\Http\Requests\V1\Promoter\PromoterDocumentRequest;
use App\Http\Resources\V1\Admin\PromoterDetailResource;
use App\Models\City;
use App\Models\DocumentType;
use App\Support\Admin\PromoterAdministration;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Création et modification d’un promoteur par le Back Office, ajout d’une pièce à son dossier, et listes du
 * formulaire (types de compte, communes et quartiers, types de pièces).
 */
class PromoterAccountController extends Controller
{
    public function __construct(private PromoterAdministration $administration) {}

    public function formOptions(): JsonResponse
    {
        $cities = City::query()
            ->where('is_active', true)
            ->with(['communes' => fn ($query) => $query->where('is_active', true)->orderBy('name')->with(['neighborhoods' => fn ($query) => $query->where('is_active', true)->orderBy('name')])])
            ->orderByRaw("name <> 'Abidjan'")
            ->orderBy('name')
            ->get();

        return ApiResponse::success([
            'profile_kinds' => array_map(fn (PromoterProfileKind $kind) => ['value' => $kind->value, 'label' => $kind->label()], PromoterProfileKind::cases()),
            'cities' => $cities->map(fn (City $city) => [
                'id' => $city->id,
                'name' => $city->name,
                'communes' => $city->communes->map(fn ($commune) => [
                    'id' => $commune->id,
                    'name' => $commune->name,
                    'neighborhoods' => $commune->neighborhoods->map->only(['id', 'name'])->values(),
                ])->values(),
            ]),
            'document_types' => DocumentType::query()
                ->whereIn('audience', [DocumentAudience::Promoter, DocumentAudience::Organization])
                ->where('is_active', true)
                ->orderBy('created_at')
                ->get()
                ->map(fn (DocumentType $type) => [
                    'code' => $type->code,
                    'label' => $type->label,
                    'audience' => $type->audience->value,
                    'is_identity' => $type->is_identity,
                    'is_required' => $type->is_required,
                    'has_back_side' => $type->has_back_side,
                    'allowed_mime' => $type->allowed_mime,
                    'max_size_kb' => $type->max_size_kb,
                ]),
        ]);
    }

    public function store(PromoterFormRequest $request): JsonResponse
    {
        $phone = $request->phoneNumber();
        $invite = $request->sendsInvitation();
        $profile = $this->administration->create($request->validated(), $phone, $invite, $request->user(), $request);

        $message = 'Compte promoteur créé'.($invite ? ' : invitation envoyée par '.($phone ? 'SMS' : 'e-mail').'.' : ', en attente d’activation.');

        return ApiResponse::success(PromoterDetailResource::make(PromoterController::find($profile->id))->resolve($request), $message, 201);
    }

    public function update(PromoterFormRequest $request, string $promoter): JsonResponse
    {
        $profile = $this->administration->update(PromoterController::find($promoter), $request->validated(), $request->phoneNumber(), $request->user(), $request);

        return ApiResponse::success(PromoterDetailResource::make(PromoterController::find($profile->id))->resolve($request), 'Promoteur mis à jour.');
    }

    public function storeDocument(PromoterDocumentRequest $request, string $promoter): JsonResponse
    {
        $profile = PromoterController::find($promoter);
        $document = $this->administration->attachDocument($profile, $request->documentType(), $request->sides(), $request->input('number'), $request->input('expires_at'), $request->user(), $request);

        return ApiResponse::success(PromoterDetailResource::make(PromoterController::find($promoter))->resolve($request), $document->documentType->label.' ajouté au dossier.', 201);
    }
}
