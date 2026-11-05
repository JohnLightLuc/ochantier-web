<?php

namespace App\Http\Controllers;

use App\Support\Api\AdminApi;
use App\Support\Api\AdminUser;
use App\Support\OfferPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Matching : file des besoins, résultats, accord des ouvriers et envoi de la proposition, par l’API
 * (api/v1/admin/matching).
 */
class MatchingController extends Controller
{
    private const PER_PAGE = 20;

    private const QUERY = ['q' => 'search', 'metier' => 'trade_id', 'localisation' => 'commune_id', 'page' => 'page'];

    private const LEVELS = ['excellent' => ['Excellent', 'g'], 'tres_bon' => ['Très bon', 'g'], 'bon' => ['Bon', 'b'], 'acceptable' => ['Acceptable', 'a'], 'faible' => ['Faible', 'o'], 'non_recommande' => ['Non recommandé', 'r']];

    private const SUGGESTION_TONES = ['suggere' => 'b', 'contacte' => 'a', 'accepte' => 'g', 'refuse' => 'r', 'indisponible' => 'r', 'propose_promoteur' => 'p', 'selectionne' => 'g', 'ecarte' => 'n'];

    private const CRITERIA = ['disponibilite' => 'Disponibilité', 'distance' => 'Distance', 'cout' => 'Coût', 'identite' => 'Pièce d’identité'];

    public function __construct(private AdminApi $api) {}

    public function index(Request $request): Response
    {
        $filters = array_filter($request->only(['q', 'metier', 'localisation', 'page']), fn ($value) => is_string($value) && $value !== '');
        $query = [];

        foreach ($filters as $key => $value) {
            $query[self::QUERY[$key]] = $value;
        }

        $response = $this->api->get('matching/queue', [...$query, 'per_page' => self::PER_PAGE]);
        $meta = $response->json('meta');
        $rows = array_map(fn (array $o) => [...OfferPresenter::row($o), 'lastRunAt' => $o['last_run_at'] ? substr($o['last_run_at'], 0, 10) : null], $response->json('data'));
        $from = $meta['total'] ? ($meta['current_page'] - 1) * $meta['per_page'] + 1 : 0;
        $option = fn (string $value, string $label): array => compact('value', 'label');

        return Inertia::render('Matching/Index', [
            'offers' => ['data' => $rows, 'currentPage' => $meta['current_page'], 'lastPage' => $meta['last_page'], 'total' => $meta['total'], 'from' => $from, 'to' => $from ? $from + count($rows) - 1 : 0],
            'filters' => $filters,
            'filterOptions' => [
                'metier' => array_map(fn (array $t): array => $option($t['id'], $t['label']), $meta['trades']),
                'localisation' => array_map(fn (array $c): array => $option($c['id'], $c['name']), $meta['communes']),
            ],
            'derogations' => array_map(fn (array $d) => ['id' => $d['id'], 'offerId' => $d['offer']['id'], 'reference' => $d['offer']['reference'], 'trade' => $d['trade'], 'worker' => $d['worker'], 'criterion' => self::CRITERIA[$d['criterion']] ?? $d['criterion'], 'justification' => $d['justification'], 'requestedBy' => $d['requested_by'], 'canDecide' => $d['can_decide']], $this->api->get('matching/derogations')->json('data')),
            'can' => ['approve' => $this->allowed($request, 'matching.approve')],
            'kpis' => ['toMatch' => $meta['counts']['todo']['a_matcher'], 'proposals' => $meta['counts']['todo']['proposition'], 'deposits' => $meta['counts']['todo']['acompte'], 'partial' => $meta['counts']['todo']['incomplet']],
        ]);
    }

    public function show(Request $request, string $offer): Response
    {
        $data = $this->api->get("matching/offers/{$offer}")->json('data');
        $view = OfferPresenter::row($data['offer']);

        return Inertia::render('Matching/Offer', [
            'offer' => $view,
            'runRefusal' => $data['run_refusal'],
            'retained' => $data['retained'],
            'needs' => array_map($this->need(...), $data['needs']),
            'can' => ['run' => $this->allowed($request, 'matching.create'), 'update' => $this->allowed($request, 'matching.update'), 'approve' => $this->allowed($request, 'matching.approve')],
            'header' => $this->header('Matching', $view['title'], [['Matching', '/matching'], [$view['reference']]]),
        ]);
    }

    public function run(string $offer): RedirectResponse
    {
        return $this->send("matching/offers/{$offer}/run");
    }

    public function dispatch(string $offer): RedirectResponse
    {
        return $this->send("matching/offers/{$offer}/send");
    }

    public function contact(Request $request, string $suggestion): RedirectResponse
    {
        return $this->send("matching/suggestions/{$suggestion}/contact", ['channel' => $request->input('channel')]);
    }

