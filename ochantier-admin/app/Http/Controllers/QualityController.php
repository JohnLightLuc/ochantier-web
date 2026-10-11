<?php

namespace App\Http\Controllers;

use App\Models\Dispute;
use App\Models\QualityActivity;
use App\Models\QualityNotification;
use App\Models\Review;
use App\Support\FinanceLedger;
use App\Support\FinanceReports;
use App\Support\PageBlocks as UI;
use App\Support\QualityInsights as Q;
use App\Support\QualityReports;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as FileResponse;

/**
 * Avis & litiges : vue d’ensemble (qualité des avis, litiges, exposition financière), modération, signalements,
 * médiations, recours, rapports, notifications et journal d’audit.
 */
class QualityController extends QualityPageController
{
    public const VIEWS = ['avis' => 'Qualité des avis', 'litiges' => 'Litiges', 'expo' => 'Exposition financière'];

    /**
     * Famille d’une catégorie de litige.
     */
    public static function family(string $category): string
    {
        return match (true) {
            (bool) preg_match('/Qualité|incomplet|Retard|Abandon|consignes|Sécurité/u', $category) => 'Exécution des travaux',
            (bool) preg_match('/Absence|Pointage|Heures/u', $category) => 'Présence et pointage',
            (bool) preg_match('/Paiement|Montant|Retenue|Commission|Remboursement/u', $category) => 'Paiements et montants',
            (bool) preg_match('/Dommage|Matériel/u', $category) => 'Matériel',
            (bool) preg_match('/Remplacement|Affectation/u', $category) => 'Affectation',
            default => 'Conduite et intégrité',
        };
    }

    /**
     * Fiche d’un indicateur (formule, numérateur, dénominateur, inclus, exclus).
     *
     * @return array<string, mixed>
     */
    private function sheet(Q $ins, string $id, string $name, string $value, string $formula, string $num, string $den, string $inc, string $exc, ?string $drill): array
    {
        $cfg = config('admin.quality');

        return ['id' => $id, 'kicker' => 'Indicateur · Avis & Litiges', 'title' => $name, 'badge' => ['label' => $value, 'tone' => 'b'], 'drill' => $drill, 'meta' => [
            ['Valeur', $value], ['Période', "{$ins->period['range']} ({$ins->period['label']})"], ['Formule', $formula], ['Numérateur', $num], ['Dénominateur', $den], ['Inclus', $inc], ['Exclus', $exc],
            ['Filtres actifs', $ins->filterSummary() ?: 'Aucun'], ['Source', "Avis & Litiges · prestations issues de Paiements et Pointages · paramètres {$cfg['weights_version']}"], ['Mise à jour', now()->format('d/m/Y H:i')],
        ]];
    }

    /**
     * Dictionnaire des indicateurs.
     *
     * @return array<string, mixed>
     */
    private function definitions(): array
    {
        $c = config('admin.quality');
        $s = $c['sla'];

        return ['id' => 'definitions', 'kicker' => 'Dictionnaire des indicateurs', 'title' => 'Définitions Avis & Litiges', 'badge' => ['label' => $c['weights_version'], 'tone' => 'b'], 'drill' => null, 'meta' => [
            ['Note globale', "Σ (note du critère × pondération) ÷ Σ pondérations des critères activés · pondérations {$c['weights_version']} (Paramètres, total 100 %)"], ['Note moyenne', 'Σ notes globales éligibles ÷ nombre d’avis éligibles'],
            ['Note ajustée (classement)', "(v ÷ (v + m)) × R + (m ÷ (v + m)) × C · m = {$c['bayes_m']} · utilisée uniquement pour le classement".($c['bayes_matching'] ? ' et le Matching' : '')],
            ['Taux de satisfaction', "Avis ≥ {$c['satisfaction']} / 5 ÷ avis éligibles × 100"], ['Taux de collecte', 'Prestations éligibles évaluées ÷ prestations éligibles × 100'],
            ['Taux de résolution', 'Litiges résolus ÷ litiges arrivés à une issue × 100'], ['Taux de médiation', 'Litiges médiés ÷ litiges éligibles à la médiation × 100'], ['Taux d’accord amiable', 'Médiations avec accord ÷ médiations terminées × 100'],
            ['Taux de recours', 'Décisions avec recours ÷ décisions rendues × 100'], ['Taux de réouverture', 'Litiges rouverts ÷ litiges clôturés ou résolus × 100'], ['Délais', 'Médiane (la moyenne est affichée en complément)'],
            ['Exposition brute', 'Σ montants contestés des litiges actifs'], ['Exposition nette', 'Montants contestés − montants réglés − montants libérés'],
            ['SLA', "Délais Paramètres : accusé {$s['ack']} j · qualification {$s['qualif']} j · réponse {$s['rep']} j · traitement {$s['trait']} j · médiation {$s['med']} j · décision {$s['dec']} j · exécution {$s['exec']} j · recours {$s['rec']} j"],
        ]];
    }

    private function delta(int|float|null $a, int|float|null $b, string $unit = ''): string
    {
        if ($a === null || $b === null) {
            return 'comparaison indisponible';
        }
        if ($unit === '%') {
            return ! $b ? ($a ? 'nouvelle activité' : 'stable') : ($a - $b >= 0 ? '+' : '− ').abs((int) round(($a - $b) / $b * 100)).' % vs période préc.';
        }

        return ($a - $b >= 0 ? '+' : '− ').Q::one(abs($a - $b)).$unit.' vs période préc.';
    }

