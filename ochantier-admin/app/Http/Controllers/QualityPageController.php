<?php

namespace App\Http\Controllers;

use App\Models\Dispute;
use App\Models\QualityActivity;
use App\Models\Review;
use App\Support\PageBlocks as UI;
use App\Support\QualityInsights as Q;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Socle des pages d’Avis & litiges : contexte d’analyse, liens du module, barre de filtres, formulaires de création
 * (réclamation, litige) et lignes de tableau partagées. Chaque page décrit ses blocs et ses actions.
 */
abstract class QualityPageController extends Controller
{
    /**
     * Liens du module : libellé, icône, chemin, chemins rattachés.
     *
     * @var array<int, array{0: string, 1: string, 2: string, 3?: array<int, string>}>
     */
    public const LINKS = [['Vue d’ensemble', 'dashboard', ''], ['Avis', 'star', '/avis'], ['Litiges', 'gavel', '/litiges'], ['Modération', 'shield', '/moderation', ['/signalements']], ['Réclamations', 'support_agent', '/reclamations'], ['Médiations', 'handshake', '/mediations'], ['Recours', 'replay', '/recours'], ['Rapports', 'summarize', '/rapports', ['/notifications', '/audit']]];

    /**
     * Filtres essentiels proposés par défaut.
     *
     * @var array<int, string>
     */
    public const ESSENTIAL = ['promoteur', 'chantier', 'ouvrier', 'metier', 'categorie', 'priorite'];

    public const DETECTION_TONES = ['Critique' => 'r', 'Élevé' => 'o', 'Modéré' => 'a', 'Faible' => 'b'];

    protected function insights(Request $request): Q
    {
        return new Q($request->query());
    }

    /**
     * Rendu d’une page : liens, période, recherche et blocs propres à la page.
     *
     * @param  array<string, mixed>  $props
     * @param  array<int, array{0: string, 1?: string|null}>  $crumbs
     */
    protected function render(Q $ins, string $section, array $props, string $title, string $subtitle, array $crumbs = []): Response
    {
        return Inertia::render('Quality/Page', [
            'page' => $section,
            'nav' => array_map(fn (array $l): array => ['label' => $l[0], 'icon' => $l[1], 'href' => $ins->url($l[2]), 'active' => $section === ($l[2] ?: '/') || in_array($section, $l[3] ?? [], true)], self::LINKS),
            'period' => array_intersect_key($ins->period, array_flip(['key', 'label', 'range', 'from', 'to'])),
            'periods' => Q::PERIODS,
            'query' => array_filter(array_map('strval', array_diff_key($ins->query, ['page' => true])), fn (string $v): bool => $v !== ''),
            'search' => false, 'banner' => null, 'selects' => [], 'views' => [], 'kpis' => [], 'chips' => [], 'alerts' => [], 'tiles' => [], 'toolbar' => [], 'flow' => null, 'charts' => [],
            'panels' => [], 'tables' => [], 'timeline' => [], 'missing' => null, 'sheets' => (object) [], 'tabs' => [], 'profile' => null,
            ...$props,
            'header' => $this->header($title, $subtitle, $crumbs ? [['Avis & litiges', $ins->url('')], ...$crumbs] : []),
        ]);
    }

    protected function missing(Q $ins, string $title, string $text, string $back = ''): Response
    {
        return $this->render($ins, 'missing', ['missing' => ['title' => $title, 'text' => $text, 'back' => $ins->url($back)]], $title, '', [[$title]]);
    }

    protected function denied(Q $ins, string $text): Response
    {
        return $this->missing($ins, 'Accès non autorisé', $text);
    }

    /**
     * @return array<string, string>
     */
    protected static function deny(string $what): array
    {
        return UI::error(Q::denied($what));
    }

    /**
     * Action soumise à un droit du rôle.
     *
     * @param  array<string, mixed>  $action
     * @return array<string, mixed>
     */
    protected static function allowed(string $right, array $action, string $what): array
    {
        return Q::can($right) ? $action : self::deny($what);
    }

