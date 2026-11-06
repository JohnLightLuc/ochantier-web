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
 * Abonnements des promoteurs, lus dans l’API (api/v1/admin/subscriptions et subscription-plans). Le promoteur paie
 * dans l’application : ici, on relance, on programme une formule, on suspend, on résilie et on gère le catalogue.
 */
class SubscriptionController extends Controller
{
    private const PER_PAGE = 20;

    private const STATUS_LABELS = ['essai' => 'Essai', 'active' => 'Active', 'en_retard' => 'En retard', 'suspendue' => 'Suspendue', 'resiliee' => 'Résiliée', 'expiree' => 'Expirée'];

    private const STATUS_TONES = ['essai' => 'b', 'active' => 'g', 'en_retard' => 'r', 'suspendue' => 'o', 'resiliee' => 'n', 'expiree' => 'n'];

    private const INVOICE_TONES = ['a_payer' => 'a', 'payee' => 'g', 'en_retard' => 'r', 'annulee' => 'n', 'remboursee' => 'o'];

    private const QUERY = ['q' => 'search', 'formule' => 'plan', 'statut' => 'status', 'page' => 'page'];

    public function __construct(private AdminApi $api) {}

    public function index(Request $request): Response
    {
        $filters = array_filter($request->only(array_keys(self::QUERY)), fn ($value) => is_string($value) && $value !== '');
        $response = $this->api->get('subscriptions', [...$this->query($filters), 'per_page' => self::PER_PAGE]);
        $meta = $response->json('meta');
        $rows = array_map($this->row(...), $response->json('data'));
        $from = $meta['total'] ? ($meta['current_page'] - 1) * $meta['per_page'] + 1 : 0;
        $plans = array_map($this->plan(...), $this->api->get('subscription-plans')->json('data'));

        return Inertia::render('Subscriptions/Index', [
            'tab' => $request->query('onglet') === 'formules' ? 'formules' : 'abonnes',
            'subscriptions' => ['data' => $rows, 'currentPage' => $meta['current_page'], 'lastPage' => $meta['last_page'], 'total' => $meta['total'], 'from' => $from, 'to' => $from ? $from + count($rows) - 1 : 0],
            'filters' => $filters,
            'filterOptions' => [
                'formule' => array_map(fn (array $plan): array => ['value' => $plan['code'], 'label' => $plan['name']], $meta['plans']),
                'statut' => array_map(fn (string $value, string $label): array => compact('value', 'label'), array_keys(self::STATUS_LABELS), self::STATUS_LABELS),
            ],
            'kpis' => [
                'paying' => $meta['counts']['paying'],
                'promoters' => $meta['counts']['promoters'],
                'revenue' => $meta['counts']['monthly_revenue'],
                'late' => $meta['counts']['statuses']['en_retard'],
                'suspended' => $meta['counts']['statuses']['suspendue'],
            ],
            'plans' => $plans,
            'can' => $this->can($request),
        ]);
    }

