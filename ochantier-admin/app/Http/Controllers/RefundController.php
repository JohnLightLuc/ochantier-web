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
 * Fin de mission : régularisations et remboursements aux promoteurs, lus dans l’API (api/v1/admin/regularisations et
 * refunds). Une personne lance le calcul ou demande un remboursement ; l’envoi demande la validation d’une autre.
 */
class RefundController extends Controller
{
    private const PER_PAGE = 20;

    private const REFUND_LABELS = ['demande' => 'Demandé', 'approuve' => 'Approuvé', 'execute' => 'Exécuté', 'echoue' => 'Échoué', 'refuse' => 'Refusé'];

    private const REFUND_TONES = ['demande' => 'a', 'approuve' => 'b', 'execute' => 'g', 'echoue' => 'r', 'refuse' => 'n'];

    private const STATE_LABELS = ['a_faire' => 'À régulariser', 'faite' => 'Régularisées'];

    private const STATE_TONES = ['a_faire' => 'a', 'faite' => 'g', 'contrat' => 'n'];

    private const SCHEDULE_TONES = ['exigible' => 'a', 'payee' => 'g', 'en_retard' => 'r', 'partiellement_payee' => 'o', 'contestee' => 'r', 'annulee' => 'n'];

    private const TRANSACTION_TONES = ['initiee' => 'b', 'en_attente' => 'a', 'reussie' => 'g', 'echouee' => 'r', 'expiree' => 'n', 'annulee' => 'n'];

    private const REGULARISATION_QUERY = ['q' => 'search', 'etat' => 'state', 'page' => 'page'];

    private const REFUND_QUERY = ['q' => 'search', 'statut' => 'status', 'page' => 'page'];

    public function __construct(private AdminApi $api) {}

    public function regularisations(Request $request): Response
    {
        $filters = $this->filters($request, self::REGULARISATION_QUERY);
        $response = $this->api->get('regularisations', [...$this->query($filters, self::REGULARISATION_QUERY), 'per_page' => self::PER_PAGE]);
        $meta = $response->json('meta');

        return Inertia::render('Payments/Regularisations', [
            'missions' => $this->page($response->json('data'), $meta, $this->mission(...)),
            'filters' => $filters,
            'filterOptions' => ['etat' => $this->options(self::STATE_LABELS)],
            'kpis' => ['toDo' => $meta['counts']['a_faire'], 'done' => $meta['counts']['faite']],
            'can' => $this->can($request),
            'header' => $this->header('Régularisations', '', [['Paiements', '/paiements'], ['Régularisations']]),
        ]);
    }

    public function regularisation(Request $request, string $mission): Response
    {
        $m = $this->api->get("regularisations/{$mission}")->json('data');

        return Inertia::render('Payments/Regularisation', [
            'mission' => [...$this->mission($m), 'refundable' => $m['refundable'], 'refunds' => array_map($this->refund(...), $m['refunds'])],
            'can' => $this->can($request),
            'header' => $this->header('Fiche de régularisation', '', [['Paiements', '/paiements'], ['Régularisations', '/paiements/regularisations'], [$m['reference']]]),
        ]);
    }

    public function run(string $mission): RedirectResponse
    {
        return $this->act("regularisations/{$mission}");
    }

    public function requestRefund(Request $request, string $mission): RedirectResponse
    {
        return $this->act("regularisations/{$mission}/refunds", $request->only('amount', 'reason'));
    }

    public function index(Request $request): Response
    {
        $filters = $this->filters($request, self::REFUND_QUERY);
        $response = $this->api->get('refunds', [...$this->query($filters, self::REFUND_QUERY), 'per_page' => self::PER_PAGE]);
        $meta = $response->json('meta');

        return Inertia::render('Payments/Refunds', [
            'refunds' => $this->page($response->json('data'), $meta, $this->refund(...)),
            'filters' => $filters,
            'filterOptions' => ['statut' => $this->options(self::REFUND_LABELS)],
            'kpis' => ['executed' => $meta['counts']['executed_amount'], 'pending' => $meta['counts']['pending_amount'], 'failed' => $meta['counts']['statuses']['echoue']],
            'can' => $this->can($request),
            'header' => $this->header('Remboursements', '', [['Paiements', '/paiements'], ['Remboursements']]),
        ]);
    }

