<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Données de l’API (api/v1/admin/sites) mises en forme pour les pages Chantiers.
 */
class SitePresenter
{
    public const STATUS_TONES = ['brouillon' => 'n', 'soumis' => 'a', 'recherche' => 'p', 'paiement' => 'o', 'confirme' => 'b', 'en_cours' => 'g', 'suspendu' => 'r', 'termine' => 'g', 'annule' => 'n'];

    public const STATUS_LABELS = ['brouillon' => 'Brouillon', 'soumis' => 'Soumis', 'recherche' => 'Recherche', 'paiement' => 'Paiement', 'confirme' => 'Confirmé', 'en_cours' => 'En cours', 'suspendu' => 'Suspendu', 'termine' => 'Terminé', 'annule' => 'Annulé'];

    private const PRIORITY_TONES = ['basse' => 'n', 'normale' => 'b', 'haute' => 'o', 'critique' => 'r'];

    /**
     * @param  array{value: string, label?: string}  $status
     * @return array{value: string, label: string, tone: string}
     */
    public static function status(array $status): array
    {
        $value = $status['value'];

        return ['value' => $value, 'label' => self::STATUS_LABELS[$value] ?? ($status['label'] ?? $value), 'tone' => self::STATUS_TONES[$value] ?? 'n'];
    }

    /**
     * Ligne de la liste.
     *
     * @param  array<string, mixed>  $s
     * @return array<string, mixed>
     */
    public static function row(array $s): array
    {
        return [
            'id' => $s['id'],
            'reference' => $s['reference'],
            'name' => $s['label'],
            'promoter' => $s['promoter']['name'],
            'promoterId' => $s['promoter']['id'],
            'commune' => $s['commune']['name'] ?? null,
            'type' => $s['construction_type']['label'] ?? null,
            'workType' => $s['work_type']['label'] ?? null,
            'priority' => $s['priority'] ? ['value' => $s['priority']['value'], 'label' => $s['priority']['label'], 'tone' => self::PRIORITY_TONES[$s['priority']['value']] ?? 'n'] : null,
            'status' => self::status($s['status']),
            'startsOn' => $s['planned_start_date'],
            'endsOn' => $s['planned_end_date'],
            'required' => $s['staffing']['required'],
            'assigned' => $s['staffing']['assigned'],
            'isLate' => (bool) $s['is_late'],
            'openIncidents' => $s['open_incidents_count'],
            'pendingDays' => $s['pending_days_count'],
            'archived' => $s['archived_at'] !== null,
        ];
    }

    /**
     * Fiche.
     *
     * @param  array<string, mixed>  $s
     * @return array<string, mixed>
     */
    public static function site(array $s): array
    {
        return [
            ...self::row($s),
            'internalReference' => $s['internal_reference'],
            'description' => $s['description'],
            'address' => $s['address'],
            'neighborhood' => $s['neighborhood']['name'] ?? null,
            'access' => $s['access_instructions'],
            'location' => $s['location'],
            'geofence' => $s['geofence_radius_m'],
            'durationDays' => $s['planned_duration_days'],
            'workHours' => array_filter($s['work_hours']) ? implode(' – ', array_map(fn (?string $t) => substr((string) $t, 0, 5), $s['work_hours'])) : null,
            'contact' => $s['contact'],
            'responsible' => $s['responsible'],
            'needs' => array_map(fn (array $n) => ['id' => $n['id'], 'trade' => $n['trade'], 'headcount' => $n['headcount'], 'startsOn' => $n['start_date'], 'status' => $n['status']['label'] ?? '—'], $s['needs']),
            'closedAt' => self::day($s['closed_at']),
            'closedBy' => $s['closed_by'],
            'can' => $s['can'],
        ];
    }

    /**
     * @param  array<string, mixed>  $s
     * @return list<array<string, string|null>>
     */
    public static function kpis(array $s): array
    {
        $required = $s['staffing']['required'];
        $assigned = $s['staffing']['assigned'];

        return [
            ['label' => 'Effectif', 'value' => "{$assigned} / {$required}", 'icon' => 'groups', 'tone' => $assigned < $required ? 'a' : 'g', 'hint' => $assigned < $required ? ($required - $assigned).' poste(s) à pourvoir' : 'Effectif complet'],
            ['label' => 'Incidents ouverts', 'value' => (string) $s['open_incidents_count'], 'icon' => 'report', 'tone' => $s['open_incidents_count'] ? 'r' : 'g', 'hint' => $s['open_incidents_count'] ? 'À traiter' : 'Aucun incident'],
            ['label' => 'Pointages à valider', 'value' => (string) $s['pending_days_count'], 'icon' => 'pending_actions', 'tone' => $s['pending_days_count'] ? 'a' : 'g', 'hint' => $s['pending_days_count'] ? 'Bloque la clôture' : 'Rien en attente'],
            ['label' => 'Durée prévue', 'value' => $s['planned_duration_days'] ? (string) $s['planned_duration_days'] : '—', 'unit' => $s['planned_duration_days'] ? 'jours' : null, 'icon' => 'date_range', 'tone' => 'b', 'hint' => $s['is_late'] ? 'Date de fin dépassée' : 'Dans les délais'],
        ];
    }

    /**
     * Bandeau d’état du chantier.
     *
     * @param  array<string, mixed>  $s
     * @return array{tone: string, icon: string, title: string, text: string}|null
     */
    public static function banner(array $s): ?array
    {
        return match (true) {
            $s['archived_at'] !== null => ['tone' => 'neutral', 'icon' => 'archive', 'title' => 'Chantier archivé.', 'text' => 'Consultable en lecture seule.'],
            $s['status']['value'] === 'suspendu' => ['tone' => 'danger', 'icon' => 'block', 'title' => 'Chantier suspendu.', 'text' => trim(($s['suspension_reason'] ?? '').' Pointages et nouvelles réservations bloqués ; l’équipe reste affectée.')],
            $s['status']['value'] === 'annule' => ['tone' => 'neutral', 'icon' => 'cancel', 'title' => 'Chantier annulé.', 'text' => (string) $s['cancellation_reason']],
            $s['status']['value'] === 'termine' => ['tone' => 'success', 'icon' => 'task_alt', 'title' => 'Chantier terminé.', 'text' => trim(($s['closed_by'] ? "Clôturé par {$s['closed_by']}" : 'Clôturé').($s['closed_at'] ? ' le '.Carbon::parse($s['closed_at'])->format('d/m/Y').'.' : '.'))],
            (bool) $s['is_late'] => ['tone' => 'warning', 'icon' => 'schedule', 'title' => 'Chantier en retard.', 'text' => 'La date de fin prévue est dépassée.'],
            default => null,
        };
    }

    private static function day(?string $date): ?string
    {
        return $date ? Carbon::parse($date)->format('Y-m-d') : null;
    }
}
