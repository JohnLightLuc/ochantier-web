<?php

namespace App\Http\Controllers;

use App\Models\Claim;
use App\Models\Dispute;
use App\Support\PageBlocks as UI;
use App\Support\QualityInsights as Q;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * Réclamations : demandes initiales des promoteurs et des ouvriers, traitées simplement ou transformées en litige
 * après qualification.
 */
class ClaimController extends QualityPageController
{
    public function index(Request $request): Response
    {
        $ins = $this->insights($request);
        if (! Q::can('lit')) {
            return $this->denied($ins, 'Votre rôle ne permet pas de consulter les réclamations.');
        }
        $status = (string) $request->query('statut', '');
        $t = Q::today();
        $list = $ins->search($ins->claimsScoped->filter(fn (Claim $c): bool => $ins->in($c->date->format('Y-m-d')) && ($status === '' || $c->status === $status)), fn (Claim $c): string => implode(' ', [$c->reference, $c->claimant['name'], $c->other_party['name'], $c->site->name, $c->subject, $c->assignment_reference]));
        $p = UI::paginate($list, (int) $request->query('page', 1), function (Claim $c) use ($ins, $t): array {
            $left = (int) $t->diffInDays($c->due_date, false);
            $go = UI::link($ins->url("/reclamations/{$c->reference}"));

            return UI::row($c->reference, [
                UI::text($c->reference, true), UI::stacked($c->claimant['name'], $c->claimant['role']), UI::stacked($c->other_party['name'], $c->other_party['role']), UI::text($c->site->name), UI::text($c->assignment_reference),
                UI::text($c->category), UI::text($c->subject), UI::text(Q::date($c->date->format('Y-m-d'))), UI::badge($c->priority, Dispute::PRIORITY_TONES[$c->priority] ?? 'n'), UI::text($c->owner),
                UI::badge(! $c->isOpen() ? 'Terminé' : ($left < 0 ? 'Dépassé de '.-$left.' j' : "{$left} j"), ! $c->isOpen() ? 'n' : ($left < 0 ? 'r' : 'g')), UI::badge($c->status, Claim::TONES[$c->status] ?? 'n'),
            ], $go, [['visibility', 'Ouvrir', $go], ['chat', 'Contacter', self::contact($c->reference, [$c->claimant['name'], $c->other_party['name']])]]);
        });

        return $this->render($ins, '/reclamations', [
            ...$this->filterBar($ins, '/reclamations', ['chantier', 'commune', 'categorie'], [['key' => 'statut', 'label' => 'Statut', 'value' => $status, 'options' => [['value' => '', 'label' => 'Statut : tous'], ...array_map(fn (string $s): array => ['value' => $s, 'label' => $s], Claim::STATUSES)]]], null, false), 'search' => true,
            'tiles' => [
                UI::tile('Réclamations', $list->count(), 'support_agent', 'b'), UI::tile('À qualifier', $list->filter(fn (Claim $c): bool => in_array($c->status, ['Reçue', 'Soumise', 'À qualifier'], true))->count(), 'rule', 'a'),
                UI::tile('Traitées sans litige', $list->filter(fn (Claim $c): bool => in_array($c->status, ['Résolue', 'Clôturée'], true))->count(), 'task_alt', 'g', ['sub' => 'Résolues ou clôturées sans procédure contradictoire']),
                UI::tile('Transformées en litige', $list->where('status', 'Transformée en litige')->count(), 'gavel', 'p'),
            ],
            'toolbar' => [self::newClaimButton(null, 'primary'), UI::button('Exporter', 'download', self::allowed('export', UI::download($ins->url('/rapports/generer', ['rapport' => 'reclamations', 'format' => 'Excel'])), 'export'))],
            'tables' => [UI::table('support_agent', 'Réclamations · '.mb_strtolower($ins->period['label']), $p['label'], ['Référence', 'Demandeur', 'Partie concernée', 'Chantier', 'Affectation', 'Catégorie', 'Objet', 'Date', 'Priorité', 'Responsable', 'Délai', 'Statut'], $p['rows'], 1900, ['inbox', 'Aucune réclamation', 'Aucune réclamation sur la période et les filtres.'], $p['pagination'])],
        ], 'Réclamations', 'Demandes initiales : traitement simple ou transformation en litige après qualification', [['Réclamations']]);
    }