    /**
     * Barre de filtres : sélections propres à la page, filtres (essentiels ou tous), base de date des litiges.
     *
     * @param  array<int, string>  $keys
     * @param  array<int, array<string, mixed>>  $extra
     * @param  array<int, string>|null  $essential
     * @return array{selects: array<int, array<string, mixed>>, alerts: array<int, array<string, mixed>>}
     */
    protected function filterBar(Q $ins, string $path, ?array $keys = null, array $extra = [], ?array $essential = null, ?bool $basis = null): array
    {
        $keys ??= array_keys(Q::FILTERS);
        $all = (bool) ($ins->query['tous'] ?? false);
        $essential ??= self::ESSENTIAL;
        $shown = array_values(array_filter($keys, fn (string $k): bool => $all || in_array($k, $essential, true) || isset($ins->filters[$k])));
        $hidden = count($keys) - count($shown);
        $basis ??= (bool) array_intersect($keys, ['categorie', 'priorite', 'gravite', 'statut_litige', 'responsable', 'impact']);
        $selects = [
            ...$extra,
            ...$ins->filterSelects($shown),
            ...($basis ? [['key' => 'base', 'label' => 'Date de référence des litiges', 'value' => $ins->basis === 'res' ? 'res' : '', 'options' => [['value' => '', 'label' => 'Litiges : date de création'], ['value' => 'res', 'label' => 'Litiges : date de résolution']]]] : []),
            ...($hidden || $all ? [['key' => 'tous', 'label' => 'Affichage des filtres', 'value' => $all ? '1' : '', 'options' => [['value' => '', 'label' => $all ? 'Moins de filtres' : "Plus de filtres (+{$hidden})"], ['value' => '1', 'label' => 'Tous les filtres ('.count($keys).')']]]] : []),
        ];
        $alerts = $ins->filters ? [UI::alert('info', 'filter_alt', 'Filtres appliqués ('.count($ins->filters).')', $ins->filterSummary().' · indicateurs et tableaux recalculés.', 'Réinitialiser', UI::link($ins->url($path, [], false)))] : [];

        return ['selects' => $selects, 'alerts' => $alerts];
    }

    /**
     * @param  array<int, array{0: string, 1: string}>  $options
     * @return array<string, mixed>
     */
    protected function select(Q $ins, string $key, string $label, array $options, string $prefix): array
    {
        return ['key' => $key, 'label' => $label, 'value' => (string) ($ins->query[$key] ?? ''), 'options' => array_map(fn (array $o): array => ['value' => $o[0], 'label' => $prefix.$o[1]], $options)];
    }

    /**
     * Prestations proposées dans les formulaires (les plus récentes).
     *
     * @return array<int, array{value: string, label: string}>
     */
    protected static function prestationOptions(?string $first = null): array
    {
        $list = Q::prestations()->take(60);
        if ($first && ! $list->contains('opRef', $first) && ($p = Q::prestations()->firstWhere('opRef', $first))) {
            $list->prepend($p);
        }

        return $list->map(fn (array $p): array => ['value' => $p['opRef'], 'label' => Q::prestationLabel($p)])->values()->all();
    }

