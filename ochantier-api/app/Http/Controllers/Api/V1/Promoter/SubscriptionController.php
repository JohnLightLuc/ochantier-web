<?php

namespace App\Http\Controllers\Api\V1\Promoter;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\Promoter\PromoterSubscriptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * « Abonnement » : formule en cours, formules proposées, facturation et résiliation. La souscription, le changement
 * de formule et le règlement d’une facture passent par POST /promoter/payments (plan_code ou
 * subscription_invoice_id).
 */
class SubscriptionController extends Controller
{
    public function __construct(private PromoterSubscriptions $subscriptions) {}

    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success($this->subscriptions->summary(ProfileController::profile($request)));
    }

    /**
     * « Résilier l’abonnement » : la formule reste active jusqu’à la fin de la période payée.
     */
    public function cancel(Request $request): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']], ['reason.max' => 'Le motif ne doit pas dépasser 500 caractères.']);
        $profile = ProfileController::profile($request);
        $subscription = $this->subscriptions->cancel($profile, $request->user(), $data['reason'] ?? null);

        return ApiResponse::success(
            $this->subscriptions->summary($profile),
            'Abonnement résilié · votre formule '.$subscription->plan->name.' reste active jusqu’au '.$subscription->current_period_end->locale('fr')->translatedFormat('j M Y').'.',
        );
    }
}
