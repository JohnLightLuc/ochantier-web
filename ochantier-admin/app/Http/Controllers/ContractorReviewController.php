<?php

namespace App\Http\Controllers;

use App\Support\Api\AdminApi;
use Closure;
use Illuminate\Http\Client\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Décisions sur un entrepreneur, envoyées à l’API : dossier (validation, complément, refus), pièces (confirmer,
 * rejeter), suspension et réactivation. Le message de l’API est affiché ; un refus sur le dossier devient une
 * notification d’erreur.
 */
class ContractorReviewController extends Controller
{
    public function __construct(private AdminApi $api) {}

    public function approve(string $contractor): RedirectResponse
    {
        return $this->send(fn () => $this->api->post("contractors/{$contractor}/approve"));
    }

    /**
     * Complément demandé : motif général et pièces à remplacer (les pièces déjà rejetées sont reprises par l’API).
     */
    public function requestCorrections(Request $request, string $contractor): RedirectResponse
    {
        return $this->send(fn () => $this->api->post("contractors/{$contractor}/corrections", [
            'reason' => $this->reason($request),
            'document_ids' => array_values(array_filter((array) $request->input('document_ids', []), 'is_string')),
        ]));
    }

    public function reject(Request $request, string $contractor): RedirectResponse
    {
        return $this->send(fn () => $this->api->post("contractors/{$contractor}/reject", ['reason' => $this->reason($request)]));
    }

    public function approveDocument(string $contractor, string $document): RedirectResponse
    {
        return $this->send(fn () => $this->api->post("contractors/{$contractor}/documents/{$document}/approve"));
    }

    public function rejectDocument(Request $request, string $contractor, string $document): RedirectResponse
    {
        return $this->send(fn () => $this->api->post("contractors/{$contractor}/documents/{$document}/reject", ['reason' => $this->reason($request)]));
    }

    public function suspend(Request $request, string $contractor): RedirectResponse
    {
        return $this->send(fn () => $this->api->post("contractors/{$contractor}/suspension", ['reason' => $this->reason($request)]));
    }

    public function reactivate(string $contractor): RedirectResponse
    {
        return $this->send(fn () => $this->api->delete("contractors/{$contractor}/suspension"));
    }

    /**
     * Motif saisi, précédé de la catégorie choisie (« Document illisible · verso flou »).
     */
    private function reason(Request $request): string
    {
        $reason = trim((string) $request->input('reason'));
        $category = trim((string) $request->input('category'));

        return $category && $reason && $category !== 'Autre' ? "{$category} · {$reason}" : $reason;
    }

    /**
     * Appelle l’API puis revient sur la page avec son message. Les erreurs liées à un champ du formulaire restent
     * des erreurs de formulaire ; un refus sur le dossier (clé contractor, document_id…) devient une notification.
     *
     * @param  Closure(): Response  $call
     */
    private function send(Closure $call): RedirectResponse
    {
        try {
            $response = $call();
        } catch (ValidationException $e) {
            $refusal = array_intersect_key($e->errors(), array_flip(['contractor', 'document', 'document_id', 'document_ids', 'api']));

            if ($refusal === []) {
                throw $e;
            }

            Inertia::flash('toastError', collect($refusal)->flatten()->first());

            return back();
        }

        if ($message = $response->json('message')) {
            Inertia::flash('toast', $message);
        }

        return back();
    }
}
