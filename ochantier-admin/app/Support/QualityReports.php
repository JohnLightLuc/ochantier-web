<?php

namespace App\Support;

use App\Models\Dispute;
use App\Models\QualityActivity;
use App\Models\Review;
use App\Support\QualityInsights as Q;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rapports de qualité et de résolution (avis, modération, litiges, délais, SLA, médiations, décisions, recours,
 * exposition financière, causes récurrentes, récidive) et synthèse des indicateurs.
 */
class QualityReports
{
    /**
     * Rapport : clé → libellé.
     *
     * @var array<string, string>
     */
    public const CATALOG = [
        'synthese' => 'Synthèse Avis & Litiges', 'avis' => 'Synthèse des avis', 'notes-periode' => 'Notes par période', 'notes-metier' => 'Notes par métier', 'notes-chantier' => 'Notes par chantier',
        'notes-promoteur' => 'Notes par promoteur', 'avis-negatifs' => 'Avis négatifs', 'avis-signales' => 'Avis signalés', 'moderation' => 'Modération', 'reponses' => 'Droits de réponse',
        'litiges-categorie' => 'Litiges par catégorie', 'litiges-chantier' => 'Litiges par chantier', 'litiges-promoteur' => 'Litiges par promoteur', 'litiges-ouvrier' => 'Litiges par ouvrier',
        'delais' => 'Délais de résolution', 'sla' => 'Respect des SLA', 'mediations' => 'Médiations', 'decisions' => 'Décisions', 'recours' => 'Recours', 'exposition' => 'Exposition financière',
        'causes' => 'Causes récurrentes', 'recidive' => 'Taux de récidive', 'liste-avis' => 'Liste des avis', 'liste-litiges' => 'Liste des litiges', 'reclamations' => 'Réclamations',
    ];

    /**
     * Rapports affichés dans le catalogue de la page Rapports.
     *
     * @var array<int, string>
     */
    public const LISTED = ['avis', 'notes-periode', 'notes-metier', 'notes-chantier', 'notes-promoteur', 'avis-negatifs', 'avis-signales', 'moderation', 'reponses', 'litiges-categorie', 'litiges-chantier', 'litiges-promoteur', 'litiges-ouvrier', 'delais', 'sla', 'mediations', 'decisions', 'recours', 'exposition', 'causes', 'recidive'];

    public function __construct(public Q $insights) {}

    /**
     * Synthèse des indicateurs (avis et litiges).
     *
     * @return array<int, array{0: string, 1: string|int}>
     */
    public function synthesis(): array
    {
        $a = $this->insights->a;
        $k = $this->insights->disputeKpis();
        $cfg = config('admin.quality');
        $rate = fn (?float $v, int $den): string => Q::percent($v)." (dénominateur {$den})";

        return [
            ['Note moyenne globale', Q::two($a['avg'])." / 5 ({$a['n']} avis)"], ["Taux de satisfaction (seuil {$cfg['satisfaction']})", Q::percent(Q::ratio($a['sat'], $a['n']))], ['Avis reçus', $a['received']],
            ['Taux de collecte', Q::percent(Q::ratio($a['collected'], $a['elig']))." ({$a['collected']} / {$a['elig']})"], ['Avis vérifiés', $a['verified']], ['Avis négatifs', $a['neg']],
            ['Nouveaux litiges', $k['new']], ['Litiges actifs', $k['activeCount']], ['Litiges critiques', $k['critical']], ['Litiges en retard', $k['late']], ['Litiges résolus', $k['resolved']], ['Litiges clôturés', $k['closed']],
            ['Taux de résolution', $rate($k['resolutionRate'], $k['resolutionDen'])], ['Taux de médiation', $rate($k['mediationRate'], $k['mediationDen'])], ['Taux d’accord amiable', $rate($k['agreementRate'], $k['agreementDen'])],
            ['Taux de recours', $rate($k['appealRate'], $k['appealDen'])], ['Taux de réouverture', $rate($k['reopenRate'], $k['reopenDen'])], ['Taux de récurrence des parties', $rate($k['recurrenceRate'], $k['recurrenceDen'])],
            ['Délai médian de première réponse', Q::one($k['firstMedian']).' h'], ['Délai médian de qualification', Q::one($k['qualificationMedian']).' j'], ['Délai médian de résolution', Q::one($k['resolutionMedian']).' j'],
            ['Délai médian d’exécution', Q::one($k['executionMedian']).' j'], ['Exposition financière brute', Q::money($k['exposure'])], ['Exposition financière nette', Q::money($k['exposureNet'])],
            ['Fonds suspendus', Q::money($k['suspended'])], ['Montant exécuté', Q::money($k['done'])],
        ];
    }

    /**
     * Regroupe des éléments par clé (du groupe le plus fourni au moins fourni).
     *
     * @param  Collection<int, mixed>  $items
     * @return Collection<string, Collection<int, mixed>>
     */
    public static function groups(Collection $items, callable $by): Collection
    {
        return $items->groupBy(fn ($x): string => (string) ($by($x) ?: '—'))->sortByDesc(fn (Collection $g): int => $g->count());
    }

