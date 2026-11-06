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
 * Paiements : encaissements des promoteurs et échéances des missions, lus dans l’API (api/v1/admin/payments). Le
 * paiement reste fait par le promoteur depuis son application ; le Back Office peut le relancer.
 *
 * Les paramètres d’URL des listes restent en français et sont traduits pour l’API.
 */
class PaymentController extends Controller
{
    private const PER_PAGE = 20;

    private const STATUS_TONES = ['initiee' => 'b', 'en_attente' => 'a', 'reussie' => 'g', 'echouee' => 'r', 'expiree' => 'n', 'annulee' => 'n'];

    private const STATUS_LABELS = ['initiee' => 'Initiée', 'en_attente' => 'En attente', 'reussie' => 'Réussie', 'echouee' => 'Échouée', 'expiree' => 'Expirée', 'annulee' => 'Annulée'];

    private const PURPOSE_LABELS = ['acompte' => 'Acompte', 'echeance' => 'Échéance', 'abonnement' => 'Abonnement'];

    private const STATE_TONES = ['prevue' => 'n', 'exigible' => 'a', 'partiellement_payee' => 'o', 'en_retard' => 'r', 'payee' => 'g', 'contestee' => 'r', 'annulee' => 'n', 'remboursee' => 'p'];

    private const STATE_LABELS = ['prevue' => 'Prévue', 'exigible' => 'Exigible', 'partiellement_payee' => 'Partiellement payée', 'en_retard' => 'En retard', 'payee' => 'Payée', 'contestee' => 'Contestée', 'annulee' => 'Annulée', 'remboursee' => 'Remboursée'];

    private const TYPE_LABELS = ['acompte' => 'Acompte', 'hebdomadaire' => 'Hebdomadaire', 'regularisation' => 'Régularisation'];

    private const PERIODS = ['aujourdhui' => 'Aujourd’hui', '7j' => '7 derniers jours', '30j' => '30 derniers jours'];

    private const TRANSACTION_QUERY = ['q' => 'search', 'statut' => 'status', 'nature' => 'purpose', 'operateur' => 'operator_id', 'chantier' => 'site_id', 'page' => 'page'];

    private const TRANSACTION_SORTS = ['date' => 'date', 'montant' => 'amount', 'statut' => 'status'];

    private const TRANSACTION_FILTERS = ['q', 'statut', 'nature', 'operateur', 'chantier', 'periode', 'tri', 'sens', 'page'];

    private const SCHEDULE_QUERY = ['q' => 'search', 'etat' => 'state', 'type' => 'type', 'chantier' => 'site_id', 'page' => 'page'];

    private const SCHEDULE_SORTS = ['echeance' => 'due', 'montant' => 'amount', 'etat' => 'state'];

    private const SCHEDULE_FILTERS = ['q', 'etat', 'type', 'chantier', 'tri', 'sens', 'page'];

    public function __construct(private AdminApi $api) {}

    public function index(Request $request): Response
    {
        $filters = $this->filters($request, self::TRANSACTION_FILTERS);
        $response = $this->api->get('payments', [...$this->transactionQuery($filters), 'per_page' => self::PER_PAGE]);
        $meta = $response->json('meta');
        $option = fn (string $value, string $label): array => compact('value', 'label');

        return Inertia::render('Payments/Index', [
            'transactions' => $this->page($response->json('data'), $meta, $this->transaction(...)),
            'filters' => $filters,
            'filterOptions' => [
                'periode' => array_map($option, array_keys(self::PERIODS), self::PERIODS),
                'statut' => array_map($option, array_keys(self::STATUS_LABELS), self::STATUS_LABELS),
                'nature' => array_map($option, array_keys(self::PURPOSE_LABELS), self::PURPOSE_LABELS),
                'operateur' => array_map(fn (array $o): array => $option($o['id'], $o['name']), $meta['operators']),
            ],
            'kpis' => ['collected' => $meta['counts']['collected'], 'month' => $meta['counts']['month'], 'fees' => $meta['counts']['fees'], 'failed' => $meta['counts']['failed']],
            'can' => $this->can($request),
        ]);
    }

    public function export(Request $request): HttpResponse
    {
        return $this->csv('payments/export', $this->transactionQuery($this->filters($request, self::TRANSACTION_FILTERS)), 'encaissements.csv');
    }

