<?php

namespace App\Http\Controllers;

use App\Models\QualityNotification;
use App\Models\Review;
use App\Support\QualityInsights as Q;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Actions sur les avis : décisions de modération (humaines, motivées et tracées), demande de correction à l’auteur,
 * signalements et décisions, détections automatiques, droit de réponse, rattachement à un litige et contrôle
 * d’éligibilité d’un nouvel avis. Le texte et la note de l’auteur ne sont jamais modifiés.
 */
class ReviewActionController extends Controller
{
    /**
     * Motifs proposés pour chaque décision de modération.
     *
     * @var array<string, array<int, string>>
     */
    public const MOTIFS = [
        'Publié' => ['Contenu conforme', 'Signalement non fondé', 'Correction effectuée par l’auteur'],
        'Masqué temporairement' => ['Enquête en cours', 'Données personnelles', 'Contenu offensant', 'Litige en cours'],
        'Rejeté' => ['Insultes ou menaces', 'Propos discriminatoires', 'Contenu sans rapport', 'Spam', 'Doublon', 'Conflit d’intérêts', 'Non éligible'],
        'Restauré' => ['Enquête close sans manquement', 'Recours accepté', 'Erreur de modération'],
        'En réexamen' => ['Suspicion de manipulation', 'Preuve réutilisée', 'Relation non vérifiée'],
        'Fraude' => ['Avis coordonnés', 'Relation fictive', 'Preuve falsifiée'],
        'Archivé' => ['Durée de conservation atteinte', 'Prestation archivée'],
    ];

    public const AUTHORS = ['Promoteur de la prestation (authentifié)', 'Autre promoteur', 'Ouvrier évalué (autoévaluation)', 'Administrateur au nom du promoteur', 'Auteur anonyme'];

    private function authorizeRight(string $right): void
    {
        abort_unless(Q::can($right), 403, Q::denied());
    }

    private function user(): string
    {
        return auth()->user()?->name ?? 'Système';
    }