    /**
     * Colonnes et lignes d’un rapport.
     *
     * @return array{0: array<int, string>, 1: array<int, array<int, mixed>>}
     */
    public function build(string $id): array
    {
        $ins = $this->insights;
        $cfg = config('admin.quality');
        $counted = $ins->a['counted'];
        $period = $ins->disputesInPeriod;
        $scoped = $ins->disputesScoped;
        $d = fn ($date): string => $date ? Q::date(is_string($date) ? $date : $date->format('Y-m-d')) : '—';
        $avg = fn (Collection $x): string => $x->isEmpty() ? '—' : Q::two($x->avg(fn (Review $r): ?float => $r->score()));
        $notes = fn (callable $by): array => [['Groupe', 'Avis', 'Note moyenne', 'Satisfaction'], self::groups($counted, $by)->map(fn (Collection $x, string $k): array => [$k, $x->count(), $avg($x), Q::percent(Q::ratio($x->filter(fn (Review $r): bool => $r->score() >= $cfg['satisfaction'])->count(), $x->count()))])->values()->all()];
        $disputes = fn (callable $by): array => [['Groupe', 'Litiges', 'Actifs', 'Montant contesté', 'Résolus'], self::groups($period, $by)->map(fn (Collection $x, string $k): array => [$k, $x->count(), $x->filter(fn (Dispute $l): bool => $l->isActive())->count(), $x->sum('amount'), $x->filter(fn (Dispute $l): bool => (bool) $l->resolved_at)->count()])->values()->all()];
        $active = $scoped->filter(fn (Dispute $l): bool => $l->isActive());

        return match ($id) {
            'avis' => [['Indicateur', 'Valeur'], array_slice($this->synthesis(), 0, 6)],
            'notes-periode' => $notes(fn (Review $r): string => FinanceLedger::monthLabel($r->date->format('Y-m'), true)),
            'notes-metier' => $notes(fn (Review $r): string => $r->trade),
            'notes-chantier' => $notes(fn (Review $r): string => $r->site->name),
            'notes-promoteur' => $notes(fn (Review $r): string => $r->promoter_name),
            'avis-negatifs' => [['Référence', 'Cible', 'Chantier', 'Note', 'Commentaire'], $counted->filter(fn (Review $r): bool => $r->score() <= $cfg['negative'])->map(fn (Review $r): array => [$r->reference, $r->target, $r->site->name, Q::one($r->score()), $r->comment])->values()->all()],
            'avis-signales' => [['Référence', 'Motif', 'Auteur', 'Statut'], $ins->reviewsScoped->flatMap(fn (Review $r): array => array_map(fn (array $g): array => [$r->reference, $g['motif'], $g['author'], $g['status']], $r->reports))->values()->all()],
            'moderation' => [['Référence', 'Décision', 'Motif', 'Règle', 'Par', 'Date'], $ins->reviewsScoped->filter(fn (Review $r): bool => (bool) $r->moderation)->map(fn (Review $r): array => [$r->reference, $r->moderation['decision'], $r->moderation['motif'], $r->moderation['rule'], $r->moderation['by'], $d($r->moderation['date'])])->values()->all()],
            'reponses' => [['Référence', 'Auteur', 'Statut', 'Date'], $ins->reviewsScoped->filter(fn (Review $r): bool => $r->reply['status'] !== 'Non exercé')->map(fn (Review $r): array => [$r->reference, $r->reply['author'] ?? '—', $r->reply['status'], $d($r->reply['date'] ?? null)])->values()->all()],
            'litiges-categorie' => $disputes(fn (Dispute $l): string => $l->category),
            'litiges-chantier' => $disputes(fn (Dispute $l): string => $l->site->name),
            'litiges-promoteur' => $disputes(fn (Dispute $l): string => $l->site->promoter_name),
            'litiges-ouvrier' => $disputes(fn (Dispute $l): string => $l->worker?->name ?? '—'),
            'delais' => [['Litige', 'Ouverture', 'Résolution', 'Durée (j)'], [...$scoped->filter(fn (Dispute $l): bool => (bool) $l->resolved_at)->map(fn (Dispute $l): array => [$l->reference, $d($l->opened_at), $d($l->resolved_at), (int) $l->opened_at->diffInDays($l->resolved_at)])->values()->all(), ['Médiane', '', '', Q::one($ins->disputeKpis()['resolutionMedian'])]]],
            'sla' => [['Litige', 'Phase', 'Échéance', 'Statut SLA'], $active->map(function (Dispute $l) use ($d): array {
                $s = $l->sla();

                return [$l->reference, $s['phase'], $d($s['due']), $s['status']];
            })->values()->all()],
            'mediations' => [['Litige', 'Médiateur', 'Statut'], $scoped->filter(fn (Dispute $l): bool => $l->mediation['status'] !== 'Non proposée')->map(fn (Dispute $l): array => [$l->reference, $l->mediation['mediator'], $l->mediation['status']])->values()->all()],
            'decisions' => [['Litige', 'Décision', 'Approbateur', 'Date'], $scoped->filter(fn (Dispute $l): bool => ($l->decision['status'] ?? null) === 'Rendue')->map(fn (Dispute $l): array => [$l->reference, $l->decision['result'], $l->decision['approver'], $d($l->decision['date'])])->values()->all()],
            'recours' => [['Recours', 'Litige', 'Motif', 'Statut'], $scoped->flatMap(fn (Dispute $l): array => array_map(fn (array $a): array => [$a['ref'], $l->reference, $a['motif'], $a['status']], $l->appeals))->values()->all()],
            'exposition' => (function () use ($active, $ins): array {
                $k = $ins->disputeKpis();

                return [['Litige', 'Montant contesté', 'Fonds suspendus', 'Exposition nette', 'Exécuté'], [...$active->map(function (Dispute $l): array {
                    $e = $l->exposure();

                    return [$l->reference, $l->amount, $e['suspended'], $e['net'], $e['done']];
                })->values()->all(), ['Total', $k['exposure'], $k['suspended'], $k['exposureNet'], $k['done']]]];
            })(),
            'causes' => $disputes(fn (Dispute $l): string => "{$l->category} · {$l->trade}"),
            'recidive' => [['Partie', 'Litiges'], $scoped->flatMap(fn (Dispute $l): array => [$l->claimant['name'], $l->respondent['name']])->countBy()->filter(fn (int $n): bool => $n > 1)->map(fn (int $n, string $k): array => [$k, $n])->values()->all()],
            'liste-avis' => [['Référence', 'Date', 'Auteur', 'Cible', 'Type', 'Chantier', 'Affectation', 'Note globale', 'Statut', 'Vérification', 'Inclus dans la moyenne'], $ins->reviewsInPeriod->map(fn (Review $r): array => [$r->reference, $d($r->date), $r->author, $r->target, $r->target_type, $r->site->name, $r->assignment_reference, Q::one($r->score()), $r->status, $r->verification, $r->isCounted() ? 'Oui' : 'Non'])->values()->all()],
            'liste-litiges' => [['Référence', 'Ouverture', 'Demandeur', 'Partie mise en cause', 'Chantier', 'Catégorie', 'Montant contesté', 'Priorité', 'Gravité', 'Responsable', 'Statut', 'SLA'], $scoped->map(fn (Dispute $l): array => [$l->reference, $d($l->opened_at), $l->claimant['name'], $l->respondent['name'], $l->site->name, $l->category, $l->amount, $l->priority, $l->severity, $l->owner ?: 'Non affecté', $l->status, $l->sla()['text']])->values()->all()],
            'reclamations' => [['Référence', 'Demandeur', 'Partie concernée', 'Chantier', 'Catégorie', 'Objet', 'Date', 'Priorité', 'Responsable', 'Statut'], $ins->claimsScoped->map(fn ($c): array => [$c->reference, $c->claimant['name'], $c->other_party['name'], $c->site->name, $c->category, $c->subject, $d($c->date), $c->priority, $c->owner, $c->status])->values()->all()],
            default => [['Indicateur', 'Valeur'], $this->synthesis()],
        };
    }

