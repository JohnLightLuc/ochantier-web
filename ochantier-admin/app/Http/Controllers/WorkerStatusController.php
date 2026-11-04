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
 * Décisions sur un ouvrier, envoyées à l’API : dossier (validation, validation groupée, corrections, refus), pièces,
 * suspension, réactivation et exclusion. Le message de l’API est affiché ; un refus sur le dossier devient une
 * notification d’erreur.
 */
class WorkerStatusController extends Controller
{
    public function __construct(private AdminApi $api) {}

    public function approve(string $worker): RedirectResponse
    {
        return $this->send(fn () => $this->api->post("workers/{$worker}/approve"));
    }

    public function approveMany(Request $request): RedirectResponse
    {
        $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['uuid']], ['ids.required' => 'Sélectionnez au moins un ouvrier.']);

        return $this->send(function () use ($request) {
            $response = $this->api->post('workers/approve', ['ids' => $request->input('ids')]);
            $skipped = array_map(fn (array $s): string => ($s['full_name'] ?: 'Un ouvrier').' : '.$s['reason'], $response->json('data.skipped'));

            if ($skipped !== []) {
                Inertia::flash('toastError', implode(' ', $skipped));
            }

            return $response;
        });
    }

    public function requestCorrections(Request $request, string $worker): RedirectResponse
    {
        return $this->send(fn () => $this->api->post("workers/{$worker}/corrections", $request->only(['corrections', 'message'])));
    }

    public function reject(Request $request, string $worker): RedirectResponse
    {
        return $this->send(fn () => $this->api->post("workers/{$worker}/reject", ['reason' => $this->reason($request)]));
    }

    public function approveDocument(string $worker, string $document): RedirectResponse
    {
        return $this->send(fn () => $this->api->post("workers/{$worker}/documents/{$document}/approve"));
    }

    public function rejectDocument(Request $request, string $worker, string $document): RedirectResponse
    {
        return $this->send(fn () => $this->api->post("workers/{$worker}/documents/{$document}/reject", ['reason' => $this->reason($request)]));
    }

    public function suspend(Request $request, string $worker): RedirectResponse
    {
        return $this->send(fn () => $this->api->post("workers/{$worker}/suspension", array_filter(['reason' => $this->reason($request), 'until' => $request->input('until') ?: null])));
    }

    public function reactivate(string $worker): RedirectResponse
    {
        return $this->send(fn () => $this->api->delete("workers/{$worker}/suspension"));
    }

    public function exclude(Request $request, string $worker): RedirectResponse
    {
        return $this->send(fn () => $this->api->post("workers/{$worker}/exclusion", ['reason' => $this->reason($request)]));
    }

    /**
     * Motif saisi, précédé de la catégorie choisie (« Absences répétées · trois jours sans prévenir »).
     */
    private function reason(Request $request): string
    {
        $reason = trim((string) $request->input('reason'));
        $category = trim((string) $request->input('category'));

        return $category && $reason && $category !== 'Autre' ? "{$category} · {$reason}" : $reason;
    }

    /**
     * Appelle l’API puis revient sur la page avec son message. Les erreurs liées à un champ du formulaire restent
     * des erreurs de formulaire ; un refus sur le dossier (clé worker, document_id…) devient une notification.
     *
     * @param  Closure(): Response  $call
     */
    private function send(Closure $call): RedirectResponse
    {
        try {
            $response = $call();
        } catch (ValidationException $e) {
            $refusal = array_intersect_key($e->errors(), array_flip(['worker', 'document', 'document_id', 'api']));

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
