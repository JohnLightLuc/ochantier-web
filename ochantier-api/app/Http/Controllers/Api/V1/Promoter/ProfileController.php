<?php

namespace App\Http\Controllers\Api\V1\Promoter;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Promoter\PromoterPhotoRequest;
use App\Http\Requests\V1\Promoter\UpdatePromoterProfileRequest;
use App\Http\Resources\V1\Promoter\PromoterProfileResource;
use App\Models\PromoterProfile;
use App\Support\Promoter\PromoterAccess;
use App\Support\Promoter\PromoterAccount;
use Illuminate\Http\Request;

/**
 * Profil du promoteur connecté (« Profil », « Modifier le profil ») et photo de profil.
 */
class ProfileController extends Controller
{
    public const WITH = ['user', 'photoMedia', 'commune', 'neighborhood'];

    public function __construct(private PromoterAccount $account) {}

    public function show(Request $request): PromoterProfileResource
    {
        return PromoterProfileResource::make($this->profile($request));
    }

    /**
     * Enregistre les champs envoyés. Le profil est transmis au Back office dès qu’il est complet.
     */
    public function update(UpdatePromoterProfileRequest $request): PromoterProfileResource
    {
        $profile = $this->account->update($this->profile($request), $request->user(), $request->validated());

        return PromoterProfileResource::make($profile->load(self::WITH))->additional(['message' => 'Profil mis à jour']);
    }

    public function storePhoto(PromoterPhotoRequest $request): PromoterProfileResource
    {
        $profile = $this->account->setPhoto($this->profile($request), $request->user(), $request->file('photo'));

        return PromoterProfileResource::make($profile->load(self::WITH))->additional(['message' => 'Photo mise à jour']);
    }

    public function destroyPhoto(Request $request): PromoterProfileResource
    {
        $profile = $this->account->removePhoto($this->profile($request));

        return PromoterProfileResource::make($profile->load(self::WITH))->additional(['message' => 'Photo retirée']);
    }

    /**
     * Profil du compte de l’utilisateur (le sien, ou celui du propriétaire pour un collaborateur), relu en base à
     * chaque requête.
     */
    public static function profile(Request $request): PromoterProfile
    {
        return PromoterProfile::query()->whereKey(app(PromoterAccess::class)->profile($request->user())?->getKey())->with(self::WITH)->firstOrFail();
    }
}
