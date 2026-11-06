<?php

namespace App\Support;

use App\Support\Api\AdminApi;
use App\Support\Api\AdminUser;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Onglets en lecture seule de la fiche ouvrier (disponibilités, affectations, pointages, gains, avis, remplacements,
 * historique) : seul l’onglet affiché est lu dans l’API (api/v1/admin/workers/{id}/…), puis mis en forme.
 *
 * Paramètres d’URL : page, statut (affectations, pointages), origine (historique).
 */
class WorkerTabs
{
    public const LABELS = [
        'disponibilites' => 'Disponibilités',
        'affectations' => 'Affectations et missions',
        'pointages' => 'Pointages',
        'paiements' => 'Paiements et gains',
        'avis' => 'Avis et notes',
        'remplacements' => 'Indisponibilités et remplacements',
        'historique' => 'Historique',
    ];

    private const ASSIGNMENT_TONES = ['confirmee' => 'b', 'active' => 'g', 'terminee' => 'n', 'remplacee' => 'p', 'annulee' => 'r'];

    private const DAY_TONES = ['en_cours' => 'b', 'a_valider' => 'a', 'valide' => 'g', 'rejete' => 'r', 'absent' => 'r', 'annule' => 'n'];

    private const UNAVAILABILITY_TONES = ['declaree' => 'a', 'en_analyse' => 'b', 'remplacement_recherche' => 'b', 'remplacant_propose' => 'p', 'validee' => 'g', 'refusee' => 'r', 'cloturee' => 'n', 'annulee' => 'n'];

    private const REPLACEMENT_TONES = ['signale' => 'a', 'recherche' => 'b', 'propose' => 'p', 'valide' => 'g', 'prise_de_poste' => 'g', 'termine' => 'n', 'annule' => 'n'];

    private const PAYOUT_TONES = ['a_calculer' => 'n', 'provisionne' => 'b', 'autorise' => 'b', 'envoye' => 'a', 'confirme' => 'g', 'rejete' => 'r', 'annule' => 'n'];

    private const AVAILABILITY_TONES = ['disponible' => 'g', 'occupe' => 'a', 'indisponible' => 'r'];

    private const ORIGINS = ['admin' => ['Back Office', 'b', 'desktop_windows'], 'commercial' => ['Commercial', 'g', 'badge'], 'systeme' => ['Système', 'n', 'memory'], 'mission' => ['Mission', 'p', 'construction']];

    public function __construct(private AdminApi $api) {}

