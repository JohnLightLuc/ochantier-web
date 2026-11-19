<?php

namespace App\Http\Controllers\Api\V1\Contractor;

use App\Enums\ContractorKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Contractor\ChooseContractorProfileRequest;
use App\Http\Requests\V1\Contractor\ContractorPhotoRequest;
use App\Http\Requests\V1\Contractor\SubmitContractorProfileRequest;
use App\Http\Requests\V1\Contractor\UpdateContractorProfileRequest;
use App\Http\Resources\V1\Contractor\ContractorProfileResource;
use App\Models\Contractor;
use App\Support\Contractor\ContractorAccess;
use App\Support\Contractor\ContractorAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Profil de l’entrepreneur connecté : choix du profil (entreprise ou indépendant), dossier de vérification en
 * brouillon, photo ou logo, et soumission au Back Office.
 */
class ProfileController extends Controller
{
    public function __construct(private ContractorAccount $account) {}

    /**
     * 404 tant que le profil n’est pas choisi (« Quel est votre profil ? »).
     */
    public function show(Request $request): ContractorProfileResource
    {
        return ContractorProfileResource::make($this->contractor($request));
    }

    public function store(ChooseContractorProfileRequest $request): JsonResponse
    {
        $contractor = $this->account->choose($request->user(), ContractorKind::from($request->validated('kind')), collect($request->profileData())->except('kind')->all());

        return ContractorProfileResource::make($contractor)
            ->additional(['message' => 'Profil choisi : complétez votre dossier.'])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Enregistre les champs envoyés. Sur un dossier vérifié, une donnée sensible modifiée remet le dossier en
     * brouillon : il faut le soumettre de nouveau.
     */
    public function update(UpdateContractorProfileRequest $request): ContractorProfileResource
    {
        $contractor = $this->account->update($this->contractor($request), $request->profileData());

        return ContractorProfileResource::make($contractor)->additional(['message' => 'Dossier enregistré']);
    }

    public function submit(SubmitContractorProfileRequest $request): ContractorProfileResource
    {
        $contractor = $this->account->submit($this->contractor($request), $request->user());

        return ContractorProfileResource::make($contractor)->additional(['message' => 'Dossier soumis à vérification.']);
    }

    public function storePhoto(ContractorPhotoRequest $request): ContractorProfileResource
    {
        $contractor = $this->account->setPhoto($this->contractor($request), $request->user(), $request->file('photo'));

        return ContractorProfileResource::make($contractor)->additional(['message' => $contractor->isCompany() ? 'Logo mis à jour' : 'Photo mise à jour']);
    }

    public function destroyPhoto(Request $request): ContractorProfileResource
    {
        $contractor = $this->account->removePhoto($this->contractor($request));

        return ContractorProfileResource::make($contractor)->additional(['message' => $contractor->isCompany() ? 'Logo retiré' : 'Photo retirée']);
    }

    /**
     * Dossier de l’utilisateur, relu en base à chaque requête.
     */
    public static function contractor(Request $request): Contractor
    {
        $contractor = app(ContractorAccess::class)->contractor($request->user())?->load(ContractorAccount::WITH);

        abort_unless($contractor, 404, 'Choisissez d’abord votre profil : entreprise ou indépendant.');

        return $contractor;
    }
}