    public function export(Request $request): HttpResponse
    {
        $response = $this->api->get('refunds/export', $this->query($this->filters($request, self::REFUND_QUERY), self::REFUND_QUERY));

        return response($response->body(), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="remboursements.csv"']);
    }

    public function show(Request $request, string $refund): Response
    {
        $r = $this->api->get("refunds/{$refund}")->json('data');

        return Inertia::render('Payments/Refund', [
            'refund' => [
                ...$this->refund($r),
                'savedNumbers' => $r['saved_numbers'],
                'operators' => $r['operators'],
                'transactions' => array_map(fn (array $t): array => [...$t, 'status' => $this->toned($t['status'], self::TRANSACTION_TONES), 'date' => $this->moment($t['initiated_at'])], $r['transactions']),
            ],
            'can' => $this->can($request),
            'header' => $this->header('Fiche du remboursement', '', [['Paiements', '/paiements'], ['Remboursements', '/paiements/remboursements'], [$r['reference']]]),
        ]);
    }

    public function chooseNumber(Request $request, string $refund): RedirectResponse
    {
        return $this->act("refunds/{$refund}/method", $request->only('payment_method_id'));
    }

    public function enterNumber(Request $request, string $refund): RedirectResponse
    {
        return $this->act("refunds/{$refund}/number", $request->only('operator_id', 'dial_code', 'phone_number'));
    }

    public function approve(string $refund): RedirectResponse
    {
        return $this->act("refunds/{$refund}/approve");
    }

    public function retry(string $refund): RedirectResponse
    {
        return $this->act("refunds/{$refund}/retry");
    }

    public function refuse(Request $request, string $refund): RedirectResponse
    {
        return $this->act("refunds/{$refund}/refuse", $request->only('reason'));
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
            $refusal = array_intersect_key($e->errors(), array_flip(['refund', 'regularisation', 'api']));

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
    private function mission(array $m): array
    {
        return [
            'id' => $m['id'],
            'reference' => $m['reference'],
            'site' => $m['site'],
            'promoter' => $m['promoter'],
            'closedAt' => $this->moment($m['closed_at']),
            'state' => $this->toned($m['state'], self::STATE_TONES),
            'days' => $m['days'],
            'workers' => $m['workers_amount'],
            'commission' => $m['commission_amount'],
            'yRate' => $m['y_rate'],
            'total' => $m['total'],
            'plannedTotal' => $m['planned_total'],
            'paid' => $m['paid'],
            'refunded' => $m['refunded'],
            'balance' => $m['balance'],
            'blockers' => $m['blockers'],
            'schedule' => $m['schedule'] ? [...$m['schedule'], 'status' => $this->toned($m['schedule']['status'], self::SCHEDULE_TONES)] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    private function refund(array $r): array
    {
        return [
            'id' => $r['id'],
            'reference' => $r['reference'],
            'origin' => $r['origin'],
            'status' => $this->toned($r['status'], self::REFUND_TONES),
            'amount' => $r['amount'],
            'reason' => $r['reason'],
            'refusalReason' => $r['refusal_reason'],
            'mission' => $r['mission'],
            'site' => $r['site'],
            'promoter' => $r['promoter'],
            'transaction' => $r['transaction'],
            'number' => $r['number'],
            'operator' => $r['operator'],
            'requestedBy' => $r['requested_by'],
            'approvedBy' => $r['approved_by'],
            'secondApprover' => $r['second_approver'],
            'approvalsRequired' => $r['approvals_required'],
            'date' => $this->moment($r['created_at']),
            'executedAt' => $this->moment($r['executed_at']),
            'canApprove' => $r['can_approve'],
            'canRetry' => $r['can_retry'],
            'canRefuse' => $r['can_refuse'],
            'canChangeNumber' => $r['can_change_number'],
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function can(Request $request): array
    {
        $user = $request->user();
        $allowed = fn (string $permission): bool => $user instanceof AdminUser && $user->hasPermission($permission);

        return [
            'update' => $allowed('payments.update'),
            'decide' => $allowed('payments.approve'),
            'exportRefunds' => $allowed('payments.export'),
            'payouts' => $allowed('payouts.read'),
            'commercials' => $allowed('commercial_earnings.read'),
        ];
    }

    /**
     * @param  array<string, string>  $status
     * @param  array<string, string>  $tones
     * @return array<string, string>
     */
    private function toned(array $status, array $tones): array
    {
        return [...$status, 'tone' => $tones[$status['value']] ?? 'n'];
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

    /**
     * @param  list<array<string, mixed>>  $data
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
     * @param  array<string, string>  $labels
     * @return list<array{value: string, label: string}>
     */
    private function options(array $labels): array
    {
        return array_map(fn (string $value, string $label): array => compact('value', 'label'), array_keys($labels), $labels);
    }

    private function moment(?string $date): ?string
    {
        return $date ? Carbon::parse($date)->setTimezone(config('app.timezone'))->format('Y-m-d H:i') : null;
    }
}
