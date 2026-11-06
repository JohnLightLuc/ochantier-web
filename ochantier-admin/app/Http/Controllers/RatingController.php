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
 * Avis laissés par les promoteurs, lus dans l’API (api/v1/admin/ratings) : liste, fiche et modération motivée.
 */
class RatingController extends Controller
{
    private const PER_PAGE = 20;

    private const QUERY = ['q' => 'search', 'statut' => 'status', 'moderation' => 'moderation', 'type' => 'scope', 'note' => 'score', 'suspects' => 'suspected', 'page' => 'page'];

    private const STATUS_TONES = ['publie' => 'g', 'masque' => 'o'];

    private const MODERATION_TONES = ['signale' => 'r', 'maintenu' => 'b', 'masque' => 'o'];

    private const ACTION_LABELS = [
        'ratings.reported' => 'Avis signalé',
        'ratings.kept' => 'Avis maintenu',
        'ratings.hidden' => 'Avis masqué',
        'ratings.restored' => 'Avis rétabli',
        'ratings.suspected' => 'Marqué comme suspect',
        'ratings.cleared' => 'Doute levé',
    ];

    public function __construct(private AdminApi $api) {}

    public function index(Request $request): Response
    {
        $filters = array_filter($request->only(array_keys(self::QUERY)), fn ($value) => is_string($value) && $value !== '');
        $query = [];

        foreach ($filters as $key => $value) {
            $query[self::QUERY[$key]] = $value;
        }

        $response = $this->api->get('ratings', [...$query, 'per_page' => self::PER_PAGE]);
        $meta = $response->json('meta');
        $rows = array_map($this->row(...), $response->json('data'));
        $from = $meta['total'] ? ($meta['current_page'] - 1) * $meta['per_page'] + 1 : 0;

        return Inertia::render('Quality/Ratings', [
            'ratings' => ['data' => $rows, 'currentPage' => $meta['current_page'], 'lastPage' => $meta['last_page'], 'total' => $meta['total'], 'from' => $from, 'to' => $from ? $from + count($rows) - 1 : 0],
            'filters' => $filters,
            'kpis' => $meta['counts'],
            'header' => $this->header('Avis', '', [['Avis & litiges', '/avis-litiges'], ['Avis']]),
        ]);
    }

    public function show(Request $request, string $rating): Response
    {
        $r = $this->api->get("ratings/{$rating}")->json('data');

        return Inertia::render('Quality/Rating', [
            'rating' => [
                ...$this->row($r),
                'versions' => array_map(fn (array $v): array => [...$v, 'date' => $this->moment($v['date'])], $r['versions']),
                'history' => array_map(fn (array $h): array => [...$h, 'label' => self::ACTION_LABELS[$h['action']] ?? $h['action'], 'date' => $this->moment($h['date'])], $r['history']),
                'actions' => $r['can'],
            ],
            'can' => $this->can($request),
            'header' => $this->header('Fiche de l’avis', '', [['Avis & litiges', '/avis-litiges'], ['Avis', '/avis-litiges/avis'], [$r['target']['name']]]),
        ]);
    }

    public function report(Request $request, string $rating): RedirectResponse
    {
        return $this->act("ratings/{$rating}/report", $request->only('reason'));
    }

    public function keep(Request $request, string $rating): RedirectResponse
    {
        return $this->act("ratings/{$rating}/keep", $request->only('reason'));
    }

    public function hide(Request $request, string $rating): RedirectResponse
    {
        return $this->act("ratings/{$rating}/hide", $request->only('reason'));
    }

    public function restore(Request $request, string $rating): RedirectResponse
    {
        return $this->act("ratings/{$rating}/restore", $request->only('reason'));
    }

    public function suspect(Request $request, string $rating): RedirectResponse
    {
        return $this->act("ratings/{$rating}/suspicion", $request->only('reason', 'suspected'));
    }

    /**
     * Les anciennes fiches (par référence), les pages modération et signalements renvoient vers la liste.
     */
    public function legacy(): RedirectResponse
    {
        Inertia::flash('toast', 'Cette page a été remplacée : voici les avis.');

        return to_route('reviews.index');
    }

    public function moderationQueue(): RedirectResponse
    {
        return redirect('/avis-litiges/avis?moderation=signale');
    }

    /**
     * Un refus métier s’affiche en message ; une erreur de champ reste sous le champ du formulaire.
     *
     * @param  array<string, mixed>  $data
     */
    private function act(string $uri, array $data): RedirectResponse
    {
        try {
            $response = $this->api->post($uri, $data);
        } catch (ValidationException $e) {
            $refusal = array_intersect_key($e->errors(), array_flip(['rating', 'api']));

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
    private function row(array $r): array
    {
        return [
            'id' => $r['id'],
            'scope' => $r['scope']['label'],
            'target' => $r['target'],
            'score' => $r['score'],
            'tag' => $r['satisfaction_tag'],
            'comment' => $r['comment'],
            'site' => $r['site'],
            'promoter' => $r['promoter'],
            'author' => $r['author'],
            'status' => [...$r['status'], 'tone' => self::STATUS_TONES[$r['status']['value']] ?? 'n'],
            'moderation' => $r['moderation'] ? [...$r['moderation'], 'tone' => self::MODERATION_TONES[$r['moderation']['value']] ?? 'n'] : null,
            'suspected' => $r['suspected'],
            'verification' => $r['verification']['label'],
            'publishedAt' => $this->moment($r['published_at']),
            'reportedAt' => $this->moment($r['reported_at']),
            'version' => $r['version'],
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function can(Request $request): array
    {
        $user = $request->user();
        $allowed = fn (string $permission): bool => $user instanceof AdminUser && $user->hasPermission($permission);

        return ['update' => $allowed('ratings.update'), 'decide' => $allowed('ratings.validate')];
    }

    private function moment(?string $date): ?string
    {
        return $date ? Carbon::parse($date)->setTimezone(config('app.timezone'))->format('Y-m-d H:i') : null;
    }
}