    /**
     * @return array<string, mixed>
     */
    public function load(string $worker, string $tab, Request $request): array
    {
        $page = max(1, (int) $request->query('page', '1'));
        $status = is_string($request->query('statut')) ? $request->query('statut') : null;

        return match ($tab) {
            'disponibilites' => $this->availability($worker),
            'affectations' => $this->assignments($worker, $page, array_key_exists((string) $status, self::ASSIGNMENT_TONES) ? $status : null),
            'pointages' => $this->attendance($worker, $page, array_key_exists((string) $status, self::DAY_TONES) ? $status : null),
            'paiements' => $this->earnings($worker),
            'avis' => $this->ratings($worker),
            'remplacements' => $this->replacements($worker),
            'historique' => $this->history($worker, $page, array_key_exists((string) $request->query('origine'), self::ORIGINS) ? (string) $request->query('origine') : null),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function availability(string $worker): array
    {
        $d = $this->api->get("workers/{$worker}/availability")->json('data');

        return [
            'kind' => 'availability',
            'status' => $d['status'] ? $this->badge($d['status'], self::AVAILABILITY_TONES) : null,
            'availableFrom' => PromoterPresenter::date($d['available_from']),
            'current' => $d['current'] ? $this->assignmentRow($d['current']) : null,
            'upcoming' => array_map($this->assignmentRow(...), $d['upcoming']),
            'openUnavailabilities' => $d['open_unavailabilities'],
            'declarations' => array_map(fn (array $a) => [
                'id' => $a['id'],
                'status' => $this->badge($a['status'], self::AVAILABILITY_TONES),
                'period' => $this->period($a['from'], $a['to']),
                'source' => $a['source']['label'] ?? '—',
                'comment' => $a['comment'],
            ], $d['declarations']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function assignments(string $worker, int $page, ?string $status): array
    {
        $response = $this->api->get("workers/{$worker}/assignments", array_filter(['page' => $page, 'status' => $status]));
        $counts = $response->json('meta.counts', []);
        $labels = ['all' => 'Toutes', 'confirmee' => 'Confirmées', 'active' => 'En cours', 'terminee' => 'Terminées', 'remplacee' => 'Remplacées', 'annulee' => 'Annulées'];

        return [
            'kind' => 'assignments',
            'status' => $status,
            'filters' => $this->filters($labels, $counts, $status),
            'rows' => array_map($this->assignmentRow(...), $response->json('data', [])),
            'pagination' => $this->pagination($response->json('meta'), count($response->json('data', []))),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function attendance(string $worker, int $page, ?string $status): array
    {
        $response = $this->api->get("workers/{$worker}/attendance", array_filter(['page' => $page, 'status' => $status]));
        $meta = $response->json('meta', []);
        $labels = ['all' => 'Toutes', 'valide' => 'Validées', 'a_valider' => 'À valider', 'en_cours' => 'En cours', 'absent' => 'Absences', 'rejete' => 'Rejetées'];
        $hours = intdiv((int) ($meta['worked_minutes'] ?? 0), 60);

        return [
            'kind' => 'attendance',
            'status' => $status,
            'filters' => $this->filters($labels, $meta['counts'] ?? [], $status),
            'summary' => [
                ['label' => 'Journées validées', 'value' => (string) ($meta['counts']['valide'] ?? 0), 'tone' => 'g', 'hint' => $hours.' h travaillées'],
                ['label' => 'Absences', 'value' => (string) ($meta['counts']['absent'] ?? 0), 'tone' => ($meta['counts']['absent'] ?? 0) > 0 ? 'r' : 'g', 'hint' => 'Journées marquées absent'],
                ['label' => 'Taux de présence', 'value' => $meta['presence_rate'] !== null ? str_replace('.', ',', (string) $meta['presence_rate']).' %' : '—', 'tone' => 'b', 'hint' => 'Validées sur validées et absences'],
            ],
            'rows' => array_map(fn (array $d) => [
                'id' => $d['id'],
                'date' => PromoterPresenter::date($d['date']) ?? '—',
                'site' => $d['site']['title'] ?? '—',
                'arrival' => $d['arrival'] ?? '—',
                'departure' => $d['departure'] ?? '—',
                'outOfZone' => $d['arrival_out_of_zone'],
                'duration' => $d['worked_minutes'] ? sprintf('%d h %02d', intdiv($d['worked_minutes'], 60), $d['worked_minutes'] % 60) : '—',
                'validation' => $d['validated_at'] ? 'Le '.PromoterPresenter::date($d['validated_at']).($d['validated_by'] ? ' par '.$d['validated_by'] : '') : '—',
                'observation' => $d['observation'],
                'status' => $this->badge($d['status'], self::DAY_TONES),
            ], $response->json('data', [])),
            'pagination' => $this->pagination($meta, count($response->json('data', []))),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function paymentMethod(string $worker): ?array
    {
        $user = auth()->user();

        if (! $user instanceof AdminUser || ! $user->hasPermission('payouts.read')) {
            return null;
        }

        return [
            ...$this->api->get("workers/{$worker}/payment-method")->json('data'),
            'can' => ['create' => $user->hasPermission('payouts.create'), 'approve' => $user->hasPermission('payouts.approve')],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function earnings(string $worker): array
    {
        $response = $this->api->get("workers/{$worker}/earnings");
        $meta = $response->json('meta', []);

        return [
            'kind' => 'earnings',
            'paymentMethod' => $this->paymentMethod($worker),
            'totals' => [
                ['label' => 'Gains acquis', 'value' => $this->money($meta['totals']['earned'] ?? 0), 'tone' => 'g', 'hint' => 'Journées validées × tarif net'],
                ['label' => 'Déjà versés', 'value' => $this->money($meta['totals']['paid'] ?? 0), 'tone' => 'b', 'hint' => 'Reversements envoyés ou confirmés'],
                ['label' => 'Restant à verser', 'value' => $this->money($meta['totals']['remaining'] ?? 0), 'tone' => ($meta['totals']['remaining'] ?? 0) > 0 ? 'a' : 'g', 'hint' => 'Selon les journées validées'],
            ],
            'rows' => array_map(fn (array $r) => [
                'id' => $r['id'],
                'site' => $r['site']['title'] ?? '—',
                'mission' => $r['mission']['reference'] ?? '—',
                'period' => $this->period($r['start_date'], $r['end_date']),
                'days' => $r['validated_days'],
                'rate' => $this->money($r['worker_net_daily_rate']),
                'earned' => $this->money($r['earned']),
                'paid' => $this->money($r['paid']),
                'remaining' => $this->money($r['remaining']),
                'status' => $this->badge($r['status'], self::ASSIGNMENT_TONES),
            ], $response->json('data', [])),
            'payouts' => array_map(fn (array $p) => [
                'id' => $p['id'],
                'reference' => $p['reference'],
                'site' => $p['site']['title'] ?? '—',
                'period' => $this->period($p['period_start'], $p['period_end']),
                'gross' => $this->money($p['gross_amount']),
                'commission' => $this->money($p['commission_amount']),
                'net' => $this->money($p['net_amount']),
                'status' => $this->badge($p['status'], self::PAYOUT_TONES),
            ], $meta['payouts'] ?? []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ratings(string $worker): array
    {
        $response = $this->api->get("workers/{$worker}/ratings");
        $meta = $response->json('meta', []);

        return [
            'kind' => 'ratings',
            'average' => $meta['average'] !== null ? str_replace('.', ',', number_format((float) $meta['average'], 1)) : null,
            'count' => (int) ($meta['count'] ?? 0),
            'hidden' => (int) ($meta['hidden_count'] ?? 0),
            'distribution' => $meta['distribution'] ?? [],
            'rows' => array_map(fn (array $r) => [
                'id' => $r['id'],
                'score' => $r['score'],
                'comment' => trim(implode(' — ', array_filter([$r['satisfaction_tag'], $r['comment']]))) ?: '—',
                'site' => $r['site']['title'] ?? '—',
                'author' => $r['author'] ?? '—',
                'date' => PromoterPresenter::date($r['published_at']) ?? '—',
                'status' => ['label' => $r['status']['value'] === 'publie' ? 'Publié' : 'Masqué', 'tone' => $r['status']['value'] === 'publie' ? 'g' : 'n'],
                'moderation' => $r['moderation']['reason'] ?? null,
            ], $response->json('data', [])),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function replacements(string $worker): array
    {
        $d = $this->api->get("workers/{$worker}/replacements")->json('data');

        return [
            'kind' => 'replacements',
            'unavailabilities' => array_map(fn (array $u) => [
                'id' => $u['id'],
                'site' => $u['site']['title'] ?? '—',
                'moment' => $u['phase']['label'] ?? '—',
                'date' => PromoterPresenter::date($u['effective_at']) ?? '—',
                'duration' => $u['estimated_duration_days'] ? $u['estimated_duration_days'].' jour(s)' : '—',
                'reason' => $u['reason'] ?? '—',
                'urgent' => $u['is_urgent'],
                'replacement' => $u['replacement'] ? trim(($u['replacement']['worker'] ?? 'Remplaçant à trouver').' · '.$u['replacement']['status']['label']) : 'Aucun remplacement',
                'status' => $this->badge($u['status'], self::UNAVAILABILITY_TONES),
            ], $d['unavailabilities']),
            'replacedOthers' => array_map(fn (array $r) => [
                'id' => $r['id'],
                'site' => $r['site']['title'] ?? '—',
                'replaced' => $r['replaced_worker'] ?? '—',
                'handover' => PromoterPresenter::date($r['handover_at']) ?? '—',
                'status' => $this->badge($r['status'], self::REPLACEMENT_TONES),
            ], $d['replaced_others']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function history(string $worker, int $page, ?string $origin): array
    {
        $response = $this->api->get("workers/{$worker}/history", array_filter(['page' => $page, 'origin' => $origin]));
        $today = Carbon::today(config('app.timezone'));
        $statuses = WorkerPresenter::STATUS_LABELS;

        $rows = array_map(function (array $e) use ($today, $statuses) {
            $at = Carbon::parse($e['at'])->timezone(config('app.timezone'));
            [$label, $tone, $icon] = self::ORIGINS[$e['origin']['value']] ?? ['—', 'n', 'history'];

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
                'role' => null,
                'action' => $e['what'],
                'kind' => ['value' => $e['origin']['value'], 'label' => $label, 'tone' => $tone],
                'target' => implode(' · ', array_filter([
                    $e['from'] || $e['to'] ? ($statuses[$e['from']] ?? $e['from'] ?? '—').' → '.($statuses[$e['to']] ?? $e['to'] ?? '—') : null,
                    $e['detail'],
                ])) ?: null,
            ];
        }, $response->json('data', []));

        return [
            'kind' => 'history',
            'origin' => $origin,
            'origins' => array_map(fn (string $value, array $o) => ['value' => $value, 'label' => $o[0]], array_keys(self::ORIGINS), self::ORIGINS),
            'rows' => $rows,
            'pagination' => $this->pagination($response->json('meta'), count($rows)),
        ];
    }

    /**
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>
     */
    private function assignmentRow(array $a): array
    {
        return [
            'id' => $a['id'],
            'site' => $a['site']['title'] ?? '—',
            'promoter' => $a['promoter'] ?? '—',
            'mission' => $a['mission']['reference'] ?? '—',
            'trade' => trim(($a['trade'] ?? '—').($a['is_team_lead'] ? ' · chef d’équipe' : '').($a['replaces'] ? ' · remplacement' : '')),
            'period' => $this->period($a['start_date'], $a['end_date']),
            'days' => $a['validated_days'].' / '.$a['worked_days'],
            'rate' => $this->money($a['worker_net_daily_rate']),
            'promoterRate' => $this->money($a['promoter_daily_rate']),
            'status' => $this->badge($a['status'], self::ASSIGNMENT_TONES),
        ];
    }

    /**
     * @param  array<string, string>  $labels
     * @param  array<string, int>  $counts
     * @return list<array{value: string|null, label: string, count: int}>
     */
    private function filters(array $labels, array $counts, ?string $status): array
    {
        return collect($labels)
            ->map(fn (string $label, string $value) => ['value' => $value === 'all' ? null : $value, 'label' => $label, 'count' => (int) ($counts[$value] ?? 0)])
            ->filter(fn (array $f) => $f['value'] === null || $f['count'] > 0 || $f['value'] === $status)
            ->values()->all();
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
}
