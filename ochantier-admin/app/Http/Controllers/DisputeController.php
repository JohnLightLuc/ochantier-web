<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Dispute;
use App\Models\Payment;
use App\Models\Worker;
use App\Support\PageBlocks as UI;
use App\Support\QualityInsights as Q;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * Litiges : liste (vues, tri par échéance SLA, affectation groupée), ouverture et dossier à douze onglets (vue
 * d’ensemble, parties, chronologie, déclarations, preuves, pointages, paiements, médiation, décision, exécution,
 * recours, historique). Chaque action est disponible selon l’étape et le rôle.
 */
class DisputeController extends QualityPageController
{
    public const VIEWS = [['', 'Litiges de la période'], ['new', 'Nouveaux litiges'], ['act', 'Litiges actifs'], ['crit', 'Litiges critiques'], ['late', 'Litiges en retard'], ['soon', 'SLA bientôt dépassé'], ['res', 'Litiges résolus'], ['fin', 'Avec exposition financière'], ['susp', 'Avec fonds suspendus'], ['all', 'Tous les litiges']];

    public const TABS = ['' => 'Vue d’ensemble', 'parties' => 'Parties', 'chronologie' => 'Chronologie', 'declarations' => 'Déclarations', 'preuves' => 'Preuves', 'pointages' => 'Pointages', 'paiements' => 'Paiements', 'mediation' => 'Médiation', 'decision' => 'Décision', 'execution' => 'Exécution', 'recours' => 'Recours', 'historique' => 'Historique'];

    public const PROOF_TYPES = ['Photo', 'Vidéo', 'Document', 'Contrat', 'Facture', 'Reçu', 'Message', 'Signature', 'Pointage', 'Localisation', 'Rapport', 'Témoignage', 'Constat', 'Autre pièce'];

    public const MEASURES = ['Aucune mesure', 'Remboursement partiel', 'Remboursement total', 'Libération d’un paiement', 'Retenue justifiée', 'Ajustement créditeur', 'Ajustement débiteur', 'Remboursement d’une commission', 'Remplacement', 'Correction de pointage', 'Masquer l’avis', 'Restriction de compte (via Promoteurs ou Ouvriers)'];

    public const MEDIATION_STEPS = ['Non proposée', 'Proposée', 'En attente d’acceptation', 'Acceptée', 'En cours', 'Accord provisoire', 'Accord validé', 'Clôturée'];

    public static function appealTone(string $status): string
    {
        return match (true) {
            (bool) preg_match('/Non recevable|annulée/u', $status) => 'r',
            (bool) preg_match('/confirmée|modifiée|Clôturé/u', $status) => 'g',
            default => 'a',
        };
    }

    public function index(Request $request): Response
    {
        $ins = $this->insights($request);
        if (! Q::can('lit')) {
            return $this->denied($ins, 'Votre rôle ne permet pas de consulter les litiges.');
        }
        $view = (string) $request->query('vue', '');
        $k = $ins->disputeKpis();
        $base = in_array($view, ['', 'new', 'res'], true) ? ($view ? $ins->disputesScoped : $ins->disputesInPeriod) : $ins->disputesScoped;
        $list = $base->filter(fn (Dispute $d): bool => match ($view) {
            'new' => $ins->in($d->opened_at->format('Y-m-d')) && $d->status !== 'Brouillon',
            'act' => $d->isActive(),
            'crit' => $d->isActive() && ($d->priority === 'Critique' || $d->severity === 'Critique'),
            'late' => $d->isActive() && $d->sla()['status'] === 'En retard',
            'soon' => $d->isActive() && $d->sla()['status'] === 'Bientôt en retard',
            'res' => $d->resolved_at && $ins->in($d->resolved_at->format('Y-m-d')),
            'fin' => $d->isActive() && $d->exposure()['amount'] > 0,
            'susp' => $d->exposure()['suspended'] > 0,
            default => true,
        });
        $list = $ins->search($list, fn (Dispute $d): string => implode(' ', [$d->reference, $d->subject, $d->claimant['name'], $d->respondent['name'], $d->site->name, $d->category, $d->owner]));
        $list = match ($request->query('tri')) {
            'date' => $list->sortByDesc(fn (Dispute $d): string => $d->opened_at->format('Y-m-d')),
            'montant' => $list->sortByDesc('amount'),
            'priorite' => $list->sortBy(fn (Dispute $d): int => array_search($d->priority, array_reverse(Dispute::PRIORITIES), true)),
            default => $list->sortBy(fn (Dispute $d): int => $d->sla()['left'] ?? 999),
        };
        $selected = array_values(array_filter(explode(',', (string) $request->query('sel', ''))));
        $p = UI::paginate($list->values(), (int) $request->query('page', 1), function (Dispute $d) use ($ins, $selected): array {
            $on = in_array($d->reference, $selected, true);
            $row = $this->disputeRow($ins, $d, $on);
            $toggle = $on ? array_diff($selected, [$d->reference]) : [...$selected, $d->reference];
            array_unshift($row['acts'], ['icon' => $on ? 'check_box' : 'check_box_outline_blank', 'label' => $on ? 'Retirer de la sélection' : 'Sélectionner', 'action' => UI::link($ins->url('/litiges', [...$this->keep($ins), 'sel' => implode(',', $toggle)]))]);

            return $row;
        });
        $pageRefs = array_column($p['rows'], 'key');

        return $this->render($ins, '/litiges', [
            ...$this->filterBar($ins, '/litiges', ['promoteur', 'chantier', 'ouvrier', 'metier', 'commune', 'categorie', 'priorite', 'gravite', 'statut_litige', 'responsable', 'impact'], [
                $this->select($ins, 'vue', 'Vue', self::VIEWS, 'Vue : '), $this->select($ins, 'tri', 'Tri', [['', 'échéance SLA'], ['date', 'date d’ouverture'], ['montant', 'montant contesté'], ['priorite', 'priorité']], 'Trier : '),
            ]), 'search' => true,
            'tiles' => [
                UI::tile('Litiges affichés', $list->count(), 'gavel', 'b'), UI::tile('Actifs', $k['activeCount'], 'pending_actions', 'a'), UI::tile('En retard', $k['late'], 'timer_off', $k['late'] ? 'r' : 'g'),
                UI::tile('Exposition brute', Q::money($k['exposure']), 'account_balance_wallet', 'o', ['sub' => 'Nette '.Q::money($k['exposureNet']).' · potentielle']), UI::tile('Fonds suspendus', Q::money($k['suspended']), 'lock', 'n'),
            ],
            'toolbar' => [
                UI::button('Nouveau litige', 'add', Q::can('create') ? UI::link('/avis-litiges/litiges/nouveau') : self::deny('création'), 'primary'),
                UI::button('Sélectionner la page', 'select_all', UI::link($ins->url('/litiges', [...$this->keep($ins), 'sel' => implode(',', array_unique([...$selected, ...$pageRefs]))]))),
                UI::button('Affecter la sélection ('.count($selected).')', 'group_add', match (true) {
                    ! Q::can('assign') => self::deny('affectation groupée'),
                    ! $selected => UI::error('Sélectionnez au moins un litige.'),
                    default => UI::form('Affecter '.count($selected).' litige(s)', implode(', ', $selected), 'group_add', 'Affecter', '/avis-litiges/litiges/affectation', [['name' => 'owner', 'label' => 'Responsable', 'type' => 'select', 'required' => true, 'options' => config('admin.quality.owners'), 'full' => true]], 'primary', ['references' => $selected]),
                }),
                UI::button('Vider la sélection', 'deselect', UI::link($ins->url('/litiges', $this->keep($ins)))),
                UI::button('Exporter', 'download', self::allowed('export', UI::download($ins->url('/rapports/generer', ['rapport' => 'liste-litiges', 'format' => 'Excel'])), 'export')),
            ],
            'tables' => [UI::table('gavel', 'Litiges', $p['label'].($selected ? ' · '.count($selected).' sélectionné(s)' : ''), self::disputeColumns(), $p['rows'], 2600, ['search_off', 'Aucun résultat', 'Aucun litige ne correspond à la vue, à la période ou aux filtres.'], $p['pagination'])],
        ], 'Litiges', 'Procédure contradictoire, médiation, décision motivée et recours', [['Litiges']]);
    }

    /**
     * Paramètres de liste conservés (vue, tri, recherche, page).
     *
     * @return array<string, mixed>
     */
    private function keep(Q $ins): array
    {
        return array_intersect_key($ins->query, array_flip(['vue', 'tri', 'q', 'page']));
    }

