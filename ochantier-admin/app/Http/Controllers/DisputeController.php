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
 * Litiges, lus dans l’API (api/v1/admin/disputes), et vue d’ensemble du module Avis & litiges. Le support instruit ;
 * la décision et le recours reviennent chacun à une autre personne.
 */
class DisputeController extends Controller
{
    private const PER_PAGE = 20;

    private const QUERY = ['q' => 'search', 'statut' => 'status', 'categorie' => 'category', 'urgence' => 'urgency', 'sans_responsable' => 'unassigned', 'page' => 'page'];

    private const STATUS_TONES = ['ouvert' => 'a', 'en_instruction' => 'b', 'resolu' => 'g', 'en_recours' => 'o', 'clos' => 'n'];

    private const URGENCY_TONES = ['basse' => 'n', 'normale' => 'b', 'haute' => 'o', 'critique' => 'r'];

    private const REFUND_TONES = ['demande' => 'a', 'approuve' => 'b', 'execute' => 'g', 'echoue' => 'r', 'refuse' => 'n'];

    private const ACTION_LABELS = [
        'disputes.opened' => 'Litige ouvert',
        'disputes.taken' => 'Pris en charge',
        'disputes.urgency' => 'Urgence modifiée',
        'disputes.mission_linked' => 'Mission rattachée',
        'disputes.decided' => 'Décision rendue',
        'disputes.appealed' => 'Recours ouvert',
        'disputes.appeal_decided' => 'Décision sur le recours',
        'disputes.closed' => 'Clos',
    ];

    public function __construct(private AdminApi $api) {}

    public function overview(Request $request): Response
    {
        $ratings = $this->allowed($request, 'ratings.read') ? $this->api->get('ratings', ['per_page' => 1])->json('meta.counts') : null;
        $canDisputes = $this->allowed($request, 'disputes.read');
        $claims = $canDisputes ? $this->api->get('claims', ['per_page' => 1])->json('meta.counts') : null;
        $disputes = $canDisputes ? $this->api->get('disputes', ['per_page' => 5]) : null;

        return Inertia::render('Quality/Overview', [
            'ratings' => $ratings,
            'claims' => $claims ? ['open' => $claims['statuses']['ouverte'] + $claims['statuses']['en_traitement'], 'overdue' => $claims['overdue']] : null,
            'disputes' => $disputes ? [
                'open' => $disputes->json('meta.counts.statuses.ouvert') + $disputes->json('meta.counts.statuses.en_instruction'),
                'appeals' => $disputes->json('meta.counts.statuses.en_recours'),
                'unassigned' => $disputes->json('meta.counts.unassigned'),
                'refundsPending' => $disputes->json('meta.counts.refunds_pending'),
                'latest' => array_map($this->row(...), $disputes->json('data')),
            ] : null,
        ]);
    }

    public function index(Request $request): Response
    {
        $filters = array_filter($request->only(array_keys(self::QUERY)), fn ($value) => is_string($value) && $value !== '');
        $query = [];

        foreach ($filters as $key => $value) {
            $query[self::QUERY[$key]] = $value;
        }

        $response = $this->api->get('disputes', [...$query, 'per_page' => self::PER_PAGE]);
        $meta = $response->json('meta');
        $rows = array_map($this->row(...), $response->json('data'));
        $from = $meta['total'] ? ($meta['current_page'] - 1) * $meta['per_page'] + 1 : 0;

        return Inertia::render('Quality/Disputes', [
            'disputes' => ['data' => $rows, 'currentPage' => $meta['current_page'], 'lastPage' => $meta['last_page'], 'total' => $meta['total'], 'from' => $from, 'to' => $from ? $from + count($rows) - 1 : 0],
            'filters' => $filters,
            'categories' => $meta['categories'],
            'kpis' => ['open' => $meta['counts']['statuses']['ouvert'], 'instructing' => $meta['counts']['statuses']['en_instruction'], 'appeals' => $meta['counts']['statuses']['en_recours'], 'unassigned' => $meta['counts']['unassigned']],
            'can' => $this->can($request),
            'header' => $this->header('Litiges', '', [['Avis & litiges', '/avis-litiges'], ['Litiges']]),
        ]);
    }

