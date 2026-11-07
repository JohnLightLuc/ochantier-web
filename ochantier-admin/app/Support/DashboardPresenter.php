<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Met en forme le tableau de bord de l’API pour ses écrans, et les files d’attente pour l’en-tête.
 */
class DashboardPresenter
{
    private const KPIS = [
        'sites' => ['Chantiers actifs', 'construction', 'g', '/chantiers'],
        'workers' => ['Ouvriers disponibles', 'groups', 'g', '/ouvriers'],
        'replacements' => ['Remplacements à traiter', 'swap_horiz', 'a', '/affectations'],
        'receivables' => ['Échéances dues', 'account_balance_wallet', 'b', '/paiements/echeances'],
    ];

    private const QUEUES = [
        'replacements' => ['remplacements à traiter', 'Absences signalées sur les chantiers', 'swap_horiz', '/affectations', 'orange'],
        'claims' => ['réclamations en retard', 'Réponse attendue dépassée', 'support_agent', '/avis-litiges/reclamations?retard=1', 'danger'],
        'batches' => ['lots de reversement à valider', 'En attente d’une seconde personne', 'payments', '/paiements/lots', 'warning'],
        'refunds' => ['remboursements à traiter', 'À valider ou à renvoyer', 'undo', '/paiements/remboursements', 'warning'],
        'workers' => ['dossiers ouvriers à valider', 'Pièces à vérifier', 'person_check', '/ouvriers', 'warning'],
        'promoters' => ['dossiers promoteurs à valider', 'Pièces à vérifier', 'domain_verification', '/promoteurs', 'warning'],
        'anomalies' => ['anomalies de pointage', 'À traiter', 'schedule', '/pointages/anomalies', 'info'],
        'disputes' => ['litiges en cours', 'Ouverts, en instruction ou en recours', 'gavel', '/avis-litiges/litiges', 'danger'],
        'ratings' => ['avis à modérer', 'Signalés par l’équipe', 'flag', '/avis-litiges/avis?moderation=signale', 'info'],
    ];

    private const FEED = [
        'site' => ['construction', 'success', 'Chantier soumis', 'Promoteur', '/chantiers'],
        'worker' => ['verified', 'success', 'Ouvrier validé', 'Back Office', '/ouvriers'],
        'payment' => ['payments', 'info', 'Paiement reçu', 'Promoteur', '/paiements'],
        'payment_failed' => ['receipt_long', 'warning', 'Paiement échoué', 'Opérateur', '/paiements'],
        'dispute' => ['gavel', 'danger', 'Litige ouvert', 'Back Office', '/avis-litiges/litiges'],
        'claim' => ['support_agent', 'warning', 'Réclamation reçue', 'Demandeur', '/avis-litiges/reclamations'],
    ];

    private const VISIBLE_PRIORITIES = 4;

    /**
     * @param  array{key: string, value: int}  $kpi
     * @return array<string, mixed>
     */
    public function kpi(array $kpi): array
    {
        [$label, $icon, $tone, $href] = self::KPIS[$kpi['key']];
        $money = $kpi['key'] === 'receivables';

        return ['label' => $label, 'value' => number_format($kpi['value'], 0, ',', ' '), 'unit' => $money ? 'FCFA' : '', 'icon' => $icon, 'tone' => $tone, 'highlight' => false, 'href' => $href];
    }

    /**
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>
     */
    public function activity(array $a): array
    {
        $dates = array_map(fn (array $p): Carbon => Carbon::parse($p['date'])->locale('fr'), $a['points']);

        return [
            'range' => $a['range'],
            'from' => $a['from'],
            'to' => $a['to'],
            'caption' => 'Du '.Carbon::parse($a['from'])->locale('fr')->translatedFormat('j F Y').' au '.Carbon::parse($a['to'])->locale('fr')->translatedFormat('j F Y'),
            'labels' => array_map(fn (Carbon $d): string => $d->translatedFormat('j M'), $dates),
            'dates' => array_map(fn (Carbon $d): string => ucfirst($d->translatedFormat('l j M Y')), $dates),
            'active' => array_column($a['points'], 'active'),
            'created' => array_column($a['points'], 'created'),
        ];
    }

    /**
     * @param  list<array{key: string, count: int, priority: string}>  $rows
     * @return array<string, mixed>
     */
    public function priorities(array $rows): array
    {
        $items = array_map(function (array $row): array {
            [$title, $text, $icon, $href] = self::QUEUES[$row['key']];

            return ['count' => $row['count'], 'title' => $title, 'text' => $text, 'priority' => $row['priority'], 'icon' => $icon, 'href' => $href];
        }, $rows);
        $visible = array_slice($items, 0, self::VISIBLE_PRIORITIES);

        return ['total' => array_sum(array_column($visible, 'count')), 'totalAll' => array_sum(array_column($items, 'count')), 'items' => $visible, 'more' => array_slice($items, self::VISIBLE_PRIORITIES)];
    }