    public function create(Request $request): Response
    {
        $ins = $this->insights($request);
        if (! Q::can('create')) {
            return $this->denied($ins, 'Votre rôle ne permet pas de créer un litige.');
        }
        $cfg = config('admin.quality');

        return $this->render($ins, '/litiges', [
            'alerts' => [UI::alert('info', 'info', 'Avant de créer un litige', 'Préférez une réclamation pour une demande simple. Le litige exige une procédure contradictoire : chaque partie est notifiée et peut répondre.')],
            'toolbar' => [UI::button('Remplir le formulaire', 'edit_note', self::newDispute(), 'primary'), UI::button('Retour aux litiges', 'arrow_back', UI::link($ins->url('/litiges')))],
            'panels' => [
                UI::keyValues('fact_check', 'Contrôles de recevabilité', [['Identité du demandeur', 'Compte authentifié'], ['Relation avec la prestation', 'Demandeur partie à la prestation'], ['Chantier et affectation', 'Existants'], ['Délai de réclamation', "{$cfg['claim_window']} jours après les faits (Paramètres)"], ['Description', '20 caractères minimum'], ['Pièces minimales', 'Au moins une pièce'], ['Doublon', 'Aucun litige actif identique'], ['Décision définitive', 'Aucune décision identique déjà rendue']]),
                UI::keyValues('priority_high', 'Priorité automatique', [['Sécurité / risque humain', '+3'], ['Fraude', '+2'], ['Montant > 300 000 FCFA', '+2 (+1 au-delà de 100 000)'], ['Interruption du chantier', '+2'], ['Ancienneté > 30 j', '+1'], ['Récidive', '+1'], ['Barème', '≥ 5 Critique · 4 Urgente · 3 Élevée · 1-2 Normale · 0 Faible'], ['Ajustement', 'Modifiable par un utilisateur autorisé avec justification']]),
            ],
        ], 'Nouveau litige', 'Formulaire d’ouverture', [['Litiges', $ins->url('/litiges')], ['Nouveau']]);
    }

