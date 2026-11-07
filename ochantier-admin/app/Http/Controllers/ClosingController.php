<?php

namespace App\Http\Controllers;

use App\Support\Api\AdminApi;
use App\Support\Api\AdminUser;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Clôtures mensuelles, lues dans l’API (api/v1/admin/finance/closings).
 */
class ClosingController extends Controller
{
    private const STATUS_TONES = ['ouverte' => 'n', 'en_cloture' => 'a', 'cloturee' => 'g', 'reouverte' => 'o'];

    public function __construct(private AdminApi $api) {}

    public function index(): Response
    {
        return Inertia::render('Finance/Closings', [
            'months' => array_map($this->month(...), $this->api->get('finance/closings')->json('data')),
            'header' => $this->header('Clôtures mensuelles', '', [['Finance', '/finance-reporting'], ['Clôtures']]),
        ]);
    }

    public function show(Request $request, string $month): Response
    {
        $m = $this->api->get("finance/closings/{$month}")->json('data');

        return Inertia::render('Finance/Closing', [
            'closing' => [...$this->month($m), 'figures' => $m['figures'], 'warnings' => $m['warnings'], 'drift' => $m['drift'], 'actions' => $m['can']],
            'can' => $this->can($request),
            'header' => $this->header('Clôture de '.$month, '', [['Finance', '/finance-reporting'], ['Clôtures', '/finance-reporting/clotures'], [$month]]),
        ]);
    }

    public function export(string $month): HttpResponse
    {
        $response = $this->api->get("finance/closings/{$month}/export");

        return response($response->body(), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"cloture-{$month}.csv\""]);
    }

    public function prepare(string $month): RedirectResponse
    {
        return $this->act("finance/closings/{$month}/prepare");
    }

    public function close(string $month): RedirectResponse
    {
        return $this->act("finance/closings/{$month}/close");
    }

    public function reopen(Request $request, string $month): RedirectResponse
    {
        return $this->act("finance/closings/{$month}/reopen", $request->only('reason'));
    }

    /**
     * Un refus métier s’affiche en message ; une erreur de champ reste sous le champ du formulaire.
     *
     * @param  array<string, mixed>  $data
     */
    private function act(string $uri, array $data = []): RedirectResponse
    {
        try {
            $response = $this->api->post($uri, $data);
        } catch (ValidationException $e) {
            $refusal = array_intersect_key($e->errors(), array_flip(['closing', 'api']));

            if ($refusal === []) {
                throw $e;
            }

            Inertia::flash('toastError', collect($refusal)->flatten()->first());

            return back();
        }

        Inertia::flash('toast', $response->json('message'));

        return back();
    }

    /**
     * @param  array<string, mixed>  $m
     * @return array<string, mixed>
     */
    private function month(array $m): array
    {
        return [
            'month' => $m['month'],
            'status' => [...$m['status'], 'tone' => self::STATUS_TONES[$m['status']['value']] ?? 'n'],
            'version' => $m['version'],
            'revenue' => $m['revenue'],
            'preparedBy' => $m['prepared_by'],
            'preparedAt' => $this->moment($m['prepared_at']),
            'closedBy' => $m['closed_by'],
            'closedAt' => $this->moment($m['closed_at']),
            'reopenReason' => $m['reopen_reason'],
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function can(Request $request): array
    {
        $user = $request->user();
        $allowed = fn (string $permission): bool => $user instanceof AdminUser && $user->hasPermission($permission);

        return ['update' => $allowed('finance.update'), 'approve' => $allowed('finance.approve'), 'reopen' => $allowed('finance.validate'), 'export' => $allowed('finance.export')];
    }

    private function moment(?string $date): ?string
    {
        return $date ? Carbon::parse($date)->setTimezone(config('app.timezone'))->format('Y-m-d H:i') : null;
    }
}
