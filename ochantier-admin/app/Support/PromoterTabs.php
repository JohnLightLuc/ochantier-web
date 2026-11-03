<?php

namespace App\Support;

use App\Support\Api\AdminApi;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Onglets en lecture seule de la fiche promoteur (chantiers, offres, paiements, avis et litiges, activité) : seul
 * l’onglet affiché est lu dans l’API (api/v1/admin/promoters/{id}/…), puis mis en forme pour l’écran.
 *
 * Paramètres d’URL : page, statut (chantiers), chantier (offres, paiements), vue=litiges (avis), auteur (activité).
 */
class PromoterTabs
{
    public const LABELS = [
        'chantiers' => 'Chantiers',
        'offres' => 'Offres',
        'paiements' => 'Paiements',
        'avis' => 'Avis & litiges',
        'activite' => 'Activité',
    ];

    private const SITE_TONES = ['brouillon' => 'n', 'soumis' => 'b', 'recherche' => 'b', 'paiement' => 'a', 'confirme' => 'g', 'en_cours' => 'g', 'suspendu' => 'o', 'termine' => 'p', 'annule' => 'r'];

    private const OFFER_TONES = ['brouillon' => 'n', 'soumise' => 'b', 'en_controle' => 'b', 'a_corriger' => 'a', 'publiee' => 'g', 'suspendue' => 'o', 'pourvue' => 'g', 'cloturee' => 'n', 'annulee' => 'r'];

    private const PAYMENT_TONES = ['en_retard' => 'r', 'a_payer' => 'a', 'a_venir' => 'b', 'effectue' => 'g', 'finalise' => 'g'];

    private const DISPUTE_TONES = ['ouvert' => 'a', 'en_instruction' => 'b', 'resolu' => 'g', 'clos' => 'n', 'en_recours' => 'o'];

    private const URGENCY_TONES = ['basse' => 'n', 'normale' => 'b', 'haute' => 'o', 'critique' => 'r'];

    /**
     * Action => [icône, ton] du journal d’activité ; à défaut, le préfixe de l’action.
     */
    private const EVENT_ICONS = [
        'login' => ['login', 'n'],
        'promoter.created' => ['person_add', 'b'],
        'promoter.updated' => ['edit', 'b'],
        'promoter.approved' => ['verified', 'g'],
        'promoter.corrections_requested' => ['edit_note', 'a'],
        'promoter.rejected' => ['block', 'r'],
        'promoter.suspended' => ['pause_circle', 'r'],
        'promoter.reactivated' => ['play_circle', 'g'],
        'promoter.messaged' => ['mail', 'b'],
        'document.uploaded' => ['upload_file', 'b'],
        'document.approved' => ['task_alt', 'g'],
        'document.rejected' => ['cancel', 'r'],
        'payments.store' => ['payments', 'g'],
        'subscription.cancel' => ['credit_card_off', 'o'],
        'sites' => ['construction', 'b'],
        'proposals' => ['handshake', 'g'],
        'replacements' => ['swap_horiz', 'a'],
        'assignments' => ['swap_horiz', 'a'],
        'ratings' => ['star', 'a'],
        'team' => ['group', 'p'],
        'profile' => ['manage_accounts', 'b'],
        'referrals' => ['share', 'p'],
    ];

    public function __construct(private AdminApi $api) {}

