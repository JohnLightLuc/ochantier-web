<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Données de l’API (api/v1/admin/commercials) mises en forme pour les pages Commerciaux : statuts et leurs couleurs,
 * indicateurs et bandeau d’état de la fiche.
 */
class CommercialPresenter
{
    public const STATUS_TONES = ['en_attente_activation' => 'a', 'actif' => 'g', 'suspendu' => 'r', 'termine' => 'n'];

    public const STATUS_LABELS = ['en_attente_activation' => 'En attente d’activation', 'actif' => 'Actif', 'suspendu' => 'Suspendu', 'termine' => 'Terminé'];

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
     * @param  array<string, mixed>  $c
     * @return array<string, mixed>
     */
    public static function row(array $c): array
    {
        $primary = collect($c['zones'])->firstWhere('is_primary', true) ?? ($c['zones'][0] ?? null);

        return [
            'id' => $c['id'],
            'code' => $c['employee_number'],
            'name' => $c['full_name'],
            'firstName' => $c['first_name'],
            'phone' => $c['phone'],
            'email' => $c['email'],
            'zone' => $primary['name'] ?? null,
            'secondaryZones' => array_values(array_map(fn (array $zone) => $zone['name'], array_filter($c['zones'], fn (array $zone) => $zone['name'] !== ($primary['name'] ?? null)))),
            'supervisor' => $c['supervisor']['full_name'] ?? null,
            'workersTotal' => $c['workers']['total'],
            'monthRegistered' => $c['workers']['month_registered'],
            'monthValidated' => $c['workers']['month_validated'],
            'validationRate' => $c['validation_rate'],
            'objective' => $c['objective'],
            'monthCommissions' => $c['commissions']['month'],
            'unpaidCommissions' => $c['commissions']['unpaid'],
            'status' => self::status($c['status']),
            'startedAt' => $c['started_at'],
            'lastActivityOn' => self::day($c['last_activity_at']),
        ];
    }

    /**
     * Fiche.
     *
     * @param  array<string, mixed>  $c
     * @return array<string, mixed>
     */
    public static function agent(array $c): array
    {
        return [
            ...self::row($c),
            'lastName' => $c['last_name'],
            'secondaryPhone' => $c['secondary_phone'],
            'address' => $c['address'],
            'isSupervisor' => $c['is_supervisor'],
            'verification' => $c['verification_status']['label'],
            'endedAt' => $c['ended_at'],
            'createdBy' => $c['created_by'],
            'lastLoginAt' => self::day($c['last_login_at']),
            'ranking' => $c['ranking'],
            'months' => $c['months'],
            'trades' => $c['trades'],
            'can' => $c['can'],
        ];
    }

    /**
     * @param  array<string, mixed>  $c
     * @return list<array<string, string|null>>
     */
    public static function kpis(array $c): array
    {
        $objective = $c['objective'];
        $rate = $c['validation_rate'];

        return [
            ['label' => 'Ouvriers enregistrés', 'value' => (string) $c['workers']['total'], 'icon' => 'engineering', 'tone' => 'g', 'hint' => $c['workers']['month_registered'].' ce mois'],
            ['label' => 'Validés ce mois', 'value' => (string) $c['workers']['month_validated'], 'icon' => 'verified', 'tone' => 'b', 'hint' => $rate !== null ? "Taux de validation {$rate} %" : 'Aucune décision ce mois'],
            ['label' => 'Objectif du mois', 'value' => $objective ? "{$objective['achieved']} / {$objective['target']}" : '—', 'icon' => 'flag', 'tone' => 'a', 'hint' => $objective ? "{$objective['percent']} % atteint" : 'Aucun objectif fixé'],
            ['label' => 'Commissions du mois', 'value' => number_format($c['commissions']['month'], 0, ',', ' '), 'unit' => 'FCFA', 'icon' => 'payments', 'tone' => 'a', 'hint' => number_format($c['commissions']['unpaid'], 0, ',', ' ').' FCFA à verser'],
        ];
    }

    /**
     * Bandeau d’état du compte (hors compte actif).
     *
     * @param  array<string, mixed>  $c
     * @return array{tone: string, icon: string, title: string, text: string}|null
     */
    public static function banner(array $c): ?array
    {
        return match ($c['status']['value']) {
            'en_attente_activation' => ['tone' => 'warning', 'icon' => 'hourglass_top', 'title' => 'Activation en attente.', 'text' => 'Le commercial n’a pas encore créé son mot de passe personnel.'],
            'suspendu' => ['tone' => 'danger', 'icon' => 'block', 'title' => 'Compte suspendu.', 'text' => trim('Motif : '.($c['suspension']['reason'] ?? '—').' '.(($c['suspension']['by'] ?? null) ? "Par {$c['suspension']['by']}." : ''))],
            'termine' => ['tone' => 'neutral', 'icon' => 'person_off', 'title' => 'Contrat terminé.', 'text' => trim(($c['ended_at'] ? 'Depuis le '.Carbon::parse($c['ended_at'])->format('d/m/Y').'. ' : '').($c['termination_reason'] ?? '').' Ouvriers et gains conservés.')],
            default => null,
        };
    }

    private static function day(?string $date): ?string
    {
        return $date ? Carbon::parse($date)->format('Y-m-d') : null;
    }
}
