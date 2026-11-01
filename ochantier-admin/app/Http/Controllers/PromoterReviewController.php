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
 * Décisions sur un promoteur, transmises à l’API : validation (seule ou groupée), corrections, refus, contrôle des
 * pièces, suspension, réactivation et message. Le message de l’API est affiché en notification ; un refus métier
 * (dossier pas prêt, compte déjà suspendu…) l’est en notification d’erreur.
 */
class PromoterReviewController extends Controller
{
    public function __construct(private AdminApi $api) {}

    public function approve(string $promoter): RedirectResponse
    {
        return $this->send(fn () => $this->api->post("promoters/{$promoter}/approve"));
    }

    public function approveMany(Request $request): RedirectResponse
    {
        $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['uuid']], ['ids.required' => 'Sélectionnez au moins un promoteur.']);

        return $this->send(function () use ($request) {
            $response = $this->api->post('promoters/approve', ['ids' => $request->input('ids')]);

            $skipped = array_map(fn (array $s): string => ($s['full_name'] ?: 'Un promoteur').' : '.$s['reason'], $response->json('data.skipped'));

            if ($skipped !== []) {
                Inertia::flash('toastError', implode(' ', $skipped));
            }

            return $response;
        });
    }

    public function requestCorrections(Request $request, string $promoter): RedirectResponse
    {
        return $this->send(fn () => $this->api->post("promoters/{$promoter}/corrections", $request->only(['corrections', 'message'])));
    }

    public function reject(Request $request, string $promoter): RedirectResponse
    {
        return $this->send(fn () => $this->api->post("promoters/{$promoter}/reject", ['reason' => $this->reason($request)]));
    }

    public function approveDocument(string $promoter, string $document): RedirectResponse
    {
        return $this->send(fn () => $this->api->post("promoters/{$promoter}/documents/{$document}/approve"));
    }

    public function rejectDocument(Request $request, string $promoter, string $document): RedirectResponse
    {
        return $this->send(fn () => $this->api->post("promoters/{$promoter}/documents/{$document}/reject", ['reason' => $this->reason($request)]));
    }

    public function suspend(Request $request, string $promoter): RedirectResponse
    {
        return $this->send(fn () => $this->api->post("promoters/{$promoter}/suspension", ['reason' => $this->reason($request)]));
    }

    public function reactivate(string $promoter): RedirectResponse
    {
        return $this->send(fn () => $this->api->delete("promoters/{$promoter}/suspension"));
    }

    public function message(Request $request, string $promoter): RedirectResponse
    {
        return $this->send(fn () => $this->api->post("promoters/{$promoter}/messages", ['title' => $request->input('subject'), 'body' => $request->input('message')]));
    }

    /**
     * Motif saisi, précédé de la catégorie choisie (« Document illisible · recto flou »).
     */
    private function reason(Request $request): string
    {
        $reason = trim((string) $request->input('reason'));
        $category = trim((string) $request->input('category'));

        return $category && $reason && $category !== 'Autre' ? "{$category} · {$reason}" : $reason;
    }

    /**
     * Appelle l’API puis revient sur la page avec son message. Les erreurs liées à un champ du formulaire restent
     * des erreurs de formulaire ; un refus sur le dossier (clé promoter, document_id…) devient une notification.
     *
     * @param  Closure(): Response  $call
     */
    private function send(Closure $call): RedirectResponse
    {
        try {
            $response = $call();
        } catch (ValidationException $e) {
            $refusal = array_intersect_key($e->errors(), array_flip(['promoter', 'document', 'document_id', 'api']));

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
