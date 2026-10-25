<?php

namespace App\Http\Controllers\Api\V1\Promoter;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Promoter\ReferralRequest;
use App\Support\ApiResponse;
use App\Support\Promoter\PromoterAccess;
use App\Support\Promoter\PromoterReferrals;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * « Inviter un ami » : lien et code parrain du compte, invitations par SMS et proches inscrits.
 */
class ReferralController extends Controller
{
    public function __construct(private PromoterReferrals $referrals, private PromoterAccess $access) {}

    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success($this->referrals->summary($this->access->profile($request->user())));
    }

    /**
     * « Inviter par SMS ».
     */
    public function store(ReferralRequest $request): JsonResponse
    {
        $phone = $request->phoneNumber();
        $profile = $this->access->profile($request->user());
        $this->referrals->invite($profile, $phone, $request->input('name'));

        return ApiResponse::success($this->referrals->summary($profile), 'Invitation envoyée par SMS au '.$phone->formatted(), 201);
    }
}