    /**
     * Vue d’ensemble : indicateurs, puis qualité des avis, litiges ou exposition financière.
     */
    public function index(Request $request): Response
    {
        $ins = $this->insights($request);
        if (! Q::can('av') && ! Q::can('lit')) {
            return $this->denied($ins, 'Votre rôle ne permet pas de consulter Avis & Litiges.');
        }
        $view = array_key_exists($request->query('vue', ''), self::VIEWS) ? $request->query('vue') : 'avis';
        $a = $ins->a;
        $p = $ins->prev;
        $k = $ins->disputeKpis();
        $queues = $ins->reviewQueues();
        $cfg = config('admin.quality');
        $collect = Q::ratio($a['collected'], $a['elig']);
        $collectBefore = Q::ratio($p['collected'], $p['elig']);
        $active = $k['active'];
        $sheets = [
            'avg' => $this->sheet($ins, 'avg', 'Note moyenne globale', Q::two($a['avg']).' / 5', 'Σ notes globales éligibles ÷ nombre d’avis éligibles', "Σ notes globales ({$a['n']} avis)", "{$a['n']} avis éligibles", implode(', ', Review::COUNTED), "Masqués, rejetés, annulés, archivés, doublons, tests, frauduleux, non éligibles ({$a['excl']})", $ins->url('/avis')),
            'collect' => $this->sheet($ins, 'collect', 'Taux de collecte des avis', Q::percent($collect), 'Prestations éligibles ayant reçu un avis ÷ prestations éligibles × 100', "{$a['collected']} prestations évaluées", "{$a['elig']} prestations terminées", 'Avis promoteur sur ouvrier ou équipe', 'Tests, doublons, brouillons, annulés, évaluations internes · jamais le nombre de comptes', $ins->url('/avis')),
            'resolution' => $this->sheet($ins, 'resolution', 'Délai médian de résolution', Q::one($k['resolutionMedian']).' j', 'Médiane (résolution − ouverture)', 'Durées en jours', "{$k['resolved']} litiges résolus", 'Résolus, accords validés', 'Non recevables, annulés', $ins->url('/litiges', ['vue' => 'res'])),
            'definitions' => $this->definitions(),
        ];
        $kpis = [
            ['label' => 'Note moyenne globale', 'value' => $a['avg'] === null ? 'Non calculable' : Q::two($a['avg']).' / 5', 'icon' => 'star', 'tone' => 'a', 'hint' => "{$a['n']} avis · ".$this->delta($a['avg'], $p['avg']), 'action' => UI::kpi('avg')],
            ['label' => 'Taux de collecte des avis', 'value' => Q::percent($collect), 'icon' => 'rate_review', 'tone' => 'b', 'hint' => "{$a['collected']} / {$a['elig']} prestations · ".$this->delta($collect, $collectBefore, ' pt'), 'action' => UI::kpi('collect')],
            ['label' => 'Avis à modérer', 'value' => (string) $queues['pending']->count(), 'icon' => 'hourglass_top', 'tone' => $queues['pending']->isNotEmpty() ? 'a' : 'g', 'hint' => 'Encours · '.$queues['suspects']->count().' avec détection suspecte', 'action' => UI::link($ins->url('/moderation'))],
            ['label' => 'Avis signalés ou contestés', 'value' => (string) $queues['flagged']->count(), 'icon' => 'flag', 'tone' => $queues['flagged']->isNotEmpty() ? 'r' : 'g', 'hint' => 'Encours · '.$queues['replies']->count().' réponse(s) à modérer', 'action' => UI::link($ins->url('/signalements'))],
            ['label' => 'Litiges actifs', 'value' => (string) $k['activeCount'], 'icon' => 'gavel', 'tone' => 'a', 'hint' => "{$k['critical']} critique(s) · {$k['new']} ouvert(s) sur la période", 'action' => UI::link($ins->url('/litiges', ['vue' => 'act']))],
            ['label' => 'Litiges en retard (SLA)', 'value' => (string) $k['late'], 'icon' => 'timer_off', 'tone' => $k['late'] ? 'r' : 'g', 'hint' => "{$k['soon']} bientôt en retard · délais Paramètres", 'action' => UI::link($ins->url('/litiges', ['vue' => 'late']))],
            ['label' => 'Délai médian de résolution', 'value' => $k['resolutionMedian'] === null ? 'Non calculable' : Q::one($k['resolutionMedian']).' j', 'icon' => 'event_available', 'tone' => 'b', 'hint' => "{$k['resolved']} résolu(s) · moyenne ".($k['resolutionAverage'] === null ? '—' : Q::one($k['resolutionAverage']).' j'), 'action' => UI::kpi('resolution')],
            ['label' => 'Exposition financière brute', 'value' => Q::money($k['exposure']), 'icon' => 'account_balance_wallet', 'tone' => 'o', 'hint' => 'Nette '.Q::money($k['exposureNet']).' · potentielle', 'action' => UI::link($ins->url('', ['vue' => 'expo']))],
        ];
        $tabs = array_map(fn (string $key, string $label): array => UI::tab($label, $ins->url('', ['vue' => $key], false), ['avis' => $queues['pending']->count(), 'litiges' => $k['late'], 'expo' => 0][$key], $key === $view), array_keys(self::VIEWS), self::VIEWS);
        $tools = fn (array $extra): array => [
            ...($ins->filters ? [UI::button('Réinitialiser ('.count($ins->filters).')', 'filter_alt_off', UI::link($ins->url('', ['vue' => $view], false)))] : []),
            UI::button('Définitions', 'menu_book', UI::kpi('definitions')), ...$extra, UI::button('Notifications', 'notifications', UI::link($ins->url('/notifications'))),
            UI::button('Audit', 'history', self::allowed('audit', UI::link($ins->url('/audit')), 'audit')),
            UI::button('Exporter la synthèse', 'download', self::allowed('export', UI::download($ins->url('/rapports/generer', ['rapport' => 'synthese', 'format' => 'Excel'])), 'export'), 'primary'),
        ];
        $props = ['kpis' => $kpis, 'tabs' => $tabs, 'sheets' => $sheets];

        if ($view === 'avis') {
            $bar = $this->filterBar($ins, '', ['promoteur', 'chantier', 'ouvrier', 'equipe', 'metier', 'commune', 'prestation', 'type_avis', 'niveau', 'moderation'], [], ['promoteur', 'chantier', 'metier', 'type_avis', 'niveau']);
            $alerts = $bar['alerts'];
            if ($queues['suspects']->isNotEmpty()) {
                $alerts[] = UI::alert('warning', 'policy', $queues['suspects']->count().' avis suspect(s) détecté(s)', 'Signalement automatique uniquement : aucune suppression sans décision humaine.', 'Modération', UI::link($ins->url('/moderation')));
            }
            $counted = $a['counted'];
            $individual = $counted->filter(fn (Review $r): bool => $r->kind !== 'Équipe');
            $months = collect(range(5, 0))->map(fn (int $i): string => Q::today()->startOfMonth()->subMonths($i)->format('Y-m'));
            $props += [
                ...$bar, 'alerts' => $alerts, 'toolbar' => $tools([UI::button('Tous les avis', 'star', UI::link($ins->url('/avis')))]),
                'tiles' => [
                    UI::tile('Avis reçus', $a['received'], 'chat_bubble', 'b', ['sub' => $this->delta($a['received'], $p['received'], '%')]),
                    UI::tile('Avis vérifiés', $a['verified'], 'verified', 'g', ['bar' => Q::ratio($a['verified'], $a['received']) ?? 0, 'sub' => Q::percent(Q::ratio($a['verified'], $a['received'])).' des avis reçus']),
                    UI::tile('Taux de satisfaction', Q::percent(Q::ratio($a['sat'], $a['n'])), 'sentiment_satisfied', 'g', ['bar' => Q::ratio($a['sat'], $a['n']) ?? 0, 'sub' => "Note ≥ {$cfg['satisfaction']} / 5 (Paramètres)"]),
                    UI::tile('Avis négatifs', $a['neg'], 'thumb_down', $a['neg'] ? 'o' : 'g', ['sub' => 'Note ≤ '.Q::one($cfg['negative']).' / 5']),
                    UI::tile('Réponses à modérer', $queues['replies']->count(), 'reply', $queues['replies']->isNotEmpty() ? 'a' : 'g', ['sub' => 'Droit de réponse ouvrier / équipe']),
                    UI::tile('Exclus de la moyenne', $a['excl'], 'block', 'n', ['sub' => 'Masqués, rejetés, doublons, tests…']),
                ],
                'charts' => [
                    UI::chart('Répartition des notes', ($a['avg'] === null ? '—' : Q::two($a['avg']).' / 5')." · {$a['n']} avis éligibles · pondérations {$cfg['weights_version']}", array_map(fn (int $n): array => ["{$n} ★", $counted->filter(fn (Review $r): bool => (int) round($r->score()) === $n)->count(), (string) $counted->filter(fn (Review $r): bool => (int) round($r->score()) === $n)->count()], [5, 4, 3, 2, 1]), 'var(--c-accent-600)'),
                    UI::chart('Évolution de la note moyenne', $queues['flagged']->count().' signalé(s) · '.$ins->reviewsScoped->where('status', 'Masqué temporairement')->count().' masqué(s) · 6 derniers mois', $months->map(function (string $m) use ($ins): array {
                        $x = $ins->reviewsScoped->filter(fn (Review $r): bool => $r->isCounted() && $r->date->format('Y-m') === $m);
                        $v = $x->isNotEmpty() ? $x->avg(fn (Review $r): ?float => $r->score()) : 0;

                        return [explode(' ', FinanceLedger::monthLabel($m))[0], $v, $v ? Q::two($v) : '—'];
                    })->all()),
                    UI::chart('Moyenne par critère', 'Avis individuels éligibles · pondération entre parenthèses', array_map(function (array $c) use ($individual): array {
                        $notes = $individual->map(fn (Review $r): ?int => $r->criteria[$c[0]] ?? null)->filter();

                        return ["{$c[1]} ({$c[2]} %)", $notes->avg() ?? 0, $notes->isNotEmpty() ? Q::one($notes->avg()) : '—'];
                    }, $cfg['criteria']['Ouvrier'])),
                ],
                'tables' => [UI::table('shield', 'Avis à modérer', $queues['pending']->count().' en attente', ['Référence', 'Cible', 'Auteur', 'Note', 'Détections', 'Statut'], $queues['pending']->take(6)->map(fn (Review $r): array => UI::row($r->reference, [
                    UI::text($r->reference, true), UI::stacked($r->target, $r->kind), UI::text($r->author), UI::text(Q::one($r->score()).' / 5'),
                    $r->detections ? UI::text(implode(', ', array_column($r->detections, 'key'))) : UI::text('Aucune', false, true), UI::badge($r->status, Review::TONES[$r->status]),
                ], UI::link($ins->url("/avis/{$r->reference}")), [['visibility', 'Examiner', UI::link($ins->url("/avis/{$r->reference}/moderation"))]]))->all(), 1000, ['verified', 'File vide', 'Aucun avis en attente de modération.'])],
            ];
        }

        if ($view === 'litiges') {
            $bar = $this->filterBar($ins, '', ['promoteur', 'chantier', 'ouvrier', 'metier', 'commune', 'categorie', 'priorite', 'gravite', 'statut_litige', 'responsable'], [], ['promoteur', 'chantier', 'categorie', 'priorite', 'statut_litige'], true);
            $late = $active->filter(fn (Dispute $d): bool => $d->sla()['status'] === 'En retard');
            $soon = $active->filter(fn (Dispute $d): bool => $d->sla()['status'] === 'Bientôt en retard');
            $alerts = $bar['alerts'];
            if ($late->isNotEmpty()) {
                $alerts[] = UI::alert('danger', 'timer_off', $late->count().' litige(s) hors délai', $late->take(3)->map(fn (Dispute $d): string => "{$d->reference} (".mb_strtolower($d->sla()['phase']).')')->implode(' · '), 'Voir', UI::link($ins->url('/litiges', ['vue' => 'late'])));
            }
            if ($soon->isNotEmpty()) {
                $alerts[] = UI::alert('warning', 'schedule', $soon->count().' SLA bientôt dépassé(s)', $soon->take(3)->pluck('reference')->implode(' · '), 'Voir', UI::link($ins->url('/litiges', ['vue' => 'soon'])));
            }
            $stages = [['Qualification', ['Soumis', 'Reçu', 'À qualifier', 'Recevabilité en cours'], 'rule'], ['Instruction', ['Ouvert', 'Affecté', 'Analyse en cours', 'Informations demandées', 'Réponse de la partie adverse attendue'], 'search'], ['Médiation', ['Médiation proposée', 'Médiation en cours', 'Accord trouvé'], 'handshake'], ['Décision et exécution', ['Décision en préparation', 'Décision rendue', 'En attente d’exécution'], 'gavel'], ['Recours', ['Recours ouvert', 'Réexamen'], 'replay']];
            $slaStates = ['Dans les délais' => 'var(--c-success)', 'À surveiller' => 'var(--c-warning)', 'Bientôt en retard' => 'var(--c-accent-600)', 'En retard' => 'var(--c-danger)', 'Suspendu avec motif' => 'var(--c-info)'];
            $previousNew = $ins->disputesScoped->filter(fn (Dispute $d): bool => $ins->in($d->opened_at->format('Y-m-d'), $ins->period['previousFrom'], $ins->period['previousTo']))->count();
            $rate = fn (string $label, ?float $v, int $den, string $icon, string $tone, string $what): array => UI::tile($label, Q::percent($v), $icon, $tone, ['bar' => $v ?? 0, 'sub' => "Sur {$den} {$what}"]);
            $props += [
                ...$bar, 'alerts' => $alerts, 'toolbar' => $tools([UI::button('Tous les litiges', 'gavel', UI::link($ins->url('/litiges'))), UI::button('Médiations', 'handshake', UI::link($ins->url('/mediations')))]),
                'flow' => [
                    'steps' => array_map(function (array $s) use ($active, $ins): array {
                        $n = $active->filter(fn (Dispute $d): bool => in_array($d->status, $s[1], true))->count();

                        return UI::step($s[0], "{$n} actif(s)", $n ? 'cur' : 'done', $s[2], UI::link($ins->url('/litiges', ['vue' => 'act'])));
                    }, $stages),
                    'stacks' => [
                        UI::stack('Respect des SLA', 'Litiges actifs · délais de Paramètres', array_map(fn (string $l, string $color): array => [$l, $active->filter(fn (Dispute $d): bool => $d->sla()['status'] === $l)->count(), $color], array_keys($slaStates), $slaStates), false),
                        UI::stack('Répartition par famille de catégories', 'Litiges de la période · '.($ins->basis === 'res' ? 'date de résolution' : 'date de création'), $ins->disputesInPeriod->groupBy(fn (Dispute $d): string => self::family($d->category))->map(fn (Collection $g, string $f): array => [$f, $g->count()])->sortByDesc(1)->values()->all(), false),
                    ],
                ],
                'tiles' => [
                    UI::tile('Nouveaux litiges', $k['new'], 'fiber_new', 'b', ['sub' => $this->delta($k['new'], $previousNew, '%')]), UI::tile('Litiges critiques', $k['critical'], 'priority_high', $k['critical'] ? 'r' : 'g', ['sub' => 'Priorité ou gravité critique']),
                    UI::tile('Médiations en cours', $k['mediating'], 'handshake', 'p'), UI::tile('Litiges résolus', $k['resolved'], 'task_alt', 'g', ['sub' => "{$k['closed']} clôturé(s)"]),
                    UI::tile('1re réponse (médiane)', $k['firstMedian'] === null ? '—' : Q::one($k['firstMedian']).' h', 'schedule', 'b'), UI::tile('Qualification (médiane)', $k['qualificationMedian'] === null ? '—' : Q::one($k['qualificationMedian']).' j', 'rule', 'b'),
                    $rate('Taux de résolution', $k['resolutionRate'], $k['resolutionDen'], 'percent', 'g', 'issue(s)'), $rate('Taux de médiation', $k['mediationRate'], $k['mediationDen'], 'handshake', 'p', 'éligible(s)'),
                    $rate('Accord amiable', $k['agreementRate'], $k['agreementDen'], 'verified', 'g', 'médiation(s) terminée(s)'), $rate('Taux de recours', $k['appealRate'], $k['appealDen'], 'replay', 'o', 'décision(s)'),
                    $rate('Taux de réouverture', $k['reopenRate'], $k['reopenDen'], 'lock_open', 'o', 'clôturé(s)'), $rate('Récurrence des parties', $k['recurrenceRate'], $k['recurrenceDen'], 'repeat', 'a', 'partie(s)'),
                ],
                'tables' => [UI::table('gavel', 'Litiges prioritaires', 'Tri par échéance SLA · '.$active->count().' actif(s)', ['Référence', 'Objet', 'Chantier', 'Priorité', 'Statut', 'Étape SLA', 'SLA', 'Montant contesté'], $active->sortBy(fn (Dispute $d): int => $d->sla()['left'] ?? 99)->take(6)->map(function (Dispute $d) use ($ins): array {
                    $s = $d->sla();

                    return UI::row($d->reference, [UI::text($d->reference, true), UI::text($d->subject), UI::text($d->site->name), UI::badge($d->priority, Dispute::PRIORITY_TONES[$d->priority]), UI::badge($d->status, Dispute::statusTone($d->status)), UI::text($s['phase']), UI::badge($s['text'], $s['tone']), $d->amount ? UI::text(Q::money($d->amount)) : UI::text('—', false, true)],
                        UI::link($ins->url("/litiges/{$d->reference}")), [['visibility', 'Ouvrir', UI::link($ins->url("/litiges/{$d->reference}"))]]);
                })->values()->all(), 1200, ['task_alt', 'Aucun litige actif', 'Aucun dossier en cours avec les filtres actifs.'])],
            ];
        }

        if ($view === 'expo') {
            $bar = $this->filterBar($ins, '', ['promoteur', 'chantier', 'ouvrier', 'categorie', 'priorite', 'impact'], [], ['promoteur', 'chantier', 'categorie', 'impact'], false);
            $families = $active->groupBy(fn (Dispute $d): string => self::family($d->category))->map(fn (Collection $g, string $f): array => [$f, (int) $g->sum('amount')])->filter(fn (array $x): bool => $x[1] > 0)->sortByDesc(1)->values()->all();
            $withImpact = $active->filter(fn (Dispute $d): bool => $d->amount || $d->financial_requests)->sortByDesc('amount');
            $settled = $k['exposure'] - $k['exposureNet'];
            $props += [
                ...$bar, 'alerts' => [...$bar['alerts'], UI::alert('info', 'account_balance', 'Montants potentiels', 'L’exposition n’est pas une perte réalisée. Les mesures financières sont exécutées exclusivement par le module Paiements.')],
                'toolbar' => $tools([UI::button('Litiges avec impact', 'filter_alt', UI::link($ins->url('/litiges', ['vue' => 'fin'])))]),
                'flow' => [
                    'steps' => [UI::step('Montant contesté', Q::money($k['exposure']), 'cur', 'account_balance_wallet'), UI::step('− Réglé ou libéré', Q::money($settled), $settled ? 'done' : 'todo', 'task_alt'), UI::step('= Exposition nette', Q::money($k['exposureNet']), $k['exposureNet'] ? 'warn' : 'done', 'balance'), UI::step('dont fonds suspendus', Q::money($k['suspended']), $k['suspended'] ? 'warn' : 'todo', 'lock')],
                    'stacks' => [
                        UI::stack('Composition de l’exposition', 'Litiges actifs · encours au '.Q::today()->format('d/m/Y'), [['Montant contesté non couvert', $k['exposureNet']], ['Remboursements potentiels', $k['refunds']], ['Ajustements potentiels', $k['adjustments']], ['Commissions potentiellement remboursables', $k['commissions']], ['Fonds suspendus', $k['suspended']]]),
                        UI::stack('Montant contesté par famille de catégories', 'Litiges actifs', $families),
                    ],
                ],
                'tiles' => [
                    UI::tile('Exposition brute', Q::money($k['exposure']), 'account_balance_wallet', 'o', ['sub' => 'Σ montants contestés actifs']), UI::tile('Exposition nette', Q::money($k['exposureNet']), 'balance', 'o', ['sub' => 'Brute − réglé − libéré']),
                    UI::tile('Fonds suspendus', Q::money($k['suspended']), 'lock', 'n', ['sub' => 'Suspensions exécutées par Paiements']), UI::tile('Remboursements potentiels', Q::money($k['refunds']), 'undo', 'a'),
                    UI::tile('Ajustements potentiels', Q::money($k['adjustments']), 'tune', 'a'), UI::tile('Montant exécuté', Q::money($k['done']), 'task_alt', 'g', ['sub' => 'Demandes exécutées par Paiements']),
                ],
                'tables' => [UI::table('account_balance_wallet', 'Litiges avec impact financier', $withImpact->count().' litige(s) actif(s)', ['Référence', 'Objet', 'Chantier', 'Montant contesté', 'Fonds suspendus', 'Exposition nette', 'Demandes Paiements', 'Statut'], $withImpact->map(function (Dispute $d) use ($ins): array {
                    $e = $d->exposure();
                    $go = UI::link($ins->url("/litiges/{$d->reference}/paiements"));

                    return UI::row($d->reference, [UI::text($d->reference, true), UI::text($d->subject), UI::text($d->site->name), UI::money($d->amount, true), UI::money($e['suspended'], false, true), UI::money($e['net']), $d->financial_requests ? UI::text(count($d->financial_requests).' demande(s)') : UI::text('—', false, true), UI::badge($d->status, Dispute::statusTone($d->status))], $go, [['visibility', 'Ouvrir', $go]]);
                })->values()->all(), 1400, ['task_alt', 'Aucune exposition', 'Aucun litige actif avec impact financier.'])],
            ];
        }

        $props['toolbar'] = [...self::createButtons(), ...$props['toolbar']];

        return $this->render($ins, '/', $props, 'Avis & Litiges', 'Qualité des prestations, modération des avis et résolution contradictoire des litiges');
    }

