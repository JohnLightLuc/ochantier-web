<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Données de l’API (api/v1/admin/quote-requests) mises en forme pour les pages Demandes de devis : une ligne par
 * corps de métier d’une demande, ses statuts et leurs couleurs, la fiche et sa chronologie.
 */
class QuotePresenter
{
    /**
     * Statut affiché => couleur.
     */
    public const TONES = [
        'ouverte' => 'b',
        'visite_programmee' => 'a',
        'report_propose' => 'o',
        'visite_contestee' => 'r',
        'devis_en_preparation' => 'a',
        'devis_envoye' => 'p',
        'devis_recu' => 'g',
        'expiree' => 'n',
        'annulee' => 'n',
    ];

    private const BID_TONES = ['proposee' => 'b', 'choisie' => 'g', 'non_retenue' => 'n', 'retiree' => 'n'];

    private const PAYMENT_TONES = ['reussie' => 'g', 'en_attente' => 'a', 'initiee' => 'a', 'echouee' => 'r', 'expiree' => 'n', 'annulee' => 'n'];

    /**
     * @param  array{value: string, label: string}  $status
     * @return array{value: string, label: string, tone: string}
     */
    public static function status(array $status): array
    {
        return ['value' => $status['value'], 'label' => $status['label'], 'tone' => self::TONES[$status['value']] ?? 'n'];
    }

    /**
     * Ligne de la liste.
     *
     * @param  array<string, mixed>  $q
     * @return array<string, mixed>
     */
    public static function row(array $q): array
    {
        $selected = $q['selected'];
        $open = in_array($q['status']['value'], ['ouverte', 'expiree'], true);

        return [
            'id' => $q['id'],
            'reference' => $q['reference'],
            'trade' => $q['trade']['label'] ?? 'Métier',
            'site' => $q['site']['label'],
            'place' => implode(' · ', array_filter([$q['site']['city'] ?? null, $q['site']['commune'] ?? null])) ?: '—',
            'promoter' => $q['promoter']['name'] ?? '—',
            'bidsCount' => (int) $q['bids_count'],
            'contractor' => $selected['contractor']['name'] ?? null,
            'amount' => $q['quote_total_amount'] ?? $selected['amount'] ?? null,
            'amountLabel' => $q['quote_total_amount'] ? 'Devis TTC' : ($selected ? 'Visite · dont '.number_format((int) $selected['service_fee_amount'], 0, ',', ' ').' FCFA de frais de service' : null),
            'status' => self::status($q['status']),
            'deadline' => $open ? self::date($q['response_deadline']) : null,
            'deadlineSoon' => $q['status']['value'] === 'ouverte' && $q['response_deadline'] && Carbon::parse($q['response_deadline'])->lte(now()->addDays(2)),
            'visit' => ! $open && $selected ? trim(self::date($selected['visit_date']).($selected['visit_slot'] ? ' · '.$selected['visit_slot'] : '')) : null,
        ];
    }

