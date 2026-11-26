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
 * Décisions sur une demande de devis, envoyées à l’API : prolonger la date limite, annuler la demande, relancer le
 * promoteur ou le professionnel, trancher une visite contestée. Le message de l’API est affiché ; un refus devient une notification d’erreur.
 */
class QuoteRequestDecisionController extends Controller
{
    public function __construct(private AdminApi $api) {}

    public function extend(Request $request, string $quote): RedirectResponse
    {
        return $this->send(fn () => $this->api->post("quote-requests/{$quote}/extension", ['deadline' => $request->input('deadline')]));
    }

    public function cancel(Request $request, string $quote): RedirectResponse
    {
        $reason = trim((string) $request->input('reason'));
        $category = trim((string) $request->input('category'));

        return $this->send(fn () => $this->api->post("quote-requests/{$quote}/cancellation", ['reason' => $category && $reason && $category !== 'Autre' ? "{$category} · {$reason}" : $reason]));
    }

    /**
     * Visite contestée par le promoteur : « confirmer » (frais dus au professionnel) ou « annuler » (visite à refaire).
     */
    public function decideVisit(Request $request, string $quote): RedirectResponse
    {
        return $this->send(fn () => $this->api->post("quote-requests/{$quote}/visit-decision", ['decision' => $request->input('decision'), 'reason' => trim((string) $request->input('reason'))]));
    }

    public function remind(Request $request, string $quote): RedirectResponse
    {
        return $this->send(fn () => $this->api->post("quote-requests/{$quote}/reminders", array_filter(['target' => $request->input('target'), 'message' => trim((string) $request->input('message')) ?: null])));
    }

    /**
     * Appelle l’API puis revient sur la page avec son message. Les erreurs de champ restent des erreurs de formulaire ;
     * un refus (demande déjà commandée, relance déjà envoyée…) devient une notification.
     *
     * @param  Closure(): Response  $call
     */
    private function send(Closure $call): RedirectResponse
    {
        try {
            $response = $call();
        } catch (ValidationException $e) {
            $refusal = array_intersect_key($e->errors(), array_flip(['quote_request', 'target', 'api']));

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