    public function respond(Request $request, string $suggestion): RedirectResponse
    {
        return $this->send("matching/suggestions/{$suggestion}/response", ['channel' => $request->input('channel'), 'decision' => $request->input('decision'), 'conditions' => trim((string) $request->input('conditions')) ?: null]);
    }

    public function exclude(Request $request, string $suggestion): RedirectResponse
    {
        return $this->send("matching/suggestions/{$suggestion}/exclusion", ['reason' => $this->reason($request)]);
    }

    public function lift(string $exclusion): RedirectResponse
    {
        return $this->send("matching/exclusions/{$exclusion}", method: 'delete');
    }

    public function requestDerogation(Request $request, string $exclusion): RedirectResponse
    {
        return $this->send("matching/exclusions/{$exclusion}/derogation", ['justification' => trim((string) $request->input('justification')) ?: null]);
    }

    public function approveDerogation(string $derogation): RedirectResponse
    {
        return $this->send("matching/derogations/{$derogation}/approve");
    }

    public function revokeDerogation(string $derogation): RedirectResponse
    {
        return $this->send("matching/derogations/{$derogation}", method: 'delete');
    }

    private function reason(Request $request): string
    {
        $reason = trim((string) $request->input('reason'));
        $category = trim((string) $request->input('category'));

        return $category && $reason && $category !== 'Autre' ? "{$category} · {$reason}" : $reason;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function send(string $uri, array $data = [], string $method = 'post'): RedirectResponse
    {
        try {
            $response = $method === 'delete' ? $this->api->delete($uri) : $this->api->post($uri, $data);
        } catch (ValidationException $e) {
            $refusal = array_intersect_key($e->errors(), array_flip(['offer', 'api']));

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
     * Anciennes adresses du module : retour à la file des besoins.
     */
    public function legacy(): RedirectResponse
    {
        Inertia::flash('toast', 'Cette page a été remplacée : voici la file des besoins.');

        return to_route('matching.index');
    }

    /**
     * @param  array<string, mixed>  $n
     * @return array<string, mixed>
     */
    private function need(array $n): array
    {
        return [
            'id' => $n['id'],
            'reference' => $n['reference'],
            'trade' => $n['trade'],
            'specialty' => $n['specialty'],
            'headcount' => $n['headcount'],
            'experience' => $n['min_experience_years'],
            'budget' => $n['daily_budget'],
            'status' => $n['status']['label'],
            'lastRun' => $n['last_run'] ? ['at' => substr((string) $n['last_run']['at'], 0, 10), 'by' => $n['last_run']['by'], 'stale' => $n['last_run']['stale']] : null,
            'suggestions' => array_map(function (array $s) {
                [$label, $tone] = self::LEVELS[$s['level'] ?? ''] ?? ['—', 'n'];

                return [
                    'id' => $s['id'], 'rank' => $s['rank'], 'score' => $s['score'], 'level' => compact('label', 'tone'), 'requested' => $s['requested'],
                    'status' => ['value' => $s['status']['value'], 'label' => $s['status']['label'], 'tone' => self::SUGGESTION_TONES[$s['status']['value']] ?? 'n'],
                    'workerId' => $s['worker']['id'], 'name' => $s['worker']['full_name'], 'code' => $s['worker']['reference'], 'phone' => $s['worker']['phone'], 'contact' => $s['contact'], 'response' => $s['response'], 'commune' => $s['worker']['commune'], 'rating' => $s['worker']['rating'], 'experience' => $s['worker']['experience_years'],
                    'distance' => $s['distance_km'], 'rate' => $s['worker_daily_rate'], 'promoterRate' => $s['promoter_daily_rate'], 'criteria' => $s['criteria'], 'warnings' => $s['warnings'],
                ];
            }, $n['suggestions']),
            'exclusions' => array_map(fn (array $e) => ['id' => $e['id'], 'workerId' => $e['worker']['id'], 'name' => $e['worker']['full_name'], 'code' => $e['worker']['reference'], 'nature' => $e['nature']['label'], 'absolute' => $e['nature']['value'] === 'reglementaire', 'reason' => $e['comment'], 'canRequest' => $e['can_request_derogation'], 'canLift' => $e['can_lift'],
                'derogation' => $e['derogation'] ? ['id' => $e['derogation']['id'], 'state' => $e['derogation']['state'], 'justification' => $e['derogation']['justification'], 'requestedBy' => $e['derogation']['requested_by'], 'approvedBy' => $e['derogation']['approved_by'], 'canDecide' => $e['derogation']['can_decide']] : null], $n['exclusions']),
        ];
    }

    private function allowed(Request $request, string $permission): bool
    {
        $user = $request->user();

        return $user instanceof AdminUser && $user->hasPermission($permission);
    }
}