    /**
     * Données de l’onglet, prêtes à afficher.
     *
     * @return array<string, mixed>
     */
    public function load(string $promoter, string $tab, Request $request): array
    {
        $page = max(1, (int) $request->query('page', '1'));
        $site = $this->uuid($request->query('chantier'));

        return match ($tab) {
            'chantiers' => $this->sites($promoter, $page, $this->filter($request->query('statut'), array_keys(self::SITE_TONES))),
            'offres' => $this->offers($promoter, $page, $site),
            'paiements' => $this->payments($promoter, $site),
            'avis' => $request->query('vue') === 'litiges' ? $this->disputes($promoter, $page) : $this->ratings($promoter),
            'activite' => $this->activity($promoter, $page, $this->filter($request->query('auteur'), ['admin', 'promoteur'])),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function sites(string $promoter, int $page, ?string $status): array
    {
        $response = $this->api->get("promoters/{$promoter}/sites", array_filter(['page' => $page, 'status' => $status]));
        $counts = $response->json('meta.counts', []);

        return [
            'kind' => 'sites',
            'status' => $status,
            'filters' => collect(['all' => 'Tous', 'brouillon' => 'Brouillons', 'recherche' => 'Recherche', 'paiement' => 'Paiement', 'confirme' => 'Confirmés', 'en_cours' => 'En cours', 'termine' => 'Terminés', 'annule' => 'Annulés'])
                ->map(fn (string $label, string $value) => ['value' => $value === 'all' ? null : $value, 'label' => $label, 'count' => (int) ($counts[$value] ?? 0)])
                ->filter(fn (array $filter) => $filter['value'] === null || $filter['count'] > 0 || $filter['value'] === $status)
                ->values()->all(),
            'rows' => array_map(fn (array $s) => [
                'id' => $s['id'],
                'reference' => $s['reference'],
                'title' => $s['title'] ?: 'Chantier sans titre',
                'location' => implode(' · ', array_filter([$s['neighborhood'], $s['commune']])) ?: ($s['address'] ?: '—'),
                'type' => $s['property_type'] ?? $s['work_type']['label'] ?? '—',
                'period' => $this->period($s['start_date'], $s['end_date']),
                'schedule' => $s['schedule']['label'] ?? null,
                'responsible' => $s['responsible'],
                'offers' => $s['offers_count'],
                'missions' => $s['missions_count'],
                'status' => $this->badge($s['status'], self::SITE_TONES),
            ], $response->json('data', [])),
            'pagination' => $this->pagination($response->json('meta'), count($response->json('data', []))),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function offers(string $promoter, int $page, ?string $site): array
    {
        $response = $this->api->get("promoters/{$promoter}/offers", array_filter(['page' => $page, 'site_id' => $site]));
        $rows = array_map(fn (array $o) => [
            'id' => $o['id'],
            'reference' => $o['reference'],
            'title' => $o['title'] ?: ($o['type']['label'] ?? 'Offre').' · '.($o['site']['title'] ?? ''),
            'site' => $o['site'],
            'needs' => implode(', ', array_map(fn (array $n) => $n['headcount'].' × '.$n['trade'], $o['needs'])) ?: '—',
            'workers' => $o['workers_count'],
            'type' => $o['type']['label'] ?? '—',
            'proposals' => $o['proposals_count'],
            'amount' => $this->money($o['amount']),
            'period' => $this->period($o['start_date'], $o['end_date']),
            'status' => $this->badge($o['status'], self::OFFER_TONES),
        ], $response->json('data', []));

        return [
            'kind' => 'offers',
            'site' => $site ? ['id' => $site, 'title' => $rows[0]['site']['title'] ?? 'chantier sélectionné'] : null,
            'rows' => $rows,
            'pagination' => $this->pagination($response->json('meta'), count($rows)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payments(string $promoter, ?string $site): array
    {
        $response = $this->api->get("promoters/{$promoter}/payments", array_filter(['site_id' => $site]));
        $rows = array_map(fn (array $p) => [
            'key' => $p['key'],
            'title' => $p['short'] ?? $p['title'],
            'kind' => $p['kind']['label'] ?? '—',
            'site' => $p['site'] ?? null,
            'who' => $p['who'] ?? null,
            'amount' => $this->money($p['amount']),
            'when' => $p['when'] ?? null,
            'paidAt' => PromoterPresenter::date($p['paid_at'] ?? null),
            'method' => $p['method'] ?? null,
            'reference' => $p['transaction_reference'] ?? null,
            'receipt' => $p['receipt']['url'] ?? null,
            'status' => $this->badge($p['status'], self::PAYMENT_TONES),
        ], $response->json('data', []));
        $totals = $response->json('meta.totals', []);

        return [
            'kind' => 'payments',
            'site' => $site ? ['id' => $site, 'title' => $rows[0]['site']['title'] ?? 'chantier sélectionné'] : null,
            'totals' => [
                ['label' => 'À régler', 'value' => $this->money($totals['a_regler'] ?? 0), 'tone' => ($totals['a_regler'] ?? 0) > 0 ? 'a' : 'g', 'hint' => ($totals['en_retard'] ?? 0) > 0 ? $this->money($totals['en_retard']).' en retard' : 'Aucun retard'],
                ['label' => 'À venir', 'value' => $this->money($totals['a_venir'] ?? 0), 'tone' => 'b', 'hint' => 'Échéances planifiées'],
                ['label' => 'Payés', 'value' => $this->money($totals['payes'] ?? 0), 'tone' => 'g', 'hint' => 'Acomptes, échéances et versements'],
            ],
            'rows' => $rows,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ratings(string $promoter): array
    {
        $response = $this->api->get("promoters/{$promoter}/ratings");
        $meta = $response->json('meta', []);

        return [
            'kind' => 'ratings',
            'average' => $meta['average'] !== null ? str_replace('.', ',', number_format((float) $meta['average'], 1)) : null,
            'count' => (int) ($meta['count'] ?? 0),
            'hidden' => (int) ($meta['hidden_count'] ?? 0),
            'distribution' => $meta['distribution'] ?? [],
            'rows' => array_map(fn (array $r) => [
                'id' => $r['id'],
                'name' => $r['name'],
                'subtitle' => $r['subtitle'] ?? null,
                'team' => ($r['kind'] ?? null) === 'team',
                'score' => $r['score'],
                'comment' => $r['summary'] ?: '—',
                'date' => PromoterPresenter::date($r['published_at'] ?? null) ?? '—',
                'author' => $r['author'],
                'status' => ['label' => $r['status']['value'] === 'publie' ? 'Publié' : 'Masqué', 'tone' => $r['status']['value'] === 'publie' ? 'g' : 'n'],
                'moderation' => $r['moderation']['reason'] ?? null,
            ], $response->json('data', [])),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function disputes(string $promoter, int $page): array
    {
        $response = $this->api->get("promoters/{$promoter}/disputes", ['page' => $page]);
        $rows = array_map(fn (array $d) => [
            'id' => $d['id'],
            'reference' => $d['reference'],
            'category' => ucfirst(str_replace('_', ' ', (string) $d['category'])),
            'description' => $d['description'],
            'site' => $d['site']['title'] ?? '—',
            'openedBy' => match ($d['opened_by']['kind'] ?? null) {
                'promoter' => 'Le promoteur',
                'admin' => 'Admin · '.$d['opened_by']['name'],
                'user' => $d['opened_by']['name'] ?? 'Un utilisateur',
                default => '—',
            },
            'urgency' => $d['urgency'] ? $this->badge($d['urgency'], self::URGENCY_TONES) : null,
            'owner' => $d['assigned_admin']['full_name'] ?? 'Non assigné',
            'date' => PromoterPresenter::date($d['created_at']) ?? '—',
            'status' => $this->badge($d['status'], self::DISPUTE_TONES),
        ], $response->json('data', []));

        return ['kind' => 'disputes', 'rows' => $rows, 'pagination' => $this->pagination($response->json('meta'), count($rows))];
    }

    /**
     * @return array<string, mixed>
     */
    private function activity(string $promoter, int $page, ?string $author): array
    {
        $response = $this->api->get("promoters/{$promoter}/activity", array_filter(['page' => $page, 'author' => ['admin' => 'admin', 'promoteur' => 'promoter'][$author] ?? null]));
        $today = Carbon::today(config('app.timezone'));

        $rows = array_map(function (array $e) use ($today) {
            $at = Carbon::parse($e['at'])->timezone(config('app.timezone'));
            [$icon, $tone] = self::EVENT_ICONS[$e['action']] ?? self::EVENT_ICONS[str($e['action'])->before('.')->toString()] ?? ['history', 'n'];

            return [
                'id' => $e['id'],
                'day' => match (true) {
                    $at->isSameDay($today) => 'Aujourd’hui',
                    $at->isSameDay($today->copy()->subDay()) => 'Hier',
                    default => $at->translatedFormat('l j F Y'),
                },
                'time' => $at->format('H:i'),
                'icon' => $icon,
                'tone' => $tone,
                'author' => $e['author'],
                'role' => $e['role'],
                'action' => $e['what'],
                'kind' => ['value' => $e['kind']['value'] === 'admin' ? 'admin' : 'promoteur', 'label' => $e['kind']['label'], 'tone' => $e['kind']['value'] === 'admin' ? 'b' : 'g'],
                'target' => implode(' · ', array_filter([$e['site'], $e['detail']])) ?: null,
            ];
        }, $response->json('data', []));

        return ['kind' => 'activity', 'author' => $author, 'rows' => $rows, 'pagination' => $this->pagination($response->json('meta'), count($rows))];
    }

    /**
     * @param  array{value: string, label: string}  $status
     * @param  array<string, string>  $tones
     * @return array{value: string, label: string, tone: string}
     */
    private function badge(array $status, array $tones): array
    {
        return [...$status, 'tone' => $tones[$status['value']] ?? 'n'];
    }

    /**
     * @param  array<string, mixed>|null  $meta
     * @return array{currentPage: int, lastPage: int, from: int, to: int, total: int}
     */
    private function pagination(?array $meta, int $count): array
    {
        $page = (int) ($meta['current_page'] ?? 1);
        $from = $count ? ($page - 1) * (int) ($meta['per_page'] ?? 20) + 1 : 0;

        return ['currentPage' => $page, 'lastPage' => (int) ($meta['last_page'] ?? 1), 'from' => $from, 'to' => $from ? $from + $count - 1 : 0, 'total' => (int) ($meta['total'] ?? 0)];
    }

    private function period(?string $start, ?string $end): string
    {
        return match (true) {
            $start && $end => PromoterPresenter::date($start).' → '.PromoterPresenter::date($end),
            (bool) $start => 'Dès le '.PromoterPresenter::date($start),
            default => '—',
        };
    }

    private function money(mixed $amount): string
    {
        return $amount === null ? '—' : number_format((float) $amount, 0, ',', ' ').' FCFA';
    }

    /**
     * @param  list<string>  $allowed
     */
    private function filter(mixed $value, array $allowed): ?string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : null;
    }

    private function uuid(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) ? $value : null;
    }
}