    /**
     * Nouvelle réclamation (éventuellement depuis un avis).
     *
     * @return array<string, mixed>
     */
    protected static function newClaim(?Review $review = null): array
    {
        if (! Q::can('lit') || (! Q::can('create') && ! Q::can('mod'))) {
            return self::deny('création de réclamation');
        }

        return UI::form('Nouvelle réclamation', 'Demande initiale : elle peut être traitée simplement ou transformée en litige après qualification.'.($review ? " Source : avis {$review->reference}." : ''), 'support_agent', 'Enregistrer', '/avis-litiges/reclamations', [
            ['name' => 'claimant', 'label' => 'Demandeur', 'type' => 'select', 'required' => true, 'options' => ['Promoteur de la prestation', 'Ouvrier de la prestation']],
            ['name' => 'category', 'label' => 'Catégorie', 'type' => 'select', 'required' => true, 'options' => config('admin.quality.categories')],
            ['name' => 'prestation', 'label' => 'Prestation concernée', 'type' => 'select', 'required' => true, 'options' => self::prestationOptions($review?->operation_reference), 'full' => true],
            ['name' => 'subject', 'label' => 'Objet', 'required' => true, 'full' => true],
            ['name' => 'description', 'label' => 'Description', 'type' => 'textarea', 'required' => true],
            ['name' => 'priority', 'label' => 'Priorité', 'type' => 'select', 'required' => true, 'options' => Dispute::PRIORITIES],
        ], 'primary', $review ? ['review' => $review->reference] : []);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function newClaimButton(?Review $review = null, string $variant = 'secondary'): array
    {
        return UI::button($review ? 'Ouvrir une réclamation' : 'Nouvelle réclamation', 'support_agent', self::newClaim($review), $variant);
    }

    /**
     * Formulaire d’ouverture d’un litige (référence unique, recevabilité avant toute procédure contradictoire).
     *
     * @return array<string, mixed>
     */
    protected static function newDispute(): array
    {
        if (! Q::can('create')) {
            return self::deny('création de litige');
        }

        return UI::form('Ouvrir un litige', 'Une référence unique est générée. Le litige passe en recevabilité avant toute procédure contradictoire.', 'gavel', 'Créer le litige', '/avis-litiges/litiges', [
            ['name' => 'source', 'label' => 'Source', 'type' => 'select', 'required' => true, 'options' => ['Réclamation', 'Avis contesté', 'Paiement', 'Pointage', 'Affectation', 'Chantier', 'Opération administrative']],
            ['name' => 'claimant', 'label' => 'Demandeur', 'type' => 'select', 'required' => true, 'options' => ['Promoteur de la prestation', 'Ouvrier de la prestation']],
            ['name' => 'prestation', 'label' => 'Prestation (chantier, affectation, partie mise en cause)', 'type' => 'select', 'required' => true, 'options' => self::prestationOptions(), 'full' => true],
            ['name' => 'category', 'label' => 'Catégorie', 'type' => 'select', 'required' => true, 'options' => config('admin.quality.categories')],
            ['name' => 'subcategory', 'label' => 'Sous-catégorie', 'required' => true],
            ['name' => 'subject', 'label' => 'Objet', 'required' => true, 'full' => true],
            ['name' => 'description', 'label' => 'Description des faits', 'type' => 'textarea', 'required' => true],
            ['name' => 'facts_at', 'label' => 'Date des faits', 'type' => 'date', 'required' => true],
            ['name' => 'amount', 'label' => 'Montant contesté (FCFA)', 'type' => 'number', 'placeholder' => '0'],
            ['name' => 'wish', 'label' => 'Résultat souhaité', 'type' => 'select', 'required' => true, 'options' => ['Règlement financier', 'Mesure corrective', 'Remplacement', 'Correction de pointage', 'Retrait d’un avis', 'Autre']],
            ['name' => 'urgent', 'label' => 'Urgence', 'type' => 'select', 'required' => true, 'options' => ['Non', 'Oui']],
            ['name' => 'proof', 'label' => 'Pièces', 'type' => 'file'],
            ['name' => 'sincere', 'label' => 'Déclaration de sincérité', 'type' => 'select', 'required' => true, 'options' => ['J’atteste l’exactitude des informations']],
            ['name' => 'consent', 'label' => 'Consentement aux échanges', 'type' => 'select', 'required' => true, 'options' => ['J’accepte la communication des éléments aux parties']],
        ]);
    }

    /**
     * Boutons communs : nouvelle réclamation, nouveau litige.
     *
     * @return array<int, array<string, mixed>>
     */
    protected static function createButtons(): array
    {
        return [self::newClaimButton(), UI::button('Nouveau litige', 'gavel', Q::can('create') ? UI::link('/avis-litiges/litiges/nouveau') : self::deny('création de litige'), 'primary')];
    }

    /**
     * Message à une partie (messagerie O’CHANTIER, coordonnées jamais exposées).
     *
     * @param  array<int, string>  $recipients
     * @return array<string, mixed>
     */
    protected static function contact(string $reference, array $recipients): array
    {
        return UI::form("Contacter · {$reference}", 'Message via la messagerie O’CHANTIER : les coordonnées privées ne sont jamais exposées. Le message est tracé dans le journal du dossier.', 'chat', 'Envoyer', '/avis-litiges/messages', [
            ['name' => 'to', 'label' => 'Destinataire', 'type' => 'select', 'required' => true, 'options' => $recipients],
            ['name' => 'channel', 'label' => 'Canal', 'type' => 'select', 'required' => true, 'options' => ['Messagerie', 'SMS', 'E-mail', 'Appel tracé']],
            ['name' => 'message', 'label' => 'Message', 'type' => 'textarea', 'required' => true],
        ], 'primary', ['reference' => $reference]);
    }

    /**
     * Événement de journal affichable.
     *
     * @return array<string, mixed>
     */
    public static function activity(QualityActivity $e): array
    {
        [$tone, $icon, $label] = match ($e->category) {
            'creation' => ['info', 'add_circle', 'Création'],
            'validation' => ['success', 'verified', 'Validation'],
            'publication' => ['success', 'public', 'Publication'],
            'controle' => ['neutral', 'fact_check', 'Contrôle'],
            'statut' => ['purple', 'swap_vert', 'Statut'],
            'notification' => ['info', 'notifications', 'Notification'],
            'communication' => ['info', 'chat', 'Communication'],
            'document' => ['info', 'description', 'Document'],
            'affectation' => ['orange', 'person_add', 'Affectation'],
            'diffusion' => ['purple', 'handshake', 'Médiation'],
            'anomalie' => ['danger', 'report', 'Signalement'],
            default => ['purple', 'edit', 'Modification'],
        };

        return [
            'id' => $e->id, 'date' => $e->occurred_at->format('Y-m-d'), 'time' => $e->occurred_at->format('H:i'), 'author' => $e->author,
            'category' => ['value' => $e->category, 'label' => $label, 'tone' => $tone, 'icon' => $icon],
            'action' => $e->action, 'oldValue' => $e->old_value, 'newValue' => $e->new_value, 'comment' => trim(($e->subject_reference ? "{$e->subject_reference} · " : '').($e->comment ?? ''), ' ·'), 'origin' => $e->origin,
        ];
    }

    /**
     * Décision de modération d’un avis (publier, masquer, rejeter, restaurer, enquête, fraude, archiver).
     *
     * @return array<string, mixed>
     */
    protected static function moderate(Review $r, string $decision): array
    {
        $right = ['Masqué temporairement' => 'hide', 'Restauré' => 'restore'][$decision] ?? 'mod';
        if (! Q::can($right)) {
            return self::deny(mb_strtolower($decision));
        }
        if ($decision === 'Publié' && ! $r->isEligible()) {
            return UI::error('Publication impossible : avis non éligible ('.($r->duplicate_of ? "doublon de {$r->duplicate_of}" : 'prestation non vérifiée').').');
        }
        if ($decision === 'Publié' && collect($r->detections)->contains(fn (array $d): bool => $d['status'] === 'À examiner' && in_array($d['level'], ['Critique', 'Élevé'], true))) {
            return UI::error('Traitez d’abord les détections de niveau élevé ou critique (onglet Modération).');
        }
        $motifs = ReviewActionController::MOTIFS[$decision];
        $title = ['Publié' => 'Publier l’avis', 'Masqué temporairement' => 'Masquer l’avis', 'Rejeté' => 'Rejeter l’avis', 'Restauré' => 'Restaurer l’avis', 'En réexamen' => 'Ouvrir une enquête', 'Fraude' => 'Qualifier l’avis de frauduleux', 'Archivé' => 'Archiver l’avis'][$decision];

        return UI::form("{$title} {$r->reference}", 'Décision humaine tracée. La note et le texte de l’auteur restent inchangés.'.($decision === 'Fraude' ? ' L’avis sera exclu de toutes les moyennes.' : ''), 'shield', 'Confirmer', "/avis-litiges/avis/{$r->reference}/moderation", [
            ['name' => 'motif', 'label' => 'Motif', 'type' => 'select', 'required' => true, 'options' => $motifs],
            ['name' => 'rule', 'label' => 'Règle appliquée', 'type' => 'select', 'required' => true, 'options' => array_column(config('admin.quality.moderation_rules'), 0)],
            ['name' => 'comment', 'label' => 'Commentaire', 'type' => 'textarea', 'required' => true],
        ], in_array($decision, ['Rejeté', 'Fraude'], true) ? 'danger' : (in_array($decision, ['Publié', 'Restauré'], true) ? 'primary' : 'warning'), ['decision' => $decision]);
    }

    /**
     * Signalement d’un avis par la cible, le promoteur ou la modération.
     *
     * @return array<string, mixed>
     */
    protected static function report(Review $r): array
    {
        return self::allowed('sig', UI::form("Signaler l’avis {$r->reference}", '', 'flag', 'Signaler', "/avis-litiges/avis/{$r->reference}/signalements", [
            ['name' => 'author', 'label' => 'Auteur du signalement', 'type' => 'select', 'required' => true, 'options' => ["{$r->target} (cible)", "{$r->promoter_name} (promoteur)", 'Modération O’CHANTIER']],
            ['name' => 'motif', 'label' => 'Motif', 'type' => 'select', 'required' => true, 'options' => ['Faux avis', 'Erreur d’identité', 'Mauvaise prestation', 'Propos offensant', 'Contenu confidentiel', 'Conflit d’intérêts', 'Preuve trompeuse', 'Harcèlement', 'Autre motif']],
            ['name' => 'comment', 'label' => 'Commentaire', 'type' => 'textarea', 'required' => true],
            ['name' => 'proof', 'label' => 'Preuve', 'type' => 'file'],
        ], 'warning'), 'signalement');
    }

    /**
     * Décision sur un signalement d’avis.
     *
     * @param  array<string, mixed>  $g
     * @return array<string, mixed>
     */
    protected static function decideReport(Review $r, array $g): array
    {
        return self::allowed('mod', UI::form('Décider du signalement', "{$g['motif']} · {$g['author']}", 'gavel', 'Enregistrer', "/avis-litiges/avis/{$r->reference}/signalements/{$g['id']}", [
            ['name' => 'decision', 'label' => 'Décision', 'type' => 'select', 'required' => true, 'options' => ['Signalement fondé · avis masqué', 'Signalement non fondé · avis maintenu', 'Transmis en réclamation']],
            ['name' => 'comment', 'label' => 'Motivation', 'type' => 'textarea', 'required' => true],
        ]), 'décision de signalement');
    }

    /**
     * Confirmer ou écarter une détection automatique.
     *
     * @param  array<string, mixed>  $d
     * @return array<string, mixed>
     */
    protected static function decideDetection(Review $r, int $index, array $d, string $status): array
    {
        return self::allowed('mod', UI::form(($status === 'Confirmée' ? 'Confirmer' : 'Écarter')." la détection « {$d['key']} »", 'La détection automatique ne supprime jamais un avis. Votre décision est tracée.', 'policy', 'Valider', "/avis-litiges/avis/{$r->reference}/detections/{$index}", [
            ['name' => 'reason', 'label' => 'Justification', 'type' => 'textarea', 'required' => true],
        ], 'primary', ['status' => $status]), 'détection');
    }

    /**
     * Modération du droit de réponse.
     *
     * @return array<string, mixed>
     */
    protected static function moderateReply(Review $r, string $status): array
    {
        $title = ['Publiée' => 'Publier la réponse', 'Rejetée' => 'Rejeter la réponse', 'Masquée' => 'Masquer la réponse'][$status];

        return self::allowed('mod', $status === 'Publiée'
            ? UI::form($title, 'La réponse ne modifie pas la note initiale.', 'reply', 'Confirmer', "/avis-litiges/avis/{$r->reference}/reponse", [], 'primary', ['status' => $status])
            : UI::form($title, 'La réponse ne modifie pas la note initiale.', 'reply', 'Confirmer', "/avis-litiges/avis/{$r->reference}/reponse", [['name' => 'reason', 'label' => 'Motif', 'type' => 'textarea', 'required' => true]], 'warning', ['status' => $status]), 'modération de la réponse');
    }

    /**
     * Colonnes et cellules d’une ligne d’avis (liste).
     *
     * @return array<string, mixed>
     */
    protected function reviewRow(Q $ins, Review $r): array
    {
        $score = $r->score();
        $cfg = config('admin.quality');
        $top = collect($r->criteriaDefinitions())->sortByDesc(fn (array $c): int => $c[2])->take(2)->map(fn (array $c): string => last(explode(' ', $c[1])).' '.($r->criteria[$c[0]] ?? '—'))->implode(' · ');
        $open = collect($r->reports)->where('status', '!=', 'Traité')->count();
        $go = UI::link($ins->url("/avis/{$r->reference}"));

        return UI::row($r->reference, [
            UI::text($r->reference, true), UI::text(Q::date($r->date->format('Y-m-d'))), UI::text($r->author), UI::text($r->author_type), UI::text($r->target, true), UI::badge($r->target_type, $r->target_type === 'Équipe' ? 'p' : 'b'),
            UI::stacked($r->site->name, $r->site->commune), UI::text($r->assignment_reference), UI::text($r->trade),
            UI::badge($score === null ? '—' : Q::one($score).' / 5', $score === null ? 'n' : ($score >= $cfg['satisfaction'] ? 'g' : ($score <= $cfg['negative'] ? 'r' : 'a'))), UI::text($top),
            $r->comment ? UI::text(mb_strimwidth($r->comment, 0, 60, '…')) : UI::text('—', false, true),
            UI::badge($r->is_test ? 'Test (exclu)' : ($r->duplicate_of ? 'Doublon bloqué' : ($r->isEligible() ? $r->verification : 'Non éligible')), $r->is_test || $r->duplicate_of || ! $r->isEligible() ? 'n' : ($r->verification === 'Vérifié' ? 'g' : 'a')),
            UI::badge($r->status, Review::TONES[$r->status] ?? 'n'), $open ? UI::text("{$open} ouvert(s)") : ($r->reports ? UI::text(count($r->reports).' traité(s)') : UI::text('—', false, true)),
            UI::badge($r->reply['status'], ['Publiée' => 'g', 'Non exercé' => 'n'][$r->reply['status']] ?? 'a'), UI::text($r->visibility),
        ], $go, [['visibility', 'Consulter', $go], ['chat', 'Contacter', self::contact($r->reference, [$r->author, $r->target])]], [
            ['public', 'Publier', self::moderate($r, 'Publié')], ['visibility_off', 'Masquer', self::moderate($r, 'Masqué temporairement')], ['flag', 'Signaler', self::report($r)],
            ['support_agent', 'Ouvrir une réclamation', self::newClaim($r)], ['construction', 'Voir le chantier', UI::link("/chantiers/{$r->site->slug}")],
        ]);
    }

    /**
     * @return array<int, string>
     */
    protected static function reviewColumns(): array
    {
        return ['Référence', 'Date', 'Auteur', 'Type d’auteur', 'Cible', 'Type de cible', 'Chantier', 'Affectation', 'Métier', 'Note globale', 'Principaux critères', 'Commentaire', 'Vérification', 'Modération', 'Signalements', 'Droit de réponse', 'Publication'];
    }

    /**
     * Ligne d’un litige (liste).
     *
     * @return array<string, mixed>
     */
    protected function disputeRow(Q $ins, Dispute $d, bool $selected = false): array
    {
        $sla = $d->sla();
        $ex = $d->exposure();
        $go = UI::link($ins->url("/litiges/{$d->reference}"));

        return UI::row($d->reference, [
            UI::text(($selected ? '☑ ' : '').$d->reference, true), UI::text(Q::date($d->opened_at->format('Y-m-d'))), UI::stacked($d->claimant['name'], $d->claimant['role']), UI::stacked($d->respondent['name'], $d->respondent['role']),
            UI::stacked($d->site->name, $d->site->commune), UI::text($d->category), $d->amount ? UI::text(Q::money($d->amount)) : UI::text('—', false, true),
            UI::badge($d->priority, Dispute::PRIORITY_TONES[$d->priority]), UI::badge($d->severity, Dispute::SEVERITY_TONES[$d->severity]), $d->owner ? UI::text($d->owner) : UI::text('Non affecté', false, true),
            UI::badge($d->status, Dispute::statusTone($d->status)), UI::text($sla['due'] ? "{$sla['phase']} · ".Q::date($sla['due']) : '—'), UI::badge($sla['text'], $sla['tone']),
            $ex['suspended'] ? UI::text('Suspendu '.Q::money($ex['suspended'])) : ($d->financial_requests ? UI::text(count($d->financial_requests).' demande(s) Paiements') : ($d->amount ? UI::text('Potentiel') : UI::text('Aucun', false, true))),
        ], $go, [['visibility', 'Ouvrir', $go], ['chat', 'Contacter', self::contact($d->reference, [$d->claimant['name'], $d->respondent['name']])]], [
            ['handshake', 'Médiations', UI::link($ins->url('/mediations'))], ['construction', 'Voir le chantier', UI::link("/chantiers/{$d->site->slug}")],
            ...($d->promoter?->slug ? [['apartment', 'Voir le promoteur', UI::link("/promoteurs/{$d->promoter->slug}")]] : []),
            ...($d->worker ? [['person', 'Profil de l’ouvrier', UI::link("/ouvriers/{$d->worker->slug}")]] : []),
            ['history', 'Journal d’audit', UI::link($ins->url('/audit'))],
        ]);
    }

    /**
     * @return array<int, string>
     */
    protected static function disputeColumns(): array
    {
        return ['Référence', 'Ouverture', 'Demandeur', 'Partie mise en cause', 'Chantier', 'Catégorie', 'Montant contesté', 'Priorité', 'Gravité', 'Responsable', 'Statut', 'Prochaine échéance', 'SLA restant', 'Impact financier'];
    }
}
