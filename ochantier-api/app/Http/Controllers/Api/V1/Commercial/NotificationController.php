<?php

namespace App\Http\Controllers\Api\V1\Commercial;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\Commercial\CommercialNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Centre de « Notifications » du commercial : onglets, groupes par période, lecture.
 */
class NotificationController extends Controller
{
    public function __construct(private CommercialNotifier $notifier) {}

    /**
     * Filtres : filter (toutes, non_lues, dossiers, paiements). meta : non lues et compteurs par onglet.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['filter' => ['nullable', Rule::in(array_keys(CommercialNotifier::TABS))]], ['filter.in' => 'Filtre inconnu.']);
        $center = $this->notifier->center($request->user(), $filters['filter'] ?? 'toutes');

        return ApiResponse::success($center['data'], meta: $center['meta']);
    }

    public function read(Request $request, string $notification): JsonResponse
    {
        $this->notifier->markRead($request->user(), $notification);

        return ApiResponse::success(['unread' => $this->notifier->unreadCount($request->user())]);
    }

    /**
     * « Tout marquer lu ».
     */
    public function readAll(Request $request): JsonResponse
    {
        $count = $this->notifier->markRead($request->user());

        return ApiResponse::success(['unread' => 0], $count ? 'Toutes les notifications sont lues' : null);
    }
}