    /**
     * Modération : file d’attente, détections automatiques et droits de réponse.
     */
    public function moderation(Request $request): Response
    {
        $ins = $this->insights($request);
        if (! Q::can('mod') && ! Q::can('hide')) {
            return $this->denied($ins, 'Votre rôle ne permet pas de modérer les avis.');
        }
        $queues = $ins->reviewQueues();
        $detections = $ins->reviewsScoped->flatMap(fn (Review $r): array => array_map(fn (array $d, int $i): array => ['r' => $r, 'd' => $d, 'i' => $i], $r->detections, array_keys($r->detections)))->values();
        $open = $detections->filter(fn (array $x): bool => $x['d']['status'] === 'À examiner');
        $bar = $this->filterBar($ins, '/moderation', ['promoteur', 'chantier', 'ouvrier', 'metier', 'type_avis']);
        $page = (int) $request->query('page', 1);
        $queue = UI::paginate($queues['pending'], $page, fn (Review $r): array => UI::row($r->reference, [
            UI::text($r->reference, true), UI::text(Q::date($r->date->format('Y-m-d'))), UI::text($r->author), UI::text($r->target), UI::text(Q::one($r->score()).' / 5'), UI::text(mb_strimwidth((string) $r->comment, 0, 60, '…')),
            UI::badge($r->detections ? count($r->detections).' détection(s)' : 'Aucune', $r->detections ? 'r' : 'g'), UI::badge($r->status, Review::TONES[$r->status]),
        ], UI::link($ins->url("/avis/{$r->reference}/moderation")), [['public', 'Publier', self::moderate($r, 'Publié')], ['visibility_off', 'Masquer', self::moderate($r, 'Masqué temporairement')], ['visibility', 'Examiner', UI::link($ins->url("/avis/{$r->reference}/moderation"))]]));

        return $this->render($ins, '/moderation', [
            ...$bar, 'alerts' => [...$bar['alerts'], UI::alert('info', 'smart_toy', 'Détection automatique', 'L’outil signale les contenus suspects ; il ne masque, ne rejette et ne supprime jamais un avis. Toute décision est humaine, motivée et tracée.')],
            'tiles' => [UI::tile('File de modération', $queues['pending']->count(), 'hourglass_top', 'a'), UI::tile('Détections à examiner', $open->count(), 'policy', $open->isNotEmpty() ? 'r' : 'g'), UI::tile('Réponses à modérer', $queues['replies']->count(), 'reply', 'b'), UI::tile('Avis masqués temporairement', $ins->reviewsScoped->where('status', 'Masqué temporairement')->count(), 'visibility_off', 'o')],
            'panels' => [UI::keyValues('rule', 'Règles de modération', config('admin.quality.moderation_rules')), UI::keyValues('category', 'Détections ouvertes par type', $open->countBy(fn (array $x): string => $x['d']['key'])->map(fn (int $n, string $k): array => [$k, (string) $n])->values()->all() ?: [['Aucune détection', '—']])],
            'toolbar' => [UI::button('Signalements', 'flag', UI::link($ins->url('/signalements')))],
            'tables' => [
                UI::table('hourglass_top', 'File de modération', $queue['label'], ['Référence', 'Date', 'Auteur', 'Cible', 'Note', 'Commentaire', 'Détections', 'Statut'], $queue['rows'], 1200, ['verified', 'File vide', 'Aucun avis en attente.'], $queue['pagination']),
                UI::table('policy', 'Détections automatiques', $detections->count().' détection(s)', ['Avis', 'Détection', 'Niveau', 'Détail', 'Statut'], $detections->map(fn (array $x): array => UI::row("{$x['r']->reference}-{$x['i']}", [
                    UI::text($x['r']->reference, true), UI::text($x['d']['key']), UI::badge($x['d']['level'], self::DETECTION_TONES[$x['d']['level']] ?? 'n'), UI::text($x['d']['detail']), UI::badge($x['d']['status'], $x['d']['status'] === 'À examiner' ? 'a' : 'g'),
                ], UI::link($ins->url("/avis/{$x['r']->reference}/moderation")), $x['d']['status'] === 'À examiner' ? [['task_alt', 'Confirmer', self::decideDetection($x['r'], $x['i'], $x['d'], 'Confirmée')], ['close', 'Écarter', self::decideDetection($x['r'], $x['i'], $x['d'], 'Écartée')]] : []))->all(), 1200, ['verified_user', 'Aucune détection']),
                UI::table('reply', 'Droits de réponse à modérer', $queues['replies']->count().' réponse(s)', ['Avis', 'Auteur de la réponse', 'Texte', 'Date', 'Statut'], $queues['replies']->map(fn (Review $r): array => UI::row($r->reference, [
                    UI::text($r->reference, true), UI::text($r->reply['author'] ?? '—'), UI::text($r->reply['text'] ?? ''), UI::text(Q::date($r->reply['date'] ?? null)), UI::badge($r->reply['status'], 'a'),
                ], UI::link($ins->url("/avis/{$r->reference}/reponse")), [['public', 'Publier', self::moderateReply($r, 'Publiée')], ['block', 'Rejeter', self::moderateReply($r, 'Rejetée')]]))->all(), 1000, ['task_alt', 'Aucune réponse en attente']),
            ],
        ], 'Modération des avis', 'Détection automatique, décision humaine motivée', [['Modération']]);
    }

