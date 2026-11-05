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
 * Décisions sur un chantier, envoyées à l’API : suspension, reprise, annulation, clôture et archivage. Le message de
 * l’API est affiché ; un refus sur le chantier devient une notification d’erreur.
 */
class SiteDecisionController extends Controller
{
    public function __construct(private AdminApi $api) {}

    public function suspend(Request $request, string $site): RedirectResponse
    {
        return $this->send(fn () => $this->api->post("sites/{$site}/suspension", ['reason' => $this->reason($request)]));
    }

    public function resume(string $site): RedirectResponse
    {
        return $this->send(fn () => $this->api->delete("sites/{$site}/suspension"));
    }

    public function cancel(Request $request, string $site): RedirectResponse
    {
        return $this->send(fn () => $this->api->post("sites/{$site}/cancellation", ['reason' => $this->reason($request)]));
    }

    public function close(string $site): RedirectResponse
    {
        return $this->send(fn () => $this->api->post("sites/{$site}/closure"));
    }

    public function archive(string $site): RedirectResponse
    {
        return $this->send(fn () => $this->api->post("sites/{$site}/archive"));
    }

    private function reason(Request $request): string
    {
        $reason = trim((string) $request->input('reason'));
        $category = trim((string) $request->input('category'));

        return $category && $reason && $category !== 'Autre' ? "{$category} · {$reason}" : $reason;
    }

    /**
     * @param  Closure(): Response  $call
     */
    private function send(Closure $call): RedirectResponse
    {
        try {
            $response = $call();
        } catch (ValidationException $e) {
            $refusal = array_intersect_key($e->errors(), array_flip(['site', 'api']));

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
