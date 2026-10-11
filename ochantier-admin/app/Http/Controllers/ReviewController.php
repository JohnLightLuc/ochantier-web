<?php

namespace App\Http\Controllers;

use App\Models\Dispute;
use App\Models\Review;
use App\Support\PageBlocks as UI;
use App\Support\QualityInsights as Q;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * Avis : liste (vues, tri, classement par note ajustée) et fiche à huit onglets (vue d’ensemble, notes,
 * commentaire, preuves, droit de réponse, signalements, modération, historique).
 */
class ReviewController extends QualityPageController
{
    public const VIEWS = [['', 'Tous les avis de la période'], ['verif', 'Avis vérifiés'], ['neg', 'Avis négatifs'], ['rep', 'Droits de réponse en attente'], ['team', 'Avis d’équipe'], ['excl', 'Avis exclus de la moyenne']];

    public const TABS = ['' => 'Vue d’ensemble', 'notes' => 'Détail des notes', 'commentaire' => 'Commentaire', 'preuves' => 'Preuves', 'reponse' => 'Droit de réponse', 'signalements' => 'Signalements', 'moderation' => 'Modération', 'historique' => 'Historique'];

    public function index(Request $request): Response
    {
        $ins = $this->insights($request);
        if (! Q::can('av')) {
            return $this->denied($ins, 'Votre rôle ne permet pas de consulter les avis.');
        }
        $cfg = config('admin.quality');
        $view = (string) $request->query('vue', '');
        $a = $ins->a;
        $base = $view === 'rep' ? $ins->reviewsScoped : $ins->reviewsInPeriod;
        $list = $ins->search($base->filter(fn (Review $r): bool => match ($view) {
            'verif' => $r->verification === 'Vérifié' && ! $r->is_test,
            'neg' => $r->isCounted() && $r->score() <= $cfg['negative'],
            'rep' => in_array($r->reply['status'], ['Soumise', 'En attente de modération'], true),
            'team' => $r->kind === 'Équipe',
            'excl' => ! $r->isCounted(),
            default => true,
        }), fn (Review $r): string => implode(' ', [$r->reference, $r->author, $r->target, $r->promoter_name, $r->site->name, $r->comment, $r->assignment_reference, collect($r->team['members'] ?? [])->pluck('name')->implode(' ')]));
        $list = match ($request->query('tri')) {
            'hi' => $list->sortByDesc(fn (Review $r): float => $r->score() ?? 0),
            'lo' => $list->sortBy(fn (Review $r): float => $r->score() ?? 0),
            'sig' => $list->sortByDesc(fn (Review $r): int => count($r->reports)),
            default => $list,
        };
        $p = UI::paginate($list->values(), (int) $request->query('page', 1), fn (Review $r): array => $this->reviewRow($ins, $r));
        $reference = $ins->all['avg'];
        $m = $cfg['bayes_m'];
        $ranking = $ins->reviewsScoped->filter(fn (Review $r): bool => $r->isCounted() && $r->kind === 'Ouvrier' && $r->worker_id)->groupBy('worker_id')->map(function ($g) use ($reference, $m, $cfg): array {
            $v = $g->count();
            $raw = $g->avg(fn (Review $r): ?float => $r->score());

            return ['worker' => $g->first()->worker, 'v' => $v, 'raw' => $raw, 'adjusted' => $reference === null ? $raw : ($v / ($v + $m)) * $raw + ($m / ($v + $m)) * $reference, 'sat' => $g->filter(fn (Review $r): bool => $r->score() >= $cfg['satisfaction'])->count()];
        })->sortByDesc('adjusted')->values();

        return $this->render($ins, '/avis', [
            ...$this->filterBar($ins, '/avis', ['promoteur', 'chantier', 'ouvrier', 'equipe', 'metier', 'commune', 'prestation', 'type_avis', 'niveau', 'moderation'], [
                $this->select($ins, 'vue', 'Vue', self::VIEWS, 'Vue : '), $this->select($ins, 'tri', 'Tri', [['', 'plus récents'], ['hi', 'note décroissante'], ['lo', 'note croissante'], ['sig', 'signalements']], 'Trier : '),
            ]), 'search' => true,
            'tiles' => [
                UI::tile('Avis affichés', $list->count(), 'chat_bubble', 'b'), UI::tile('Note moyenne', $a['avg'] === null ? 'Non calculable' : Q::two($a['avg']).' / 5', 'star', 'a', ['sub' => "{$a['n']} avis éligibles · {$a['excl']} exclus"]),
                UI::tile('Taux de satisfaction', Q::percent(Q::ratio($a['sat'], $a['n'])), 'sentiment_satisfied', 'g', ['bar' => Q::ratio($a['sat'], $a['n']) ?? 0, 'sub' => "Seuil {$cfg['satisfaction']} / 5 (Paramètres) · distinct de la note moyenne"]),
                UI::tile('Taux de collecte', Q::percent(Q::ratio($a['collected'], $a['elig'])), 'rate_review', 'b', ['bar' => Q::ratio($a['collected'], $a['elig']) ?? 0, 'sub' => "{$a['collected']} / {$a['elig']} prestations éligibles"]),
            ],
            'toolbar' => [
                UI::button('Contrôler l’éligibilité d’un avis', 'fact_check', UI::form('Contrôler l’éligibilité d’un avis', 'Un avis ne peut être déposé que par l’auteur réellement lié à une prestation terminée. L’administration ne crée jamais d’avis au nom d’un utilisateur. Si tous les contrôles sont conformes, le dépôt depuis l’application Promoteur est simulé.', 'fact_check', 'Contrôler', '/avis-litiges/avis/eligibilite', [
                    ['name' => 'prestation', 'label' => 'Prestation', 'type' => 'select', 'required' => true, 'options' => self::prestationOptions(), 'full' => true],
                    ['name' => 'author', 'label' => 'Auteur', 'type' => 'select', 'required' => true, 'options' => ReviewActionController::AUTHORS],
                    ['name' => 'kind', 'label' => 'Cible', 'type' => 'select', 'required' => true, 'options' => ['Ouvrier individuel', 'Équipe contractuelle']],
                ])),
                UI::button('Exporter', 'download', self::allowed('export', UI::download($ins->url('/rapports/generer', ['rapport' => 'liste-avis', 'format' => 'Excel'])), 'export'), 'primary'),
            ],
            'tables' => [
                UI::table('star', 'Avis', $p['label'], self::reviewColumns(), $p['rows'], 2600, ['search_off', 'Aucun résultat', 'Aucun avis ne correspond à la recherche, à la période ou aux filtres.'], $p['pagination']),
                UI::table('leaderboard', 'Classement des ouvriers · note ajustée', "Moyenne bayésienne · m = {$m} · C = ".Q::two($reference).($cfg['bayes_matching'] ? ' · utilisée par le Matching' : ' · non utilisée par le Matching'), ['Rang', 'Ouvrier', 'Métier', 'Note publique brute', 'Avis éligibles', 'Note ajustée (classement)', 'Taux de satisfaction'], $ranking->take(15)->map(fn (array $x, int $i): array => UI::row((string) $x['worker']->id, [
                    UI::text((string) ($i + 1), true), UI::person($x['worker']->name, $x['worker']->code), UI::text($x['worker']->trade), UI::text(Q::two($x['raw']).' / 5'), UI::text((string) $x['v']), UI::badge(Q::two($x['adjusted']), 'b'), UI::text(Q::percent(Q::ratio($x['sat'], $x['v']))),
                ], UI::link("/ouvriers/{$x['worker']->slug}"), [['person', 'Fiche ouvrier', UI::link("/ouvriers/{$x['worker']->slug}")]]))->all(), 1100, ['leaderboard', 'Aucun profil noté']),
            ],
        ], 'Avis', 'Évaluations des promoteurs sur les ouvriers et les équipes · note brute, note moyenne et note ajustée distinctes', [['Avis']]);
    }

