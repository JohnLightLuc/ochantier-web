<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tableau de bord.
 *
 * Toutes les valeurs sont des données de démonstration reprises de la maquette :
 * chaque méthode privée sera remplacée par une requête quand le module concerné sera branché.
 */
class DashboardController extends Controller
{
    private const ACTIVITY_RANGES = ['7j', '30j', '3m', 'custom'];

    private const FINANCE_RANGES = ['auj', '7j', '30j', '3m'];

    public function __invoke(Request $request): Response
    {
        $activityRange = in_array($request->query('periode'), self::ACTIVITY_RANGES, true) ? $request->query('periode') : '7j';
        $financeRange = in_array($request->query('flux'), self::FINANCE_RANGES, true) ? $request->query('flux') : '7j';

        return Inertia::render('Dashboard', [
            'kpis' => $this->kpis(),
            'activity' => fn () => $this->activity($activityRange, (string) $request->query('du', ''), (string) $request->query('au', '')),
            'priorities' => $this->priorities(),
            'recentSites' => $this->recentSites(),
            'siteDistribution' => $this->siteDistribution(),
            'matching' => $this->matching(),
            'finance' => fn () => $this->finance($financeRange),
            'feed' => $this->feed(),
            'updatedAt' => now()->format('H:i'),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function kpis(): array
    {
        return [
            ['label' => 'Chantiers actifs', 'value' => '24', 'unit' => '', 'icon' => 'construction', 'tone' => 'g', 'delta' => '+8 %', 'deltaTone' => 'g', 'deltaIcon' => 'north_east', 'compare' => 'vs semaine dernière', 'highlight' => false, 'href' => '/chantiers?filtre=En cours'],
            ['label' => 'Ouvriers disponibles', 'value' => '386', 'unit' => '', 'icon' => 'groups', 'tone' => 'g', 'delta' => '+12 %', 'deltaTone' => 'g', 'deltaIcon' => 'north_east', 'compare' => 'vs semaine dernière', 'highlight' => false, 'href' => '/ouvriers?filtre=Disponible'],
            ['label' => 'Affectations à valider', 'value' => '18', 'unit' => '', 'icon' => 'pending_actions', 'tone' => 'a', 'delta' => '+50 %', 'deltaTone' => 'r', 'deltaIcon' => 'north_east', 'compare' => 'vs semaine dernière', 'highlight' => true, 'href' => '/affectations?filtre=À valider'],
            ['label' => 'Paiements en attente', 'value' => '12 450 000', 'unit' => 'FCFA', 'icon' => 'account_balance_wallet', 'tone' => 'b', 'delta' => '0 %', 'deltaTone' => 'n', 'deltaIcon' => 'east', 'compare' => 'stable vs semaine dernière', 'highlight' => false, 'href' => '/paiements?raccourci=att'],
        ];
    }

    /**
     * Activité des chantiers : chantiers actifs et nouveaux chantiers sur la période.
     *
     * @return array{range: string, from: string, to: string, caption: string, labels: array<int, string>, dates: array<int, string>, active: array<int, int>, created: array<int, int>}
     */
    private function activity(string $range, string $from, string $to): array
    {
        $presets = [
            '7j' => [
                'caption' => 'Du 15 au 21 septembre 2026 · valeurs journalières',
                'labels' => ['Lun 15', 'Mar 16', 'Mer 17', 'Jeu 18', 'Ven 19', 'Sam 20', 'Dim 21'],
                'dates' => ['Lundi 15 sept. 2026', 'Mardi 16 sept. 2026', 'Mercredi 17 sept. 2026', 'Jeudi 18 sept. 2026', 'Vendredi 19 sept. 2026', 'Samedi 20 sept. 2026', 'Dimanche 21 sept. 2026'],
                'active' => [19, 20, 21, 22, 22, 23, 24],
                'created' => [3, 2, 4, 1, 3, 1, 2],
            ],
            '30j' => [
                'caption' => 'Du 23 août au 21 septembre 2026 · valeurs tous les 5 jours',
                'labels' => ['23 août', '28 août', '2 sept.', '7 sept.', '12 sept.', '17 sept.', '21 sept.'],
                'dates' => ['23 août 2026', '28 août 2026', '2 sept. 2026', '7 sept. 2026', '12 sept. 2026', '17 sept. 2026', '21 sept. 2026'],
                'active' => [16, 17, 19, 20, 21, 23, 24],
                'created' => [5, 4, 6, 5, 7, 6, 8],
            ],
            '3m' => [
                'caption' => 'De juillet à septembre 2026 · valeurs bimensuelles',
                'labels' => ['Juil.', 'Mi-juil.', 'Août', 'Mi-août', 'Sept.', 'Mi-sept.', 'Auj.'],
                'dates' => ['1er juil. 2026', '15 juil. 2026', '1er août 2026', '15 août 2026', '1er sept. 2026', '15 sept. 2026', '23 sept. 2026'],
                'active' => [12, 14, 15, 18, 20, 22, 24],
                'created' => [9, 10, 8, 12, 11, 13, 14],
            ],
        ];

        if ($range !== 'custom') {
            return ['range' => $range, 'from' => '', 'to' => '', ...$presets[$range]];
        }

        return ['range' => 'custom', 'from' => $from, 'to' => $to, ...$this->customActivity($from, $to)];
    }

    /**
     * Série générée de façon déterministe pour une période personnalisée.
     *
     * @return array{caption: string, labels: array<int, string>, dates: array<int, string>, active: array<int, int>, created: array<int, int>}
     */
    private function customActivity(string $from, string $to): array
    {
        $empty = ['caption' => '', 'labels' => [], 'dates' => [], 'active' => [], 'created' => []];

        $isDate = fn (string $value): bool => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $value);

        if (! $isDate($from) || ! $isDate($to) || $from > $to) {
            return $empty;
        }

        $start = Carbon::parse($from)->locale('fr');
        $end = Carbon::parse($to)->locale('fr');
        $caption = 'Du '.$start->translatedFormat('j F Y').' au '.$end->translatedFormat('j F Y');

        if ($start->isAfter(today()) || $end->isBefore('2024-01-01')) {
            return [...$empty, 'caption' => $caption];
        }

        $days = max(1, (int) $start->diffInDays($end));
        $points = max(2, min(7, $days + 1));
        $seed = crc32($from.$to);
        $series = ['caption' => $caption, 'labels' => [], 'dates' => [], 'active' => [], 'created' => []];

        for ($i = 0; $i < $points; $i++) {
            $date = $start->copy()->addDays((int) round($days * $i / ($points - 1)));
            $seed = ($seed * 1103515245 + 12345) & 0xFFFFFFFF;

            $series['labels'][] = $date->translatedFormat('j M');
            $series['dates'][] = ucfirst($date->translatedFormat('l j M Y'));
            $series['active'][] = 14 + (int) round($i * 10 / ($points - 1)) + ($seed % 3);
            $series['created'][] = ($seed >> 5) % 6;
        }

        return $series;
    }

    /**
     * @return array{total: int, totalAll: int, items: array<int, array<string, mixed>>, more: array<int, array<string, mixed>>}
     */
    private function priorities(): array
    {
        $item = fn (int $count, string $title, string $text, string $priority, string $icon, string $href): array => compact('count', 'title', 'text', 'priority', 'icon', 'href');

        return [
            'total' => 23,
            'totalAll' => 35,
            'items' => [
                $item(5, 'remplacements urgents', 'Absences signalées sur 3 chantiers actifs', 'Urgent', 'swap_horiz', '/affectations?filtre=Remplacements'),
                $item(8, 'profils ouvriers à valider', 'Pièces d’identité et certificats à vérifier', 'Élevé', 'person_check', '/ouvriers?filtre=À valider'),
                $item(7, 'paiements à autoriser', 'Montant total : 12 450 000 FCFA', 'Élevé', 'payments', '/paiements/a-reverser'),
                $item(3, 'litiges ouverts', 'Dont 1 escaladé en priorité haute', 'Moyen', 'gavel', '/avis-litiges?vue=litiges'),
            ],
            'more' => [
                $item(4, 'pointages en anomalie', 'Écarts d’horaires à contrôler', 'Élevé', 'schedule', '/pointages/anomalies'),
                $item(6, 'offres sans candidature', 'Publiées depuis plus de 5 jours', 'Moyen', 'assignment', '/besoins?filtre=Ouverte'),
                $item(2, 'pièces KYC expirées', 'Dossiers promoteurs à mettre à jour', 'Moyen', 'event_busy', '/promoteurs?filtre=KYC expirées'),
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function recentSites(): array
    {
        return [
            ['id' => 'd1', 'name' => 'Résidence Les Oliviers', 'promoter' => 'SICOGI', 'promoterHref' => '/promoteurs/sicogi', 'location' => 'Cocody', 'progress' => 75, 'workers' => '24 / 30', 'status' => 'En cours', 'tone' => 'g', 'period' => 'Janv. 2024 – Déc. 2026', 'type' => 'Résidentiel collectif', 'href' => '/promoteurs/sicogi/chantiers/ch1'],
            ['id' => 'd2', 'name' => 'Tour Horizon', 'promoter' => 'Ivoire Properties', 'promoterHref' => null, 'location' => 'Plateau', 'progress' => 45, 'workers' => '18 / 40', 'status' => 'En cours', 'tone' => 'g', 'period' => 'Mars 2025 – Juin 2027', 'type' => 'Bureaux', 'href' => null],
            ['id' => 'd3', 'name' => 'Cité Belle Rive', 'promoter' => 'SAPH', 'promoterHref' => null, 'location' => 'Bingerville', 'progress' => 20, 'workers' => '12 / 50', 'status' => 'En retard', 'tone' => 'r', 'period' => 'Févr. 2025 – Mars 2027', 'type' => 'Logement social', 'href' => null],
            ['id' => 'd4', 'name' => 'Complexe scolaire SA', 'promoter' => 'Fonds Éducation CI', 'promoterHref' => null, 'location' => 'Yamoussoukro', 'progress' => 90, 'workers' => '36 / 40', 'status' => 'Presque terminé', 'tone' => 'b', 'period' => 'Sept. 2024 – Oct. 2026', 'type' => 'Équipement public', 'href' => null],
        ];
    }

    /**
     * @return array{total: int, segments: array<int, array{label: string, count: int, color: string, href: string}>}
     */
    private function siteDistribution(): array
    {
        return [
            'total' => 24,
            'segments' => [
                ['label' => 'En cours', 'count' => 17, 'color' => 'var(--c-primary-500)', 'href' => '/chantiers?filtre=En cours'],
                ['label' => 'En retard', 'count' => 4, 'color' => 'var(--c-danger)', 'href' => '/chantiers?filtre=En retard'],
                ['label' => 'Presque terminés', 'count' => 3, 'color' => 'var(--c-info)', 'href' => '/chantiers?filtre=Presque terminé'],
            ],
        ];
    }

    /**
     * @return array{relevance: int, pending: int, delta: string, profiles: array<int, array<string, mixed>>}
     */
    private function matching(): array
    {
        $profile = fn (string $name, string $site, int $score, string $job, string $experience, string $rate, string $availability, string $rating, string $location): array => compact('name', 'site', 'score', 'job', 'experience', 'rate', 'availability', 'rating', 'location') + ['verification' => 'Vérifié'];

        return [
            'relevance' => 92,
            'pending' => 38,
            'delta' => '+4 pts',
            'profiles' => [
                $profile('Koffi Adjoumani', 'Résidence Les Oliviers', 96, 'Maçon', '8 ans', '12 000 FCFA/jour', 'Disponible', '4,8 / 5', 'Cocody'),
                $profile('Diallo Fatoumata', 'Cité Belle Rive', 94, 'Manœuvre', '3 ans', '7 000 FCFA/jour', 'Disponible', '4,6 / 5', 'Yopougon'),
                $profile('Traoré Ibrahima', 'Tour Horizon', 91, 'Électricien', '6 ans', '11 000 FCFA/jour', 'Disponible', '4,5 / 5', 'Abobo'),
                $profile('Mamadou Koné', 'Cité Belle Rive', 89, 'Plombier', '7 ans', '11 000 FCFA/jour', 'En mission', '4,7 / 5', 'Abobo'),
                $profile('Issa Traoré', 'Tour Horizon', 87, 'Électricien', '5 ans', '10 000 FCFA/jour', 'Disponible', '4,6 / 5', 'Yopougon'),
            ],
        ];
    }

    /**
     * Flux financier en millions de FCFA. Les commissions valent 8 % des encaissements.
     *
     * @return array{range: string, labels: array<int, string>, collected: array<int, float>, paidOut: array<int, float>, pending: float}
     */
    private function finance(string $range): array
    {
        $presets = [
            'auj' => ['labels' => ['08h', '10h', '12h', '14h', '16h', '18h', '20h'], 'collected' => [0.8, 1.6, 2.2, 1.1, 2.9, 1.4, 0.6], 'paidOut' => [0.4, 0.9, 1.3, 0.7, 1.6, 0.8, 0.3], 'pending' => 3.2],
            '7j' => ['labels' => ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'], 'collected' => [4.2, 5.1, 3.8, 4.6, 6.2, 2.9, 1.7], 'paidOut' => [2.3, 2.8, 2.1, 2.6, 3.4, 1.5, 0.9], 'pending' => 12.45],
            '30j' => ['labels' => ['25 août', '29 août', '2 sept.', '6 sept.', '10 sept.', '14 sept.', '18 sept.'], 'collected' => [14.2, 16.8, 15.1, 18.4, 17.2, 19.6, 21.5], 'paidOut' => [7.6, 9.1, 8.4, 10.2, 9.3, 10.8, 11.9], 'pending' => 18.9],
            '3m' => ['labels' => ['Juil.', 'Mi-juil.', 'Août', 'Mi-août', 'Sept.', 'Mi-sept.', 'Auj.'], 'collected' => [38, 41, 36, 44, 47, 52, 28], 'paidOut' => [21, 23, 20, 24, 26, 29, 15], 'pending' => 24.6],
        ];

        return ['range' => $range, ...$presets[$range]];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function feed(): array
    {
        $entry = fn (string $icon, string $tone, string $title, string $target, string $user, string $when, string $href): array => compact('icon', 'tone', 'title', 'target', 'user', 'when', 'href');

        return [
            $entry('construction', 'success', 'Nouveau chantier créé', 'Extension Cité Verte', 'SICOGI · M. Konan', 'Il y a 28 min', '/promoteurs/sicogi/chantiers/ch6'),
            $entry('verified', 'success', 'Ouvrier validé', 'Koffi Adjoumani · Maçon', 'N. Yao', 'Il y a 1 h', '/ouvriers?q=Koffi Adjoumani'),
            $entry('payments', 'warning', 'Paiement autorisé', 'PAY-260922-1842 · 3 750 000 FCFA', 'N. Yao', 'Il y a 2 h', '/promoteurs/sicogi/paiements/PAY-260922-1842'),
            $entry('gavel', 'danger', 'Litige ouvert', 'LIT-0102 · Logements sociaux Yopougon', 'Équipe Bâtir Pro', 'Il y a 3 h', '/promoteurs/sicogi/litiges'),
            $entry('campaign', 'info', 'Offre publiée', 'Chef d’équipe gros œuvre', 'SICOGI · M. Konan', 'Il y a 5 h', '/promoteurs/sicogi/offres/of8'),
            $entry('task_alt', 'purple', 'Affectation confirmée', 'Issa Traoré → Tour Horizon', 'A. Bamba', 'Hier · 17:40', '/affectations?filtre=Confirmées'),
            $entry('person_add', 'info', 'Nouveau promoteur inscrit', 'Horizon BTP', 'Système', 'Hier · 09:15', '/promoteurs/horizon-btp'),
            $entry('block', 'danger', 'Compte suspendu', 'Bâtir Afrique', 'N. Yao', '11 sept. · 16:20', '/promoteurs/batir-afrique'),
            $entry('receipt_long', 'warning', 'Paiement échoué', 'PAY-260910-1590 · 980 000 FCFA', 'Orange Money', '10 sept. · 17:15', '/promoteurs/sicogi/paiements/PAY-260910-1590'),
            $entry('verified', 'success', 'Document KYC validé', 'Registre de commerce · Ivoire Construction', 'N. Yao', '9 sept. · 10:02', '/promoteurs/ivoire-construction/kyc'),
        ];
    }
}
