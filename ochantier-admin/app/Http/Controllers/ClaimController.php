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
 * Réclamations, lues dans l’API (api/v1/admin/claims) : déposées dans l’application Promoteur ou saisies ici au nom
 * d’un promoteur, d’un ouvrier ou d’un commercial.
 */
class ClaimController extends Controller
{
    private const PER_PAGE = 20;

    private const QUERY = ['q' => 'search', 'statut' => 'status', 'categorie' => 'category', 'priorite' => 'priority', 'demandeur' => 'claimant_type', 'retard' => 'overdue', 'page' => 'page'];

    private const STATUS_TONES = ['ouverte' => 'a', 'en_traitement' => 'b', 'resolue' => 'g', 'escaladee' => 'p', 'rejetee' => 'r', 'close' => 'n'];

    private const PRIORITY_TONES = ['basse' => 'n', 'normale' => 'b', 'haute' => 'o', 'critique' => 'r'];

    private const ACTION_LABELS = [
        'claims.created' => 'Saisie au Back Office',
        'claims.taken' => 'Prise en charge',
        'claims.prioritized' => 'Priorité modifiée',
        'claims.resolved' => 'Résolue',
        'claims.rejected' => 'Rejetée',
        'claims.escalated' => 'Passée en litige',
        'claims.closed' => 'Close',
    ];

    public function __construct(private AdminApi $api) {}

    public function index(Request $request): Response
    {
        $filters = array_filter($request->only(array_keys(self::QUERY)), fn ($value) => is_string($value) && $value !== '');
        $query = [];

        foreach ($filters as $key => $value) {
            $query[self::QUERY[$key]] = $value;
        }

        $response = $this->api->get('claims', [...$query, 'per_page' => self::PER_PAGE]);
        $meta = $response->json('meta');
        $rows = array_map($this->row(...), $response->json('data'));
        $from = $meta['total'] ? ($meta['current_page'] - 1) * $meta['per_page'] + 1 : 0;

        return Inertia::render('Quality/Claims', [
            'claims' => ['data' => $rows, 'currentPage' => $meta['current_page'], 'lastPage' => $meta['last_page'], 'total' => $meta['total'], 'from' => $from, 'to' => $from ? $from + count($rows) - 1 : 0],
            'filters' => $filters,
            'categories' => $meta['categories'],
            'kpis' => ['open' => $meta['counts']['statuses']['ouverte'], 'processing' => $meta['counts']['statuses']['en_traitement'], 'overdue' => $meta['counts']['overdue'], 'resolved' => $meta['counts']['statuses']['resolue']],
            'can' => $this->can($request),
            'header' => $this->header('Réclamations', '', [['Avis & litiges', '/avis-litiges'], ['Réclamations']]),
        ]);
    }

    public function show(Request $request, string $claim): Response
    {
        $c = $this->api->get("claims/{$claim}")->json('data');

        return Inertia::render('Quality/Claim', [
            'claim' => [
                ...$this->row($c),
                'description' => $c['description'],
                'otherParty' => $c['other_party'],
                'resolution' => $c['resolution'],
                'dispute' => $c['dispute'],
                'history' => array_map(fn (array $h): array => [...$h, 'label' => self::ACTION_LABELS[$h['action']] ?? $h['action'], 'date' => $this->moment($h['date'])], $c['history']),
                'actions' => $c['can'],
            ],
            'can' => $this->can($request),
            'header' => $this->header('Fiche de la réclamation', '', [['Avis & litiges', '/avis-litiges'], ['Réclamations', '/avis-litiges/reclamations'], [$c['reference']]]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        return $this->act('claims', $request->only('claimant_type', 'dial_code', 'phone_number', 'site_reference', 'category', 'priority', 'subject', 'description'));
    }

    public function take(string $claim): RedirectResponse
    {
        return $this->act("claims/{$claim}/take");
    }

    public function prioritize(Request $request, string $claim): RedirectResponse
    {
        return $this->act("claims/{$claim}/priority", $request->only('priority'));
    }

    public function resolve(Request $request, string $claim): RedirectResponse
    {
        return $this->act("claims/{$claim}/resolve", $request->only('reason'));
    }

    public function reject(Request $request, string $claim): RedirectResponse
    {
        return $this->act("claims/{$claim}/reject", $request->only('reason'));
    }

    public function escalate(Request $request, string $claim): RedirectResponse
    {
        return $this->act("claims/{$claim}/escalate", $request->only('urgency', 'mission_reference'));
    }

    public function close(string $claim): RedirectResponse
    {
        return $this->act("claims/{$claim}/close");
    }

    public function legacy(): RedirectResponse
    {
        Inertia::flash('toast', 'Cette page a été remplacée : voici les réclamations.');

        return to_route('claims.index');
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
            $refusal = array_intersect_key($e->errors(), array_flip(['claim', 'api']));

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
     * @param  array<string, mixed>  $c
     * @return array<string, mixed>
     */
    private function row(array $c): array
    {
        return [
            'id' => $c['id'],
            'reference' => $c['reference'],
            'category' => $c['category']['label'],
            'subject' => $c['subject'],
            'claimant' => $c['claimant'],
            'site' => $c['site'],
            'priority' => [...$c['priority'], 'tone' => self::PRIORITY_TONES[$c['priority']['value']] ?? 'n'],
            'status' => [...$c['status'], 'tone' => self::STATUS_TONES[$c['status']['value']] ?? 'n'],
            'owner' => $c['owner'],
            'dueAt' => $this->moment($c['due_at']),
            'overdue' => $c['overdue'],
            'createdAt' => $this->moment($c['created_at']),
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function can(Request $request): array
    {
        $user = $request->user();

        return ['update' => $user instanceof AdminUser && $user->hasPermission('disputes.update')];
    }

    private function moment(?string $date): ?string
    {
        return $date ? Carbon::parse($date)->setTimezone(config('app.timezone'))->format('Y-m-d H:i') : null;
    }
}