    public function moderate(Request $request, Review $review): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(array_keys(self::MOTIFS))],
            'motif' => ['required', 'string'],
            'rule' => ['required', Rule::in(array_column(config('admin.quality.moderation_rules'), 0))],
            'comment' => ['required', 'string', 'min:10'],
        ]);
        $decision = $data['decision'];
        $this->authorizeRight(['Masqué temporairement' => 'hide', 'Restauré' => 'restore'][$decision] ?? 'mod');
        if ($decision === 'Publié' && ! $review->isEligible()) {
            throw ValidationException::withMessages(['decision' => 'Publication impossible : avis non éligible.']);
        }
        if ($decision === 'Publié' && collect($review->detections)->contains(fn (array $d): bool => $d['status'] === 'À examiner' && in_array($d['level'], ['Critique', 'Élevé'], true))) {
            throw ValidationException::withMessages(['decision' => 'Traitez d’abord les détections de niveau élevé ou critique.']);
        }
        $old = $review->status;
        $status = $decision === 'Fraude' ? 'Rejeté' : $decision;
        $label = $decision === 'Fraude' ? 'Frauduleux confirmé' : $status;
        $review->fill([
            'status' => $status,
            'visibility' => in_array($status, ['Publié', 'Restauré'], true) ? 'Public' : ($status === 'En réexamen' ? $review->visibility : 'Non public'),
            'fraud' => $review->fraud || $decision === 'Fraude',
            'verification' => $status === 'Publié' ? 'Vérifié' : $review->verification,
            'moderation' => ['decision' => $label, 'motif' => $data['motif'], 'rule' => $data['rule'], 'comment' => trim($data['comment']), 'by' => $this->user(), 'date' => Q::today()->format('Y-m-d')],
            'detections' => $decision === 'Publié' ? $review->detections : array_map(fn (array $d): array => $d['status'] === 'À examiner' ? [...$d, 'status' => 'Confirmée'] : $d, $review->detections),
        ])->save();
        $review->log($decision === 'Publié' ? 'publication' : 'statut', 'a pris une décision de modération', $old, $label, "{$data['motif']} · {$data['rule']} · ".trim($data['comment']));
        if ($decision === 'Publié') {
            QualityNotification::send($review->target, 'Push', 'Un nouvel avis est publié sur votre profil', 'Droit de réponse disponible', $review->reference);
        }
        Inertia::flash('toast', 'Décision enregistrée : '.($decision === 'Fraude' ? 'frauduleux' : mb_strtolower($status)).'.');

        return back();
    }

    /**
     * Demande de correction à l’auteur (l’administration ne modifie jamais le texte ni la note).
     */
    public function askChange(Request $request, Review $review): RedirectResponse
    {
        $this->authorizeRight('mod');
        $reason = trim($request->validate(['reason' => ['required', 'string', 'min:10']])['reason']);
        $old = $review->status;
        $review->update(['modification_request' => $reason, 'status' => 'En attente de modération', 'visibility' => 'En attente']);
        QualityNotification::send($review->author, 'E-mail', "Correction demandée sur votre avis {$review->reference}", 'Modifier l’avis', $review->reference);
        $review->log('notification', 'a demandé une correction à l’auteur', $old, 'En attente de modération', $reason);
        Inertia::flash('toast', 'Demande envoyée à l’auteur.');

        return back();
    }

    public function report(Request $request, Review $review): RedirectResponse
    {
        $this->authorizeRight('sig');
        $data = $request->validate(['author' => ['required', 'string'], 'motif' => ['required', 'string'], 'comment' => ['required', 'string', 'min:10'], 'proof' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240']]);
        $old = $review->status;
        $review->update([
            'reports' => [...$review->reports, ['id' => 'sg-'.(count($review->reports) + 1), 'author' => $data['author'], 'motif' => $data['motif'], 'comment' => trim($data['comment']), 'proof' => $request->file('proof')?->getClientOriginalName() ?? '', 'date' => Q::today()->format('Y-m-d'), 'status' => 'Ouvert', 'decision' => '']],
            'status' => in_array($review->status, ['Publié', 'Restauré'], true) ? 'Signalé' : $review->status,
        ]);
        QualityNotification::send('Modération', 'Push', "Avis {$review->reference} signalé : {$data['motif']}", 'Examiner le signalement', $review->reference);
        $review->log('anomalie', 'a signalé l’avis', $old, $review->status, "{$data['motif']} · ".trim($data['comment']));
        Inertia::flash('toast', 'Signalement enregistré.');

        return back();
    }

    /**
     * Décision sur un signalement : fondé (avis masqué), non fondé (avis maintenu) ou transmis en réclamation.
     */
    public function decideReport(Request $request, Review $review, string $report): RedirectResponse
    {
        $this->authorizeRight('mod');
        $data = $request->validate(['decision' => ['required', Rule::in(['Signalement fondé · avis masqué', 'Signalement non fondé · avis maintenu', 'Transmis en réclamation'])], 'comment' => ['required', 'string', 'min:10']]);
        abort_unless(collect($review->reports)->contains('id', $report), 404);
        $old = $review->status;
        $decision = "{$data['decision']} · ".trim($data['comment']);
        $status = match (true) {
            str_contains($data['decision'], 'masqué') => 'Masqué temporairement',
            str_contains($data['decision'], 'maintenu') && in_array($review->status, ['Signalé', 'Contesté'], true) => 'Publié',
            default => $review->status,
        };
        $review->update([
            'reports' => array_map(fn (array $g): array => $g['id'] === $report ? [...$g, 'status' => 'Traité', 'decision' => $decision] : $g, $review->reports),
            'status' => $status, 'visibility' => $status === 'Masqué temporairement' ? 'Non public' : $review->visibility,
        ]);
        $review->log('controle', 'a traité un signalement', $old, $status, $decision);
        if ($data['decision'] === 'Transmis en réclamation') {
            $claim = ClaimActionController::open($review->load('site'), 'Avis contesté', "Contestation de l’avis {$review->reference}", trim($data['comment']), 'Normale', 'Ouvrier de la prestation');
            Inertia::flash('toast', "Signalement traité · réclamation {$claim->reference} ouverte.");

            return redirect("/avis-litiges/reclamations/{$claim->reference}");
        }
        Inertia::flash('toast', 'Signalement traité.');

        return back();
    }

    public function decideDetection(Request $request, Review $review, int $index): RedirectResponse
    {
        $this->authorizeRight('mod');
        $data = $request->validate(['status' => ['required', Rule::in(['Confirmée', 'Écartée'])], 'reason' => ['required', 'string', 'min:10']]);
        $detection = $review->detections[$index] ?? abort(404);
        abort_unless($detection['status'] === 'À examiner', 422);
        $detections = $review->detections;
        $detections[$index] = [...$detection, 'status' => $data['status'], 'by' => $this->user()];
        $review->update(['detections' => $detections]);
        $review->log('controle', 'a '.($data['status'] === 'Confirmée' ? 'confirmé' : 'écarté').' une détection', 'À examiner', $data['status'], "{$detection['key']} · ".trim($data['reason']));
        Inertia::flash('toast', 'Détection '.mb_strtolower($data['status']).'.');

        return back();
    }

    /**
     * Modération du droit de réponse (sans effet sur la note).
     */
    public function moderateReply(Request $request, Review $review): RedirectResponse
    {
        $this->authorizeRight('mod');
        $data = $request->validate(['status' => ['required', Rule::in(['Publiée', 'Rejetée', 'Masquée'])], 'reason' => [Rule::requiredIf($request->input('status') !== 'Publiée'), 'nullable', 'string', 'min:10']]);
        $old = $review->reply['status'];
        $review->update(['reply' => [...$review->reply, 'status' => $data['status']]]);
        $review->log($data['status'] === 'Publiée' ? 'publication' : 'statut', 'a modéré le droit de réponse', $old, $data['status'], $data['reason'] ?? 'Contenu conforme');
        Inertia::flash('toast', 'Réponse '.mb_strtolower($data['status']).'.');

        return back();
    }

    public function notifyReply(Review $review): RedirectResponse
    {
        abort_unless($review->reply['status'] === 'Non exercé', 422);
        $review->update(['reply' => [...$review->reply, 'status' => 'Réponse en préparation']]);
        QualityNotification::send($review->target, 'SMS', "Vous pouvez répondre à l’avis {$review->reference}", 'Répondre sous 7 jours', $review->reference);
        $review->log('notification', 'a notifié le droit de réponse', 'Non exercé', 'Réponse en préparation');
        Inertia::flash('toast', 'Droit de réponse notifié.');

        return back();
    }

    /**
     * Simulation d’une réponse déposée depuis l’application Ouvrier.
     */
    public function submitReply(Request $request, Review $review): RedirectResponse
    {
        abort_unless(in_array($review->reply['status'], ['Non exercé', 'Réponse en préparation'], true), 422);
        $data = $request->validate(['text' => ['required', 'string', 'min:15'], 'file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240']]);
        $old = $review->reply['status'];
        $review->update(['reply' => ['status' => 'En attente de modération', 'text' => trim($data['text']), 'author' => $review->target, 'date' => Q::today()->format('Y-m-d'), 'pieces' => $request->file('file') ? [$request->file('file')->getClientOriginalName()] : []]]);
        $review->log('creation', 'a soumis une réponse', $old, 'En attente de modération', 'Via l’application', $review->target, 'Application Ouvrier');
        Inertia::flash('toast', 'Réponse soumise à la modération.');

        return back();
    }

    public function link(Request $request, Review $review): RedirectResponse
    {
        $reference = $request->validate(['dispute' => ['required', Rule::exists('disputes', 'reference')]])['dispute'];
        $review->update(['dispute_reference' => $reference]);
        $review->log('modification', 'a rattaché l’avis à un litige', null, $reference);
        Inertia::flash('toast', "Avis rattaché au litige {$reference}.");

        return back();
    }

    /**
     * Contrôle d’éligibilité d’un nouvel avis : bloque le dépôt (motifs affichés) ou simule le dépôt depuis
     * l’application Promoteur.
     */
    public function eligibility(Request $request): RedirectResponse
    {
        $data = $request->validate(['prestation' => ['required', 'string'], 'author' => ['required', Rule::in(self::AUTHORS)], 'kind' => ['required', Rule::in(['Ouvrier individuel', 'Équipe contractuelle'])]]);
        $p = Q::prestations()->firstWhere('opRef', $data['prestation']) ?? throw ValidationException::withMessages(['prestation' => 'Prestation inconnue.']);
        $team = $data['kind'] === 'Équipe contractuelle';
        $existing = Review::query()->where('operation_reference', $p['opRef'])->where('kind', $team ? 'Équipe' : 'Ouvrier')->where('is_test', false)->whereNotIn('status', ['Annulé', 'Rejeté', 'Archivé'])->value('reference');
        $age = (int) round(CarbonImmutable::parse($p['to'])->diffInDays(Q::today(), false));
        $window = config('admin.quality.review_window');
        $checks = [
            'Auteur authentifié' => $data['author'] !== 'Auteur anonyme',
            'Auteur lié à la prestation' => str_starts_with($data['author'], 'Promoteur de la prestation'),
            'Pas d’autoévaluation' => ! str_starts_with($data['author'], 'Ouvrier évalué'),
            'Pas de saisie par un administrateur' => ! str_starts_with($data['author'], 'Administrateur'),
            'Prestation terminée et pointages clôturés' => $age >= 0,
            "Période d’évaluation ({$window} j) non expirée · prestation terminée depuis {$age} j" => $age <= $window,
            'Aucun avis actif identique'.($existing ? " · doublon de {$existing}" : '') => ! $existing,
        ];
        $blocking = array_keys(array_filter($checks, fn (bool $ok): bool => ! $ok));
        if ($blocking) {
            throw ValidationException::withMessages(['author' => 'Avis bloqué, aucune donnée créée : '.implode(' ; ', $blocking).'.']);
        }
        $number = (int) Review::query()->max('id') + 1;
        $criteria = collect(config('admin.quality.criteria')[$team ? 'Équipe' : 'Ouvrier'])->mapWithKeys(fn (array $c, int $i): array => [$c[0] => 4 + $i % 2])->all();
        $review = Review::create([
            'reference' => 'AV-'.Q::today()->year.'-'.sprintf('%04d', $number), 'kind' => $team ? 'Équipe' : 'Ouvrier', 'author' => "{$p['author']} · {$p['promoter']}", 'author_type' => 'Promoteur',
            'promoter_id' => $p['promoterId'], 'promoter_name' => $p['promoter'], 'site_id' => $p['siteId'], 'worker_id' => $p['workerId'], 'target' => $team ? 'Équipe '.explode(' ', $p['worker'])[0] : $p['worker'],
            'target_type' => $team ? 'Équipe' : 'Ouvrier', 'team' => $team ? ['chief' => $p['worker'], 'members' => [['name' => $p['worker'], 'trade' => $p['trade'], 'slug' => $p['workerSlug']]], 'scope' => 'Toute l’équipe'] : null,
            'operation_reference' => $p['opRef'], 'assignment_reference' => $p['assignment'], 'trade' => $p['trade'], 'prestation' => $p['prestation'], 'period_from' => $p['from'], 'period_to' => $p['to'],
            'date' => Q::today(), 'weights_version' => config('admin.quality.weights_version'), 'criteria' => $criteria, 'comment' => 'Prestation conforme, bonne tenue du chantier.', 'positives' => 'Ponctualité', 'negatives' => '',
            'recommends' => 'Oui', 'proofs' => [], 'verification' => 'En cours de vérification', 'status' => 'Soumis', 'visibility' => 'En attente', 'reply' => ['status' => 'Non exercé'], 'detections' => [], 'reports' => [],
            'eligibility' => array_fill_keys(array_keys(Review::ELIGIBILITY), true), 'device' => 'APP-'.(1000 + $number),
        ]);
        $review->log('creation', 'a soumis l’avis', null, 'Soumis', 'Depuis l’application Promoteur · éligibilité contrôlée', $review->author, 'Application Promoteur');
        Inertia::flash('toast', "Avis {$review->reference} autorisé et soumis · en attente de vérification.");

        return redirect("/avis-litiges/avis/{$review->reference}");
    }
}