    /**
     * Files d’attente affichées dans la cloche de l’en-tête.
     *
     * @param  list<array{key: string, count: int, priority: string}>  $rows
     * @return list<array<string, mixed>>
     */
    public function notifications(array $rows): array
    {
        return array_map(function (array $row): array {
            [$title, $text, $icon, $href, $tone] = self::QUEUES[$row['key']];

            return ['id' => $row['key'], 'icon' => $icon, 'tone' => $tone, 'title' => $row['count'].' '.$title, 'text' => $text, 'priority' => $row['priority'], 'when' => 'À traiter', 'read' => false, 'href' => $href];
        }, $rows);
    }

    /**
     * @param  array<string, mixed>  $s
     * @return array<string, mixed>
     */
    public function site(array $s): array
    {
        return [
            'id' => $s['id'],
            'name' => $s['name'],
            'promoter' => $s['promoter']['name'],
            'promoterHref' => "/promoteurs/{$s['promoter']['id']}",
            'location' => $s['location'] ?? '—',
            'progress' => $s['elapsed'],
            'workers' => "{$s['workers']['assigned']} / {$s['workers']['required']}",
            'status' => $s['late'] ? 'En retard' : $s['status']['label'],
            'tone' => $s['late'] ? 'r' : ($s['status']['value'] === 'suspendu' ? 'o' : 'g'),
            'period' => $s['start'] && $s['end'] ? Carbon::parse($s['start'])->format('d/m/Y').' au '.Carbon::parse($s['end'])->format('d/m/Y') : '—',
            'type' => $s['type'] ?? '—',
            'href' => "/chantiers/{$s['id']}",
        ];
    }

    /**
     * @param  array{running: int, late: int, suspended: int}  $d
     * @return array<string, mixed>
     */
    public function distribution(array $d): array
    {
        return [
            'total' => $d['running'] + $d['late'] + $d['suspended'],
            'segments' => [
                ['label' => 'En cours', 'count' => $d['running'], 'color' => 'var(--c-primary-500)', 'href' => '/chantiers?statut=en_cours'],
                ['label' => 'En retard', 'count' => $d['late'], 'color' => 'var(--c-danger)', 'href' => '/chantiers?rapide=retard'],
                ['label' => 'Suspendus', 'count' => $d['suspended'], 'color' => 'var(--c-info)', 'href' => '/chantiers?statut=suspendu'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $m
     * @return array<string, mixed>
     */
    public function matching(array $m): array
    {
        return [
            'relevance' => $m['relevance'],
            'pending' => $m['pending'],
            'profiles' => array_map(fn (array $p): array => [
                'name' => $p['name'],
                'site' => $p['site'] ?? '—',
                'score' => $p['score'],
                'job' => $p['trade'] ?? '—',
                'experience' => $p['experience_years'] !== null ? "{$p['experience_years']} ans" : '—',
                'rate' => $p['daily_rate'] ? number_format($p['daily_rate'], 0, ',', ' ').' FCFA/jour' : '—',
                'availability' => $p['availability'] ?? '—',
                'rating' => $p['rating'] !== null ? str_replace('.', ',', (string) round($p['rating'], 1)).' / 5' : '—',
                'location' => $p['location'] ?? '—',
                'verification' => $p['verification'] ?? '—',
            ], $m['profiles']),
        ];
    }

    /**
     * Montants en millions de FCFA, comme le graphique.
     *
     * @param  array<string, mixed>  $f
     * @return array<string, mixed>
     */
    public function finance(array $f): array
    {
        $format = $f['range'] === 'auj' ? 'H\h' : 'j M';
        $million = fn (int $amount): float => round($amount / 1000000, 2);

        return [
            'range' => $f['range'],
            'labels' => array_map(fn (array $p): string => Carbon::parse($p['from'])->setTimezone(config('app.timezone'))->locale('fr')->translatedFormat($format), $f['points']),
            'collected' => array_map(fn (array $p): float => $million($p['collected']), $f['points']),
            'paidOut' => array_map(fn (array $p): float => $million($p['paid_out']), $f['points']),
            'pending' => $million($f['pending']),
        ];
    }

    /**
     * @param  array<string, mixed>  $e
     * @return array<string, string>
     */
    public function feedEntry(array $e): array
    {
        [$icon, $tone, $title, $user, $href] = self::FEED[$e['kind']];
        $detail = in_array($e['kind'], ['site', 'worker', 'dispute'], true) ? match ($e['kind']) {
            'site' => "/chantiers/{$e['id']}",
            'worker' => "/ouvriers/{$e['id']}",
            default => "/avis-litiges/litiges/{$e['id']}",
        } : ($e['kind'] === 'claim' ? "/avis-litiges/reclamations/{$e['id']}" : "/paiements/{$e['id']}");

        return [
            'icon' => $icon,
            'tone' => $tone,
            'title' => $title,
            'target' => isset($e['amount']) ? $e['target'].' · '.number_format($e['amount'], 0, ',', ' ').' FCFA' : $e['target'],
            'user' => $user,
            'when' => $e['at'] ? Carbon::parse($e['at'])->setTimezone(config('app.timezone'))->locale('fr')->diffForHumans() : '—',
            'href' => $detail ?: $href,
        ];
    }
}
