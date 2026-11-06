<?php

namespace App\Http\Controllers;

use App\Support\Api\AdminApi;
use App\Support\Api\AdminUser;
use Carbon\Carbon;
use Illuminate\Http\Client\Response as ApiResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Reversements aux ouvriers, lus et pilotés dans l’API (api/v1/admin/payouts) : ce qui est à reverser, les
 * reversements, les lots et les numéros Mobile Money à confirmer.
 *
 * Les paramètres d’URL des listes restent en français et sont traduits pour l’API.
 */
class PayoutController extends Controller
{
    private const PER_PAGE = 20;

    private const PAYOUT_TONES = ['a_calculer' => 'n', 'provisionne' => 'b', 'autorise' => 'b', 'envoye' => 'a', 'confirme' => 'g', 'rejete' => 'r', 'annule' => 'n'];

    private const PAYOUT_LABELS = ['provisionne' => 'Provisionné', 'autorise' => 'Autorisé', 'envoye' => 'Envoyé', 'confirme' => 'Confirmé', 'rejete' => 'Rejeté', 'annule' => 'Annulé'];

    private const BATCH_TONES = ['brouillon' => 'n', 'a_valider' => 'a', 'en_execution' => 'b', 'termine' => 'g', 'partiel' => 'o', 'echoue' => 'r', 'annule' => 'n'];

    private const BATCH_LABELS = ['brouillon' => 'Brouillon', 'a_valider' => 'À valider', 'en_execution' => 'En exécution', 'termine' => 'Terminé', 'partiel' => 'Partiel', 'echoue' => 'Échoué', 'annule' => 'Annulé'];

    private const ADJUSTMENT_TONES = ['proposee' => 'a', 'approuvee' => 'g', 'refusee' => 'r', 'annulee' => 'n'];

    private const TRANSACTION_TONES = ['initiee' => 'b', 'en_attente' => 'a', 'reussie' => 'g', 'echouee' => 'r', 'expiree' => 'n', 'annulee' => 'n'];

    private const STATES = ['payable' => 'Prêt à reverser', 'blocked' => 'Bloqué'];

    private const PAYABLE_QUERY = ['q' => 'search', 'etat' => 'state', 'page' => 'page'];

    private const PAYOUT_QUERY = ['q' => 'search', 'statut' => 'status', 'lot' => 'batch_id', 'page' => 'page'];

    private const BATCH_QUERY = ['statut' => 'status', 'page' => 'page'];

    public function __construct(private AdminApi $api) {}

    public function payable(Request $request): Response
    {
        $filters = $this->filters($request, self::PAYABLE_QUERY);
        $response = $this->api->get('payouts/payable', [...$this->query($filters, self::PAYABLE_QUERY), 'per_page' => self::PER_PAGE]);
        $meta = $response->json('meta');

        return Inertia::render('Payments/Payable', [
            'rows' => $this->page($response->json('data'), $meta, fn (array $row): array => $row),
            'filters' => $filters,
            'filterOptions' => ['etat' => $this->options(self::STATES)],
            'kpis' => [...$meta['counts'], 'pendingMethods' => $meta['pending_methods']],
            'can' => $this->can($request),
            'header' => $this->header('À reverser', 'Journées validées dont l’échéance du promoteur est réglée.', [['Paiements', '/paiements'], ['À reverser']]),
        ]);
    }

    public function prepare(Request $request): RedirectResponse
    {
        $response = $this->attempt(fn (): ApiResponse => $this->api->post('payouts/batches', ['items' => $request->input('items', [])]));

        return $response ? redirect("/paiements/lots/{$response->json('data.id')}") : back();
    }

    public function index(Request $request): Response
    {
        $filters = $this->filters($request, self::PAYOUT_QUERY);
        $response = $this->api->get('payouts', [...$this->query($filters, self::PAYOUT_QUERY), 'per_page' => self::PER_PAGE]);
        $meta = $response->json('meta');

        return Inertia::render('Payments/Payouts', [
            'payouts' => $this->page($response->json('data'), $meta, $this->payout(...)),
            'filters' => $filters,
            'filterOptions' => ['statut' => $this->options(self::PAYOUT_LABELS)],
            'kpis' => ['paid' => $meta['counts']['paid_amount'], 'rejected' => $meta['counts']['statuses']['rejete'], 'confirmed' => $meta['counts']['statuses']['confirme']],
            'can' => $this->can($request),
            'header' => $this->header('Reversements', '', [['Paiements', '/paiements'], ['Reversements']]),
        ]);
    }

