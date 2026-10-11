<?php

namespace App\Http\Controllers;

use App\Enums\SiteActivityCategory;
use App\Enums\SiteIncidentSeverity;
use App\Enums\SiteIncidentStatus;
use App\Models\Site;
use App\Models\SiteIncident;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Incidents de chantier : signalement et résolution.
 */
class SiteIncidentController extends Controller
{
    public function store(Request $request, Site $site): RedirectResponse
    {
        abort_unless($site->status->isOperational(), 422, 'Aucun incident ne peut être signalé sur ce chantier.');

        $data = $request->validate([
            'type' => ['required', Rule::in(SiteIncident::TYPES)],
            'severity' => ['required', Rule::enum(SiteIncidentSeverity::class)],
            'description' => ['required', 'string', 'min:10', 'max:1000'],
        ], ['description.*' => 'La description est obligatoire (10 caractères minimum).']);

        $severity = SiteIncidentSeverity::from($data['severity']);
        $site->incidents()->create([
            'reference' => SiteIncident::nextReference(),
            'occurred_on' => today(),
            'type' => $data['type'],
            'severity' => $severity,
            'description' => trim($data['description']),
            'reported_by' => $request->user()?->name ?? 'Système',
            'status' => SiteIncidentStatus::Open,
        ]);
        $site->logActivity(SiteActivityCategory::Incident, 'a signalé un incident', null, SiteIncidentStatus::Open->label(), "{$data['type']} · {$severity->label()}");
        Inertia::flash('toast', 'Incident signalé.');

        return back();
    }

    public function resolve(Request $request, Site $site, SiteIncident $incident): RedirectResponse
    {
        abort_if($incident->status === SiteIncidentStatus::Resolved, 422, 'Cet incident est déjà résolu.');

        $measures = trim($request->validate(['measures' => ['required', 'string', 'min:5', 'max:1000']], ['measures.*' => 'Décrivez les mesures prises (5 caractères minimum).'])['measures']);

        $old = $incident->status->label();
        $incident->update(['status' => SiteIncidentStatus::Resolved, 'resolution' => $measures]);
        $site->logActivity(SiteActivityCategory::Incident, 'a résolu un incident', $old, SiteIncidentStatus::Resolved->label(), "{$incident->reference} · {$measures}");
        Inertia::flash('toast', 'Incident résolu.');

        return back();
    }
}
