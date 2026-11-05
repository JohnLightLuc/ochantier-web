<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Données de l’API (api/v1/admin/offers) mises en forme pour les pages Besoins.
 */
class OfferPresenter
{
    public const STATUS_TONES = ['soumise' => 'a', 'en_controle' => 'b', 'a_corriger' => 'o', 'publiee' => 'p', 'suspendue' => 'r', 'pourvue' => 'g', 'cloturee' => 'n', 'annulee' => 'n'];

    public const STATUS_LABELS = ['soumise' => 'Soumise', 'en_controle' => 'En contrôle', 'a_corriger' => 'À corriger', 'publiee' => 'Publiée', 'suspendue' => 'Suspendue', 'pourvue' => 'Pourvue', 'cloturee' => 'Clôturée', 'annulee' => 'Annulée'];

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
     * @param  array<string, mixed>  $o
     * @return array<string, mixed>
     */
    public static function row(array $o): array
    {
        return [
            'id' => $o['id'],
            'reference' => $o['reference'],
            'title' => $o['title'],
            'type' => $o['type']['label'] ?? null,
            'urgency' => $o['urgency']['label'] ?? null,
            'status' => self::status($o['status']),
            'site' => ['id' => $o['site']['id'], 'label' => $o['site']['label'], 'reference' => $o['site']['reference'], 'commune' => $o['site']['commune']],
            'promoter' => $o['promoter'],
            'mainTrade' => $o['main_trade'],
            'tradesCount' => $o['trades_count'],
            'required' => $o['coverage']['required'],
            'assigned' => $o['coverage']['assigned'],
            'percent' => $o['coverage']['percent'],
            'proposalPending' => $o['proposal_pending'],
            'depositExpected' => $o['deposit_expected'],
            'startsOn' => $o['start_date'],
            'endsOn' => $o['end_date'],
            'deadline' => self::day($o['application_deadline']),
        ];
    }

    /**
     * @param  array<string, mixed>  $o
     * @return array<string, mixed>
     */
    public static function offer(array $o): array
    {
        return [
            ...self::row($o),
            'description' => $o['description'],
            'deliverables' => $o['deliverables'],
            'extensions' => $o['extension_count'],
            'engagement' => $o['engagement'],
            'needs' => array_map(fn (array $n) => [
                'id' => $n['id'], 'reference' => $n['reference'], 'trade' => $n['trade'], 'headcount' => $n['headcount'], 'assigned' => $n['assigned'], 'level' => $n['level'], 'experience' => $n['min_experience_years'],
                'startsOn' => $n['start_date'], 'endsOn' => $n['end_date'], 'days' => $n['duration_days'], 'budget' => $n['daily_budget'], 'status' => $n['status']['label'],
            ], $o['needs']),
            'can' => $o['can'],
        ];
    }

    /**
     * @param  array<string, mixed>  $o
     * @return array{tone: string, icon: string, title: string, text: string}|null
     */
    public static function banner(array $o): ?array
    {
        return match ($o['status']['value']) {
            'suspendue' => ['tone' => 'danger', 'icon' => 'pause_circle', 'title' => 'Besoin suspendu.', 'text' => (string) $o['suspension_reason']],
            'annulee' => ['tone' => 'neutral', 'icon' => 'cancel', 'title' => 'Besoin annulé.', 'text' => (string) $o['cancellation_reason']],
            default => $o['deposit_expected'] ? ['tone' => 'warning', 'icon' => 'payments', 'title' => 'Acompte attendu.', 'text' => 'Le promoteur a accepté une proposition.'] : null,
        };
    }

    /**
     * @param  array<string, mixed>  $o
     * @return list<array<string, string|null>>
     */
    public static function kpis(array $o): array
    {
        $rest = max(0, $o['coverage']['required'] - $o['coverage']['assigned']);

        return [
            ['label' => 'Postes pourvus', 'value' => "{$o['coverage']['assigned']} / {$o['coverage']['required']}", 'icon' => 'groups', 'tone' => $rest ? 'a' : 'g', 'hint' => $rest ? "{$rest} poste(s) à pourvoir" : 'Effectif complet'],
            ['label' => 'Couverture', 'value' => (string) $o['coverage']['percent'], 'unit' => '%', 'icon' => 'donut_large', 'tone' => 'b', 'hint' => $o['trades_count'].' métier(s)'],
            ['label' => 'Démarrage', 'value' => $o['start_date'] ? Carbon::parse($o['start_date'])->format('d/m/Y') : '—', 'icon' => 'event', 'tone' => 'b', 'hint' => $o['end_date'] ? 'Fin le '.Carbon::parse($o['end_date'])->format('d/m/Y') : 'Fin non définie'],
            ['label' => 'Date limite', 'value' => $o['application_deadline'] ? Carbon::parse($o['application_deadline'])->format('d/m/Y') : '—', 'icon' => 'hourglass_top', 'tone' => 'a', 'hint' => $o['extension_count'] ? $o['extension_count'].' prolongation(s)' : 'Aucune prolongation'],
        ];
    }

    private static function day(?string $date): ?string
    {
        return $date ? Carbon::parse($date)->format('Y-m-d') : null;
    }
}