    public function export(Request $request): HttpResponse
    {
        $filters = array_filter($request->only(['q', 'formule', 'statut']), fn ($value) => is_string($value) && $value !== '');
        $response = $this->api->get('subscriptions/export', $this->query($filters));

        return response($response->body(), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="abonnements.csv"']);
    }

    public function show(Request $request, string $subscription): Response
    {
        $s = $this->api->get("subscriptions/{$subscription}")->json('data');
        $plans = array_map($this->plan(...), $this->api->get('subscription-plans')->json('data'));

        return Inertia::render('Subscriptions/Show', [
            'subscription' => [
                ...$this->row($s),
                'phone' => $s['contact']['phone'],
                'email' => $s['contact']['email'],
                'periodStart' => $s['period_start'],
                'previousPlan' => $s['previous_plan'],
                'cancelledAt' => $this->moment($s['cancelled_at']),
                'cancellationReason' => $s['cancellation_reason'],
                'operator' => $s['payment_operator'],
                'users' => $s['users'],
                'invoices' => array_map(fn (array $i): array => [
                    'id' => $i['id'],
                    'reference' => $i['reference'],
                    'label' => $i['label'],
                    'amount' => $i['amount'],
                    'date' => $i['date_label'],
                    'status' => [...$i['status'], 'tone' => self::INVOICE_TONES[$i['status']['value']] ?? 'n'],
                    'receiptUrl' => $i['receipt_url'],
                ], $s['invoices']),
                'history' => array_map(fn (array $h): array => [...$h, 'date' => $this->moment($h['date'])], $s['history']),
                'actions' => [
                    'remind' => $s['can']['remind'],
                    'changePlan' => $s['can']['change_plan'],
                    'cancelPlanChange' => $s['can']['cancel_plan_change'],
                    'suspend' => $s['can']['suspend'],
                    'reactivate' => $s['can']['reactivate'],
                    'terminate' => $s['can']['terminate'],
                    'resume' => $s['can']['resume'],
                ],
            ],
            'plans' => array_values(array_filter($plans, fn (array $plan): bool => $plan['active'] && ! $plan['free'] && $plan['code'] !== $s['plan']['code'])),
            'can' => $this->can($request),
            'header' => $this->header($s['promoter']['name'], '', [['Abonnements', '/abonnements'], [$s['reference']]]),
        ]);
    }

    public function remind(string $subscription): RedirectResponse
    {
        return $this->act(fn () => $this->api->post("subscriptions/{$subscription}/remind"));
    }

    public function schedulePlan(Request $request, string $subscription): RedirectResponse
    {
        return $this->act(fn () => $this->api->post("subscriptions/{$subscription}/plan-change", $request->only('plan_code')));
    }

    public function cancelPlanChange(string $subscription): RedirectResponse
    {
        return $this->act(fn () => $this->api->delete("subscriptions/{$subscription}/plan-change"));
    }

    public function suspend(Request $request, string $subscription): RedirectResponse
    {
        return $this->act(fn () => $this->api->post("subscriptions/{$subscription}/suspension", $request->only('reason')));
    }

    public function reactivate(string $subscription): RedirectResponse
    {
        return $this->act(fn () => $this->api->post("subscriptions/{$subscription}/reactivation"));
    }

    public function terminate(Request $request, string $subscription): RedirectResponse
    {
        return $this->act(fn () => $this->api->post("subscriptions/{$subscription}/termination", $request->only('reason')));
    }

    public function resume(string $subscription): RedirectResponse
    {
        return $this->act(fn () => $this->api->delete("subscriptions/{$subscription}/termination"));
    }

    public function storePlan(Request $request): RedirectResponse
    {
        return $this->act(fn () => $this->api->post('subscription-plans', $this->planData($request)));
    }

    public function updatePlan(Request $request, string $plan): RedirectResponse
    {
        return $this->act(fn () => $this->api->patch("subscription-plans/{$plan}", $this->planData($request)));
    }

    public function retirePlan(string $plan): RedirectResponse
    {
        return $this->act(fn () => $this->api->post("subscription-plans/{$plan}/retire"));
    }

    public function restorePlan(string $plan): RedirectResponse
    {
        return $this->act(fn () => $this->api->post("subscription-plans/{$plan}/restore"));
    }

    /**
     * Les anciens liens (par promoteur) ne correspondent plus à une fiche.
     */
    public function legacy(): RedirectResponse
    {
        Inertia::flash('toast', 'Cette page a été remplacée : voici les abonnements.');

        return to_route('subscriptions.index');
    }

    /**
     * Un refus métier s’affiche en message ; une erreur de champ reste sous le champ du formulaire.
     */
    private function act(callable $call): RedirectResponse
    {
        try {
            $response = $call();
        } catch (ValidationException $e) {
            $refusal = array_intersect_key($e->errors(), array_flip(['subscription', 'plan', 'api']));

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
     * Le formulaire saisit un avantage par ligne.
     *
     * @return array<string, mixed>
     */
    private function planData(Request $request): array
    {
        $features = preg_split('/\R/', (string) $request->input('features'));

        return [...$request->only('name', 'price_amount', 'max_users', 'is_featured'), 'features' => array_values(array_filter(array_map('trim', $features)))];
    }

    /**
     * @param  array<string, mixed>  $s
     * @return array<string, mixed>
     */
    private function row(array $s): array
    {
        return [
            'id' => $s['id'],
            'reference' => $s['reference'],
            'promoter' => $s['promoter'],
            'plan' => ['code' => $s['plan']['code'], 'name' => $s['plan']['name'], 'price' => $s['plan']['price'], 'maxUsers' => $s['plan']['max_users']],
            'pending' => $s['pending_plan'] ? ['name' => $s['pending_plan']['name'], 'at' => $s['pending_plan']['effective_at']] : null,
            'status' => [...$s['status'], 'tone' => self::STATUS_TONES[$s['status']['value']] ?? 'n'],
            'seats' => $s['seats_used'],
            'since' => $s['started_at'],
            'periodEnd' => $s['period_end'],
            'nextDue' => $s['next_due_date'],
            'autoRenew' => $s['auto_renew'],
        ];
    }

    /**
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    private function plan(array $p): array
    {
        return [
            'id' => $p['id'],
            'code' => $p['code'],
            'name' => $p['name'],
            'subtitle' => $p['subtitle'],
            'price' => $p['price'],
            'maxUsers' => $p['max_users'],
            'features' => $p['features'],
            'popular' => $p['is_featured'],
            'active' => $p['is_active'],
            'free' => $p['is_free'],
            'subscribers' => $p['subscribers'],
            'revenue' => $p['monthly_revenue'],
        ];
    }

    /**
     * @param  array<string, string>  $filters
     * @return array<string, string>
     */
    private function query(array $filters): array
    {
        $query = [];

        foreach ($filters as $key => $value) {
            $query[self::QUERY[$key]] = $value;
        }

        return $query;
    }

    /**
     * @return array<string, bool>
     */
    private function can(Request $request): array
    {
        $user = $request->user();
        $allowed = fn (string $permission): bool => $user instanceof AdminUser && $user->hasPermission($permission);

        return ['update' => $allowed('subscriptions.update'), 'export' => $allowed('subscriptions.export')];
    }

    private function moment(?string $date): ?string
    {
        return $date ? Carbon::parse($date)->setTimezone(config('app.timezone'))->format('Y-m-d H:i') : null;
    }
}
