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
 * Décisions sur un besoin, envoyées à l’API : suspension, reprise, prolongation, annulation et clôture.
 */
class NeedDecisionController extends Controller
{
    public function __construct(private AdminApi $api) {}

    public function suspend(Request $request, string $need): RedirectResponse
    {
        return $this->send(fn () => $this->api->post("offers/{$need}/suspension", ['reason' => $this->reason($request)]));
    }

    public function resume(string $need): RedirectResponse
    {
        return $this->send(fn () => $this->api->delete("offers/{$need}/suspension"));
    }

    public function extend(Request $request, string $need): RedirectResponse
    {
        return $this->send(fn () => $this->api->post("offers/{$need}/extension", ['deadline' => $request->input('deadline') ?: null]));
    }

    public function cancel(Request $request, string $need): RedirectResponse
    {
        return $this->send(fn () => $this->api->post("offers/{$need}/cancellation", ['reason' => $this->reason($request)]));
    }

    public function close(Request $request, string $need): RedirectResponse
    {
        return $this->send(fn () => $this->api->post("offers/{$need}/closure", ['reason' => $this->reason($request)]));
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
            $refusal = array_intersect_key($e->errors(), array_flip(['offer', 'api']));

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
