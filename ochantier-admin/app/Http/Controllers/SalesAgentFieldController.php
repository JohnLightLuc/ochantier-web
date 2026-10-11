<?php

namespace App\Http\Controllers;

use App\Enums\SalesAgentActivityCategory;
use App\Enums\WorkerActivityCategory;
use App\Enums\WorkerStatus;
use App\Models\SalesAgent;
use App\Models\SalesAgentFieldActivity;
use App\Models\Worker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Activité terrain du commercial et suivi de son portefeuille d’ouvriers.
 */
class SalesAgentFieldController extends Controller
{
    public function validateActivity(SalesAgent $salesAgent, SalesAgentFieldActivity $fieldActivity): RedirectResponse
    {
        abort_if($fieldActivity->status === 'Validée', 422, 'Cette activité est déjà validée.');

        $old = $fieldActivity->status;
        $fieldActivity->update(['status' => 'Validée']);
        $salesAgent->logActivity(SalesAgentActivityCategory::Validation, 'a validé une activité terrain', $old, 'Validée', $fieldActivity->type);
        Inertia::flash('toast', 'Activité validée.');

        return back();
    }

    public function flagActivity(Request $request, SalesAgent $salesAgent, SalesAgentFieldActivity $fieldActivity): RedirectResponse
    {
        $reason = trim($request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']], ['reason.*' => 'Ce champ est obligatoire (5 caractères minimum).'])['reason']);

        $old = $fieldActivity->status;
        $fieldActivity->update(['status' => 'Anomalie signalée', 'comment' => "{$fieldActivity->comment} · Anomalie : {$reason}"]);
        $salesAgent->logActivity(SalesAgentActivityCategory::Rejection, 'a signalé une anomalie terrain', $old, 'Anomalie signalée', "{$fieldActivity->type} · {$reason}");
        Inertia::flash('toast', 'Anomalie signalée.');

        return back();
    }

    public function exportActivities(SalesAgent $salesAgent): StreamedResponse
    {
        $activities = $salesAgent->fieldActivities()->with('worker')->get();

        return response()->streamDownload(function () use ($activities): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\u{FEFF}");
            fputcsv($out, ['Date', 'Heure', 'Type', 'Ouvrier', 'Localisation', 'Commentaire', 'Statut'], ';');
            foreach ($activities as $a) {
                fputcsv($out, [$a->occurred_at->format('Y-m-d'), $a->occurred_at->format('H:i'), $a->type, $a->worker?->name ?? '—', $a->location, $a->comment, $a->status], ';');
            }
            fclose($out);
        }, "activite-{$salesAgent->slug}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Soumet pour validation un profil incomplet ou rejeté enregistré par ce commercial.
     */
    public function submitWorker(SalesAgent $salesAgent, Worker $worker): RedirectResponse
    {
        abort_unless($worker->recruiter === $salesAgent->name, 404);
        abort_unless(in_array($worker->status, [WorkerStatus::Incomplete, WorkerStatus::Rejected], true), 422, "Ce profil est déjà {$worker->status->label()}.");

        $old = $worker->status->label();
        $worker->update(['status' => WorkerStatus::ToReview]);
        $worker->logActivity(WorkerActivityCategory::Status, 'a soumis le profil pour validation', $old, WorkerStatus::ToReview->label(), "Par {$salesAgent->name}", 'Application commercial');
        $salesAgent->logActivity(SalesAgentActivityCategory::Worker, 'a soumis un profil', $old, WorkerStatus::ToReview->label(), $worker->name);
        Inertia::flash('toast', 'Profil soumis.');

        return back();
    }
}
