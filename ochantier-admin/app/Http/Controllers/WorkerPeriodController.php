<?php

namespace App\Http\Controllers;

use App\Enums\AvailabilityType;
use App\Enums\WorkerActivityCategory;
use App\Enums\WorkerAvailability;
use App\Http\Requests\WorkerRequest;
use App\Models\Worker;
use App\Models\WorkerPeriod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Calendrier : périodes déclarées et déclaration d’indisponibilité (avec remplacement éventuel).
 */
class WorkerPeriodController extends Controller
{
    public const UNAVAILABILITY_REASONS = ['Maladie', 'Blessure', 'Raison familiale', 'Problème de transport', 'Autre engagement', 'Autre'];

    public const URGENCIES = ['Faible', 'Moyenne', 'Élevée', 'Critique'];

    public const RECOVERIES = ['Oui — après la date de fin', 'Partielle — mi-temps', 'Non'];

    public const MOMENTS = ['Pendant l’exécution du chantier', 'Avant le début du chantier'];

    /**
     * Statuts d’affectation qu’une indisponibilité peut concerner.
     */
    public const AFFECTED_STATUSES = ['En cours', 'Confirmée', 'Acceptée', 'En attente d’acceptation', 'Proposée'];

    public function store(Request $request, Worker $worker): RedirectResponse
    {
        abort_if($worker->isArchived(), 403);

        $data = $request->validate([
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'type' => ['required', Rule::enum(AvailabilityType::class)],
            'reason' => ['nullable', 'string', 'max:160'],
            'zone' => ['nullable', Rule::in(WorkerRequest::COMMUNES)],
            'comment' => ['nullable', 'string', 'max:500'],
        ], ['*.required' => 'Ce champ est obligatoire.', 'ends_on.after_or_equal' => 'La date de fin doit suivre la date de début.']);

        $period = $worker->periods()->create($data);
        $worker->logActivity(WorkerActivityCategory::Availability, 'a déclaré une disponibilité', null, $period->type->label(), $this->range($period).($period->reason ? " · {$period->reason}" : ''));
        Inertia::flash('toast', 'Disponibilité enregistrée.');

        return back();
    }

    public function destroy(Worker $worker, WorkerPeriod $period): RedirectResponse
    {
        abort_if($worker->isArchived(), 403);

        $period->delete();
        $worker->logActivity(WorkerActivityCategory::Availability, 'a supprimé une période', $period->type->label(), null, $this->range($period));
        Inertia::flash('toast', 'Période supprimée.');

        return back();
    }

    /**
     * Déclare une indisponibilité. Si une affectation est concernée, ouvre un dossier de remplacement.
     */
    public function unavailability(Request $request, Worker $worker): RedirectResponse
    {
        abort_if($worker->isArchived(), 403);

        $active = collect($worker->demo('assignments'))->whereIn('status', self::AFFECTED_STATUSES);
        $data = $request->validate([
            'moment' => ['required', Rule::in(self::MOMENTS)],
            'assignment' => ['nullable', Rule::in($active->pluck('id')->all())],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'reason' => ['required', Rule::in(self::UNAVAILABILITY_REASONS)],
            'urgency' => ['required', Rule::in(self::URGENCIES)],
            'recovery' => ['nullable', Rule::in(self::RECOVERIES)],
            'comment' => ['required', 'string', 'min:5', 'max:500'],
            'file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ], [
            '*.required' => 'Ce champ est obligatoire.',
            'comment.min' => '5 caractères minimum.',
            'ends_on.after_or_equal' => 'La date de fin doit suivre la date de début.',
        ]);

        $period = $worker->periods()->create([
            'starts_on' => $data['starts_on'], 'ends_on' => $data['ends_on'], 'type' => AvailabilityType::Unavailable,
            'reason' => $data['reason'], 'zone' => $worker->commune, 'comment' => trim($data['comment']),
        ]);
        $old = $worker->availability->label();
        $worker->update(['availability' => WorkerAvailability::Unavailable]);
        $worker->logActivity(WorkerActivityCategory::Unavailability, 'a déclaré une indisponibilité', $old, WorkerAvailability::Unavailable->label(), "{$data['reason']} · {$this->range($period)}");

        $assignment = $active->firstWhere('id', $data['assignment'] ?? null);
        if (! $assignment) {
            Inertia::flash('toast', 'Indisponibilité enregistrée.');

            return back();
        }

        $id = $this->nextCaseId();
        $worker->refresh();
        $worker->putDemo('replacements', [[
            'id' => $id, 'assignmentId' => $assignment['id'], 'site' => $assignment['site'], 'moment' => $data['moment'],
            'start' => $data['starts_on'], 'end' => $data['ends_on'], 'reason' => $data['reason'], 'comment' => trim($data['comment']),
            'urgency' => $data['urgency'], 'recovery' => $data['recovery'] ?? '—', 'step' => 1, 'status' => 'Signalé', 'chosen' => null,
            'times' => [now()->translatedFormat('j M').' · '.now()->format('H:i')],
        ], ...$worker->demo('replacements')]);
        $worker->updateDemo('assignments', $assignment['id'], ['status' => 'Remplacement demandé']);
        $worker->logActivity(WorkerActivityCategory::Replacement, 'a ouvert un remplacement', null, 'Signalé', "{$id} · {$assignment['site']}");

        Inertia::flash('toast', 'Indisponibilité enregistrée · remplacement lancé.');

        return to_route('workers.show', [$worker, 'remplacements']);
    }

    /**
     * Prochaine référence de dossier « REP-0nnn », tous ouvriers confondus.
     */
    private function nextCaseId(): string
    {
        $max = Worker::all(['demo_relations'])
            ->flatMap(fn (Worker $w): array => array_column($w->demo('replacements'), 'id'))
            ->map(fn (string $id): int => (int) substr($id, 4))
            ->max() ?? 0;

        return sprintf('REP-%04d', max($max, 42) + 1);
    }

    private function range(WorkerPeriod $period): string
    {
        $format = fn (Carbon $d): string => $d->translatedFormat('d M Y');

        return $format($period->starts_on).' → '.$format($period->ends_on);
    }
}