    public function show(Request $request, string $dispute): Response
    {
        $d = $this->api->get("disputes/{$dispute}")->json('data');

        return Inertia::render('Quality/Dispute', [
            'dispute' => [
                ...$this->row($d),
                'description' => $d['description'],
                'claim' => $d['claim'],
                'decision' => $d['decision'],
                'decidedBy' => $d['decided_by'],
                'decidedAt' => $this->moment($d['decided_at']),
                'appealDeadline' => $this->moment($d['appeal_deadline']),
                'outcome' => $d['outcome'],
                'financial' => [
                    'refundAmount' => $d['financial']['refund_amount'],
                    'note' => $d['financial']['note'],
                    'refundPending' => $d['financial']['refund_pending'],
                    'refund' => $d['financial']['refund'] ? [...$d['financial']['refund'], 'status' => [...$d['financial']['refund']['status'], 'tone' => self::REFUND_TONES[$d['financial']['refund']['status']['value']] ?? 'n']] : null,
                ],
                'appeal' => $d['appeal'],
                'messages' => array_map(fn (array $m): array => [...$m, 'date' => $this->moment($m['date'])], $d['messages']),
                'proofs' => array_map(fn (array $p): array => [...$p, 'date' => $this->moment($p['date'])], $d['proofs']),
                'history' => array_map(fn (array $h): array => [...$h, 'label' => self::ACTION_LABELS[$h['action']] ?? $h['action'], 'date' => $this->moment($h['date'])], $d['history']),
                'actions' => [
                    'take' => $d['can']['take'],
                    'urgency' => $d['can']['urgency'],
                    'linkMission' => $d['can']['link_mission'],
                    'message' => $d['can']['message'],
                    'proof' => $d['can']['proof'],
                    'decide' => $d['can']['decide'],
                    'decideAppeal' => $d['can']['decide_appeal'],
                    'appeal' => $d['can']['appeal'],
                    'close' => $d['can']['close'],
                    'requestRefund' => $d['can']['request_refund'],
                ],
            ],
            'can' => $this->can($request),
            'header' => $this->header('Fiche du litige', '', [['Avis & litiges', '/avis-litiges'], ['Litiges', '/avis-litiges/litiges'], [$d['reference']]]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        return $this->act(fn () => $this->api->post('disputes', $request->only('claimant_type', 'dial_code', 'phone_number', 'mission_reference', 'category', 'urgency', 'description')));
    }

    public function take(string $dispute): RedirectResponse
    {
        return $this->act(fn () => $this->api->post("disputes/{$dispute}/take"));
    }

    public function urgency(Request $request, string $dispute): RedirectResponse
    {
        return $this->act(fn () => $this->api->post("disputes/{$dispute}/urgency", $request->only('urgency')));
    }

    public function linkMission(Request $request, string $dispute): RedirectResponse
    {
        return $this->act(fn () => $this->api->post("disputes/{$dispute}/mission", $request->only('mission_reference')));
    }

    public function message(Request $request, string $dispute): RedirectResponse
    {
        return $this->act(fn () => $this->api->post("disputes/{$dispute}/messages", $request->only('body', 'internal')));
    }

    public function proof(Request $request, string $dispute): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:10240']], ['file.*' => 'Ajoutez un fichier de 10 Mo maximum.']);

        return $this->act(fn () => $this->api->upload("disputes/{$dispute}/proofs", $request->only('label'), ['file' => $request->file('file')]));
    }

    public function decide(Request $request, string $dispute): RedirectResponse
    {
        return $this->act(fn () => $this->api->post("disputes/{$dispute}/decision", $this->decision($request)));
    }

    public function appeal(Request $request, string $dispute): RedirectResponse
    {
        return $this->act(fn () => $this->api->post("disputes/{$dispute}/appeal", $request->only('reason')));
    }

    public function decideAppeal(Request $request, string $dispute): RedirectResponse
    {
        return $this->act(fn () => $this->api->post("disputes/{$dispute}/appeal-decision", [...$this->decision($request), 'outcome' => $request->input('outcome')]));
    }

    public function close(string $dispute): RedirectResponse
    {
        return $this->act(fn () => $this->api->post("disputes/{$dispute}/close"));
    }

    public function requestRefund(string $dispute): RedirectResponse
    {
        return $this->act(fn () => $this->api->post("disputes/{$dispute}/refund"));
    }

    /**
     * Les anciennes fiches et les pages de démonstration renvoient vers les écrans branchés sur l’API.
     */
    public function legacy(): RedirectResponse
    {
        Inertia::flash('toast', 'Cette page a été remplacée : voici les litiges.');

        return to_route('disputes.index');
    }

    public function legacyOverview(): RedirectResponse
    {
        Inertia::flash('toast', 'Cette page a été retirée : voici la vue d’ensemble.');

        return to_route('quality.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function decision(Request $request): array
    {
        return ['decision' => $request->input('decision'), 'refund_amount' => $request->filled('refund_amount') ? $request->input('refund_amount') : null, 'financial_note' => $request->input('financial_note') ?: null];
    }

    /**
     * Un refus métier s’affiche en message ; une erreur de champ reste sous le champ du formulaire.
     */
    private function act(callable $call): RedirectResponse
    {
        try {
            $response = $call();
        } catch (ValidationException $e) {
            $refusal = array_intersect_key($e->errors(), array_flip(['dispute', 'refund', 'api']));

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
     * @param  array<string, mixed>  $d
     * @return array<string, mixed>
     */
    private function row(array $d): array
    {
        return [
            'id' => $d['id'],
            'reference' => $d['reference'],
            'category' => $d['category']['label'],
            'parties' => $d['parties'],
            'site' => $d['site'],
            'mission' => $d['mission'],
            'urgency' => [...$d['urgency'], 'tone' => self::URGENCY_TONES[$d['urgency']['value']] ?? 'n'],
            'status' => [...$d['status'], 'tone' => self::STATUS_TONES[$d['status']['value']] ?? 'n'],
            'owner' => $d['owner'],
            'appealOf' => $d['appeal_of'],
            'createdAt' => $this->moment($d['created_at']),
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function can(Request $request): array
    {
        return ['update' => $this->allowed($request, 'disputes.update'), 'decide' => $this->allowed($request, 'disputes.validate'), 'approve' => $this->allowed($request, 'disputes.approve')];
    }

    private function allowed(Request $request, string $permission): bool
    {
        $user = $request->user();

        return $user instanceof AdminUser && $user->hasPermission($permission);
    }

    private function moment(?string $date): ?string
    {
        return $date ? Carbon::parse($date)->setTimezone(config('app.timezone'))->format('Y-m-d H:i') : null;
    }
}
