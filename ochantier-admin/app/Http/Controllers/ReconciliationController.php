<?php

namespace App\Http\Controllers;

use App\Support\Api\AdminApi;
use App\Support\Api\AdminUser;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Rapprochements, lus dans l’API (api/v1/admin/finance/reconciliations) : relevés d’opérateurs importés et écarts.
 */
class ReconciliationController extends Controller
{
    private const PER_PAGE = 20;

    private const ITEM_TONES = ['ouvert' => 'a', 'resolu' => 'g', 'accepte' => 'b'];

    public function __construct(private AdminApi $api) {}

    public function index(Request $request): Response
    {
        $response = $this->api->get('finance/reconciliations', array_filter(['page' => $request->query('page'), 'per_page' => self::PER_PAGE]));
        $meta = $response->json('meta');
        $rows = array_map($this->run(...), $response->json('data'));
        $from = $meta['total'] ? ($meta['current_page'] - 1) * $meta['per_page'] + 1 : 0;

        return Inertia::render('Finance/Reconciliations', [
            'runs' => ['data' => $rows, 'currentPage' => $meta['current_page'], 'lastPage' => $meta['last_page'], 'total' => $meta['total'], 'from' => $from, 'to' => $from ? $from + count($rows) - 1 : 0],
            'kpis' => $meta['counts'],
            'operators' => array_map(fn (array $o): array => ['value' => $o['id'], 'label' => $o['name']], $meta['operators']),
            'can' => $this->can($request),
            'header' => $this->header('Rapprochements', '', [['Finance', '/finance-reporting'], ['Rapprochements']]),
        ]);
    }

    public function show(Request $request, string $run): Response
    {
        $r = $this->api->get("finance/reconciliations/{$run}")->json('data');

        return Inertia::render('Finance/Reconciliation', [
            'run' => [
                ...$this->run($r),
                'items' => array_map(fn (array $i): array => [...$i, 'status' => [...$i['status'], 'tone' => self::ITEM_TONES[$i['status']['value']] ?? 'n'], 'resolved_at' => $this->moment($i['resolved_at'])], $r['items']),
            ],
            'can' => $this->can($request),
            'header' => $this->header('Fiche du rapprochement', '', [['Finance', '/finance-reporting'], ['Rapprochements', '/finance-reporting/rapprochements'], [$r['period']['from'].' au '.$r['period']['to']]]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:5120']], ['file.*' => 'Ajoutez un relevé CSV de 5 Mo maximum.']);

        return $this->act(fn () => $this->api->upload('finance/reconciliations', $request->only('operator_id', 'from', 'to'), ['file' => $request->file('file')]));
    }

    public function accept(Request $request, string $run, string $item): RedirectResponse
    {
        return $this->act(fn () => $this->api->post("finance/reconciliations/{$run}/items/{$item}/accept", $request->only('reason')));
    }

    public function resolve(Request $request, string $run, string $item): RedirectResponse
    {
        return $this->act(fn () => $this->api->post("finance/reconciliations/{$run}/items/{$item}/resolve", $request->only('reason')));
    }

    /**
     * Un refus métier s’affiche en message ; une erreur de champ reste sous le champ du formulaire.
     */
    private function act(callable $call): RedirectResponse
    {
        try {
            $response = $call();
        } catch (ValidationException $e) {
            $refusal = array_intersect_key($e->errors(), array_flip(['reconciliation', 'api']));

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
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    private function run(array $r): array
    {
        return ['id' => $r['id'], 'provider' => ucfirst($r['provider']), 'period' => $r['period'], 'summary' => $r['summary'], 'openItems' => $r['open_items'], 'by' => $r['by'], 'importedAt' => $this->moment($r['imported_at'])];
    }

    /**
     * @return array<string, bool>
     */
    private function can(Request $request): array
    {
        $user = $request->user();

        return ['update' => $user instanceof AdminUser && $user->hasPermission('finance.update')];
    }

    private function moment(?string $date): ?string
    {
        return $date ? Carbon::parse($date)->setTimezone(config('app.timezone'))->format('Y-m-d H:i') : null;
    }
}