    public function show(Request $request, string $transaction): Response
    {
        $t = $this->api->get("payments/{$transaction}")->json('data');

        return Inertia::render('Payments/Show', [
            'transaction' => [
                ...$this->transaction($t),
                'providerReference' => $t['provider_reference'],
                'phone' => $t['phone'],
                'lines' => $t['lines'],
                'failure' => $t['failure'],
                'invoice' => $t['invoice'],
                'allocations' => $t['allocations'],
                'receipts' => array_map(fn (array $r): array => [...$r, 'issued_at' => $this->day($r['issued_at'])], $t['receipts']),
                'history' => array_map(fn (array $h): array => ['label' => $h['to']['label'], 'at' => $this->moment($h['at'])], $t['history']),
            ],
            'header' => $this->header('Fiche de l’encaissement', '', [['Paiements', '/paiements'], [$t['reference']]]),
        ]);
    }

    public function schedules(Request $request): Response
    {
        $filters = $this->filters($request, self::SCHEDULE_FILTERS);
        $response = $this->api->get('payments/schedules', [...$this->scheduleQuery($filters), 'per_page' => self::PER_PAGE]);
        $meta = $response->json('meta');
        $option = fn (string $value, string $label): array => compact('value', 'label');

        return Inertia::render('Payments/Schedules', [
            'schedules' => $this->page($response->json('data'), $meta, $this->scheduleRow(...)),
            'filters' => $filters,
            'filterOptions' => [
                'etat' => array_map($option, array_keys(self::STATE_LABELS), self::STATE_LABELS),
                'type' => array_map($option, array_keys(self::TYPE_LABELS), self::TYPE_LABELS),
            ],
            'kpis' => ['open' => $meta['counts']['open_amount'], 'late' => $meta['counts']['late_amount'], 'lateCount' => $meta['counts']['states']['en_retard'], 'paid' => $meta['counts']['paid_amount']],
            'states' => $meta['counts']['states'],
            'can' => $this->can($request),
            'header' => $this->header('Échéances', '', [['Paiements', '/paiements'], ['Échéances']]),
        ]);
    }

    public function exportSchedules(Request $request): HttpResponse
    {
        return $this->csv('payments/schedules/export', $this->scheduleQuery($this->filters($request, self::SCHEDULE_FILTERS)), 'echeances.csv');
    }

    public function schedule(Request $request, string $schedule): Response
    {
        $s = $this->api->get("payments/schedules/{$schedule}")->json('data');

        return Inertia::render('Payments/Schedule', [
            'schedule' => [
                ...$this->scheduleRow($s),
                'workersAmount' => $s['workers_amount'],
                'commissionAmount' => $s['promoter_commission_amount'],
                'depositDeduction' => $s['deposit_deduction_amount'],
                'adjustmentAmount' => $s['adjustment_amount'],
                'lastReminderAt' => $this->day($s['last_reminder_at']),
                'days' => $s['days'],
                'payments' => array_map(fn (array $p): array => ['id' => $p['transaction']['id'], 'reference' => $p['transaction']['reference'], 'operator' => $p['transaction']['operator'], 'amount' => $p['amount'], 'date' => $this->day($p['transaction']['completed_at'])], $s['payments']),
                'installments' => array_map(fn (array $i): array => ['label' => "Versement {$i['sequence']}/{$i['count']}", 'amount' => $i['amount'], 'dueDate' => $i['due_date'], 'status' => $i['status']['label'], 'isOpen' => $i['is_open']], $s['installments']),
                'canRemind' => $s['can']['remind'],
            ],
            'can' => $this->can($request),
            'header' => $this->header('Fiche de l’échéance', '', [['Paiements', '/paiements'], ['Échéances', '/paiements/echeances'], [$s['reference']]]),
        ]);
    }