    /**
     * Signalements d’avis.
     */
    public function reports(Request $request): Response
    {
        $ins = $this->insights($request);
        if (! Q::can('sig')) {
            return $this->denied($ins, 'Votre rôle ne permet pas de consulter les signalements.');
        }
        $list = $ins->search($ins->reviewsScoped->flatMap(fn (Review $r): array => array_map(fn (array $g): array => ['r' => $r, 'g' => $g], $r->reports)), fn (array $x): string => "{$x['r']->reference} {$x['g']['author']} {$x['g']['motif']} {$x['g']['comment']}")
            ->sortByDesc(fn (array $x): string => $x['g']['date'])->values();
        $p = UI::paginate($list, (int) $request->query('page', 1), fn (array $x): array => UI::row("{$x['r']->reference}-{$x['g']['id']}", [
            UI::text($x['r']->reference, true), UI::text($x['g']['author']), UI::text($x['g']['motif']), UI::text($x['g']['comment']), UI::text($x['g']['proof'] ?: '—'), UI::text(Q::date($x['g']['date'])),
            UI::badge($x['g']['status'], $x['g']['status'] === 'Traité' ? 'g' : 'a'), UI::text($x['g']['decision'] ?: '—'),
        ], UI::link($ins->url("/avis/{$x['r']->reference}/signalements")), $x['g']['status'] !== 'Traité' ? [['gavel', 'Décider', self::decideReport($x['r'], $x['g'])]] : [['visibility', 'Voir l’avis', UI::link($ins->url("/avis/{$x['r']->reference}"))]]));

        return $this->render($ins, '/signalements', [
            ...$this->filterBar($ins, '/signalements', ['promoteur', 'chantier', 'ouvrier', 'metier']), 'search' => true,
            'tiles' => [UI::tile('Signalements', $list->count(), 'flag', 'b'), UI::tile('Ouverts', $list->filter(fn (array $x): bool => $x['g']['status'] !== 'Traité')->count(), 'pending', 'a'), UI::tile('Traités', $list->filter(fn (array $x): bool => $x['g']['status'] === 'Traité')->count(), 'task_alt', 'g')],
            'tables' => [UI::table('flag', 'Signalements d’avis', $p['label'], ['Avis', 'Auteur', 'Motif', 'Commentaire', 'Preuve', 'Date', 'Statut', 'Décision'], $p['rows'], 1400, ['outlined_flag', 'Aucun signalement', 'Aucun avis signalé avec ces filtres.'], $p['pagination'])],
        ], 'Signalements', 'Faux avis, erreurs d’identité, propos offensants, conflits d’intérêts…', [['Modération', $ins->url('/moderation')], ['Signalements']]);
    }

