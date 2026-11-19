<?php

namespace App\Http\Controllers\Api\V1\Contractor;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\Contractor\ContractorNotifier;
use App\Support\Contractor\ContractorSupport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Centre de notifications (onglets, groupes par période, lecture) et assistance (« Nous écrire », mes demandes) de
 * l’application Entrepreneurs et Techniciens. Les questions fréquentes et les contacts du support sont sur GET /help.
 */
class AssistanceController extends Controller
{
    public function __construct(private ContractorNotifier $notifier, private ContractorSupport $support) {}

    /**
     * Filtres : filter (toutes, non_lues, nouvelles_demandes, offres_acceptees, offres_refusees, paiements,
     * rendez_vous, chantiers, documents, abonnement). meta : non lues et compteurs par onglet.
     */
    public function notifications(Request $request): JsonResponse
    {
        $filters = $request->validate(['filter' => ['nullable', Rule::in(array_keys(ContractorNotifier::TABS))]], ['filter.in' => 'Filtre inconnu.']);
        $center = $this->notifier->center($request->user(), $filters['filter'] ?? 'toutes');

        return ApiResponse::success($center['data'], meta: $center['meta']);
    }

    public function read(Request $request, string $notification): JsonResponse
    {
        $this->notifier->markRead($request->user(), $notification);

        return ApiResponse::success(['unread' => $this->notifier->unreadCount($request->user())]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $count = $this->notifier->markRead($request->user());

        return ApiResponse::success(['unread' => 0], $count ? 'Toutes les notifications sont lues' : null);
    }

    public function tickets(Request $request): JsonResponse
    {
        $tickets = $this->support->list($request->user());

        return ApiResponse::success($tickets->all(), meta: ['count' => $tickets->count(), 'subjects' => array_keys(ContractorSupport::SUBJECTS)]);
    }

    /**
     * « Nous écrire » : sujet et message (10 caractères au moins) ; client_uuid évite un doublon en cas de renvoi.
     */
    public function storeTicket(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subject' => ['required', Rule::in(array_keys(ContractorSupport::SUBJECTS))],
            'message' => ['required', 'string', 'min:10', 'max:3000'],
            'client_uuid' => ['nullable', 'uuid'],
        ], [
            'subject.required' => 'Choisissez un sujet.',
            'subject.in' => 'Choisissez un sujet.',
            'message.required' => 'Décrivez votre demande (10 caractères minimum).',
            'message.min' => 'Décrivez votre demande (10 caractères minimum).',
        ]);
        $ticket = $this->support->create(ProfileController::contractor($request), $request->user(), $data['subject'], trim($data['message']), $data['client_uuid'] ?? null);

        return ApiResponse::success($this->support->ticket($ticket), 'Demande '.$ticket->reference.' enregistrée : le support vous répond sous 48 h.', status: 201);
    }
}