    /**
     * Actions du dossier : libellé, icône, action, disponibilité (et motif d’indisponibilité), aide.
     *
     * @return array<string, array{label: string, icon: string, action: array<string, mixed>, off: bool, why: string, hint: string}>
     */
    private function actions(Dispute $d, Q $ins): array
    {
        $cfg = config('admin.quality');
        $url = "/avis-litiges/litiges/{$d->reference}";
        $early = $d->isEarly();
        $final = $d->isFinal();
        $parties = [$d->claimant['name'], $d->respondent['name']];
        $t = Q::today();
        $decisionWhy = match (true) {
            ! $d->isQualified() => 'Litige non encore recevable.',
            ! $d->isResponded() && (! $d->qualified_at || $d->qualified_at->diffInDays($t) < $cfg['sla']['rep']) => "Procédure contradictoire en cours : réponse de la partie adverse attendue (ou délai de {$cfg['sla']['rep']} j non écoulé).",
            default => '',
        };
        $decisionRendered = ($d->decision['status'] ?? null) === 'Rendue';
        $openAppeal = collect($d->appeals)->contains(fn (array $a): bool => ! in_array($a['status'], ['Clôturé', 'Non recevable', 'Décision confirmée', 'Décision modifiée', 'Décision annulée'], true));
        $make = fn (string $label, string $icon, string $right, array $action, bool $off = false, string $why = 'Action indisponible à cette étape.', string $hint = ''): array => ['label' => $label, 'icon' => $icon, 'action' => $off ? UI::error($why) : self::allowed($right, $action, mb_strtolower($label)), 'off' => $off, 'why' => $why, 'hint' => $hint];
        $operations = Payment::query()->where('worker_id', $d->worker_id)->where('site_id', $d->site_id)->orderByDesc('date')->limit(8)->pluck('reference')->prepend($d->operation_reference)->unique()->values()->all();
        $attendances = $d->worker_id ? Attendance::query()->where('worker_id', $d->worker_id)->orderByDesc('date')->limit(10)->get(['reference', 'date'])->map(fn (Attendance $a): array => ['value' => $a->reference, 'label' => "{$a->reference} · ".$a->date->format('d/m/Y')])->all() : [];
        $financial = fn (string $preset = '', ?array $parent = null): array => UI::form('Demande vers Paiements', 'Avis & Litiges ne modifie aucun solde : la demande est exécutée par Paiements après approbation. Au-delà de '.number_format($cfg['double_approval'], 0, ',', ' ').' FCFA, une seconde approbation est requise.', 'request_quote', 'Transmettre', "{$url}/demandes-financieres", [
            ['name' => 'type', 'label' => 'Type', 'type' => 'select', 'required' => true, 'options' => $cfg['financial_types']],
            ['name' => 'amount', 'label' => 'Montant demandé (FCFA)', 'type' => 'number', 'required' => true],
            ['name' => 'operation', 'label' => 'Opération Paiements', 'type' => 'select', 'required' => true, 'options' => $operations],
            ['name' => 'motif', 'label' => 'Motif', 'type' => 'textarea', 'required' => true],
        ], 'primary', $parent ? ['parent' => $parent['ref']] : [], array_filter(['type' => $preset ?: $cfg['financial_types'][0], 'amount' => (string) ($parent['amount'] ?? ($d->amount ?: '')), 'operation' => $d->operation_reference]));
        $decision = $d->decision ?? [];

        return [
            'qualif' => $make('Qualifier la recevabilité', 'rule', 'qualif', UI::form("Recevabilité · {$d->reference}", 'Contrôles avant ouverture formelle. La non-recevabilité est motivée et ne supprime pas le dossier.', 'rule', 'Valider', "{$url}/recevabilite", [
                ...array_map(fn (string $k, string $label): array => ['name' => $k, 'label' => mb_strtoupper(mb_substr($label, 0, 1)).mb_substr($label, 1), 'type' => 'select', 'required' => true, 'options' => ['Conforme', 'Non conforme']], array_keys(Dispute::ADMISSIBILITY), Dispute::ADMISSIBILITY),
                ['name' => 'decision', 'label' => 'Décision', 'type' => 'select', 'required' => true, 'options' => ['Recevable · ouvrir la procédure contradictoire', 'Non recevable'], 'full' => true],
                ['name' => 'reason', 'label' => 'Motivation', 'type' => 'textarea', 'required' => true],
            ], 'primary', [], array_map(fn (bool $v): string => $v ? 'Conforme' : 'Non conforme', $d->admissibility)), ! $early, 'Recevabilité déjà traitée.', '9 contrôles avant ouverture formelle'),
            'assign' => $make('Affecter', 'person_add', 'assign', UI::form("Affecter le litige {$d->reference}", '', 'person_add', 'Affecter', "{$url}/affectation", [['name' => 'owner', 'label' => 'Responsable', 'type' => 'select', 'required' => true, 'options' => $cfg['owners'], 'full' => true]], 'primary', [], ['owner' => (string) $d->owner]), $final),
            'priority' => $make('Changer la priorité', 'priority_high', 'qualif', UI::form('Changer la priorité', 'Priorité automatique : '.($a = Dispute::autoPriority($d->category, $d->amount, $d->opened_at->format('Y-m-d'), false))['level']." ({$a['why']}).", 'priority_high', 'Modifier', "{$url}/priorite", [
                ['name' => 'priority', 'label' => 'Priorité', 'type' => 'select', 'required' => true, 'options' => Dispute::PRIORITIES], ['name' => 'severity', 'label' => 'Gravité (impact)', 'type' => 'select', 'required' => true, 'options' => Dispute::SEVERITIES],
                ['name' => 'reason', 'label' => 'Justification', 'type' => 'textarea', 'required' => true],
            ], 'primary', [], ['priority' => $d->priority, 'severity' => $d->severity]), $final),
            'info' => $make('Demander une information', 'help', 'qualif', UI::form('Demander une information', 'Le SLA de traitement est suspendu avec motif jusqu’à réception.', 'help', 'Envoyer', "{$url}/informations", [['name' => 'to', 'label' => 'Destinataire', 'type' => 'select', 'required' => true, 'options' => $parties, 'full' => true], ['name' => 'question', 'label' => 'Informations demandées', 'type' => 'textarea', 'required' => true]]), $final || $early),
            'infoOk' => $make('Informations reçues', 'mark_email_read', 'qualif', UI::post("{$url}/informations/recues"), $d->status !== 'Informations demandées'),
            'proof' => $make('Ajouter une preuve', 'upload_file', 'proof', UI::form('Déposer une preuve', '', 'upload_file', 'Déposer', "{$url}/preuves", [
                ['name' => 'title', 'label' => 'Titre', 'required' => true, 'full' => true], ['name' => 'type', 'label' => 'Type', 'type' => 'select', 'required' => true, 'options' => self::PROOF_TYPES],
                ['name' => 'by', 'label' => 'Déposée par', 'type' => 'select', 'required' => true, 'options' => [...$parties, 'Administration O’CHANTIER']], ['name' => 'facts_at', 'label' => 'Date des faits', 'type' => 'date', 'required' => true],
                ['name' => 'visibility', 'label' => 'Visibilité', 'type' => 'select', 'required' => true, 'options' => ['Parties', 'Une seule partie', 'Interne', 'Confidentiel']], ['name' => 'file', 'label' => 'Fichier', 'type' => 'file', 'required' => true],
                ['name' => 'comment', 'label' => 'Commentaire', 'type' => 'textarea'],
            ]), in_array($d->status, ['Clôturé', 'Archivé'], true)),
            'declare' => $make('Ajouter une déclaration', 'record_voice_over', 'lit', UI::form('Déclaration d’une partie', 'Dépôt effectué par la partie depuis son application ; l’administration enregistre la réception.', 'record_voice_over', 'Enregistrer', "{$url}/declarations", [
                ['name' => 'by', 'label' => 'Partie', 'type' => 'select', 'required' => true, 'options' => $parties], ['name' => 'type', 'label' => 'Type', 'type' => 'select', 'required' => true, 'options' => ['Déclaration initiale', 'Réponse', 'Observation complémentaire', 'Contestation d’une preuve', 'Proposition de règlement', 'Commentaire final']],
                ['name' => 'content', 'label' => 'Contenu', 'type' => 'textarea', 'required' => true], ['name' => 'visibility', 'label' => 'Visibilité', 'type' => 'select', 'required' => true, 'options' => ['Parties', 'Une seule partie', 'Interne']], ['name' => 'file', 'label' => 'Pièce', 'type' => 'file'],
            ]), $final),
            'response' => $make('Simulation · réponse de la partie adverse', 'smartphone', 'lit', UI::reason("Réponse de {$d->respondent['name']}", 'Simulation d’une réponse déposée depuis l’application.', 'smartphone', 'Soumettre', "{$url}/reponse", 'Réponse'), $d->status !== 'Réponse de la partie adverse attendue', 'Aucune réponse attendue à cette étape.', 'La partie mise en cause présente ses observations'),
            'contact' => $make('Contacter une partie', 'mail', 'lit', self::contact($d->reference, array_column($d->parties, 'name'))),
            'note' => $make('Ajouter une note interne', 'lock', 'notes', UI::reason('Note interne confidentielle', 'Jamais visible des parties.', 'lock', 'Ajouter', "{$url}/notes", 'Note')),
            'mediation' => $make('Proposer une médiation', 'handshake', 'med', UI::form('Proposer une médiation', '', 'handshake', 'Proposer', "{$url}/mediation", [
                ['name' => 'mediator', 'label' => 'Médiateur', 'type' => 'select', 'required' => true, 'options' => $cfg['mediators'], 'full' => true], ['name' => 'date', 'label' => 'Première séance', 'type' => 'date', 'required' => true],
                ['name' => 'place', 'label' => 'Lieu', 'type' => 'select', 'required' => true, 'options' => ['Visioconférence', 'Bureau O’CHANTIER Plateau', 'Sur le chantier']],
            ]), $final || $early || $d->mediation['status'] !== 'Non proposée' || in_array($d->category, $cfg['no_mediation'], true), in_array($d->category, $cfg['no_mediation'], true) ? 'Médiation non éligible pour cette catégorie.' : 'Médiation déjà engagée ou étape incompatible.', 'Recherche d’un accord amiable'),
            'financial' => $make('Demander une action financière', 'request_quote', 'fin', $financial(), $final || $early, 'Disponible après recevabilité.', 'Transmise à Paiements'),
            'suspend' => $make('Suspendre un paiement', 'pause_circle', 'fin', $financial('Suspension d’un paiement'), $final || $early, 'Disponible après recevabilité.'),
            'replacement' => $make('Demander un remplacement', 'swap_horiz', 'rempl', UI::form('Demande de remplacement', 'Transmise au module Affectations, qui exécute le remplacement.', 'swap_horiz', 'Transmettre', "{$url}/remplacements", [
                ['name' => 'motif', 'label' => 'Motif', 'type' => 'select', 'required' => true, 'options' => ['Indisponibilité', 'Abandon de chantier', 'Mauvaise exécution', 'Décision de litige']], ['name' => 'urgency', 'label' => 'Urgence', 'type' => 'select', 'required' => true, 'options' => ['Normale', 'Élevée', 'Urgente']],
                ['name' => 'date', 'label' => 'Date souhaitée', 'type' => 'date', 'required' => true], ['name' => 'transfer', 'label' => 'Transfert des dossiers', 'type' => 'select', 'required' => true, 'options' => ['Pointages et documents transférés au remplaçant', 'Sans transfert']],
            ]), $final || $early, 'Disponible après recevabilité.'),
            'attendance' => $make('Demander une correction de pointage', 'edit_calendar', 'ptc', UI::form('Demande de correction de pointage', 'La correction effective est réalisée dans Pointages selon son processus d’autorisation.', 'edit_calendar', 'Transmettre', "{$url}/corrections-pointage", [
                ['name' => 'attendance', 'label' => 'Pointage concerné', 'type' => 'select', 'required' => true, 'options' => $attendances ?: [['value' => $d->operation_reference, 'label' => "Pointage de référence · {$d->operation_reference}"]], 'full' => true],
                ['name' => 'old', 'label' => 'Valeur enregistrée', 'required' => true], ['name' => 'contested', 'label' => 'Valeur contestée', 'required' => true], ['name' => 'requested', 'label' => 'Correction demandée', 'required' => true], ['name' => 'proof', 'label' => 'Preuve', 'type' => 'file', 'required' => true],
            ]), $final || $early, 'Disponible après recevabilité.'),
            'prepare' => $make($d->decision ? 'Modifier le projet de décision' : 'Préparer une décision', 'edit_document', 'dec', UI::form("Projet de décision · {$d->reference}", 'Décision motivée : la seule note d’un avis ne peut fonder une décision.', 'edit_document', 'Enregistrer le projet', "{$url}/decision", [
                ['name' => 'facts', 'label' => 'Rappel des faits', 'type' => 'textarea', 'required' => true], ['name' => 'requests', 'label' => 'Demandes des parties', 'type' => 'textarea', 'required' => true],
                ['name' => 'retained', 'label' => 'Éléments retenus', 'type' => 'textarea', 'required' => true], ['name' => 'rejected', 'label' => 'Éléments écartés', 'type' => 'textarea'],
                ['name' => 'analysis', 'label' => 'Analyse', 'type' => 'textarea', 'required' => true], ['name' => 'rules', 'label' => 'Règles appliquées', 'required' => true, 'full' => true],
                ['name' => 'result', 'label' => 'Décision', 'type' => 'select', 'required' => true, 'options' => $cfg['results']], ['name' => 'measure', 'label' => 'Mesure ordonnée', 'type' => 'select', 'required' => true, 'options' => self::MEASURES],
                ['name' => 'delay', 'label' => 'Délai d’exécution', 'type' => 'select', 'required' => true, 'options' => ['7 jours', '15 jours', '30 jours']],
            ], 'primary', [], [
                'facts' => $decision['facts'] ?? $d->description, 'requests' => $decision['requests'] ?? '', 'retained' => $decision['retained'] ?? collect($d->proofs)->where('status', 'Vérifiée')->map(fn (array $x): string => "{$x['ref']} {$x['title']}")->implode(' ; '),
                'rejected' => $decision['rejected'] ?? '', 'analysis' => $decision['analysis'] ?? '', 'rules' => $decision['rules'] ?? 'Conditions générales art. 12 · Règlement des litiges art. 4 à 9', 'result' => $decision['result'] ?? $cfg['results'][0],
            ]), $final || $early || $decisionRendered, 'Décision déjà rendue ou étape incompatible.', 'Faits, preuves, analyse et règles'),
            'render' => $make(Q::can('approve') ? 'Approuver et rendre la décision' : 'Soumettre la décision à approbation', 'gavel', 'dec', $decisionWhy ? UI::error($decisionWhy) : UI::confirm("Rendre la décision {$d->reference} ?", 'Décision : '.($decision['result'] ?? '—').'. Mesure : '.($decision['measures'][0] ?? 'aucune').'. Les demandes sont transmises aux modules concernés ; les parties sont notifiées et peuvent former un recours.', 'gavel', Q::can('approve') ? 'Approuver et rendre' : 'Soumettre', "{$url}/decision/rendu"), ! $d->decision || $decisionRendered, 'Préparez d’abord la décision.', $decisionWhy ?: 'Décision motivée prête'),
            'done' => $make('Marquer l’exécution terminée', 'task_alt', 'close', UI::post("{$url}/execution"), ! in_array($d->status, ['En attente d’exécution', 'Accord trouvé', 'Décision rendue'], true), 'Aucune exécution en cours.'),
            'appeal' => $make('Ouvrir un recours', 'replay', 'rec', UI::form("Ouvrir un recours · {$d->reference}", "Délai : {$cfg['appeal_window']} jours après la décision du ".Q::date($decision['date'] ?? null).'. La décision initiale est conservée.', 'replay', 'Ouvrir le recours', "{$url}/recours", [
                ['name' => 'by', 'label' => 'Auteur (partie au litige)', 'type' => 'select', 'required' => true, 'options' => $parties], ['name' => 'motif', 'label' => 'Motif', 'type' => 'select', 'required' => true, 'options' => ['Erreur factuelle', 'Preuve non prise en compte', 'Nouvelle preuve', 'Erreur de procédure', 'Décision disproportionnée', 'Autre motif recevable']],
                ['name' => 'description', 'label' => 'Description', 'type' => 'textarea', 'required' => true], ['name' => 'file', 'label' => 'Éléments nouveaux ou erreur invoquée', 'type' => 'file'],
            ]), ! $decisionRendered || CarbonImmutable::parse($decision['date'])->diffInDays($t) > $cfg['appeal_window'] || $openAppeal, match (true) {
                ! $decisionRendered => 'Aucune décision rendue.',
                $openAppeal => 'Un recours est déjà en cours.',
                default => "Délai de recours de {$cfg['appeal_window']} j expiré.",
            }),
            'close' => $make('Clôturer', 'lock', 'close', UI::reason("Clôturer {$d->reference}", '', 'lock', 'Clôturer', "{$url}/cloture", 'Motif de clôture'), ! in_array($d->status, ['Résolu', 'Accord trouvé', 'Non recevable'], true), 'Clôture possible après résolution, accord ou non-recevabilité.'),
            'reopen' => $make('Rouvrir', 'lock_open', 'reopen', UI::reason("Rouvrir {$d->reference}", 'Autorisation du Responsable qualité & litiges requise. L’historique est conservé.', 'lock_open', 'Rouvrir', "{$url}/reouverture", 'Motif de réouverture', 'warning'), ! in_array($d->status, ['Clôturé', 'Résolu'], true)),
            'cancel' => $make('Annuler le litige', 'cancel', 'close', UI::reason("Annuler {$d->reference}", 'Le dossier est conservé et reste consultable.', 'cancel', 'Annuler le litige', "{$url}/annulation", 'Motif (retrait du demandeur…)', 'danger'), $final),
            'archive' => $make('Archiver', 'inventory_2', 'close', UI::confirm("Archiver {$d->reference} ?", 'Le dossier reste consultable.', 'inventory_2', 'Archiver', "{$url}/archivage"), ! in_array($d->status, ['Clôturé', 'Non recevable', 'Annulé'], true)),
            'site' => ['label' => 'Voir le chantier', 'icon' => 'construction', 'action' => UI::link("/chantiers/{$d->site->slug}"), 'off' => false, 'why' => '', 'hint' => ''],
            'payment' => ['label' => 'Voir le paiement concerné', 'icon' => 'credit_card', 'action' => Payment::query()->where('reference', $d->operation_reference)->exists() ? UI::link("/paiements/operations/{$d->operation_reference}") : UI::link('/paiements'), 'off' => false, 'why' => '', 'hint' => ''],
        ];
    }

