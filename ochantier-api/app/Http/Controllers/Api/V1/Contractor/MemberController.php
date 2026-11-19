<?php

namespace App\Http\Controllers\Api\V1\Contractor;

use App\Enums\ContractorMemberRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Contractor\MemberInvitationRequest;
use App\Support\ApiResponse;
use App\Support\Contractor\ContractorMembers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * « Mon équipe » : collaborateurs du compte de l’entrepreneur (titulaire uniquement), invitation, changement de rôle
 * et retrait. Chaque action renvoie l’équipe à jour.
 */
class MemberController extends Controller
{
    public function __construct(private ContractorMembers $members) {}

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success($this->members->summary(ProfileController::contractor($request), $request->user()));
    }

    public function store(MemberInvitationRequest $request): JsonResponse
    {
        $contractor = ProfileController::contractor($request);
        $member = $this->members->invite($contractor, $request->user(), $request->invitation());

        return ApiResponse::success($this->members->summary($contractor, $request->user()),
            'Invitation envoyée à '.trim($member->user->first_name.' '.$member->user->last_name).($request->input('channel') === 'sms' ? ' par SMS' : ' par e-mail').'.', status: 201);
    }

    public function update(Request $request, string $member): JsonResponse
    {
        $role = $request->validate(['role' => ['required', Rule::enum(ContractorMemberRole::class)]], ['role.required' => 'Choisissez un rôle.', 'role.enum' => 'Rôle inconnu.'])['role'];
        $contractor = ProfileController::contractor($request);
        $this->members->changeRole($this->members->findMember($contractor, $member), $role);

        return ApiResponse::success($this->members->summary($contractor, $request->user()), 'Rôle modifié.');
    }

    public function destroy(Request $request, string $member): JsonResponse
    {
        $contractor = ProfileController::contractor($request);
        $model = $this->members->findMember($contractor, $member);
        $this->members->remove($model);

        return ApiResponse::success($this->members->summary($contractor, $request->user()), trim($model->user->first_name.' '.$model->user->last_name).' a été retiré de l’équipe.');
    }
}