    public function export(Request $request): HttpResponse
    {
        $response = $this->api->get('payouts/export', $this->query($this->filters($request, self::PAYOUT_QUERY), self::PAYOUT_QUERY));

        return response($response->body(), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => $response->header('Content-Disposition') ?: 'attachment; filename="reversements.csv"']);
    }

    public function show(Request $request, string $payout): Response
    {
        $p = $this->api->get("payouts/{$payout}")->json('data');

        return Inertia::render('Payments/Payout', [
            'payout' => [
                ...$this->payout($p),
                'schedule' => $p['schedule'],
                'preparedBy' => $p['prepared_by'],
                'approvedBy' => $p['approved_by'],
                'approvedAt' => $this->moment($p['approved_at']),
                'workDays' => $p['work_days'],
                'adjustments' => array_map(fn (array $a): array => [...$a, 'status' => $this->toned($a['status'], self::ADJUSTMENT_TONES)], $p['adjustments']),
                'transactions' => array_map(fn (array $t): array => [...$t, 'status' => $this->toned($t['status'], self::TRANSACTION_TONES), 'date' => $this->moment($t['initiated_at'])], $p['transactions']),
                'canAdjust' => $p['can_adjust'],
                'canCancel' => $p['can_cancel'],
                'canRetry' => $p['can_retry'],
            ],
            'can' => $this->can($request),
            'header' => $this->header('Fiche du reversement', '', [['Paiements', '/paiements'], ['Reversements', '/paiements/reversements'], [$p['reference']]]),
        ]);
    }

    public function cancel(Request $request, string $payout): RedirectResponse
    {
        return $this->act("payouts/{$payout}/cancel", ['reason' => $request->input('reason')]);
    }

    public function retry(string $payout): RedirectResponse
    {
        return $this->act("payouts/{$payout}/retry");
    }

    public function propose(Request $request, string $payout): RedirectResponse
    {
        return $this->act("payouts/{$payout}/adjustments", $request->only(['kind', 'amount', 'reason', 'basis']));
    }

    public function approveAdjustment(string $payout, string $adjustment): RedirectResponse
    {
        return $this->act("payouts/{$payout}/adjustments/{$adjustment}/approve");
    }

    public function refuseAdjustment(Request $request, string $payout, string $adjustment): RedirectResponse
    {
        return $this->act("payouts/{$payout}/adjustments/{$adjustment}/refuse", ['reason' => $request->input('reason')]);
    }

    public function batches(Request $request): Response
    {
        $filters = $this->filters($request, self::BATCH_QUERY);
        $response = $this->api->get('payouts/batches', [...$this->query($filters, self::BATCH_QUERY), 'per_page' => self::PER_PAGE]);
        $meta = $response->json('meta');

        return Inertia::render('Payments/Batches', [
            'batches' => $this->page($response->json('data'), $meta, $this->batchRow(...)),
            'filters' => $filters,
            'filterOptions' => ['statut' => $this->options(self::BATCH_LABELS)],
            'counts' => $meta['counts'],
            'can' => $this->can($request),
            'header' => $this->header('Lots de reversements', '', [['Paiements', '/paiements'], ['Lots']]),
        ]);
    }

    public function batch(Request $request, string $batch): Response
    {
        $b = $this->api->get("payouts/batches/{$batch}")->json('data');

        return Inertia::render('Payments/Batch', [
            'batch' => [
                ...$this->batchRow($b),
                'pendingAdjustments' => $b['pending_adjustments'],
                'payouts' => array_map($this->payout(...), $b['payouts']),
                'canSubmit' => $b['can_submit'],
                'canCancel' => $b['can_cancel'],
                'canApprove' => $b['can_approve'],
                'canResume' => $b['can_resume'],
            ],
            'can' => $this->can($request),
            'header' => $this->header('Fiche du lot', '', [['Paiements', '/paiements'], ['Lots', '/paiements/lots'], [$b['reference']]]),
        ]);
    }

    public function submit(string $batch): RedirectResponse
    {
        return $this->act("payouts/batches/{$batch}/submit");
    }

    public function approve(string $batch): RedirectResponse
    {
        return $this->act("payouts/batches/{$batch}/approve");
    }

    public function resume(string $batch): RedirectResponse
    {
        return $this->act("payouts/batches/{$batch}/resume");
    }

    public function reject(Request $request, string $batch): RedirectResponse
    {
        return $this->act("payouts/batches/{$batch}/reject", ['reason' => $request->input('reason')]);
    }

    public function cancelBatch(string $batch): RedirectResponse
    {
        return $this->act("payouts/batches/{$batch}/cancel");
    }

    public function methods(Request $request): Response
    {
        $filters = $this->filters($request, ['page' => 'page']);
        $response = $this->api->get('payouts/payment-methods/pending', ['page' => $filters['page'] ?? 1, 'per_page' => self::PER_PAGE]);

        return Inertia::render('Payments/Methods', [
            'methods' => $this->page($response->json('data'), $response->json('meta'), fn (array $row): array => [...$row, 'proposed_at' => $this->moment($row['proposed_at'])]),
            'can' => $this->can($request),
            'header' => $this->header('Numéros à confirmer', 'Un numéro saisi ne reçoit de reversement qu’une fois confirmé par une autre personne.', [['Paiements', '/paiements'], ['Numéros à confirmer']]),
        ]);
    }

    public function storeMethod(Request $request, string $worker): RedirectResponse
    {
        return $this->act("workers/{$worker}/payment-method", $request->only(['operator_id', 'dial_code', 'phone_number']));
    }

    public function approveMethod(string $worker, string $method): RedirectResponse
    {
        return $this->act("workers/{$worker}/payment-method/{$method}/approve");
    }

    public function rejectMethod(Request $request, string $worker, string $method): RedirectResponse
    {
        return $this->act("workers/{$worker}/payment-method/{$method}/reject", ['reason' => $request->input('reason')]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function act(string $uri, array $data = []): RedirectResponse
    {
        $this->attempt(fn (): ApiResponse => $this->api->post($uri, $data));

        return back();
    }

    /**
     * Un refus métier s’affiche en message ; une erreur de champ reste sous le champ du formulaire.
     */
    private function attempt(callable $call): ?ApiResponse
    {
        try {
            $response = $call();
        } catch (ValidationException $e) {
            $refusal = array_intersect_key($e->errors(), array_flip(['payout', 'api']));

            if ($refusal === []) {
                throw $e;
            }

            Inertia::flash('toastError', collect($refusal)->flatten()->first());

            return null;
        }

        Inertia::flash('toast', $response->json('message'));

        return $response;
    }

    /**
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    private function payout(array $p): array
    {
        return [
            'id' => $p['id'],
            'reference' => $p['reference'],
            'batch' => $p['batch'],
            'worker' => $p['worker'],
            'site' => $p['site'],
            'mission' => $p['mission'],
            'periodStart' => $p['period_start'],
            'periodEnd' => $p['period_end'],
            'days' => $p['days'],
            'gross' => $p['gross_amount'],
            'commission' => $p['commission_amount'],
            'deduction' => $p['deduction_amount'],
            'bonus' => $p['bonus_amount'],
            'net' => $p['net_amount'],
            'number' => $p['number'],
            'operator' => $p['operator'],
            'status' => $this->toned($p['status'], self::PAYOUT_TONES),
        ];
    }

    /**
     * @param  array<string, mixed>  $b
     * @return array<string, mixed>
     */
    private function batchRow(array $b): array
    {
        return [
            'id' => $b['id'],
            'reference' => $b['reference'],
            'status' => $this->toned($b['status'], self::BATCH_TONES),
            'count' => $b['payout_count'],
            'net' => $b['total_net_amount'],
            'commission' => $b['total_commission_amount'],
            'results' => $b['results'],
            'preparedBy' => $b['prepared_by'],
            'approvedBy' => $b['approved_by'],
            'createdAt' => $this->moment($b['created_at']),
            'executedAt' => $this->moment($b['executed_at']),
        ];
    }

    /**
     * @param  array{value: string, label: string}  $status
     * @param  array<string, string>  $tones
     * @return array{value: string, label: string, tone: string}
     */
    private function toned(array $status, array $tones): array
    {
        return [...$status, 'tone' => $tones[$status['value']] ?? 'n'];
    }

    /**
     * @param  array<int, array<string, mixed>>  $data
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function page(array $data, array $meta, callable $map): array
    {
        $rows = array_map($map, $data);
        $from = $meta['total'] ? ($meta['current_page'] - 1) * $meta['per_page'] + 1 : 0;

        return ['data' => $rows, 'currentPage' => $meta['current_page'], 'lastPage' => $meta['last_page'], 'total' => $meta['total'], 'from' => $from, 'to' => $from ? $from + count($rows) - 1 : 0];
    }

    /**
     * @return array<string, bool>
     */
    private function can(Request $request): array
    {
        $user = $request->user();
        $allowed = fn (string $permission): bool => $user instanceof AdminUser && $user->hasPermission($permission);

        return ['create' => $allowed('payouts.create'), 'approve' => $allowed('payouts.approve'), 'export' => $allowed('payouts.export')];
    }

    /**
     * @param  array<string, string>  $labels
     * @return list<array{value: string, label: string}>
     */
    private function options(array $labels): array
    {
        return array_map(fn (string $value, string $label): array => compact('value', 'label'), array_keys($labels), $labels);
    }

    /**
     * @param  array<string, string>  $keys
     * @return array<string, string>
     */
    private function filters(Request $request, array $keys): array
    {
        return array_filter($request->only(array_keys($keys)), fn ($value) => is_string($value) && $value !== '');
    }

    /**
     * @param  array<string, string>  $filters
     * @param  array<string, string>  $keys
     * @return array<string, string>
     */
    private function query(array $filters, array $keys): array
    {
        $query = [];

        foreach ($filters as $key => $value) {
            $query[$keys[$key]] = $value;
        }

        return $query;
    }

    private function moment(?string $date): ?string
    {
        return $date ? Carbon::parse($date)->setTimezone(config('app.timezone'))->format('Y-m-d H:i') : null;
    }
}