    /**
     * Médiations des litiges.
     */
    public function mediations(Request $request): Response
    {
        $ins = $this->insights($request);
        if (! Q::can('lit')) {
            return $this->denied($ins, 'Votre rôle ne permet pas de consulter les médiations.');
        }
        $k = $ins->disputeKpis();
        $list = $ins->search($ins->disputesScoped->filter(fn (Dispute $d): bool => $d->mediation['status'] !== 'Non proposée'), fn (Dispute $d): string => "{$d->reference} {$d->subject} {$d->mediation['mediator']} {$d->claimant['name']} {$d->respondent['name']}");
        $t = Q::today()->format('Y-m-d');
        $p = UI::paginate($list, (int) $request->query('page', 1), function (Dispute $d) use ($ins, $t): array {
            $m = $d->mediation;
            $next = collect($m['calendar'])->first(fn (array $c): bool => $c['date'] >= $t);
            $last = last($m['proposals']) ?: null;
            $go = UI::link($ins->url("/litiges/{$d->reference}/mediation"));

            return UI::row($d->reference, [
                UI::text($d->reference, true), UI::text($d->subject), UI::text($m['mediator']), UI::badge($m['status'], $m['status'] === 'Accord validé' ? 'g' : (in_array($m['status'], ['Échec', 'Refusée'], true) ? 'r' : 'p')),
                UI::text($d->claimant['name'].($m['claimant_accepts'] ? ' ✓' : '')), UI::text($d->respondent['name'].($m['respondent_accepts'] ? ' ✓' : '')), UI::text($next ? Q::date($next['date'])." {$next['time']}" : '—'),
                UI::text($last['text'] ?? '—'), $d->amount ? UI::text(Q::money($d->amount)) : UI::text('—', false, true),
            ], $go, [['visibility', 'Ouvrir', $go]]);
        });

        return $this->render($ins, '/mediations', [
            ...$this->filterBar($ins, '/mediations', ['promoteur', 'chantier', 'ouvrier', 'categorie', 'responsable']), 'search' => true,
            'tiles' => [UI::tile('Médiations', $list->count(), 'handshake', 'b'), UI::tile('En cours', $k['mediating'], 'forum', 'p'), UI::tile('Taux de médiation', Q::percent($k['mediationRate']), 'percent', 'b', ['sub' => "Dénominateur : {$k['mediationDen']} litiges éligibles"]), UI::tile('Taux d’accord amiable', Q::percent($k['agreementRate']), 'verified', 'g', ['sub' => "Dénominateur : {$k['agreementDen']} médiations terminées"])],
            'tables' => [UI::table('handshake', 'Médiations', $p['label'], ['Litige', 'Objet', 'Médiateur', 'Statut', 'Demandeur', 'Partie adverse', 'Prochaine séance', 'Dernière proposition', 'Montant contesté'], $p['rows'], 1600, ['handshake', 'Aucune médiation'], $p['pagination'])],
        ], 'Médiations', 'Proposition, acceptation, échanges, propositions, accord ou échec', [['Médiations']]);
    }

