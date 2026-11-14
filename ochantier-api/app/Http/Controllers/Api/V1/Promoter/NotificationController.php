<?php

namespace App\Http\Controllers\Api\V1\Promoter;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\Promoter\PromoterNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Centre de « Notifications » de l’utilisateur connecté : onglets, groupes par période, lecture.
 */
class NotificationController extends Controller
{
    public function __construct(private PromoterNotifier $notifier) {}

    /**
     * Filtres : filter (all, unread, offres, paiements, chantiers). meta : non lues et compteurs par onglet.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['filter' => ['nullable', Rule::in(array_keys(PromoterNotifier::TABS))]], ['filter.in' => 'Filtre inconnu.']);
        $center = $this->notifier->center($request->user(), $filters['filter'] ?? 'all');

        return ApiResponse::success($center['data'], meta: $center['meta']);
    }

    /**
     * Badge de la cloche : nombre de notifications non lues, sans la liste.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        return ApiResponse::success(['unread_count' => $this->notifier->unreadCount($request->user())], headers: ['Cache-Control' => 'no-store, private']);
    }

    /**
     * Marque une notification lue ; renvoie le nombre de non lues restantes (unread, et unread_count pour le badge).
     */
    public function read(Request $request, string $notification): JsonResponse
    {
        $this->notifier->markRead($request->user(), $notification);
        $unread = $this->notifier->unreadCount($request->user());

        return ApiResponse::success(['unread' => $unread, 'unread_count' => $unread]);
    }

    /**
     * « Tout marquer lu ».
     */
    public function readAll(Request $request): JsonResponse
    {
        $count = $this->notifier->markRead($request->user());

        return ApiResponse::success(['unread' => 0, 'unread_count' => 0], $count ? 'Toutes les notifications sont lues' : null);
    }
}
