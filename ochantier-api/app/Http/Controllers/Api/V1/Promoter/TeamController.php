<?php

namespace App\Http\Controllers\Api\V1\Promoter;

use App\Enums\OrganizationRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Promoter\InvitationRequest;
use App\Support\ApiResponse;
use App\Support\Promoter\PromoterAccess;
use App\Support\Promoter\PromoterActivity;
use App\Support\Promoter\PromoterSubscriptions;
use App\Support\Promoter\PromoterTeam;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * « Utilisateurs et rôles » et « Activité des utilisateurs » du compte promoteur.
 */
class TeamController extends Controller
{
    public function __construct(
        private PromoterTeam $team,
        private PromoterAccess $access,
        private PromoterActivity $activity,
        private PromoterSubscriptions $subscriptions,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success($this->team->summary($this->access->profile($request->user()), $request->user()));
    }

    public function invite(InvitationRequest $request): JsonResponse
    {
        $profile = $this->access->profile($request->user());
        $data = $request->invitation();
        $this->team->invite($profile, $request->user(), $data);

        return ApiResponse::success(
            $this->team->summary($profile, $request->user()),
            'Invitation envoyée '.($data['channel'] === 'email' ? 'par e-mail à '.mb_strtolower($data['email']) : 'par SMS au '.$data['phone']->formatted()),
            201,
        );
    }

    public function update(Request $request, string $member): JsonResponse
    {
        $data = $request->validate(['role' => ['required', Rule::enum(OrganizationRole::class)]], ['role.required' => 'Choisissez un rôle.', 'role.enum' => 'Rôle inconnu.']);
        $profile = $this->access->profile($request->user());
        $member = $this->team->findMember($profile, $member);

        if ($member->user->is($request->user())) {
            return response()->json(['message' => 'Vous ne pouvez pas changer votre propre rôle.'], 403);
        }

        $member = $this->team->changeRole($member, $data['role']);

        return ApiResponse::success($this->team->summary($profile, $request->user()), trim($member->user->first_name.' '.$member->user->last_name).' est maintenant '.PromoterAccess::ROLE_LABELS[$data['role']].'.');
    }

    public function destroy(Request $request, string $member): JsonResponse
    {
        $profile = $this->access->profile($request->user());
        $member = $this->team->findMember($profile, $member);

        if ($member->user->is($request->user())) {
            return response()->json(['message' => 'Vous ne pouvez pas vous retirer vous-même.'], 403);
        }

        $this->team->remove($member);

        return ApiResponse::success($this->team->summary($profile, $request->user()), trim($member->user->first_name.' '.$member->user->last_name).' retiré');
    }

    /**
     * Connexions et actions des utilisateurs du compte ; réservé aux comptes abonnés.
     */
    public function activity(Request $request): JsonResponse
    {
        $profile = $this->access->profile($request->user());

        if (! $this->subscriptions->isSubscribed($profile)) {
            return ApiResponse::success([], meta: ['locked' => true, 'lock_message' => 'Historique réservé aux abonnés : suivez les connexions et les actions de votre équipe avec une formule Équipe, Pro ou Entreprise.']);
        }

        return ApiResponse::success($this->activity->recent($profile), meta: ['locked' => false, 'lock_message' => null]);
    }
}
