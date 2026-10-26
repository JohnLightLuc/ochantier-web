<?php

namespace App\Http\Controllers\Api\V1\Commercial;

use App\Enums\ProspectStatus;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureActiveCommercial;
use App\Http\Resources\V1\Commercial\ProspectResource;
use App\Support\ApiResponse;
use App\Support\Commercial\CommercialEarnings;
use App\Support\Commercial\CommercialNotifier;
use App\Support\Commercial\CommercialPerformance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * « Accueil » : priorités du jour, activité de la semaine, objectif du mois, gains de la semaine et prospects à suivre.
 */
class HomeController extends Controller
{
    public function __construct(private CommercialPerformance $performance, private CommercialEarnings $earnings, private CommercialNotifier $notifier) {}

    public function __invoke(Request $request): JsonResponse
    {
        $commercial = EnsureActiveCommercial::profile($request);
        $week = $this->performance->week($commercial);
        $corrections = collect($week)->firstWhere('key', 'a_corriger')['count'];

        return ApiResponse::success([
            'greeting' => ['title' => 'Bonjour, '.($request->user()->first_name ?: 'à vous'), 'subtitle' => 'Voici vos priorités du jour'],
            'unread_notifications' => $this->notifier->unreadCount($request->user()),
            'corrections' => ['count' => $corrections, 'label' => $corrections ? $corrections.' dossier'.($corrections > 1 ? 's' : '').' à corriger' : 'Aucun dossier à corriger'],
            'week' => $week,
            'objective' => $this->performance->objective($commercial),
            'earnings' => $this->earnings->week($commercial),
            'to_follow' => ProspectResource::collection($commercial->prospects()
                ->whereNotIn('status', [ProspectStatus::Converti, ProspectStatus::Abandonne])
                ->with(ProspectController::WITH)
                ->oldest('updated_at')
                ->limit(3)
                ->get()),
        ]);
    }
}
