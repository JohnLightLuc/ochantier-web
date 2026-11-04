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
 * Décisions sur le compte d’un commercial, envoyées à l’API : suspension, réactivation, fin de contrat. Le message
 * de l’API est affiché ; un refus sur le compte devient une notification d’erreur.
 */
class SalesAgentAccessController extends Controller
{
    public function __construct(private AdminApi $api) {}

    public function suspend(Request $request, string $commercial): RedirectResponse
    {
        return $this->send(fn () => $this->api->post("commercials/{$commercial}/suspension", ['reason' => $this->reason($request)]));
    }

    public function reactivate(string $commercial): RedirectResponse
    {
        return $this->send(fn () => $this->api->post("commercials/{$commercial}/reactivation"));
    }

    public function terminate(Request $request, string $commercial): RedirectResponse
    {
        return $this->send(fn () => $this->api->post("commercials/{$commercial}/termination", ['reason' => $this->reason($request)]));
    }

    /**
     * Motif choisi et précisions, dans le seul motif attendu par l’API.
     */
    private function reason(Request $request): string
    {
        $reason = trim((string) $request->input('reason'));
        $category = trim((string) $request->input('category'));

        return $category && $reason && $category !== 'Autre' ? "{$category} · {$reason}" : $reason;
    }

    /**
     * Appelle l’API puis revient sur la page avec son message.
     *
     * @param  Closure(): Response  $call
     */
    private function send(Closure $call): RedirectResponse
    {
        try {
            $response = $call();
        } catch (ValidationException $e) {
            $refusal = array_intersect_key($e->errors(), array_flip(['commercial', 'api']));

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