    public function remind(string $schedule): RedirectResponse
    {
        try {
            $response = $this->api->post("payments/schedules/{$schedule}/remind");
        } catch (ValidationException $e) {
            $refusal = array_intersect_key($e->errors(), array_flip(['payment', 'api']));

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
     * Anciens liens (références locales, reversements, commissions, rapports) : retour aux paiements.
     */
    public function legacy(): RedirectResponse
    {
        Inertia::flash('toast', 'Cette page a été remplacée : voici les paiements.');

        return to_route('payments.index');
    }

    /**
     * @param  array<string, mixed>  $t
     * @return array<string, mixed>
     */
    private function transaction(array $t): array
    {
        return [
            'id' => $t['id'],
            'reference' => $t['reference'],
            'status' => [...$t['status'], 'tone' => self::STATUS_TONES[$t['status']['value']] ?? 'n'],
            'purpose' => $t['purpose']['label'],
            'label' => $t['label'],
            'site' => $t['site'],
            'promoter' => $t['promoter'],
            'operator' => $t['operator'],
            'amount' => $t['amount'],
            'fees' => $t['fees_amount'],
            'total' => $t['total_amount'],
            'date' => $this->moment($t['initiated_at']),
            'completedAt' => $this->moment($t['completed_at']),
        ];
    }

    /**
     * @param  array<string, mixed>  $s
     * @return array<string, mixed>
     */
    private function scheduleRow(array $s): array
    {
        return [
            'id' => $s['id'],
            'reference' => $s['reference'],
            'state' => [...$s['state'], 'tone' => self::STATE_TONES[$s['state']['value']] ?? 'n'],
            'type' => $s['type']['label'],
            'sequence' => $s['sequence'],
            'site' => $s['site'],
            'promoter' => $s['promoter'],
            'trade' => $s['trade'],
            'period' => $s['period'],
            'dueDate' => $s['due_date'],
            'isFlexible' => $s['is_flexible'],
            'due' => $s['amount_due'],
            'paid' => $s['amount_paid'],
            'remaining' => $s['remaining'],
        ];
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
     * @param  array<string, mixed>  $query
     */
    private function csv(string $uri, array $query, string $name): HttpResponse
    {
        $response = $this->api->get($uri, $query);

        return response($response->body(), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => $response->header('Content-Disposition') ?: "attachment; filename=\"{$name}\""]);
    }

    /**
     * @return array<string, bool>
     */
    private function can(Request $request): array
    {
        $user = $request->user();
        $allowed = fn (string $permission): bool => $user instanceof AdminUser && $user->hasPermission($permission);

        return ['update' => $allowed('payments.update'), 'export' => $allowed('payments.export')];
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, string>
     */
    private function filters(Request $request, array $keys): array
    {
        return array_filter($request->only($keys), fn ($value) => is_string($value) && $value !== '');
    }

    /**
     * @param  array<string, string>  $filters
     * @return array<string, string>
     */
    private function transactionQuery(array $filters): array
    {
        $query = $this->translate($filters, self::TRANSACTION_QUERY, self::TRANSACTION_SORTS);
        $today = today();
        $from = match ($filters['periode'] ?? null) {
            'aujourdhui' => $today,
            '7j' => $today->copy()->subDays(6),
            '30j' => $today->copy()->subDays(29),
            default => null,
        };

        return $from ? [...$query, 'date_from' => $from->toDateString(), 'date_to' => $today->toDateString()] : $query;
    }

    /**
     * @param  array<string, string>  $filters
     * @return array<string, string>
     */
    private function scheduleQuery(array $filters): array
    {
        return $this->translate($filters, self::SCHEDULE_QUERY, self::SCHEDULE_SORTS);
    }

    /**
     * @param  array<string, string>  $filters
     * @param  array<string, string>  $keys
     * @param  array<string, string>  $sorts
     * @return array<string, string>
     */
    private function translate(array $filters, array $keys, array $sorts): array
    {
        $query = [];

        foreach (array_intersect_key($filters, $keys) as $key => $value) {
            $query[$keys[$key]] = $value;
        }

        if ($sort = $sorts[$filters['tri'] ?? ''] ?? null) {
            $query['sort'] = $sort;
            $query['direction'] = ($filters['sens'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        }

        return $query;
    }

    private function day(?string $date): ?string
    {
        return $date ? Carbon::parse($date)->setTimezone(config('app.timezone'))->toDateString() : null;
    }

    private function moment(?string $date): ?string
    {
        return $date ? Carbon::parse($date)->setTimezone(config('app.timezone'))->format('Y-m-d H:i') : null;
    }
}
