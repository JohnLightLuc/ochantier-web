<?php

namespace App\Http\Controllers;

use App\Support\Api\AdminApi;
use App\Support\Api\AdminUser;
use Closure;
use Illuminate\Http\Client\Response as ApiResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Classement mensuel des commerciaux, lu dans l’API : consultation, export, clôture et publication.
 */
class SalesRankingController extends Controller
{
    public function __construct(private AdminApi $api) {}

    public function show(Request $request): Response
    {
        $r = $this->api->get('commercials/ranking', $this->month($request))->json('data');

        return Inertia::render('SalesAgents/Ranking', [
            'month' => $r['month'],
            'label' => $r['label'],
            'closedAt' => $r['closed_at'],
            'publishedAt' => $r['published_at'],
            'canClose' => $r['can_close'],
            'canPublish' => $r['can_publish'],
            'top' => $r['top'],
            'rows' => $r['rows'],
            'months' => $r['months'],
            'can' => ['export' => $this->allowed($request, 'commercials.export'), 'validate' => $this->allowed($request, 'commercials.validate')],
            'header' => $this->header('Classement des commerciaux', 'Ouvriers validés sur le mois.', [['Commerciaux', '/commerciaux'], ['Classement']]),
        ]);
    }

    public function export(Request $request): HttpResponse
    {
        $response = $this->api->get('commercials/ranking/export', $this->month($request));

        return response($response->body(), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => $response->header('Content-Disposition') ?: 'attachment; filename="classement-commerciaux.csv"',
        ]);
    }

    public function close(Request $request): RedirectResponse
    {
        return $this->send(fn () => $this->api->post('commercials/ranking/close', ['month' => $request->input('month')]));
    }

    public function publish(Request $request): RedirectResponse
    {
        return $this->send(fn () => $this->api->post('commercials/ranking/publish', ['month' => $request->input('month')]));
    }

    /**
     * @return array<string, string>
     */
    private function month(Request $request): array
    {
        $month = $request->query('mois');

        return is_string($month) && preg_match('/^\d{4}-\d{2}$/', $month) ? ['month' => $month] : [];
    }

    private function allowed(Request $request, string $permission): bool
    {
        $user = $request->user();

        return $user instanceof AdminUser && $user->hasPermission($permission);
    }

    /**
     * @param  Closure(): ApiResponse  $call
     */
    private function send(Closure $call): RedirectResponse
    {
        try {
            $response = $call();
        } catch (ValidationException $e) {
            Inertia::flash('toastError', collect($e->errors())->flatten()->first());

            return back();
        }

        Inertia::flash('toast', $response->json('message'));

        return back();
    }
}