    /**
     * Actions de médiation selon son statut ($propose : action « Proposer une médiation » du dossier).
     *
     * @param  array<string, mixed>  $propose
     * @return array<int, array<string, mixed>>
     */
    private function mediationActions(Dispute $d, array $propose): array
    {
        $m = $d->mediation;
        $url = "/avis-litiges/litiges/{$d->reference}/mediation";
        $parties = [$d->claimant['name'], $d->respondent['name']];
        $guard = fn (array $action): array => self::allowed('med', $action, 'médiation');
        $out = [];
        if ($m['status'] === 'Non proposée') {
            $out[] = UI::button('Proposer une médiation', 'handshake', $propose, 'primary');
        }
        if (in_array($m['status'], ['Proposée', 'En attente d’acceptation'], true)) {
            $out[] = UI::button('Enregistrer l’acceptation', 'how_to_reg', $guard(UI::form('Réponse à la proposition', '', 'how_to_reg', 'Enregistrer', "{$url}/acceptation", [['name' => 'who', 'label' => 'Partie', 'type' => 'select', 'required' => true, 'options' => $parties], ['name' => 'answer', 'label' => 'Réponse', 'type' => 'select', 'required' => true, 'options' => ['Acceptée', 'Refusée']]])), 'primary');
        }
        if (in_array($m['status'], ['Acceptée', 'En cours', 'Accord provisoire'], true)) {
            $out[] = UI::button('Ajouter un échange', 'forum', $guard(UI::reason('Échange de médiation', '', 'forum', 'Ajouter', "{$url}/echange", 'Compte rendu')));
            $out[] = UI::button('Ajouter une proposition', 'swap_horiz', $guard(UI::form('Proposition ou contre-proposition', '', 'swap_horiz', 'Ajouter', "{$url}/proposition", [['name' => 'by', 'label' => 'Partie', 'type' => 'select', 'required' => true, 'options' => $parties, 'full' => true], ['name' => 'text', 'label' => 'Proposition', 'type' => 'textarea', 'required' => true]])));
            $out[] = UI::button('Saisir un accord', 'edit_document', $guard(UI::form('Accord de médiation', '', 'edit_document', 'Enregistrer (provisoire)', "{$url}/accord", [
                ['name' => 'claimant', 'label' => 'Engagements du demandeur', 'type' => 'textarea', 'required' => true], ['name' => 'respondent', 'label' => 'Engagements de la partie adverse', 'type' => 'textarea', 'required' => true],
                ['name' => 'amount', 'label' => 'Montant (FCFA)', 'type' => 'number'], ['name' => 'replacement', 'label' => 'Remplacement', 'type' => 'select', 'required' => true, 'options' => ['Aucun', 'Remplacement de l’ouvrier']],
                ['name' => 'delay', 'label' => 'Délai d’exécution', 'type' => 'select', 'required' => true, 'options' => ['7 jours', '15 jours', '30 jours']],
                ['name' => 'payments', 'label' => 'Conséquences sur les paiements', 'type' => 'select', 'required' => true, 'options' => ['Aucune', 'Ajustement créditeur', 'Ajustement débiteur', 'Libération des fonds suspendus', 'Remboursement partiel']],
                ['name' => 'reviews', 'label' => 'Conséquences sur les avis', 'type' => 'select', 'required' => true, 'options' => ['Aucune', 'Avis maintenu', 'Avis masqué à la demande de l’auteur']], ['name' => 'effective_at', 'label' => 'Date d’effet', 'type' => 'date', 'required' => true],
            ])), 'primary');
        }
        if ($m['status'] === 'Accord provisoire') {
            $out[] = UI::button('Valider l’accord (parties)', 'verified', $guard(UI::form('Validation de l’accord', '', 'verified', 'Enregistrer', "{$url}/validation", [['name' => 'who', 'label' => 'Partie', 'type' => 'select', 'required' => true, 'options' => [...$parties, 'Les deux parties'], 'full' => true]])), 'primary');
        }
        if (in_array($m['status'], ['Acceptée', 'En cours', 'Accord provisoire'], true)) {
            $out[] = UI::button('Constater l’échec', 'cancel', $guard(UI::reason('Échec de la médiation', '', 'cancel', 'Constater', "{$url}/echec", 'Motif', 'danger')));
        }
        if (in_array($m['status'], ['Accord validé', 'Échec', 'Refusée'], true)) {
            $out[] = UI::button('Clôturer la médiation', 'lock', $guard(UI::post("{$url}/cloture")));
        }

        return $out;
    }

    /**
     * Actions sur un recours (recevabilité, décision sur recours).
     *
     * @param  array<string, mixed>  $a
     * @return array<int, array{0: string, 1: string, 2: array<string, mixed>}>
     */
    public static function appealActions(Dispute $d, int $index, array $a): array
    {
        $url = "/avis-litiges/litiges/{$d->reference}/recours/{$index}";
        $guard = fn (array $action): array => self::allowed('rec', $action, 'recours');

        return match (true) {
            in_array($a['status'], ['Soumis', 'Recevabilité en cours'], true) => [
                ['task_alt', 'Recevable · réexamen', $guard(UI::confirm("Recours {$a['ref']} recevable ?", 'Le litige passe en réexamen ; la décision initiale est conservée.', 'task_alt', 'Recevable', "{$url}/recevable"))],
                ['block', 'Non recevable', $guard(UI::reason('Recours non recevable', '', 'block', 'Valider', "{$url}/non-recevable", 'Motivation', 'warning'))],
            ],
            $a['status'] === 'Réexamen' => [['gavel', 'Rendre la décision sur recours', $guard(UI::form('Décision sur recours', 'La décision initiale v'.($d->decision['version'] ?? 1).' est conservée dans le dossier.', 'gavel', 'Rendre', "{$url}/decision", [
                ['name' => 'outcome', 'label' => 'Issue', 'type' => 'select', 'required' => true, 'options' => ['Décision confirmée', 'Décision modifiée', 'Décision annulée'], 'full' => true], ['name' => 'reason', 'label' => 'Motivation', 'type' => 'textarea', 'required' => true],
            ]))]],
            default => [],
        };
    }