    public function show(Request $request, Review $review, string $tab = ''): Response
    {
        $ins = $this->insights($request);
        if (! Q::can('av')) {
            return $this->denied($ins, 'Votre rôle ne permet pas de consulter les avis.');
        }
        abort_unless(array_key_exists($tab, self::TABS), 404);
        $r = $review->load('site', 'worker', 'promoter');
        $cfg = config('admin.quality');
        $score = $r->score();
        $criteria = $r->criteriaDefinitions();
        $base = "/avis/{$r->reference}";
        $openReports = collect($r->reports)->where('status', '!=', 'Traité')->count();
        $tabs = collect(self::TABS)->map(fn (string $label, string $key): array => UI::tab($label, $ins->url($base.($key ? "/{$key}" : '')), match ($key) {
            'preuves' => count($r->proofs),
            'reponse' => in_array($r->reply['status'], ['Soumise', 'En attente de modération'], true) ? 1 : 0,
            'signalements' => $openReports,
            'moderation' => collect($r->detections)->where('status', 'À examiner')->count(),
            default => 0,
        }, $key === $tab))->values()->all();
        $buttons = [];
        if (in_array($r->status, ['Soumis', 'En cours de vérification', 'Vérifié', 'En attente de modération', 'En réexamen', 'Signalé', 'Contesté'], true)) {
            $buttons[] = UI::button('Publier', 'public', self::moderate($r, 'Publié'), 'primary');
        }
        if (! in_array($r->status, ['Masqué temporairement', 'Rejeté', 'Annulé', 'Archivé', 'Brouillon'], true)) {
            $buttons[] = UI::button('Masquer', 'visibility_off', self::moderate($r, 'Masqué temporairement'));
        }
        if (in_array($r->status, ['Masqué temporairement', 'Rejeté', 'Archivé'], true)) {
            $buttons[] = UI::button('Restaurer', 'settings_backup_restore', self::moderate($r, 'Restauré'));
        }
        if (! in_array($r->status, ['Rejeté', 'Annulé', 'Archivé'], true)) {
            $buttons[] = UI::button('Rejeter', 'block', self::moderate($r, 'Rejeté'), 'danger-soft');
        }
        $openDisputes = Dispute::query()->whereNotIn('status', ['Clôturé', 'Archivé', 'Annulé'])->orderBy('reference')->get(['reference', 'subject']);
        $menu = [
            ['person', 'Voir l’auteur', $r->promoter ? UI::link("/promoteurs/{$r->promoter->slug}") : UI::error('Auteur interne : aucune fiche promoteur.')],
            ...($r->worker ? [['engineering', 'Voir la cible', UI::link("/ouvriers/{$r->worker->slug}")]] : []),
            ['construction', 'Voir le chantier', UI::link("/chantiers/{$r->site->slug}")], ['assignment_ind', 'Voir l’affectation', UI::link('/affectations')],
            ['edit_note', 'Demander une modification à l’auteur', self::allowed('mod', UI::reason('Demander une modification à l’auteur', "{$r->reference} · l’auteur reçoit une notification ; l’administration ne modifie jamais le texte ni la note.", 'edit_note', 'Envoyer la demande', "/avis-litiges/avis/{$r->reference}/correction", 'Correction demandée'), 'demande de correction')],
            ['flag', 'Signaler', self::report($r)], ['support_agent', 'Ouvrir une réclamation', self::newClaim($r)],
            ['link', 'Rattacher à un litige', UI::form("Rattacher {$r->reference} à un litige", '', 'link', 'Rattacher', "/avis-litiges/avis/{$r->reference}/litige", [['name' => 'dispute', 'label' => 'Litige', 'type' => 'select', 'required' => true, 'full' => true, 'options' => $openDisputes->map(fn (Dispute $d): array => ['value' => $d->reference, 'label' => "{$d->reference} · {$d->subject}"])->all()]])],
            ['manage_search', 'Ouvrir une enquête', self::moderate($r, 'En réexamen')], ['gpp_bad', 'Qualifier de frauduleux', self::moderate($r, 'Fraude')], ['inventory_2', 'Archiver', self::moderate($r, 'Archivé')],
        ];
        $profile = UI::profile("{$r->reference} · {$r->target}", [$r->status, Review::TONES[$r->status] ?? 'n'], ["Visibilité : {$r->visibility}", $r->visibility === 'Public' ? 'g' : 'n'],
            ($r->kind === 'Équipe' ? 'Avis d’équipe' : ($r->kind === 'Interne' ? 'Évaluation interne' : 'Avis individuel')).' · note '.($score === null ? '—' : Q::one($score).' / 5').($r->is_test ? ' · Test' : ''),
            [['person', 'Auteur', $r->author], ['engineering', 'Cible', $r->target], ['construction', 'Chantier', "{$r->site->name} · {$r->site->commune}"], ['assignment_ind', 'Affectation', $r->assignment_reference], ['event', 'Date', Q::date($r->date->format('Y-m-d'))]],
            $buttons, $menu);

        $alerts = [UI::alert('info', 'lock', 'Contenu de l’auteur non modifiable', 'La note, le texte et les critères choisis par l’auteur ne peuvent pas être modifiés par l’administration : publier, masquer, rejeter, restaurer ou demander une correction à l’auteur.')];
        if (! $r->isEligible()) {
            $labels = ['auth' => 'auteur non authentifié', 'linked' => 'auteur non lié à la prestation', 'affConf' => 'affectation non confirmée ou annulée', 'done' => 'prestation non terminée', 'ptClosed' => 'pointages non clôturés', 'target' => 'cible incorrecte', 'window' => 'période d’évaluation expirée', 'noDup' => "doublon : {$r->duplicate_of}", 'self' => 'autoévaluation'];
            $alerts[] = UI::alert('danger', 'block', 'Avis non éligible', collect($r->eligibility)->filter(fn (bool $v): bool => ! $v)->keys()->map(fn (string $k): string => $labels[$k])->implode(' · ').'. Exclu de la moyenne.');
        }
        if ($r->is_test) {
            $alerts[] = UI::alert('info', 'science', 'Avis de test', 'N’alimente aucun indicateur.');
        }
        if ($r->modification_request) {
            $alerts[] = UI::alert('warning', 'edit_note', 'Correction demandée à l’auteur', $r->modification_request);
        }
        if ($r->dispute_reference && ($d = Dispute::query()->where('reference', $r->dispute_reference)->first())) {
            $alerts[] = UI::alert('info', 'link', "Rattaché au litige {$d->reference}", $d->subject, 'Ouvrir', UI::link($ins->url("/litiges/{$d->reference}")));
        }
        $props = ['profile' => $profile, 'tabs' => $tabs, 'alerts' => $alerts];

        switch ($tab) {
            case '':
                $props['tiles'] = [
                    UI::tile('Note globale', $score === null ? '—' : Q::two($score).' / 5', 'star', 'a', ['bar' => ($score ?? 0) / 5 * 100, 'sub' => "Pondérations {$r->weights_version}"]),
                    UI::tile('Critères notés', count($r->criteria).' / '.count($criteria), 'checklist', 'b'), UI::tile('Inclus dans la moyenne', $r->isCounted() ? 'Oui' : 'Non', 'functions', $r->isCounted() ? 'g' : 'n'),
                    UI::tile('Signalements', count($r->reports), 'flag', $r->reports ? 'r' : 'n'),
                ];
                $props['panels'] = [
                    UI::keyValues('link', 'Prestation évaluée', [
                        ['Promoteur', $r->promoter_name], ['Chantier', "{$r->site->name} · {$r->site->commune}"], ['Affectation', $r->assignment_reference], ['Prestation', "{$r->prestation} · ".Q::date($r->period_from->format('Y-m-d')).' → '.Q::date($r->period_to->format('Y-m-d'))],
                        ['Paiement source', $r->operation_reference], ['Métier', $r->trade],
                        ['Type d’évaluation', match ($r->kind) {
                            'Équipe' => 'Avis du promoteur sur une équipe contractuelle', 'Interne' => 'Évaluation interne autorisée', default => 'Avis du promoteur sur un ouvrier individuel',
                        }], ['Appareil', $r->device],
                    ]),
                    UI::cards('fact_check', 'Contrôle d’éligibilité', collect(Review::ELIGIBILITY)->map(fn (string $label, string $k): array => UI::card($r->eligibility[$k] ? 'check_circle' : 'cancel', 'Règle ELIG', $k === 'window' ? "Période d’évaluation de {$cfg['review_window']} j respectée" : $label, $r->eligibility[$k] ? 'Conforme' : 'Non conforme', $r->eligibility[$k] ? 'OK' : 'Bloquant', $r->eligibility[$k] ? 'g' : 'r', null))->values()->all()),
                ];
                break;
            case 'notes':
                $weights = collect($criteria)->sum(fn (array $c): int => ($r->criteria[$c[0]] ?? null) ? $c[2] : 0);
                $props['alerts'][] = UI::alert('info', 'functions', 'Calcul', 'Note globale = Σ (note × pondération) ÷ Σ pondérations des critères notés. Note brute ≠ note moyenne du profil ≠ note ajustée de classement ≠ taux de satisfaction.');
                $props['tables'] = [UI::table('star', 'Détail des notes', "Pondérations {$r->weights_version} · total ".collect($criteria)->sum(2).' %', ['Critère', 'Note (1 à 5)', 'Pondération', 'Contribution'], [
                    ...array_map(fn (array $c): array => UI::row($c[0], [UI::text($c[1], true), UI::text(isset($r->criteria[$c[0]]) ? "{$r->criteria[$c[0]]} / 5" : 'Non noté'), UI::text("{$c[2]} %"), UI::text(isset($r->criteria[$c[0]]) ? Q::two($r->criteria[$c[0]] * $c[2] / max(1, $weights)) : '—')]), $criteria),
                    UI::row('total', [UI::text('Note globale', true), UI::text(''), UI::text("{$weights} %"), UI::text(Q::two($score).' / 5', true)]),
                ], 800)];
                break;
            case 'commentaire':
                $props['panels'] = [UI::keyValues('format_quote', 'Contenu déposé par l’auteur', [['Commentaire', $r->comment ?: 'Aucun commentaire'], ['Points positifs', $r->positives], ['Points à améliorer', $r->negatives], ['Recommandation', $r->recommends], ['Accord pour publication', $r->publish_consent ? 'Oui, selon les règles de publication' : 'Non'], ['Déclaration de sincérité', $r->sincere ? 'Acceptée' : 'Non acceptée']])];
                if ($r->team) {
                    $props['panels'][] = UI::keyValues('groups', 'Portée de l’évaluation d’équipe', [['Concerne', $r->team['scope']], ['Chef d’équipe', $r->team['chief']], ['Membres', collect($r->team['members'])->map(fn (array $m): string => "{$m['name']} ({$m['trade']})")->implode(', ')], ['Report sur les membres', 'Non : la note d’équipe n’est pas copiée sur chaque ouvrier']]);
                }
                break;
            case 'preuves':
                $props['tables'] = [UI::table('photo_library', 'Photos et justificatifs', count($r->proofs).' fichier(s)', ['Fichier', 'Taille', 'Déposé le', 'Visibilité'], array_map(fn (array $x): array => UI::row($x['name'], [UI::text($x['name'], true), UI::text($x['size']), UI::text(Q::date($r->date->format('Y-m-d'))), UI::text('Parties et modération')]), $r->proofs), 700, ['image', 'Aucune pièce', 'L’auteur n’a joint aucune photo.'])];
                break;
            case 'reponse':
                $reply = $r->reply;
                $props['panels'] = [UI::keyValues('reply', "Droit de réponse · {$reply['status']}", [['Statut', $reply['status']], ['Auteur', $reply['author'] ?? ($r->team ? "{$r->team['chief']} (équipe)" : $r->target)], ['Date', Q::date($reply['date'] ?? null)], ['Texte', $reply['text'] ?? '—'], ['Pièces', implode(', ', $reply['pieces'] ?? []) ?: '—'], ['Effet sur la note', 'Aucun : la réponse ne modifie pas la note initiale']])];
                $props['toolbar'] = [
                    ...($reply['status'] === 'Non exercé' ? [UI::button('Notifier le droit de réponse', 'notifications', UI::post("/avis-litiges/avis/{$r->reference}/reponse/notification"))] : []),
                    ...(in_array($reply['status'], ['Non exercé', 'Réponse en préparation'], true) ? [UI::button('Simulation · réponse reçue (application)', 'smartphone', UI::form("Réponse de {$r->target}", 'Simulation d’une réponse déposée depuis l’application Ouvrier.', 'reply', 'Soumettre', "/avis-litiges/avis/{$r->reference}/reponse/depot", [['name' => 'text', 'label' => 'Texte de la réponse', 'type' => 'textarea', 'required' => true], ['name' => 'file', 'label' => 'Pièce éventuelle', 'type' => 'file']]))] : []),
                    ...(in_array($reply['status'], ['Soumise', 'En attente de modération'], true) ? [UI::button('Publier la réponse', 'public', self::moderateReply($r, 'Publiée'), 'primary'), UI::button('Rejeter la réponse', 'block', self::moderateReply($r, 'Rejetée'), 'danger-soft')] : []),
                    ...($reply['status'] === 'Publiée' ? [UI::button('Masquer la réponse', 'visibility_off', self::moderateReply($r, 'Masquée'))] : []),
                ];
                break;
            case 'signalements':
                $props['toolbar'] = [UI::button('Signaler l’avis', 'flag', self::report($r), 'primary')];
                $props['tables'] = [UI::table('flag', 'Signalements', count($r->reports).' signalement(s)', ['Auteur', 'Motif', 'Commentaire', 'Preuve', 'Date', 'Statut', 'Décision'], array_map(fn (array $g): array => UI::row($g['id'], [
                    UI::text($g['author'], true), UI::text($g['motif']), UI::text($g['comment']), UI::text($g['proof'] ?: '—'), UI::text(Q::date($g['date'])), UI::badge($g['status'], $g['status'] === 'Traité' ? 'g' : 'a'), UI::text($g['decision'] ?: '—'),
                ], null, $g['status'] !== 'Traité' ? [['gavel', 'Décider', self::decideReport($r, $g)]] : []), $r->reports), 1100, ['outlined_flag', 'Aucun signalement'])];
                break;
            case 'moderation':
                $md = $r->moderation;
                $props['panels'] = [
                    UI::keyValues('shield', 'Dernière décision de modération', $md ? [['Décision', $md['decision']], ['Motif', $md['motif']], ['Règle appliquée', $md['rule']], ['Commentaire', $md['comment']], ['Auteur de la décision', $md['by']], ['Date', Q::date($md['date'])]] : [['Décision', 'Aucune décision humaine enregistrée']]),
                    UI::keyValues('rule', 'Règles de modération', $cfg['moderation_rules']),
                ];
                $props['tables'] = [UI::table('policy', 'Détections automatiques', 'Signalement uniquement · décision humaine obligatoire', ['Détection', 'Niveau', 'Détail', 'Statut'], array_map(fn (array $d, int $i): array => UI::row((string) $i, [
                    UI::text($d['key'], true), UI::badge($d['level'], self::DETECTION_TONES[$d['level']] ?? 'n'), UI::text($d['detail']), UI::badge($d['status'], $d['status'] === 'À examiner' ? 'a' : 'g'),
                ], null, $d['status'] === 'À examiner' ? [['task_alt', 'Confirmer', self::decideDetection($r, $i, $d, 'Confirmée')], ['close', 'Écarter', self::decideDetection($r, $i, $d, 'Écartée')]] : []), $r->detections, array_keys($r->detections)), 1000, ['verified_user', 'Aucune détection'])];
                break;
            case 'historique':
                $props['timeline'] = $r->activities()->get()->map(fn ($e): array => self::activity($e))->all();
                break;
        }

        return $this->render($ins, '/avis', $props, "Avis {$r->reference}", '', [['Avis', $ins->url('/avis')], [$r->reference, $tab ? $ins->url($base) : null], ...($tab ? [[self::TABS[$tab]]] : [])]);
    }
}
