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
 * Décisions sur une offre contractuelle, envoyées à l’API : prolonger la date limite, annuler l’offre avant
 * l’acompte, relancer le promoteur ou les professionnels. Le message de l’API est affiché ; un refus devient une
 * notification d’erreur.
 */
class ContractOfferDecisionController extends Controller
{
    public function __construct(private AdminApi $api) {}

    public function extend(Request $request, string $offer): RedirectResponse
    {
        return $this->send(fn () => $this->api->post("contract-offers/{$offer}/extension", ['deadline' => $request->input('deadline')]));
    }

    public function cancel(Request $request, string $offer): RedirectResponse
    {
        $reason = trim((string) $request->input('reason'));
        $category = trim((string) $request->input('category'));

        return $this->send(fn () => $this->api->post("contract-offers/{$offer}/cancellation", ['reason' => $category && $reason && $category !== 'Autre' ? "{$category} · {$reason}" : $reason]));
    }

    public function remind(Request $request, string $offer): RedirectResponse
    {
        return $this->send(fn () => $this->api->post("contract-offers/{$offer}/reminders", array_filter(['target' => $request->input('target'), 'message' => trim((string) $request->input('message')) ?: null])));
    }

    /**
     * Appelle l’API puis revient sur la page avec son message. Les erreurs de champ restent des erreurs de formulaire ;
     * un refus (acompte payé ou en cours, relance déjà envoyée…) devient une notification.
     *
     * @param  Closure(): Response  $call
     */
    private function send(Closure $call): RedirectResponse
    {
        try {
            $response = $call();
        } catch (ValidationException $e) {
            $refusal = array_intersect_key($e->errors(), array_flip(['offer', 'target', 'api']));

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