    /**
     * Recours contre les décisions (la décision initiale n’est jamais supprimée).
     */
    public function appeals(Request $request): Response
    {
        $ins = $this->insights($request);
        if (! Q::can('lit')) {
            return $this->denied($ins, 'Votre rôle ne permet pas de consulter les recours.');
        }
        $k = $ins->disputeKpis();
        $list = $ins->disputesScoped->flatMap(fn (Dispute $d): array => array_map(fn (array $a, int $i): array => ['d' => $d, 'a' => $a, 'i' => $i], $d->appeals, array_keys($d->appeals)))->values();
        $p = UI::paginate($list, (int) $request->query('page', 1), function (array $x) use ($ins): array {
            $go = UI::link($ins->url("/litiges/{$x['d']->reference}/recours"));

            return UI::row($x['a']['ref'], [
                UI::text($x['a']['ref'], true), UI::text($x['d']->reference), UI::text($x['a']['by']), UI::text($x['a']['motif']), UI::text(Q::date($x['a']['date'])), UI::text($x['d']->decision ? "{$x['d']->decision['result']} (conservée)" : '—'),
                UI::badge($x['a']['status'], DisputeController::appealTone($x['a']['status'])), UI::text($x['a']['outcome'] ?: '—'),
            ], $go, [...DisputeController::appealActions($x['d'], $x['i'], $x['a']), ['visibility', 'Ouvrir', $go]]);
        });

        return $this->render($ins, '/recours', [
            ...$this->filterBar($ins, '/recours', ['promoteur', 'chantier', 'categorie']),
            'tiles' => [UI::tile('Recours', $list->count(), 'replay', 'b'), UI::tile('En cours', $list->filter(fn (array $x): bool => in_array($x['a']['status'], ['Soumis', 'Recevabilité en cours', 'Recevable', 'Réexamen'], true))->count(), 'pending', 'a'), UI::tile('Taux de recours', Q::percent($k['appealRate']), 'percent', 'b', ['sub' => "Dénominateur : {$k['appealDen']} décisions rendues"])],
            'tables' => [UI::table('replay', 'Recours', $p['label'], ['Recours', 'Litige', 'Auteur', 'Motif', 'Date', 'Décision initiale', 'Statut', 'Issue'], $p['rows'], 1500, ['replay', 'Aucun recours'], $p['pagination'])],
        ], 'Recours', 'Contestations des décisions · la décision initiale n’est jamais supprimée', [['Recours']]);
    }