    /**
     * Fiche d’une ligne : la demande, les propositions, la visite, les finances, les devis et la chronologie.
     *
     * @param  array<string, mixed>  $q
     * @return array<string, mixed>
     */
    public static function quote(array $q): array
    {
        $visit = $q['visit'];
        $payment = $q['payment'];
        $selected = collect($q['bids'])->firstWhere('is_selected', true);
        $money = fn (?int $amount): ?string => $amount === null ? null : number_format($amount, 0, ',', ' ').' FCFA';

        return [
            ...self::row($q),
            'siteId' => $q['site']['id'],
            'requestStatus' => $q['request']['status']['label'] ?? null,
            'notes' => $q['request']['notes'],
            'tradesCount' => (int) $q['request']['trades_count'],
            'otherLines' => $q['request']['other_lines'],
            'deadlineIso' => $q['response_deadline'] ? Carbon::parse($q['response_deadline'])->toDateString() : null,
            'submittedAt' => self::date($q['submitted_at']),
            'orderedAt' => self::date($q['request']['ordered_at']),
            'promoterContact' => ['phone' => $q['promoter']['phone'] ?? null, 'email' => $q['promoter']['email'] ?? null],
            'bids' => array_map(fn (array $b): array => [
                'id' => $b['id'],
                'contractor' => $b['contractor']['name'] ?? 'Professionnel',
                'contractorId' => $b['contractor']['id'] ?? null,
                'contractorRef' => $b['contractor']['reference'] ?? null,
                'rating' => $b['contractor']['rating'] ?? null,
                'fee' => (int) $b['fee_amount'],
                'serviceFee' => (int) $b['service_fee_amount'],
                'servicePercent' => $b['service_fee_percent'],
                'total' => (int) $b['amount'],
                'visit' => trim(self::date($b['visit_date']).($b['visit_slot'] ? ' · '.$b['visit_slot'] : '')),
                'duration' => $b['visit_duration'],
                'distance' => $b['distance_km'] !== null ? str_replace('.', ',', (string) round((float) $b['distance_km'], 1)).' km' : null,
                'comment' => $b['comment'],
                'status' => ['label' => $b['is_selected'] ? 'Choisie' : $b['status']['label'], 'tone' => $b['is_selected'] ? 'g' : (self::BID_TONES[$b['status']['value']] ?? 'n')],
                'shown' => (bool) $b['is_shown'],
                'at' => self::date($b['created_at']),
            ], $q['bids']),
            'visitInfo' => $visit ? [
                'status' => $visit['status']['label'] ?? '—',
                'date' => trim(self::date($visit['date']).($visit['slot'] ? ' · '.$visit['slot'] : '')),
                'duration' => $visit['duration'],
                'visitedAt' => self::date($visit['visited_at']),
                'confirmation' => ($c = $visit['confirmation'] ?? null) ? [
                    'value' => $c['status']['value'],
                    'label' => $c['status']['label'],
                    'deadline' => self::date($c['deadline']),
                    'confirmedAt' => self::date($c['confirmed_at']),
                    'contestReason' => $c['contest_reason'],
                    'contestedAt' => self::date($c['contested_at'] ?? null),
                ] : null,
                'reschedule' => $visit['reschedule'] ? [
                    'date' => trim(self::date($visit['reschedule']['date']).($visit['reschedule']['slot'] ? ' · '.$visit['reschedule']['slot'] : '')),
                    'reason' => $visit['reschedule']['reason'],
                    'requestedAt' => self::date($visit['reschedule']['requested_at']),
                ] : null,
            ] : null,
            'finance' => $selected ? [
                ['Frais de visite du professionnel', $money($selected['fee_amount'])],
                ['Frais de service O’CHANTIER'.($selected['service_fee_percent'] !== null ? ' ('.str_replace('.', ',', (string) $selected['service_fee_percent']).' %)' : ''), $money($selected['service_fee_amount'])],
                ['Total payé par le promoteur', $money($selected['amount'])],
            ] : [],
            'payment' => $payment ? [
                'reference' => $payment['reference'],
                'status' => ['label' => $payment['status']['label'], 'tone' => self::PAYMENT_TONES[$payment['status']['value']] ?? 'n'],
                'amount' => $money($payment['amount']),
                'paidAt' => self::date($payment['completed_at']),
                'shared' => $payment['covers_trades'] > 1 ? 'Commande groupée de '.$payment['covers_trades'].' métiers' : null,
            ] : null,
            'versions' => array_map(fn (array $v): array => [
                'id' => $v['id'],
                'label' => $v['number'].' · v'.$v['version'],
                'status' => ['label' => $v['status']['label'], 'tone' => $v['status']['value'] === 'recu' ? 'g' : 'p'],
                'totalHt' => $money($v['total_ht']),
                'totalTtc' => $money($v['total_ttc']),
                'duration' => $v['works_duration'],
                'validity' => $v['validity_days'] ? $v['validity_days'].' jours' : null,
                'sentAt' => self::date($v['sent_at']),
                'receivedAt' => self::date($v['received_at']),
                'pdfUrl' => $v['document']['files'][0]['url'] ?? null,
            ], $q['versions']),
            'timeline' => array_map(self::event(...), $q['timeline']),
            'can' => [
                'extend' => (bool) $q['actions']['extend'],
                'cancel' => (bool) $q['actions']['cancel'],
                'remindPromoter' => (bool) $q['actions']['remind_promoter'],
                'remindContractor' => (bool) $q['actions']['remind_contractor'],
                'decideVisit' => (bool) ($q['actions']['decide_visit'] ?? false),
            ],
        ];
    }

    /**
     * Événement de la chronologie, au format des historiques du Back Office.
     *
     * @param  array<string, mixed>  $e
     * @return array<string, mixed>
     */
    public static function event(array $e): array
    {
        $at = Carbon::parse($e['at'])->timezone(config('app.timezone'));
        [$label, $icon, $tone] = match ($e['origin']) {
            'admin' => ['Back-office', 'desktop_windows', 'info'],
            'professionnel' => ['Professionnel', 'handyman', 'purple'],
            'promoteur' => ['Promoteur', 'apartment', 'success'],
            default => ['Système', 'memory', 'neutral'],
        };

        return [
            'id' => $e['id'],
            'date' => $at->toDateString(),
            'time' => $at->format('H:i'),
            'author' => $e['author'],
            'action' => $e['what'],
            'category' => ['value' => $e['origin'], 'label' => $label, 'icon' => $icon, 'tone' => $tone],
            'origin' => $e['origin'] === 'admin' ? 'Back-office' : ($e['origin'] === 'systeme' ? 'Système' : 'Application mobile'),
            'comment' => $e['detail'],
            'oldValue' => null,
            'newValue' => null,
        ];
    }

    public static function date(?string $iso): ?string
    {
        return $iso ? Carbon::parse($iso)->timezone(config('app.timezone'))->translatedFormat('d M Y') : null;
    }
}