    public function show(Request $request, Claim $claim): Response
    {
        $ins = $this->insights($request);
        if (! Q::can('lit')) {
            return $this->denied($ins, 'Votre rôle ne permet pas de consulter les réclamations.');
        }
        $c = $claim->load('site', 'dispute');
        $d = $c->dispute;
        $url = "/avis-litiges/reclamations/{$c->reference}";
        $buttons = match (true) {
            $c->isOpen() => [
                UI::button('Qualifier', 'rule', self::allowed('qualif', UI::form("Qualifier la réclamation {$c->reference}", '', 'rule', 'Valider', "{$url}/qualification", [
                    ['name' => 'outcome', 'label' => 'Issue', 'type' => 'select', 'required' => true, 'options' => ['Traitement simple', 'Information demandée', 'Transformer en litige', 'Rejeter']],
                    ['name' => 'reason', 'label' => 'Motivation', 'type' => 'textarea', 'required' => true],
                ]), 'qualification'), 'primary'),
                UI::button('Résoudre sans litige', 'task_alt', UI::reason('Résoudre la réclamation', 'Traitement simple : aucune procédure contradictoire n’est ouverte.', 'task_alt', 'Résoudre', "{$url}/resolution", 'Solution apportée')),
                UI::button('Transformer en litige', 'gavel', self::allowed('qualif', UI::confirm("Transformer {$c->reference} en litige ?", 'Le litige est créé en recevabilité ; la procédure contradictoire se poursuit dans le dossier du litige.', 'gavel', 'Transformer', "{$url}/litige", 'warning'), 'qualification')),
            ],
            $c->status === 'Résolue' => [UI::button('Clôturer', 'lock', UI::confirm("Clôturer {$c->reference} ?", 'Clôture après résolution.', 'lock', 'Clôturer', "{$url}/cloture"))],
            (bool) $d => [UI::button("Ouvrir le litige {$d->reference}", 'gavel', UI::link($ins->url("/litiges/{$d->reference}")), 'primary')],
            default => [],
        };
        $profile = UI::profile("{$c->reference} · {$c->subject}", [$c->status, Claim::TONES[$c->status] ?? 'n'], ["Priorité {$c->priority}", Dispute::PRIORITY_TONES[$c->priority] ?? 'n'], $c->category, [
            ['person', 'Demandeur', "{$c->claimant['name']} ({$c->claimant['role']})"], ['person_off', 'Partie concernée', $c->other_party['name']], ['construction', 'Chantier', $c->site->name],
            ['assignment_ind', 'Affectation', $c->assignment_reference], ['event', 'Reçue le', Q::date($c->date->format('Y-m-d'))], ['badge', 'Responsable', $c->owner],
        ], $buttons, [['chat', 'Contacter', self::contact($c->reference, [$c->claimant['name'], $c->other_party['name']])], ['construction', 'Voir le chantier', UI::link("/chantiers/{$c->site->slug}")]]);

        return $this->render($ins, '/reclamations', [
            'profile' => $profile,
            'alerts' => [$d
                ? UI::alert('info', 'gavel', "Transformée en litige {$d->reference}", 'La procédure contradictoire se poursuit dans le litige.', 'Ouvrir', UI::link($ins->url("/litiges/{$d->reference}")))
                : UI::alert('info', 'info', 'Réclamation ≠ litige', 'Une réclamation ne devient un litige qu’après qualification.')],
            'panels' => [UI::keyValues('description', 'Réclamation', [['Catégorie', $c->category], ['Objet', $c->subject], ['Description', $c->description], ['Priorité', $c->priority], ['Échéance de qualification', Q::date($c->due_date->format('Y-m-d'))], ['Source', $c->review_reference ? "Avis {$c->review_reference}" : 'Application'], ['Traitement', $c->resolution ?: '—']])],
            'timeline' => $c->activities()->get()->map(fn ($e): array => self::activity($e))->all(),
        ], "Réclamation {$c->reference}", '', [['Réclamations', $ins->url('/reclamations')], [$c->reference]]);
    }
}