    /**
     * Tableau des demandes transmises à un autre module (Paiements, Affectations, Pointages).
     *
     * @return array<string, mixed>
     */
    private function requestTable(Dispute $d, string $kind): array
    {
        $url = "/avis-litiges/litiges/{$d->reference}/demandes";
        $sync = fn (string $ref, string $module): array => ['sync', "Synchroniser le statut depuis {$module}", UI::post("{$url}/{$kind}/{$ref}/synchronisation")];

        return match ($kind) {
            'fin' => UI::table('request_quote', 'Demandes transmises à Paiements', count($d->financial_requests).' demande(s) · exécution dans Paiements', ['Référence', 'Type', 'Montant demandé', 'Motif', 'Décision source', 'Approbations', 'Statut d’exécution', 'Opération Paiements'], array_map(function (array $f) use ($d, $sync): array {
                $go = $f['payment_ref'] ? UI::link("/paiements/operations/{$f['payment_ref']}") : UI::link('/paiements');
                $acts = [$sync($f['ref'], 'Paiements'), ['open_in_new', 'Ouvrir Paiements', $go]];
                if (str_starts_with($f['type'], 'Suspension') && $f['status'] === 'Exécutée' && ! $f['released']) {
                    $acts[] = ['lock_open', 'Demander la libération', self::allowed('fin', UI::form('Libération des fonds suspendus', '', 'lock_open', 'Transmettre', "/avis-litiges/litiges/{$d->reference}/demandes-financieres", [['name' => 'motif', 'label' => 'Motif', 'type' => 'textarea', 'required' => true]], 'primary', ['type' => 'Libération d’un paiement', 'amount' => $f['amount'], 'operation' => $f['op_ref'], 'parent' => $f['ref']]), 'action financière')];
                }

                return UI::row($f['ref'], [UI::text($f['ref'], true), UI::text($f['type']), UI::money($f['amount'], true), UI::text($f['motif']), UI::text($f['source']), UI::text(implode(', ', $f['approvals'])), UI::badge($f['status'].($f['released'] ? ' · libérée' : ''), $f['status'] === 'Exécutée' ? 'g' : ($f['status'] === 'Refusée' ? 'r' : 'a')), UI::text($f['op_ref'].($f['executed_at'] ? ' · '.Q::date($f['executed_at']) : ''))], $go, $acts);
            }, $d->financial_requests), 1700, ['request_quote', 'Aucune demande', 'Aucune demande transmise à Paiements.']),
            'rempl' => UI::table('swap_horiz', 'Demandes de remplacement (Affectations)', count($d->replacements).' demande(s) · exécution dans Affectations', ['Référence', 'Ouvrier concerné', 'Affectation', 'Motif', 'Urgence', 'Date souhaitée', 'Transfert des dossiers', 'Statut', 'Remplaçant proposé', 'Résultat'], array_map(fn (array $r): array => UI::row($r['ref'], [
                UI::text($r['ref'], true), UI::text($r['worker']), UI::text($r['assignment']), UI::text($r['motif']), UI::text($r['urgency']), UI::text(Q::date($r['date'])), UI::text($r['transfer']), UI::badge($r['status'], str_contains($r['status'], 'confirmé') ? 'g' : 'a'), UI::text($r['replacement'] ?: '—'), UI::text($r['result'] ?: '—'),
            ], UI::link('/affectations'), [$sync($r['ref'], 'Affectations'), ['open_in_new', 'Ouvrir Affectations', UI::link('/affectations')]]), $d->replacements), 1500, ['swap_horiz', 'Aucune demande', 'Aucune demande transmise à Affectations.']),
            default => UI::table('edit_calendar', 'Demandes de correction (Pointages)', count($d->attendance_requests).' demande(s) · exécution dans Pointages', ['Référence', 'Pointage', 'Ancienne valeur', 'Valeur contestée', 'Correction demandée', 'Preuve', 'Décision', 'Statut'], array_map(fn (array $r): array => UI::row($r['ref'], [
                UI::text($r['ref'], true), UI::text($r['attendance']), UI::text($r['old']), UI::text($r['contested']), UI::text($r['requested']), UI::text($r['proof']), UI::text($r['decision']), UI::badge($r['status'], str_contains($r['status'], 'Corrigée') ? 'g' : 'a'),
            ], UI::link("/pointages/{$r['attendance']}"), [$sync($r['ref'], 'Pointages'), ['open_in_new', 'Ouvrir Pointages', UI::link("/pointages/{$r['attendance']}")]]), $d->attendance_requests), 1500, ['edit_calendar', 'Aucune demande', 'Aucune demande transmise à Pointages.']),
        };
    }

