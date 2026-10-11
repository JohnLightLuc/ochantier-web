<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\AttendanceAnomaly;
use App\Models\AttendanceCorrection;
use App\Support\AttendanceCalculator as Calc;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Actions du Back-office sur les pointages : il supervise et contrôle, sans pointer à la place du promoteur.
 *
 * Un pointage validé ne se modifie jamais directement : la correction est demandée, puis approuvée et appliquée
 * (l’ancienne valeur est conservée). Aucune action ne déclenche de paiement.
 */
class AttendanceActionController extends Controller
{
    /**
     * Relance du promoteur pour compléter une ou plusieurs journées.
     */
    public function remind(Request $request): RedirectResponse
    {
        $list = $this->many($request);
        foreach ($list as $p) {
            $c = Calc::calculate($p);
            $p->increment('reminders');
            $p->logActivity('statut', 'a relancé le promoteur', null, "Relance n° {$p->reminders}", implode(', ', array_slice([...$c['incomplete'], ...$c['blockers']], 0, 2)) ?: null);
        }
        Inertia::flash('toast', $list->count() > 1 ? "{$list->count()} promoteurs relancés." : "Promoteur relancé ({$list->first()->promoterLabel()}).");

        return back();
    }

    public function note(Request $request, Attendance $attendance): RedirectResponse
    {
        $text = $this->text($request, 'note', 'La note est obligatoire.');
        $attendance->update(['notes' => [['date' => now()->format('Y-m-d H:i'), 'by' => $request->user()?->name ?? 'Système', 'text' => $text], ...$attendance->notes]]);
        $attendance->logActivity('modification', 'a ajouté une note interne', null, null, $text);
        Inertia::flash('toast', 'Note interne ajoutée.');

        return back();
    }

    /**
     * Demande de correction : la valeur actuelle est conservée jusqu’à l’approbation.
     */
    public function requestCorrection(Request $request, Attendance $attendance): RedirectResponse
    {
        $data = $request->validate([
            'field' => ['required', Rule::in(AttendanceController::CORRECTION_FIELDS)],
            'new_value' => ['required', 'string', 'max:120'],
            'motif' => ['required', Rule::in(AttendanceController::CORRECTION_MOTIFS)],
            'proof' => ['nullable', 'string', 'max:120'],
            'comment' => ['nullable', 'string', 'max:500'],
        ], ['new_value.required' => 'La nouvelle valeur est obligatoire.']);
        $value = trim($data['new_value']);
        if (str_starts_with($data['field'], 'Heure') && ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value)) {
            return back()->withErrors(['new_value' => 'Format d’heure attendu : HH:MM (24 h).']);
        }
        $old = $this->currentValue($attendance, $data['field']);

        $attendance->corrections()->create([
            'field' => $data['field'], 'old_value' => $old, 'new_value' => $value, 'motif' => $data['motif'], 'comment' => $data['comment'] ?? null, 'proof' => $data['proof'] ?? null,
            'status' => 'Soumise', 'requested_by' => ($request->user()?->name ?? 'Système').' (Back-office)', 'requested_at' => now(),
        ]);
        $this->markEvent($attendance, $data['field'], 'Correction demandée');
        $attendance->logActivity('modification', 'a demandé une correction', $old, $value, "{$data['field']} · {$data['motif']}");
        Inertia::flash('toast', 'Demande de correction soumise · en attente d’approbation.');

