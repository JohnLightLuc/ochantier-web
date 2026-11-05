<?php

namespace App\Support\Admin;

use App\Enums\SiteIncidentSeverity;
use App\Enums\SiteIncidentStatus;
use App\Models\Admin;
use App\Models\Site;
use App\Models\SiteIncident;
use App\Support\Promoter\PromoterNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Incidents d’un chantier, déclarés et traités par le Back Office. Le promoteur est prévenu d’un incident grave ou
 * critique, puis de sa résolution.
 */
class SiteIncidents
{
    private const NOTIFIED = [SiteIncidentSeverity::Grave, SiteIncidentSeverity::Critique];

    public function __construct(private PromoterNotifier $notifier) {}

    /**
     * @param  array{incident_type: string, severity: string, occurred_at: string, description: string}  $data
     */
    public function declare(Site $site, array $data, Admin $admin, Request $request): SiteIncident
    {
        if ($site->archived_at) {
            throw ValidationException::withMessages(['site' => 'Ce chantier est archivé.']);
        }

        $incident = DB::transaction(fn () => $site->incidents()->create([
            'reference' => $this->nextReference(),
            'occurred_at' => $data['occurred_at'],
            'incident_type' => $data['incident_type'],
            'severity' => $data['severity'],
            'description' => trim($data['description']),
            'reported_by_type' => $admin->getMorphClass(),
            'reported_by_id' => $admin->getKey(),
            'status' => SiteIncidentStatus::Ouvert,
        ]));

        AdminAudit::record($admin, 'sites', 'site.incident_declared', $request, $site, null, ['reference' => $incident->reference, 'severity' => $data['severity']]);

        if (in_array($incident->severity, self::NOTIFIED, true)) {
            $this->notify($site, $incident, 'site.incident', 'Incident sur '.$site->label, $incident->incident_type->label().' · '.$incident->description);
        }

        return $incident;
    }

    public function start(Site $site, SiteIncident $incident, Admin $admin, Request $request): void
    {
        if ($incident->status !== SiteIncidentStatus::Ouvert) {
            throw ValidationException::withMessages(['site' => 'Cet incident n’est plus ouvert.']);
        }

        $incident->update(['status' => SiteIncidentStatus::EnTraitement]);
        AdminAudit::record($admin, 'sites', 'site.incident_started', $request, $site, null, ['reference' => $incident->reference]);
    }

    public function resolve(Site $site, SiteIncident $incident, string $resolution, Admin $admin, Request $request): void
    {
        if (! in_array($incident->status, [SiteIncidentStatus::Ouvert, SiteIncidentStatus::EnTraitement], true)) {
            throw ValidationException::withMessages(['site' => 'Cet incident est déjà résolu.']);
        }

        $incident->update(['status' => SiteIncidentStatus::Resolu, 'resolution' => $resolution, 'resolved_by_admin_id' => $admin->getKey(), 'resolved_at' => now()]);
        AdminAudit::record($admin, 'sites', 'site.incident_resolved', $request, $site, null, ['reference' => $incident->reference, 'reason' => $resolution]);

        if (in_array($incident->severity, self::NOTIFIED, true)) {
            $this->notify($site, $incident, 'site.incident_resolved', 'Incident résolu · '.$site->label, $resolution);
        }
    }

    private function notify(Site $site, SiteIncident $incident, string $event, string $title, string $body): void
    {
        $this->notifier->notify($site->promoterProfile, $event, $title, $body, $site, ['type' => 'site', 'id' => $site->id], $incident, $event.':'.$incident->id);
    }

    /**
     * Référence suivante de l’année (INC-2026-00012), sans doublon entre requêtes concurrentes.
     */
    private function nextReference(): string
    {
        DB::statement("select pg_advisory_xact_lock(hashtext('site_incident_reference'))");
        $year = now()->year;
        $last = (int) DB::scalar("select coalesce(max(substring(reference from '[0-9]+$')::bigint), 0) from site_incidents where reference like ?", ["INC-{$year}-%"]);

        return sprintf('INC-%d-%05d', $year, $last + 1);
    }
}