    /**
     * Fichier d’un rapport (période, filtres, auteur, sources et paramètres en en-tête), tracé dans l’audit.
     */
    public function file(string $id, string $format): Response
    {
        [$columns, $rows] = $this->build($id);
        $name = self::CATALOG[$id];
        $ins = $this->insights;
        $cfg = config('admin.quality');
        $meta = [
            ['Rapport', $name], ['Période', "{$ins->period['range']} ({$ins->period['label']})"], ['Filtres', $ins->filterSummary() ?: 'Aucun filtre'], ['Date de génération', now()->format('d/m/Y H:i')],
            ['Utilisateur', (auth()->user()?->name ?? 'Système').' · '.Q::role()], ['Devise', 'FCFA'], ['Sources', 'Avis & Litiges · Paiements · Pointages · Affectations'],
            ['Paramètres', "Pondérations {$cfg['weights_version']} · seuil de satisfaction {$cfg['satisfaction']} / 5 · m = {$cfg['bayes_m']}"],
            ['Confidentialité', Q::can('notes') ? 'Notes internes exclues de l’export' : 'Données confidentielles masquées'],
        ];
        QualityActivity::record(null, 'document', "a exporté « {$name} »", null, $format, count($rows).' ligne(s) · '.($ins->filterSummary() ?: 'sans filtre'));

        return FinanceReports::file($name, $format, $meta, $columns, $rows);
    }

    /**
     * Nombre de lignes de chaque rapport du catalogue.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        return collect(self::LISTED)->mapWithKeys(fn (string $id): array => [$id => count($this->build($id)[1])])->all();
    }
}