        return back();
    }

    /**
     * Approuve et applique une correction : la durée est recalculée, une journée clôturée est rouverte.
     */
    public function approveCorrection(Attendance $attendance, AttendanceCorrection $correction): RedirectResponse
    {
        abort_unless(in_array($correction->status, ['Soumise', 'En attente'], true), 422, 'Cette correction a déjà été traitée.');
        $attendance->load('worker', 'anomalies', 'corrections');
        $before = Calc::calculate($attendance);

        $arrival = $attendance->arrival;
        $departure = $attendance->departure;
        $pauses = $attendance->pauses;
        $absence = $attendance->absence;
        match ($correction->field) {
            'Heure d’arrivée' => $arrival = [...$arrival, 'time' => $correction->new_value, 'status' => 'Corrigée'],
            'Heure de départ' => $departure = [...$departure, 'time' => $correction->new_value, 'status' => 'Corrigé'],
            'Durée de pause' => isset($pauses[0]) ? $pauses[0]['minutes'] = (int) $correction->new_value ?: $pauses[0]['minutes'] : null,
            'Motif d’absence' => $absence ? $absence['motif'] = $correction->new_value : null,
            default => null,
        };
        $reopen = $attendance->isClosed();
        $attendance->fill(['arrival' => $arrival, 'departure' => $departure, 'pauses' => $pauses, 'absence' => $absence]);
        if ($reopen) {
            $attendance->fill(['closure' => ['status' => Attendance::REOPENED, 'by' => auth()->user()?->name, 'at' => now()->format('Y-m-d H:i'), 'previous' => $attendance->closure['status']], 'transmission' => Attendance::NOT_TRANSMITTED]);
        }
        $attendance->save();
        $correction->update(['status' => 'Appliquée', 'decision' => (auth()->user()?->name ?? 'Système').' · '.now()->format('d/m/Y H:i')]);
        $after = Calc::calculate($attendance->load('corrections'));
        $impact = 'Durée nette '.Calc::duration($before['net']).' → '.Calc::duration($after['net']).($after['overtime'] !== $before['overtime'] ? ' · HS '.Calc::duration($after['overtime']) : '');
        $correction->update(['impact' => $impact]);
        $attendance->logActivity('validation', 'a appliqué une correction', $correction->old_value, $correction->new_value, "{$correction->field} · {$impact}");
        Inertia::flash('toast', "Correction appliquée · {$impact}.");

        return back();
    }

    public function rejectCorrection(Request $request, Attendance $attendance, AttendanceCorrection $correction): RedirectResponse
    {
        abort_unless(in_array($correction->status, ['Soumise', 'En attente'], true), 422, 'Cette correction a déjà été traitée.');
        $reason = $this->text($request, 'reason', 'Le motif du rejet est obligatoire.');

        $correction->update(['status' => 'Rejetée', 'decision' => ($request->user()?->name ?? 'Système')." · {$reason}"]);
        $arrival = $attendance->arrival;
        $departure = $attendance->departure;
        if ($arrival['status'] === 'Correction demandée') {
            $arrival['status'] = 'Validée';
        }
        if ($departure['status'] === 'Correction demandée') {
            $departure['status'] = 'Validé';
        }
        $attendance->update(['arrival' => $arrival, 'departure' => $departure]);
        $attendance->logActivity('rejet', 'a rejeté une correction', $correction->new_value, $correction->old_value, $reason);
        Inertia::flash('toast', 'Correction rejetée · valeur d’origine conservée.');

        return back();
    }

    public function reportAnomaly(Request $request, Attendance $attendance): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(AttendanceController::ANOMALY_TYPES)],
            'level' => ['required', Rule::in(AttendanceController::LEVELS)],
            'note' => ['required', 'string', 'min:3', 'max:500'],
        ], ['note.*' => 'La description est obligatoire.']);

        $attendance->anomalies()->create([...$data, 'status' => 'Nouvelle', 'detected_on' => Attendance::referenceDate()]);
        $attendance->logActivity('litige', 'a signalé une anomalie', null, $data['type'], $data['level']);
        Inertia::flash('toast', 'Anomalie signalée.');

        return back();
    }

    /**
     * Change le statut d’une anomalie ; une décision (confirmation, fausse alerte, correction, clôture, justification) exige un commentaire.
     */
    public function updateAnomaly(Request $request, Attendance $attendance, AttendanceAnomaly $anomaly): RedirectResponse
    {
        $status = $request->validate(['status' => ['required', Rule::in(AttendanceController::ANOMALY_STATUSES)]])['status'];
        $needsComment = in_array($status, ['Confirmée', 'Fausse alerte', 'Corrigée', 'Clôturée', 'Justification demandée'], true);
        $comment = $needsComment ? $this->text($request, 'reason', 'Le commentaire est obligatoire.') : null;

        $old = $anomaly->status;
        $anomaly->update(['status' => $status, 'decision' => $comment ?? $anomaly->decision]);
        $attendance->logActivity('statut', 'a changé le statut d’une anomalie', $old, $status, $anomaly->type.($comment ? " · {$comment}" : ''));
        Inertia::flash('toast', 'Anomalie : '.mb_strtolower($status).'.');

        return back();
    }

    /**
     * Régularisation exceptionnelle (administrateurs) : tracée et signalée au promoteur.
     */
    public function regularize(Request $request, Attendance $attendance): RedirectResponse
    {
        $data = $request->validate([
            'object' => ['required', Rule::in(['Retard', 'Départ anticipé', 'Absence', 'Journée incomplète', 'Anomalie'])],
            'decision' => ['required', Rule::in(['Régularisé', 'Justifié', 'Non justifié'])],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ], ['reason.*' => 'La justification est obligatoire (5 caractères minimum).']);

        match ($data['object']) {
            'Retard' => $attendance->lateness && $attendance->lateness = [...$attendance->lateness, 'status' => $data['decision']],
            'Départ anticipé' => $attendance->early_leave && $attendance->early_leave = [...$attendance->early_leave, 'status' => $data['decision']],
            'Absence' => $attendance->absence && $attendance->absence = [...$attendance->absence, 'status' => ['Régularisé' => 'Régularisée', 'Justifié' => 'Justifiée', 'Non justifié' => 'Non justifiée'][$data['decision']]],
            'Anomalie' => $attendance->anomalies()->whereNotIn('status', Calc::ANOMALY_DONE)->update(['status' => 'Corrigée', 'decision' => trim($data['reason'])]),
            default => null,
        };
        $attendance->save();
        $attendance->logActivity('validation', 'a régularisé exceptionnellement', $data['object'], $data['decision'], trim($data['reason']));
        Inertia::flash('toast', 'Régularisation enregistrée et auditée.');

        return back();
    }

    /**
     * Clôture une ou plusieurs journées clôturables ; avec `exceptionnelle`, clôture malgré les points bloquants (justification exigée).
     */
    public function close(Request $request): RedirectResponse
    {
        $list = $this->many($request);
        $exceptional = $request->boolean('exceptionnelle');
        $reason = $exceptional ? $this->text($request, 'reason', 'La justification est obligatoire.') : null;
        $computed = $list->map(fn (Attendance $p): array => [$p, Calc::calculate($p)]);
        $targets = $computed->filter(fn (array $x): bool => $exceptional ? ! $x[1]['closed'] : $x[1]['closable']);
        $skipped = $computed->filter(fn (array $x): bool => ! $x[1]['closable'] && ! $x[1]['closed'])->count();
        if ($targets->isEmpty()) {
            $first = $computed->first(fn (array $x): bool => ! $x[1]['closed']);
            abort(422, 'Aucune journée clôturable : '.($first ? implode(', ', $first[1]['blockers']) : 'déjà clôturée').'.');
        }

        foreach ($targets as [$p]) {
            $overtime = $p->overtime && $p->overtime['status'] === 'Validées' ? [...$p->overtime, 'status' => 'Transmises au calcul financier'] : $p->overtime;
            $p->update(['closure' => ['status' => 'Clôturée manuellement', 'by' => $request->user()?->name, 'at' => now()->format('Y-m-d H:i'), 'reason' => $reason], 'overtime' => $overtime]);
            $p->logActivity('statut', $exceptional ? 'a clôturé exceptionnellement la journée' : 'a clôturé la journée', null, 'Clôturée manuellement', $reason);
        }
        Inertia::flash('toast', "{$targets->count()} journée(s) clôturée(s)".(! $exceptional && $skipped ? " · {$skipped} ignorée(s) (non clôturables)" : '').'.');

        return back();
    }

    /**
     * Réouverture autorisée : la journée sort de la progression et la transmission financière est suspendue.
     */
    public function reopen(Request $request, Attendance $attendance): RedirectResponse
    {
        abort_unless($attendance->isClosed(), 422, 'Seule une journée clôturée peut être rouverte.');
        $reason = $this->text($request, 'reason', 'Le motif est obligatoire.');

        $previous = $attendance->closure['status'];
        $attendance->update(['closure' => ['status' => Attendance::REOPENED, 'by' => $request->user()?->name, 'at' => now()->format('Y-m-d H:i'), 'previous' => $previous], 'transmission' => Attendance::NOT_TRANSMITTED]);
        $attendance->logActivity('statut', 'a rouvert la journée', $previous, Attendance::REOPENED, $reason);
        Inertia::flash('toast', 'Journée rouverte · impact progression et paiements signalé.');

        return back();
    }

    /**
     * Transmet les données d’une journée clôturée au module Paiements (aucun paiement déclenché).
     */
    public function transmit(Attendance $attendance): RedirectResponse
    {
        $attendance->load('worker', 'anomalies', 'corrections');
        $c = Calc::calculate($attendance);
        abort_unless($c['closed'], 422, 'Transmission possible uniquement après clôture (Paramètres).');
        abort_if($attendance->transmission !== Attendance::NOT_TRANSMITTED, 422, 'Données déjà transmises.');

        $attendance->update(['transmission' => Attendance::TRANSMITTED, 'overtime' => $attendance->overtime ? [...$attendance->overtime, 'status' => 'Transmises au calcul financier'] : null]);
        $attendance->logActivity('paiement', 'a transmis les données au module Paiements', null, Calc::duration($c['net']), 'Aucun paiement déclenché par Pointages');
        Inertia::flash('toast', 'Données transmises au module Paiements (aucun paiement déclenché).');

        return back();
    }

    /**
     * Vérifie, rejette, fait remplacer ou commente une preuve (photo, signature).
     */
    public function proof(Request $request, Attendance $attendance, string $event, string $proof, string $action): RedirectResponse
    {
        $key = $event === 'arrivee' ? 'arrival' : 'departure';
        $data = $attendance->{$key};
        $index = collect($data['proofs'])->search(fn (array $x): bool => $x['id'] === $proof);
        abort_if($index === false, 404);
        $item = $data['proofs'][$index];
        $reason = $action === 'verification' ? null : $this->text($request, 'reason', 'Le motif est obligatoire.');

        if ($action === 'commentaire') {
            $data['proofs'][$index]['comment'] = $reason;
            $attendance->update([$key => $data]);
            $attendance->logActivity('modification', 'a commenté une preuve', null, $item['type'], $reason);
            Inertia::flash('toast', 'Commentaire ajouté.');

            return back();
        }

        $status = ['verification' => 'Vérifiée', 'rejet' => 'Rejetée', 'remplacement' => 'Remplacement demandé'][$action];
        $data['proofs'][$index]['status'] = $status;
        if ($reason) {
            $data['proofs'][$index]['comment'] = $reason;
        }
        $attendance->update([$key => $data]);
        $verb = ['verification' => 'a vérifié une preuve', 'rejet' => 'a rejeté une preuve', 'remplacement' => 'a demandé le remplacement d’une preuve'][$action];
        $attendance->logActivity($action === 'verification' ? 'validation' : 'rejet', $verb, $item['status'], $status, $item['type'].($reason ? " · {$reason}" : ''));
        Inertia::flash('toast', 'Preuve : '.mb_strtolower($status).'.');

        return back();
    }

    /**
     * Statue sur un retard, un départ anticipé, des heures supplémentaires ou une absence (sans impact financier automatique).
     */
    public function decide(Request $request, Attendance $attendance, string $object): RedirectResponse
    {
        $current = $attendance->{$object};
        abort_unless($current, 404);
        $status = $request->validate(['status' => ['required', Rule::in(AttendanceController::DECISIONS[$object][1])]])['status'];
        $comment = $this->text($request, 'reason', 'Le commentaire est obligatoire.');
        $label = ['lateness' => 'le retard', 'early_leave' => 'le départ anticipé', 'overtime' => 'les heures supplémentaires', 'absence' => 'l’absence'][$object];

        $attendance->update([$object => [...$current, 'status' => $status]]);
        $attendance->logActivity('validation', "a statué sur {$label}", $current['status'], $status, $comment);
        Inertia::flash('toast', 'Décision enregistrée : '.mb_strtolower($status).'.');

        return back();
    }

    /**
     * Pointages visés (`refs`), avec ce qu’il faut pour les calculer.
     *
     * @return Collection<int, Attendance>
     */
    private function many(Request $request): Collection
    {
        $refs = $request->validate(['refs' => ['required', 'array', 'min:1'], 'refs.*' => ['string']])['refs'];
        $list = Attendance::with('worker', 'site', 'anomalies', 'corrections')->whereIn('reference', $refs)->get();
        abort_if($list->isEmpty(), 404);

        return $list;
    }

    private function currentValue(Attendance $p, string $field): string
    {
        return match ($field) {
            'Heure d’arrivée' => $p->arrival['time'] ?? '—',
            'Heure de départ' => $p->departure['time'] ?? '—',
            'Durée de pause' => isset($p->pauses[0]) ? $p->pauses[0]['minutes'].' min' : '—',
            'Localisation' => $p->arrival['location'] ? $p->arrival['location']['meters'].' m' : '—',
            default => $p->absence['motif'] ?? '—',
        };
    }

    /**
     * Une heure validée faisant l’objet d’une demande passe « Correction demandée ».
     */
    private function markEvent(Attendance $p, string $field, string $status): void
    {
        if ($field === 'Heure d’arrivée' && in_array($p->arrival['status'], Attendance::VALIDATED['arrival'], true)) {
            $p->update(['arrival' => [...$p->arrival, 'status' => $status]]);
        }
        if ($field === 'Heure de départ' && in_array($p->departure['status'], Attendance::VALIDATED['departure'], true)) {
            $p->update(['departure' => [...$p->departure, 'status' => $status]]);
        }
    }

    private function text(Request $request, string $key, string $message): string
    {
        return trim($request->validate([$key => ['required', 'string', 'min:3', 'max:500']], ["{$key}.*" => $message])[$key]);
    }
}