    /**
     * Rapports de qualité et de résolution, analyse des causes récurrentes.
     */
    public function reportsCatalog(Request $request): Response
    {
        $ins = $this->insights($request);
        if (! Q::can('export') && ! Q::can('lit')) {
            return $this->denied($ins, 'Votre rôle ne permet pas de consulter les rapports.');
        }
        $reports = new QualityReports($ins);
        $counts = $reports->counts();
        $file = fn (string $id, string $format): array => self::allowed('export', UI::download($ins->url('/rapports/generer', ['rapport' => $id, 'format' => $format])), 'export');
        $axes = [['Catégories', fn (Dispute $d): string => $d->category], ['Métiers', fn (Dispute $d): string => $d->trade], ['Chantiers', fn (Dispute $d): string => $d->site->name], ['Promoteurs', fn (Dispute $d): string => $d->site->promoter_name], ['Ouvriers', fn (Dispute $d): string => $d->worker?->name ?? '—'], ['Périodes', fn (Dispute $d): string => FinanceLedger::monthLabel($d->opened_at->format('Y-m'), true)]];
        $causeRow = function (string $label, Collection $groups): array {
            $g = $groups->values();
            $keys = $groups->keys()->values();

            return UI::row($label, [UI::text($label, true), UI::text($keys[0] ?? 'Aucune donnée'), UI::text((string) ($g[0] ?? 0)), UI::text(isset($keys[1]) ? "{$keys[1]} ({$g[1]})" : '—'), UI::text(isset($keys[2]) ? "{$keys[2]} ({$g[2]})" : '—')]);
        };
        $period = $ins->disputesInPeriod;
        $negatives = $ins->a['counted']->filter(fn (Review $r): bool => $r->score() <= config('admin.quality.negative'));
        $bar = $this->filterBar($ins, '/rapports');

        return $this->render($ins, '/rapports', [
            ...$bar, 'alerts' => [...$bar['alerts'], UI::alert('info', 'insights', 'Lecture des causes récurrentes', 'Une corrélation (métier, chantier ou promoteur fréquemment concerné) ne constitue pas une preuve de faute : aucune conclusion automatique n’est tirée.')],
            'toolbar' => [UI::button('Exporter la synthèse', 'download', $file('synthese', 'Excel'), 'primary')],
            'tables' => [
                UI::table('summarize', 'Rapports de qualité et de résolution', count(QualityReports::LISTED)." rapports · {$ins->period['range']}", ['Rapport', 'Contenu', 'Formats'], array_map(fn (string $id): array => UI::row($id, [UI::text(QualityReports::CATALOG[$id], true), UI::text("{$counts[$id]} ligne(s)"), UI::text('PDF · Excel · CSV')], $file($id, 'Excel'), [['picture_as_pdf', 'PDF', $file($id, 'PDF')], ['table_view', 'Excel', $file($id, 'Excel')], ['description', 'CSV', $file($id, 'CSV')]]), QualityReports::LISTED), 900),
                UI::table('hub', 'Analyse des causes récurrentes', 'Litiges de la période · aucune conclusion automatique de faute', ['Axe', 'Le plus concerné', 'Litiges', 'Deuxième', 'Troisième'], [
                    ...array_map(fn (array $axis): array => $causeRow($axis[0], QualityReports::groups($period, $axis[1])->map(fn (Collection $g): int => $g->count())), $axes),
                    $causeRow('Causes des retards', $period->filter(fn (Dispute $d): bool => (bool) preg_match('/Retard|Abandon/u', $d->category))->countBy('subcategory')->sortDesc()),
                    $causeRow('Causes des remboursements', $period->filter(fn (Dispute $d): bool => collect($d->financial_requests)->contains(fn (array $f): bool => str_contains($f['type'], 'Rembours')))->countBy('category')->sortDesc()),
                    $causeRow('Causes des remplacements', $period->filter(fn (Dispute $d): bool => (bool) $d->replacements)->countBy('category')->sortDesc()),
                    $causeRow('Causes des avis négatifs', $negatives->countBy(fn (Review $r): string => $r->negatives ?: 'Non précisé')->sortDesc()),
                ], 1100),
            ],
        ], 'Rapports', 'Qualité des prestations, modération, résolution des litiges et exposition financière', [['Rapports']]);
    }

