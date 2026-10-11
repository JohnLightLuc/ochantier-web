<?php

namespace App\Http\Controllers;

use App\Enums\DocumentStatus;
use App\Enums\SiteActivityCategory;
use App\Enums\SiteDocumentType;
use App\Enums\SitePriority;
use App\Enums\SiteStatus;
use App\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Cycle de vie du chantier (planification, suspension, reprise, clôture, annulation, archivage),
 * informations modifiables par l’administration et demandes de correction au promoteur.
 */
class SiteStatusController extends Controller
{
    public const CORRECTION_FIELDS = ['Adresse / localisation', 'Dates', 'Type de construction', 'Documents d’autorisation', 'Effectif prévu', 'Autre'];

    /**
     * Informations autorisées : les éléments contractuels (promoteur, type, adresse) relèvent du promoteur.
     */
    public function updateInfo(Request $request, Site $site): RedirectResponse
    {
        abort_if($site->status->isReadOnly(), 403, 'Chantier en lecture seule.');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('sites', 'name')->ignore($site->id)],
            'priority' => ['required', Rule::enum(SitePriority::class)],
            'working_hours' => ['nullable', 'string', 'max:120'],
            'instructions' => ['nullable', 'string', 'max:1000'],
            'access_conditions' => ['nullable', 'string', 'max:1000'],
            'safety_equipment' => ['nullable', 'string', 'max:255'],
        ], [
            'name.required' => 'Le libellé est obligatoire.',
            'name.unique' => 'Un chantier porte déjà ce libellé.',
        ]);

        $site->update([...$data, 'name' => trim($data['name'])]);
        $site->logActivity(SiteActivityCategory::Update, 'a modifié les informations autorisées');
        Inertia::flash('toast', 'Informations enregistrées.');

        return back();
    }

    public function requestCorrection(Request $request, Site $site): RedirectResponse
    {
        abort_if($site->status->isFinal(), 422, 'Ce chantier est clôturé.');

        $data = $request->validate([
            'field' => ['required', Rule::in(self::CORRECTION_FIELDS)],
            'message' => ['required', 'string', 'min:10', 'max:1000'],
        ], ['message.*' => 'Le message est obligatoire (10 caractères minimum).']);

        $message = trim($data['message']);
        $site->update(['correction_request' => "{$data['field']} — {$message}"]);
        $site->logActivity(SiteActivityCategory::Update, 'a demandé une correction au promoteur', null, $data['field'], $message);
        Inertia::flash('toast', 'Demande de correction envoyée au promoteur.');

        return back();
    }

    public function plan(Request $request, Site $site): RedirectResponse
    {
        abort_unless($site->status->canBePlanned(), 422, 'Ce chantier ne peut plus être planifié.');

        $data = $request->validate([
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after:starts_on'],
        ], [
            'starts_on.*' => 'La date de début est obligatoire.',
            'ends_on.required' => 'La date de fin est obligatoire.',
            'ends_on.date' => 'La date de fin est obligatoire.',
            'ends_on.after' => 'La fin doit suivre le début.',
        ]);

        $start = CarbonImmutable::parse($data['starts_on']);
        $end = CarbonImmutable::parse($data['ends_on']);
        $site->transitionTo(SiteStatus::Planned, 'a planifié le chantier', $start->format('d/m/Y').' → '.$end->format('d/m/Y'), [
            'starts_on' => $start,
            'ends_on' => $end,
            'planned_days' => Site::workingDaysBetween($start, $end),
        ]);
        Inertia::flash('toast', 'Chantier planifié.');

        return back();
    }

    public function suspend(Request $request, Site $site): RedirectResponse
    {
        abort_unless($site->status->isOperational(), 422, 'Ce chantier ne peut pas être suspendu.');

        $site->transitionTo(SiteStatus::Suspended, 'a suspendu le chantier', $this->reason($request));
        Inertia::flash('toast', 'Chantier : suspendu.');

        return back();
    }

    /**
     * Reprise : le chantier repart en cours, ou attend sa clôture si les travaux étaient achevés.
     */
    public function resume(Site $site): RedirectResponse
    {
        abort_unless($site->status->isStopped(), 422, 'Ce chantier n’est pas à l’arrêt.');

        $site->transitionTo($site->progress >= 100 ? SiteStatus::PendingClosure : SiteStatus::InProgress, 'a repris le chantier');
        Inertia::flash('toast', 'Chantier repris.');

        return back();
    }

    /**
     * Clôture : les affectations sont terminées et les derniers paiements déclenchés.
     */
    public function close(Site $site): RedirectResponse
    {
        abort_unless($site->status->canBeClosed(), 422, 'Ce chantier ne peut pas être clôturé.');

        $verified = $site->documents()->where('type', SiteDocumentType::AcceptanceReport)->where('status', DocumentStatus::Verified)->exists();
        $site->transitionTo(SiteStatus::Closed, 'a clôturé le chantier', $verified ? null : 'PV de réception non vérifié', ['progress' => 100]);
        Inertia::flash('toast', 'Chantier clôturé.');

        return back();
    }

    public function cancel(Request $request, Site $site): RedirectResponse
    {
        abort_if($site->status->isFinal(), 422, 'Ce chantier ne peut plus être annulé.');

        $site->transitionTo(SiteStatus::Cancelled, 'a annulé le chantier', $this->reason($request));
        Inertia::flash('toast', 'Chantier : annulé.');

        return back();
    }

    public function archive(Site $site): RedirectResponse
    {
        abort_unless($site->status->canBeArchived(), 422, 'Seul un chantier clôturé ou annulé peut être archivé.');

        $site->transitionTo(SiteStatus::Archived, 'a archivé le chantier');
        Inertia::flash('toast', 'Chantier archivé.');

        return back();
    }

    private function reason(Request $request): string
    {
        return trim($request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']], ['reason.*' => 'Le motif est obligatoire (5 caractères minimum).'])['reason']);
    }
}
