<?php

namespace App\Http\Controllers;

use App\Enums\WorkerActivityCategory;
use App\Models\Worker;
use App\Support\WorkerInsights;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Workflow de remplacement en 9 étapes (données de démonstration, en attendant le module Affectations).
 *
 * Étapes : 1 déclaration, 2 analyse d’impact, 3 recherche, 4 proposition, 5 validation administrative,
 * 6 validation du promoteur, 7 confirmation du remplaçant, 8 transfert des informations, 9 clôture.
 */
class WorkerReplacementController extends Controller
{
    public const STEPS = ['Déclaration', 'Analyse de l’impact', 'Recherche d’ouvriers', 'Proposition de remplaçants', 'Validation administrative', 'Validation du promoteur', 'Confirmation du remplaçant', 'Transfert des informations', 'Clôture'];

    /**
     * Avancement : action → [étape requise, nouvelle étape, nouveau statut, message].
     */
    private const ADVANCES = [
        'analyser' => [1, 2, 'En analyse', 'en analyse'],
        'rechercher' => [2, 4, 'Remplaçant proposé', '4 profils compatibles trouvés'],
        'valider' => [5, 6, 'En attente de validation', 'en attente de validation'],
        'valider-promoteur' => [6, 7, 'Validé', 'validé'],
        'confirmer' => [7, 8, 'Remplacement effectué', 'remplacement effectué'],
        'transferer' => [8, 9, 'Remplacement effectué', 'informations transférées'],
    ];

    public function advance(Worker $worker, string $case, string $action): RedirectResponse
    {
        [$required, $step, $status, $message] = self::ADVANCES[$action] ?? abort(404);
        $item = $this->openCase($worker, $case);
        abort_unless($item['step'] === $required, 422, 'Étape non disponible.');

        $times = $item['times'];
        foreach (range($required + 1, $step) as $s) {
            $times[$s - 1] = $this->now();
        }
        $worker->updateDemo('replacements', $case, ['step' => $step, 'status' => $status, 'times' => $times]);
        $worker->logActivity(WorkerActivityCategory::Replacement, 'a fait avancer le remplacement', null, $status, "{$case} · ".self::STEPS[$step - 1]);

        Inertia::flash('toast', "Remplacement {$case} : {$message}.");

        return back();
    }

    /**
     * Propose un remplaçant parmi les candidats calculés (étape 4 → 5).
     */
    public function pick(Request $request, Worker $worker, string $case): RedirectResponse
    {
        $item = $this->openCase($worker, $case);
        abort_unless($item['step'] === 4, 422);

        $slug = $request->validate(['candidate' => ['required', 'string']])['candidate'];
        $candidate = collect((new WorkerInsights($worker))->replacementCandidates())->firstWhere('slug', $slug);
        abort_unless($candidate, 422, 'Ce profil ne fait pas partie des candidats proposés.');

        $times = $item['times'];
        $times[4] = $this->now();
        $worker->updateDemo('replacements', $case, [
            'step' => 5, 'status' => 'En attente de validation', 'times' => $times,
            'chosen' => ['name' => $candidate['name'], 'detail' => "{$candidate['trade']} · {$candidate['experienceYears']} ans", 'score' => $candidate['score']],
        ]);
        $worker->logActivity(WorkerActivityCategory::Replacement, 'a proposé un remplaçant', null, $candidate['name'], "{$case} · score {$candidate['score']} %");

        Inertia::flash('toast', "{$candidate['name']} proposé comme remplaçant.");

        return back();
    }

    /**
     * Refuse le remplaçant proposé : retour à la proposition (étape 5 → 4).
     */
    public function refuse(Request $request, Worker $worker, string $case): RedirectResponse
    {
        $item = $this->openCase($worker, $case);
        abort_unless($item['step'] === 5, 422);
        $reason = trim($request->validate(['reason' => ['required', 'string', 'min:10', 'max:500']], ['reason.*' => 'Ce champ est obligatoire (10 caractères minimum).'])['reason']);

        $worker->updateDemo('replacements', $case, ['step' => 4, 'status' => 'Remplaçant proposé', 'chosen' => null]);
        $worker->logActivity(WorkerActivityCategory::Replacement, 'a refusé le remplaçant proposé', 'En attente de validation', 'Remplaçant proposé', $reason);

        Inertia::flash('toast', 'Remplaçant refusé · choisissez un autre profil.');

        return back();
    }

    /**
     * Clôture : l’affectation d’origine passe « Remplacée ».
     */
    public function close(Worker $worker, string $case): RedirectResponse
    {
        $item = $this->openCase($worker, $case);
        abort_unless($item['step'] === 9, 422);

        $worker->updateDemo('replacements', $case, ['status' => 'Clôturé']);
        if ($item['assignmentId']) {
            $worker->updateDemo('assignments', $item['assignmentId'], ['status' => 'Remplacée']);
        }
        $worker->logActivity(WorkerActivityCategory::Replacement, 'a clôturé le remplacement', 'Remplacement effectué', 'Clôturé', $case);

        Inertia::flash('toast', 'Remplacement clôturé.');

        return back();
    }

    /**
     * Annulation : l’affectation d’origine est restaurée.
     */
    public function cancel(Worker $worker, string $case): RedirectResponse
    {
        $item = $this->openCase($worker, $case);
        abort_unless($item['step'] < 9, 422);

        $worker->updateDemo('replacements', $case, ['status' => 'Annulé']);
        if ($item['assignmentId']) {
            $worker->updateDemo('assignments', $item['assignmentId'], ['status' => 'En cours']);
        }
        $worker->logActivity(WorkerActivityCategory::Replacement, 'a annulé le remplacement', null, 'Annulé', $case);

        Inertia::flash('toast', 'Remplacement annulé.');

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function openCase(Worker $worker, string $case): array
    {
        $item = $worker->findDemo('replacements', $case);
        abort_if(in_array($item['status'], ['Clôturé', 'Annulé', 'Refusé'], true), 422, 'Ce dossier est clos.');

        return $item;
    }

    private function now(): string
    {
        return now()->translatedFormat('j M').' · '.now()->format('H:i');
    }
}
