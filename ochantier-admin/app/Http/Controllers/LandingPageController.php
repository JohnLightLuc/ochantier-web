<?php

namespace App\Http\Controllers;

use App\Support\Api\AdminApi;
use App\Support\Api\AdminUser;
use App\Support\LandingPresenter;
use Closure;
use Illuminate\Http\Client\Response as ApiResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Landing Page : demandes reçues par le site public O’CHANTIER, lues dans l’API (api/v1/admin/landing). Onglets
 * vue d’ensemble, candidatures « Devenir ouvrier », messages de contact et newsletter, chacun selon les droits.
 */
class LandingPageController extends Controller
{
    private const PER_PAGE = 20;

    /**
     * Onglet (clé d’URL) => [libellé, icône, droit requis].
     */
    private const TABS = [
        '' => ['Vue d’ensemble', 'dashboard', null],
        'candidatures' => ['Candidatures ouvriers', 'engineering', 'workers.read'],
        'messages' => ['Messages de contact', 'mail', 'tickets.read'],
        'newsletter' => ['Newsletter', 'campaign', 'content.read'],
    ];

    public function __construct(private AdminApi $api) {}

    public function index(Request $request, ?string $tab = null): Response
    {
        $tab ??= '';
        abort_unless(array_key_exists($tab, self::TABS), 404);
        abort_if(self::TABS[$tab][2] && ! $this->allowed($request, self::TABS[$tab][2]), 403);
        $filters = array_filter($request->only(['statut', 'q', 'metier', 'retard', 'page']), fn ($v) => is_string($v) && $v !== '');
        $overview = $this->api->get('landing/overview')->json('data');

        return Inertia::render('Landing/Index', [
            'tab' => $tab,
            'tabs' => array_values(array_filter(array_map(fn (string $key, array $t) => ! $t[2] || $this->allowed($request, $t[2]) ? ['key' => $key, 'label' => $t[0], 'icon' => $t[1], 'count' => $this->tabCount($key, $overview)] : null, array_keys(self::TABS), self::TABS))),
            'overview' => $overview,
            'filters' => $filters,
            'list' => match ($tab) {
                'candidatures' => $this->list('landing/applications', ['status' => $filters['statut'] ?? null, 'search' => $filters['q'] ?? null, 'trade' => $filters['metier'] ?? null, 'page' => $filters['page'] ?? null], LandingPresenter::applicationRow(...)),
                'messages' => $this->list('landing/messages', ['status' => $filters['statut'] ?? null, 'search' => $filters['q'] ?? null, 'late' => ($filters['retard'] ?? null) ? 1 : null, 'page' => $filters['page'] ?? null], LandingPresenter::messageRow(...)),
                'newsletter' => $this->list('landing/newsletter', ['search' => $filters['q'] ?? null, 'page' => $filters['page'] ?? null], fn (array $s) => ['id' => $s['id'], 'email' => $s['email'], 'subscribedAt' => LandingPresenter::moment($s['subscribed_at'])]),
                default => null,
            },
        ]);
    }

    public function application(Request $request, string $application): Response
    {
        $view = LandingPresenter::application($this->api->get("landing/applications/{$application}")->json('data'));

        return Inertia::render('Landing/Application', [
            'application' => $view,
            'can' => ['validate' => $this->allowed($request, 'workers.validate'), 'create' => $this->allowed($request, 'workers.create')],
            'header' => $this->header('Candidature ouvrier', 'Reçue depuis le formulaire « Devenir ouvrier » du site', [['Landing Page', '/landing-page'], ['Candidatures', '/landing-page/candidatures'], [$view['reference']]]),
        ]);
    }

    public function review(string $application): RedirectResponse
    {
        return $this->send(fn () => $this->api->post("landing/applications/{$application}/review"));
    }

    public function refuse(Request $request, string $application): RedirectResponse
    {
        $reason = trim((string) $request->input('reason'));
        $category = trim((string) $request->input('category'));

        return $this->send(fn () => $this->api->post("landing/applications/{$application}/refuse", ['reason' => $category && $reason && $category !== 'Autre' ? "{$category} · {$reason}" : $reason]));
    }

    public function message(Request $request, string $message): Response
    {
        $view = LandingPresenter::message($this->api->get("landing/messages/{$message}")->json('data'));

        return Inertia::render('Landing/Message', [
            'message' => $view,
            'can' => ['update' => $this->allowed($request, 'tickets.update')],
            'header' => $this->header('Message de contact', 'Reçu depuis le formulaire « Nous contacter » du site', [['Landing Page', '/landing-page'], ['Messages', '/landing-page/messages'], [$view['reference']]]),
        ]);
    }

    public function handle(Request $request, string $message): RedirectResponse
    {
        return $this->send(fn () => $this->api->post("landing/messages/{$message}/status", array_filter(['status' => $request->input('status'), 'note' => trim((string) $request->input('note')) ?: null])));
    }

    public function exportNewsletter(): HttpResponse
    {
        $response = $this->api->get('landing/newsletter/export');

        return response($response->body(), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => $response->header('Content-Disposition') ?: 'attachment; filename="newsletter.csv"']);
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  callable(array<string, mixed>): array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function list(string $uri, array $query, callable $row): array
    {
        $response = $this->api->get($uri, [...array_filter($query, fn ($v) => $v !== null), 'per_page' => self::PER_PAGE]);
        $meta = $response->json('meta');
        $rows = array_map($row, $response->json('data'));
        $from = $meta['total'] ? ($meta['current_page'] - 1) * $meta['per_page'] + 1 : 0;

        return [
            'data' => $rows,
            'currentPage' => $meta['current_page'],
            'lastPage' => $meta['last_page'],
            'total' => $meta['total'],
            'from' => $from,
            'to' => $from ? $from + count($rows) - 1 : 0,
            'counts' => $meta['counts'] ?? null,
            'trades' => $meta['trades'] ?? null,
            'responseHours' => $meta['response_hours'] ?? null,
        ];
    }

    /**
     * Demandes à traiter d’un onglet (nouvelles candidatures, messages non traités).
     *
     * @param  array<string, mixed>  $overview
     */
    private function tabCount(string $tab, array $overview): ?int
    {
        return match ($tab) {
            'candidatures' => isset($overview['applications']) ? $overview['applications']['nouvelle'] + $overview['applications']['en_verification'] : null,
            'messages' => isset($overview['messages']) ? $overview['messages']['nouveau'] + $overview['messages']['en_cours'] : null,
            default => null,
        };
    }

    /**
     * Appelle l’API puis revient sur la page avec son message ; un refus métier devient une notification d’erreur.
     *
     * @param  Closure(): ApiResponse  $call
     */
    private function send(Closure $call): RedirectResponse
    {
        try {
            $response = $call();
        } catch (ValidationException $e) {
            $refusal = array_intersect_key($e->errors(), array_flip(['application', 'message', 'status', 'api']));

            if ($refusal === []) {
                throw $e;
            }

            Inertia::flash('toastError', collect($refusal)->flatten()->first());

            return back();
        }

        if ($message = $response->json('message')) {
            Inertia::flash('toast', $message);
        }

        return back();
    }

    private function allowed(Request $request, string $permission): bool
    {
        $user = $request->user();

        return $user instanceof AdminUser && $user->hasPermission($permission);
    }
}
