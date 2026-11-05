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
 * Décisions sur le compte d’un commercial, envoyées à l’API : suspension, réactivation, fin de contrat, lien d’accès,
 * sessions et contrôle des pièces. Le message de l’API est affiché ; un refus sur le compte devient une notification d’erreur.
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

    public function sendLink(string $commercial): RedirectResponse
    {
        return $this->send(fn () => $this->api->post("commercials/{$commercial}/access-link"));
    }

    public function revokeLink(string $commercial): RedirectResponse
    {
        return $this->send(fn () => $this->api->delete("commercials/{$commercial}/access-link"));
    }

    public function revokeSessions(string $commercial, ?string $session = null): RedirectResponse
    {
        return $this->send(fn () => $this->api->delete("commercials/{$commercial}/sessions".($session ? "/{$session}" : '')));
    }

    public function approveDocument(string $commercial, string $document): RedirectResponse
    {
        return $this->send(fn () => $this->api->post("commercials/{$commercial}/documents/{$document}/approve"));
    }

    public function rejectDocument(Request $request, string $commercial, string $document): RedirectResponse
    {
        return $this->send(fn () => $this->api->post("commercials/{$commercial}/documents/{$document}/reject", ['reason' => $this->reason($request)]));
    }

    public function transfer(Request $request, string $commercial): RedirectResponse
    {
        return $this->send(fn () => $this->api->post("commercials/{$commercial}/workers/transfer", array_filter([
            'to_commercial_id' => $request->input('to_commercial_id') ?: null,
            'worker_ids' => array_values(array_filter((array) $request->input('worker_ids', []))) ?: null,
            'reason' => trim((string) $request->input('reason')) ?: null,
        ])));
    }

    public function addZone(Request $request, string $commercial): RedirectResponse
    {
        return $this->send(fn () => $this->api->post("commercials/{$commercial}/zones", ['commune_id' => $request->input('commune_id') ?: null, 'is_primary' => $request->input('kind') === 'primary']));
    }

    public function removeZone(string $commercial, string $commune): RedirectResponse
    {
        return $this->send(fn () => $this->api->delete("commercials/{$commercial}/zones/{$commune}"));
    }

    public function acceptZoneRequest(Request $request, string $commercial, string $zoneRequest): RedirectResponse
    {
        return $this->send(fn () => $this->api->post("commercials/{$commercial}/zone-requests/{$zoneRequest}/accept", array_filter(['primary_commune_id' => $request->input('primary_commune_id'), 'comment' => $request->input('comment')])));
    }

    public function refuseZoneRequest(Request $request, string $commercial, string $zoneRequest): RedirectResponse
    {
        return $this->send(fn () => $this->api->post("commercials/{$commercial}/zone-requests/{$zoneRequest}/refuse", ['reason' => $this->reason($request)]));
    }

    public function saveObjective(Request $request, string $commercial): RedirectResponse
    {
        return $this->send(fn () => $this->api->post("commercials/{$commercial}/objectives", ['month' => $request->input('month') ?: null, 'target' => $request->input('target') ?: null, 'comment' => trim((string) $request->input('comment')) ?: null]));
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