    public function show(Request $request, Dispute $dispute, string $tab = ''): Response
    {
        $ins = $this->insights($request);
        if (! Q::can('lit')) {
            return $this->denied($ins, 'Votre rôle ne permet pas de consulter les litiges.');
        }
        abort_unless(array_key_exists($tab, self::TABS), 404);
        $d = $dispute->load('site', 'worker', 'promoter');
        $cfg = config('admin.quality');
        $sla = $d->sla();
        $ex = $d->exposure();
        $x = $this->actions($d, $ins);
        $base = "/litiges/{$d->reference}";
        $pending = $d->pendingRequests();
        $confidential = Q::can('notes');
        $tabs = collect(self::TABS)->map(fn (string $label, string $key): array => UI::tab($label, $ins->url($base.($key ? "/{$key}" : '')), match ($key) {
            'parties' => count($d->parties), 'declarations' => count($d->declarations), 'preuves' => count($d->proofs), 'pointages' => count($d->attendance_requests),
            'paiements' => count($d->financial_requests), 'execution' => $pending, 'recours' => count($d->appeals), default => 0,
        }, $key === $tab))->values()->all();
        $button = fn (string $key, string $variant = 'primary'): array => UI::button($x[$key]['label'], $x[$key]['icon'], $x[$key]['action'], $variant);
        $buttons = array_values(array_filter([
            $d->isEarly() ? $button('qualif') : null, $d->status === 'Réponse de la partie adverse attendue' ? $button('response', 'secondary') : null, $d->status === 'Informations demandées' ? $button('infoOk') : null,
            ! $d->isEarly() && ! $d->isFinal() && ! $d->decision && ! in_array($d->status, ['Médiation proposée', 'Médiation en cours'], true) ? $button('prepare') : null,
            $d->decision && ($d->decision['status'] ?? '') !== 'Rendue' ? $button('render') : null, in_array($d->status, ['En attente d’exécution', 'Accord trouvé', 'Décision rendue'], true) ? $button('done') : null,
            $d->status === 'Résolu' ? $button('close') : null, in_array($d->status, ['Clôturé', 'Résolu'], true) ? $button('reopen', 'secondary') : null,
        ]));
        $menu = array_map(fn (string $k): array => [$x[$k]['icon'], $x[$k]['label'], $x[$k]['action']], ['assign', 'priority', 'info', 'proof', 'declare', 'contact', 'note', 'mediation', 'financial', 'suspend', 'replacement', 'attendance', 'prepare', 'render', 'appeal', 'close', 'reopen', 'cancel', 'archive', 'site', 'payment']);
        $profile = UI::profile("{$d->reference} · {$d->subject}", [$d->status, Dispute::statusTone($d->status)], ["Priorité {$d->priority}", Dispute::PRIORITY_TONES[$d->priority]], "{$d->category} · gravité ".mb_strtolower($d->severity), [
            ['person', 'Demandeur', $d->claimant['name']], ['person_off', 'Partie adverse', $d->respondent['name']], ['construction', 'Chantier', $d->site->name], ['badge', 'Responsable', $d->owner ?: 'Non affecté'],
            ['timer', 'Délai restant', $sla['text']], ['payments', 'Montant contesté', $d->amount ? Q::money($d->amount) : 'Aucun'],
        ], $buttons, $menu);

        $alerts = [];
        if ($sla['status'] === 'En retard') {
            $alerts[] = UI::alert('danger', 'timer_off', 'SLA dépassé', "{$sla['phase']} : échéance ".Q::date($sla['due']).' dépassée de '.-$sla['left']." j (délai Paramètres {$sla['total']} j).");
        } elseif ($sla['status'] === 'Bientôt en retard') {
            $alerts[] = UI::alert('warning', 'schedule', 'SLA bientôt dépassé', "{$sla['phase']} : échéance ".Q::date($sla['due']).'.');
        }
        if ($d->suspension) {
            $alerts[] = UI::alert('info', 'pause_circle', 'SLA suspendu avec motif', $d->suspension);
        }
        if (! $d->proofs) {
            $alerts[] = UI::alert('warning', 'attach_file_off', 'Preuve manquante', 'Aucune pièce déposée : la recevabilité exige au moins une pièce.', 'Ajouter une preuve', $x['proof']['action']);
        }
        if ($d->isEarly() && in_array(false, $d->admissibility, true)) {
            $alerts[] = UI::alert('warning', 'rule', 'Litige incomplet', 'Contrôles de recevabilité non conformes : '.collect($d->admissibility)->filter(fn (bool $v): bool => ! $v)->keys()->map(fn (string $k): string => Dispute::ADMISSIBILITY[$k])->implode(', ').'.');
        }
        if ($d->status === 'Non recevable') {
            $alerts[] = UI::alert('info', 'info', 'Non recevable · dossier conservé', (string) $d->inadmissibility);
        }
        if (collect($d->appeals)->contains(fn (array $a): bool => ! in_array($a['status'], ['Clôturé', 'Non recevable', 'Décision confirmée', 'Décision modifiée', 'Décision annulée'], true))) {
            $alerts[] = UI::alert('warning', 'replay', 'Recours ouvert', 'La décision initiale est conservée pendant le réexamen.');
        }
        if ($pending) {
            $alerts[] = UI::alert('info', 'hourglass_top', "{$pending} demande(s) en attente d’exécution", 'Exécution dans les modules Paiements, Affectations ou Pointages.', 'Exécution', UI::link($ins->url("{$base}/execution")));
        }
        $props = ['profile' => $profile, 'tabs' => $tabs, 'alerts' => $alerts];
        $card = fn (string $k): array => UI::card($x[$k]['icon'], 'Action', $x[$k]['label'], $x[$k]['hint'], 'À faire', 'a', $x[$k]['action']);

        switch ($tab) {
            case '':
                $steps = [['Notification du demandeur', $d->opened_at->format('Y-m-d'), true], ['Notification de la partie mise en cause', $d->qualified_at?->format('Y-m-d'), (bool) $d->qualified_at], ['Résumé du litige communiqué', $d->qualified_at?->format('Y-m-d'), (bool) $d->qualified_at],
                    ["Délai de réponse accordé ({$cfg['sla']['rep']} j)", $d->qualified_at?->addDays($cfg['sla']['rep'])->format('Y-m-d'), (bool) $d->qualified_at], ['Réponse recueillie', $d->responded_at?->format('Y-m-d'), $d->isResponded()],
                    ['Observations complémentaires', null, collect($d->declarations)->contains('type', 'Observation complémentaire')], ['Preuves communicables partagées', null, collect($d->proofs)->contains(fn (array $p): bool => $p['visibility'] === 'Parties' && $p['status'] !== 'À vérifier')],
                    ['Éléments confidentiels identifiés', null, collect($d->proofs)->contains(fn (array $p): bool => $p['visibility'] !== 'Parties') || (bool) $d->notes], ['Médiation proposée si pertinente', $d->mediation['proposed_at'], $d->mediation['status'] !== 'Non proposée'],
                    ['Décision motivée préparée', $d->decision['date'] ?? null, (bool) $d->decision]];
                $current = collect($steps)->search(fn (array $s): bool => ! $s[2]);
                $props['tiles'] = [
                    UI::tile('Montant contesté', $d->amount ? Q::money($d->amount) : 'Aucun', 'payments', 'o', ['sub' => 'Exposition potentielle · pas une perte réalisée']), UI::tile('Fonds suspendus', Q::money($ex['suspended']), 'lock', $ex['suspended'] ? 'a' : 'n', ['sub' => 'Exécutés par Paiements']),
                    UI::tile("SLA · {$sla['phase']}", $sla['text'], 'timer', $sla['tone'], ['bar' => $sla['percent'], 'barColor' => $sla['status'] === 'En retard' ? 'var(--c-danger)' : 'var(--c-primary-500)', 'sub' => $sla['due'] ? 'Échéance '.Q::date($sla['due'])." · consommé {$sla['used']} / {$sla['total']} j" : '']),
                    UI::tile('Priorité', $d->priority, 'priority_high', Dispute::PRIORITY_TONES[$d->priority], ['sub' => $d->priority_reason ?: 'Priorité automatique conservée']), UI::tile('Gravité', $d->severity, 'warning', Dispute::SEVERITY_TONES[$d->severity], ['sub' => 'Importance de l’impact ≠ urgence']),
                ];
                $props['flow'] = ['steps' => array_map(fn (array $s, int $i): array => UI::step($s[0], $s[2] ? ($s[1] ? Q::date($s[1]) : 'Fait') : 'À faire', $s[2] ? 'done' : ($i === $current ? 'cur' : 'todo'), $s[2] ? 'check' : 'radio_button_unchecked'), $steps, array_keys($steps)), 'stacks' => []];
                $priorities = array_values(array_filter(['qualif', 'response', 'mediation', 'prepare', 'render', 'financial', 'done'], fn (string $k): bool => ! $x[$k]['off']));
                $props['panels'] = [
                    UI::keyValues('summarize', 'Résumé', [['Catégorie', $d->category.($d->subcategory ? " · {$d->subcategory}" : '')], ['Faits', $d->description], ['Date des faits', Q::date($d->facts_at->format('Y-m-d'))], ['Demandes', $d->wish.($d->amount ? ' · '.Q::money($d->amount) : '')],
                        ['Source', $d->source.($d->source_reference ? " · {$d->source_reference}" : '')], ['Chantier', "{$d->site->name} · {$d->site->commune}"], ['Prestation', "{$d->prestation} · {$d->trade}"], ['Affectation', $d->assignment_reference], ['Paiement concerné', $d->operation_reference],
                        ['Pointages concernés', implode(', ', array_column($d->attendance_requests, 'attendance')) ?: 'Voir l’onglet Pointages'], ['Ouvert le', Q::date($d->opened_at->format('Y-m-d'))], ['Prochaine échéance', $sla['due'] ? "{$sla['phase']} · ".Q::date($sla['due']) : '—']]),
                    UI::cards('bolt', 'Actions prioritaires', array_map($card, array_slice($priorities, 0, 4))),
                ];
                if ($confidential) {
                    $props['panels'][] = ['icon' => 'lock', 'title' => 'Notes internes · confidentielles', 'kind' => 'feed', 'feed' => $d->notes ? array_map(fn (array $n): array => ['icon' => 'lock', 'title' => $n['by'], 'text' => "· {$n['text']}", 'when' => Q::date($n['date']).' · jamais visible des parties'], $d->notes) : [['icon' => 'info', 'title' => 'Aucune note', 'text' => '', 'when' => '']], 'button' => UI::button('Ajouter une note', 'add', $x['note']['action'])];
                }
                break;
            case 'parties':
                $props['tables'] = [UI::table('groups', 'Parties au litige', count($d->parties).' intervenant(s)', ['Nom', 'Rôle', 'Coordonnées autorisées', 'Notification', 'Statut de réponse', 'Documents déposés', 'Confidentialité'], array_map(fn (array $p): array => UI::row($p['role'].$p['name'], [
                    UI::text($p['name'], true), UI::badge($p['role'], ['Demandeur' => 'b', 'Partie mise en cause' => 'o', 'Médiateur' => 'p', 'Témoin' => 'n', 'Représentant' => 'n', 'Administrateur en charge' => 'g'][$p['role']] ?? 'n'),
                    UI::text($p['phone'] ? Q::mask($p['phone']).' · via la messagerie O’CHANTIER' : 'Messagerie O’CHANTIER'), UI::text(Q::date($p['notified_at'])), UI::text($p['response']), UI::text((string) $p['documents']), UI::text($p['confidentiality']),
                ], match ($p['link'] ?? null) {
                    'ouv' => $p['slug'] ? UI::link("/ouvriers/{$p['slug']}") : null,
                    'pro' => $p['slug'] ? UI::link("/promoteurs/{$p['slug']}") : null,
                    default => null,
                }, [['mail', 'Contacter', self::contact($d->reference, [$p['name']])]]), $d->parties), 1400)];
                break;
            case 'chronologie':
                $sequence = ['Soumis', 'Reçu', 'Recevabilité en cours', 'Ouvert', 'Réponse de la partie adverse attendue', 'Analyse en cours', 'Médiation en cours', 'Décision rendue', 'En attente d’exécution', 'Résolu', 'Clôturé'];
                $ci = array_search($d->status, $sequence, true);
                $props['flow'] = ['steps' => array_map(fn (string $s, int $i): array => UI::step($s, $s === $d->status ? 'Statut actuel' : '', $s === $d->status ? 'cur' : ($ci !== false && $i < $ci ? 'done' : 'todo'), 'circle'), $sequence, array_keys($sequence)), 'stacks' => []];
                if ($ci === false) {
                    $props['alerts'][] = UI::alert('info', 'info', 'Statut hors parcours standard', $d->status);
                }
                $props['tables'] = [UI::table('timer', 'Délais et SLA (Paramètres)', "Phase en cours : {$sla['phase']}", ['Phase', 'Délai', 'Début', 'Échéance', 'Temps consommé', 'Temps restant', 'Statut'], array_map(function (string $k, string $label) use ($sla, $cfg): array {
                    $on = $sla['key'] === $k;

                    return UI::row($k, [UI::text($label, $on), UI::text("{$cfg['sla'][$k]} j"), UI::text($on ? Q::date($sla['start']) : '—'), UI::text($on ? Q::date($sla['due']) : '—'), UI::text($on ? "{$sla['used']} j" : '—'), UI::text($on ? $sla['text'] : '—'), UI::badge($on ? $sla['status'] : '—', $on ? $sla['tone'] : 'n')]);
                }, ['ack', 'qualif', 'rep', 'trait', 'med', 'dec', 'exec', 'rec'], ['Accusé de réception', 'Qualification', 'Réponse de la partie adverse', 'Traitement', 'Médiation', 'Décision', 'Exécution', 'Recours']), 1000)];
                $props['timeline'] = $d->activities()->where('category', '!=', 'document')->get()->map(fn ($e): array => self::activity($e))->all();
                break;
            case 'declarations':
                $props['toolbar'] = array_values(array_filter([UI::button($x['declare']['label'], $x['declare']['icon'], $x['declare']['action']), $d->status === 'Réponse de la partie adverse attendue' ? UI::button($x['response']['label'], $x['response']['icon'], $x['response']['action']) : null]));
                $props['tables'] = [UI::table('record_voice_over', 'Déclarations des parties', count($d->declarations).' déclaration(s)', ['Auteur', 'Rôle', 'Date', 'Type', 'Contenu', 'Pièces', 'Visibilité', 'Statut', 'Version'], array_map(fn (array $c): array => UI::row($c['id'], [
                    UI::text($c['author'], true), UI::text($c['role']), UI::text(Q::date($c['date'])), UI::badge($c['type'], 'b'), UI::text($c['content']), UI::text(implode(', ', $c['pieces']) ?: '—'), UI::text($c['visibility']), UI::badge($c['status'], 'g'),
                    UI::text("v{$c['version']}".($c['history'] ? ' · '.count($c['history']).' antérieure(s) conservée(s)' : '')),
                ], null, [['history_edu', 'Nouvelle version', UI::reason('Nouvelle version de la déclaration', "La version v{$c['version']} est conservée dans l’historique.", 'history_edu', 'Enregistrer', "/avis-litiges/litiges/{$d->reference}/declarations/{$c['id']}/version", 'Nouveau contenu')]]), $d->declarations), 1500)];
                break;
            case 'preuves':
                $props['toolbar'] = [UI::button('Déposer une preuve', 'upload_file', $x['proof']['action'], 'primary')];
                $props['alerts'][] = UI::alert('info', 'verified_user', 'Intégrité des preuves', 'Fichier d’origine conservé, aucun remplacement silencieux : tout complément crée une nouvelle version. Les consultations sensibles sont tracées.');
                $props['tables'] = [UI::table('inventory_2', 'Registre des preuves', count($d->proofs).' pièce(s)', ['Référence', 'Titre', 'Type', 'Auteur', 'Dépôt', 'Date des faits', 'Fichier', 'Taille', 'Visibilité', 'Statut', 'Version', 'Commentaire', 'Métadonnées'], array_map(function (array $p) use ($d, $confidential): array {
                    $hide = $p['visibility'] === 'Confidentiel' && ! $confidential;
                    $url = "/avis-litiges/litiges/{$d->reference}/preuves/{$p['id']}";
                    $status = fn (string $title, string $icon, ?string $preset, string $tone = 'primary', array $extraFields = []): array => self::allowed('proof', UI::form($title, $preset === 'Archivée' ? 'Le fichier n’est plus consultable ; une trace administrative non publique est conservée.' : 'Le fichier d’origine n’est jamais modifié.', $icon, 'Valider', "{$url}/statut", [
                        ...($preset ? [] : [['name' => 'status', 'label' => 'Statut', 'type' => 'select', 'required' => true, 'options' => ['À vérifier', 'Vérifiée', 'Contestée', 'Incomplète', 'Illisible', 'Non pertinente', 'Rejetée'], 'full' => true]]),
                        ...$extraFields, ['name' => 'reason', 'label' => 'Motif', 'type' => 'textarea', 'required' => true],
                    ], $tone, $preset ? ['status' => $preset] : []), 'statut de preuve');

                    return UI::row($p['id'], [
                        UI::text($p['ref'], true), UI::text($hide ? 'Pièce confidentielle' : $p['title']), UI::text($p['type']), UI::text($hide ? '••••' : $p['author']), UI::text(Q::date($p['deposited_at'])), UI::text(Q::date($p['facts_at'])), UI::text($hide ? '••••' : $p['file']),
                        UI::text($p['size']), UI::badge($p['visibility'], $p['visibility'] === 'Parties' ? 'b' : 'o'), UI::badge($p['status'], ['Vérifiée' => 'g', 'Contestée' => 'o', 'Rejetée' => 'r', 'Archivée' => 'n'][$p['status']] ?? 'a'),
                        UI::text("v{$p['version']}"), UI::text($p['comment'] ?: ($p['duplicate_of'] ? "Doublon potentiel de {$p['duplicate_of']}" : '—')), UI::text($p['meta']),
                    ], null, [['verified', 'Vérifier', $status('Vérifier la preuve', 'verified', 'Vérifiée')]], [
                        ['report', 'Contester', $status('Contester la preuve', 'report', 'Contestée', 'warning', [['name' => 'by', 'label' => 'Contestée par', 'type' => 'select', 'required' => true, 'options' => [$d->claimant['name'], $d->respondent['name']], 'full' => true]])],
                        ['rule', 'Qualifier (incomplète, illisible…)', $status("Statut de la preuve {$p['ref']}", 'rule', null)],
                        ['difference', 'Ajouter une version', self::allowed('proof', UI::form("Ajouter une version à {$p['ref']}", "Aucun remplacement silencieux : la version v{$p['version']} reste conservée.", 'difference', 'Ajouter', "{$url}/version", [['name' => 'file', 'label' => 'Fichier complémentaire', 'type' => 'file', 'required' => true], ['name' => 'note', 'label' => 'Nature du complément', 'required' => true, 'full' => true]]), 'version de preuve')],
                        ['visibility', 'Consulter (tracé)', self::allowed('proof', UI::post("{$url}/consultation"), 'consultation')],
                        ['gpp_maybe', 'Retirer (motif légal)', $status('Retirer la preuve (motif légal ou confidentialité)', 'gpp_maybe', 'Archivée', 'danger')],
                    ]);
                }, $d->proofs), 2100, ['attach_file_off', 'Preuve manquante', 'Aucune pièce déposée pour ce litige.'])];
                break;
            case 'pointages':
                $attendances = $d->worker_id ? Attendance::query()->where('worker_id', $d->worker_id)->with('site')->orderByDesc('date')->limit(8)->get() : collect();
                $props['toolbar'] = [UI::button($x['attendance']['label'], $x['attendance']['icon'], $x['attendance']['action'], 'primary'), UI::button('Ouvrir dans Pointages', 'open_in_new', UI::link('/pointages'))];
                $props['tables'] = [
                    UI::table('schedule', 'Pointages de '.($d->worker?->name ?? 'l’ouvrier').' (preuves)', $attendances->count().' pointage(s) récents · lecture seule', ['Référence', 'Date', 'Chantier', 'Arrivée', 'Départ', 'Statut arrivée'], $attendances->map(fn (Attendance $a): array => UI::row($a->reference, [
                        UI::text($a->reference, true), UI::text($a->date->format('d/m/Y')), UI::text($a->site?->name ?? '—'), UI::text($a->arrival['time'] ?? '—'), UI::text($a->departure['time'] ?? '—'), UI::text($a->arrival['status'] ?? '—'),
                    ], UI::link("/pointages/{$a->reference}")))->all(), 1000, ['schedule', 'Aucun pointage', 'Aucun pointage disponible pour cet ouvrier dans Pointages.']),
                    $this->requestTable($d, 'ptc'),
                ];
                break;
            case 'paiements':
                $operations = Payment::query()->where('worker_id', $d->worker_id)->where('site_id', $d->site_id)->orderByDesc('date')->limit(8)->get();
                $props['toolbar'] = [UI::button($x['financial']['label'], $x['financial']['icon'], $x['financial']['action'], 'primary'), UI::button($x['suspend']['label'], $x['suspend']['icon'], $x['suspend']['action'])];
                $props['alerts'][] = UI::alert('info', 'account_balance', 'Aucune exécution directe', 'Avis & Litiges transmet des demandes au module Paiements ; il ne modifie aucun solde et n’exécute aucun remboursement.');
                $props['tables'] = [
                    UI::table('credit_card', 'Opérations concernées (Paiements)', $operations->count().' opération(s) · lecture seule', ['Opération', 'Date', 'Type', 'Statut du paiement', 'Reversement'], $operations->map(fn (Payment $o): array => UI::row($o->reference, [
                        UI::text($o->reference, true), UI::text($o->date->format('d/m/Y')), UI::text($o->type), UI::text($o->pay_status), UI::text($o->payout_status),
                    ], UI::link("/paiements/operations/{$o->reference}"), [['open_in_new', 'Voir dans Paiements', UI::link("/paiements/operations/{$o->reference}")]]))->all(), 1000, ['credit_card', 'Aucune opération récente', "Opération de référence : {$d->operation_reference}"]),
                    $this->requestTable($d, 'fin'),
                ];
                break;
            case 'mediation':
                $m = $d->mediation;
                $mi = array_search($m['status'], self::MEDIATION_STEPS, true);
                $props['flow'] = ['steps' => array_map(fn (string $s, int $i): array => UI::step($s, $s === $m['status'] ? 'Statut actuel' : '', $s === $m['status'] ? 'cur' : ($mi !== false && $i < $mi ? 'done' : (in_array($m['status'], ['Refusée', 'Échec'], true) && $i > 2 ? 'fail' : 'todo')), 'handshake'), self::MEDIATION_STEPS, array_keys(self::MEDIATION_STEPS)), 'stacks' => []];
                if (in_array($m['status'], ['Refusée', 'Échec'], true)) {
                    $props['alerts'][] = UI::alert('danger', 'cancel', 'Médiation : '.mb_strtolower($m['status']), 'Le litige poursuit son instruction vers une décision motivée.');
                }
                $props['toolbar'] = $this->mediationActions($d, $x['mediation']['action']);
                $accept = fn (?bool $v): string => $v === null ? 'En attente' : ($v ? 'Acceptée' : 'Refusée');
                $props['panels'] = [
                    UI::keyValues('handshake', "Médiation · {$m['status']}", [['Médiateur', $m['mediator'] ?: '—'], ['Proposée le', Q::date($m['proposed_at'])], ['Acceptation du demandeur', $accept($m['claimant_accepts'])], ['Acceptation de la partie adverse', $accept($m['respondent_accepts'])], ['Calendrier', collect($m['calendar'])->map(fn (array $c): string => Q::date($c['date'])." {$c['time']} · {$c['place']}")->implode(' | ') ?: '—'], ['Délai de médiation', "{$cfg['sla']['med']} j (Paramètres)"]]),
                    ['icon' => 'forum', 'title' => 'Échanges', 'kind' => 'feed', 'feed' => $m['exchanges'] ? array_map(fn (array $e): array => ['icon' => 'forum', 'title' => $e['by'], 'text' => "· {$e['text']}", 'when' => Q::date($e['date'])], $m['exchanges']) : [['icon' => 'info', 'title' => 'Aucun échange', 'text' => '', 'when' => '']], 'button' => null],
                ];
                if ($ag = $m['agreement']) {
                    $props['panels'][] = UI::keyValues('verified', 'Accord', [['Engagements du demandeur', $ag['claimant']], ['Engagements de la partie adverse', $ag['respondent']], ['Montant', Q::money($ag['amount'])], ['Remplacement', $ag['replacement']], ['Délai d’exécution', $ag['delay']], ['Conséquences sur les paiements', $ag['payments']], ['Conséquences sur les avis', $ag['reviews']],
                        ['Validation des parties', ($ag['claimant_validated'] ? 'Demandeur ✓' : 'Demandeur en attente').' · '.($ag['respondent_validated'] ? 'Partie adverse ✓' : 'Partie adverse en attente')], ['Date d’effet', Q::date($ag['effective_at'])]]);
                }
                $props['tables'] = [UI::table('swap_horiz', 'Propositions et contre-propositions', count($m['proposals']).' proposition(s)', ['Date', 'Auteur', 'Proposition', 'Statut'], array_map(fn (array $p, int $i): array => UI::row((string) $i, [UI::text(Q::date($p['date'])), UI::text($p['by'], true), UI::text($p['text']), UI::badge($p['status'], ['Acceptée' => 'g', 'Refusée' => 'r'][$p['status']] ?? 'a')]), $m['proposals'], array_keys($m['proposals'])), 900, ['swap_horiz', 'Aucune proposition'])];
                break;
            case 'decision':
                $dec = $d->decision;
                $props['toolbar'] = array_values(array_map(fn (string $k): array => UI::button($x[$k]['label'], $x[$k]['icon'], $x[$k]['action'], 'primary'), array_filter(['prepare', 'render'], fn (string $k): bool => ! $x[$k]['off'])));
                $props['alerts'][] = UI::alert('info', 'balance', 'Décision motivée', 'Une décision ne repose jamais sur la seule note d’un avis : faits, preuves examinées et règles appliquées sont obligatoires. Elle est approuvée par un responsable distinct de l’auteur.');
                $props['panels'] = [UI::keyValues('gavel', $dec ? 'Décision '.($dec['status'] === 'Rendue' ? 'rendue le '.Q::date($dec['date']) : '· '.mb_strtolower($dec['status']))." · v{$dec['version']}" : 'Aucune décision préparée', $dec ? [
                    ['Rappel des faits', $dec['facts']], ['Demandes des parties', $dec['requests']], ['Preuves examinées', $dec['proofs']], ['Éléments retenus', $dec['retained']], ['Éléments écartés', $dec['rejected']], ['Analyse', $dec['analysis']], ['Règles appliquées', $dec['rules']],
                    ['Décision', $dec['result']], ['Mesures ordonnées', implode(' ; ', $dec['measures']) ?: 'Aucune mesure'], ['Délai d’exécution', $dec['delay']], ['Possibilité de recours', $dec['appeal']], ['Auteur', $dec['author']], ['Approbateur', $dec['approver'] ?: 'En attente d’approbation'], ['Date', Q::date($dec['date'])],
                ] : [['Statut', 'Préparez la décision après la phase contradictoire']])];
                if ($d->appeal_decision) {
                    $props['panels'][] = UI::keyValues('replay', "Décision sur recours · {$d->appeal_decision['result']}", [['Issue', $d->appeal_decision['result']], ['Motivation', $d->appeal_decision['why']], ['Date', Q::date($d->appeal_decision['date'])], ['Décision initiale', 'Conservée (v'.($dec['version'] ?? 1).')']]);
                }
                break;
            case 'execution':
                $props['toolbar'] = array_values(array_map(fn (string $k): array => UI::button($x[$k]['label'], $x[$k]['icon'], $x[$k]['action']), array_filter(['financial', 'replacement', 'attendance', 'done'], fn (string $k): bool => ! $x[$k]['off'])));
                $progress = fn (string $label, array $items, callable $ok, string $icon): array => UI::step($label, $items ? count(array_filter($items, $ok)).' / '.count($items).' exécutée(s)' : 'Aucune demande', ! $items ? 'todo' : (count(array_filter($items, $ok)) === count($items) ? 'done' : 'cur'), $icon);
                $props['flow'] = ['steps' => [
                    $progress('Paiements', $d->financial_requests, fn (array $f): bool => in_array($f['status'], ['Exécutée', 'Refusée'], true), 'credit_card'),
                    $progress('Affectations', $d->replacements, fn (array $r): bool => $r['status'] === 'Remplacement confirmé', 'swap_horiz'),
                    $progress('Pointages', $d->attendance_requests, fn (array $r): bool => str_contains($r['status'], 'Corrigée'), 'schedule'),
                ], 'stacks' => [UI::stack('Exposition financière du litige', 'Potentielle tant que les demandes ne sont pas exécutées', [['Montant contesté non couvert', $ex['net']], ['Fonds suspendus', $ex['suspended']], ['Remboursements potentiels', $ex['refunds']], ['Ajustements potentiels', $ex['adjustments']], ['Montant exécuté', $ex['done']]])]];
                $props['tables'] = [$this->requestTable($d, 'fin'), $this->requestTable($d, 'rempl'), $this->requestTable($d, 'ptc')];
                break;
            case 'recours':
                $props['toolbar'] = $x['appeal']['off'] ? [] : [UI::button($x['appeal']['label'], $x['appeal']['icon'], $x['appeal']['action'], 'primary')];
                if ($x['appeal']['off']) {
                    $props['alerts'][] = UI::alert('info', 'info', 'Recours indisponible', $x['appeal']['why']);
                }
                $props['tables'] = [UI::table('replay', 'Recours', count($d->appeals)." recours · délai {$cfg['appeal_window']} j (Paramètres)", ['Référence', 'Auteur', 'Motif', 'Description', 'Éléments nouveaux', 'Date', 'Statut', 'Issue'], array_map(fn (array $a, int $i): array => UI::row($a['ref'], [
                    UI::text($a['ref'], true), UI::text($a['by']), UI::text($a['motif']), UI::text($a['description']), UI::text($a['new_elements'] ?: '—'), UI::text(Q::date($a['date'])), UI::badge($a['status'], self::appealTone($a['status'])), UI::text($a['outcome'] ?: '—'),
                ], null, self::appealActions($d, $i, $a)), $d->appeals, array_keys($d->appeals)), 1500, ['replay', 'Aucun recours'])];
                break;
            case 'historique':
                $props['timeline'] = $d->activities()->get()->reject(fn ($e): bool => ! $confidential && str_contains($e->action, 'note interne'))->map(fn ($e): array => self::activity($e))->values()->all();
                break;
        }

        return $this->render($ins, '/litiges', $props, "Litige {$d->reference}", '', [['Litiges', $ins->url('/litiges')], [$d->reference, $tab ? $ins->url($base) : null], ...($tab ? [[self::TABS[$tab]]] : [])]);
    }

    /**
     * Remplaçant proposé : premier ouvrier validé du même métier.
     */
    public static function replacementFor(Dispute $d): string
    {
        return Worker::query()->where('status', 'validated')->where('trade', $d->trade)->where('id', '!=', $d->worker_id)->orderBy('id')->first()?->name ?? 'Profil similaire';
    }
}