    /**
     * Génère un rapport (période et filtres de la requête).
     */
    public function generate(Request $request): FileResponse
    {
        abort_unless(Q::can('export'), 403, Q::denied('export'));
        $data = $request->validate(['rapport' => ['required', Rule::in(array_keys(QualityReports::CATALOG))], 'format' => ['required', Rule::in(FinanceReports::FORMATS)]]);

        return (new QualityReports($this->insights($request)))->file($data['rapport'], $data['format']);
    }

    public function notifications(Request $request): Response
    {
        $ins = $this->insights($request);
        $disputes = Dispute::query()->pluck('reference')->flip();
        $reviews = Review::query()->pluck('reference')->flip();
        $p = UI::paginate(QualityNotification::query()->latest('sent_at')->latest('id')->get(), (int) $request->query('page', 1), fn (QualityNotification $n): array => UI::row((string) $n->id, [
            UI::text($n->sent_at->format('d/m/Y H:i')), UI::text($n->recipient, true), UI::text($n->channel), UI::text($n->content), UI::text($n->expected_action), UI::badge($n->status, ['Échec d’envoi' => 'r', 'Lue' => 'g'][$n->status] ?? 'b'),
        ], match (true) {
            isset($disputes[$n->reference]) => UI::link($ins->url("/litiges/{$n->reference}")),
            isset($reviews[$n->reference]) => UI::link($ins->url("/avis/{$n->reference}")),
            default => null,
        }));

        return $this->render($ins, '/notifications', [
            'tables' => [UI::table('notifications', 'Notifications', $p['label'], ['Date', 'Destinataire', 'Canal', 'Contenu', 'Action attendue', 'Statut'], $p['rows'], 1300, ['notifications_off', 'Aucune notification'], $p['pagination'])],
        ], 'Notifications', 'Avis, droits de réponse, réclamations, litiges, médiations, décisions et SLA', [['Notifications']]);
    }

    public function audit(Request $request): Response
    {
        $ins = $this->insights($request);
        if (! Q::can('audit')) {
            return $this->denied($ins, 'Votre rôle ne permet pas de consulter l’audit.');
        }
        $events = QualityActivity::query()->latest('occurred_at')->latest('id')->get();

        return $this->render($ins, '/audit', [
            'tiles' => [
                UI::tile('Événements tracés', $events->count(), 'history', 'b'), UI::tile('Décisions et statuts', $events->whereIn('category', ['statut', 'validation', 'publication'])->count(), 'gavel', 'o'),
                UI::tile('Consultations sensibles', $events->filter(fn (QualityActivity $e): bool => $e->category === 'controle' && str_contains($e->action, 'consulté'))->count(), 'visibility', 'n'),
                UI::tile('Exports', $events->filter(fn (QualityActivity $e): bool => $e->category === 'document' && str_contains($e->action, 'export'))->count(), 'download', 'b'),
            ],
            'timeline' => $events->take(300)->map(fn (QualityActivity $e): array => self::activity($e))->all(),
        ], 'Journal d’audit Avis & Litiges', 'Date, utilisateur, rôle, action, ancienne et nouvelle valeur, motif, origine et IP', [['Audit']]);
    }
}
