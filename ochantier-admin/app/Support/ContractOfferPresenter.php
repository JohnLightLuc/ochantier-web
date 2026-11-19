<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Données de l’API (api/v1/admin/contract-offers) mises en forme pour les pages Offres contractuelles : statuts et
 * couleurs, prix promoteur des réponses (montant du professionnel, commission Y, acompte X), attribution et
 * chronologie.
 */
class ContractOfferPresenter
{
    /**
     * Statut affiché => couleur.
     */
    public const TONES = [
        'ouverte' => 'b',
        'en_comparaison' => 'p',
        'acompte_attendu' => 'a',
        'attribuee' => 'g',
        'expiree' => 'n',
        'suspendue' => 'o',
        'cloturee' => 'n',
        'annulee' => 'n',
    ];

    private const RESPONSE_TONES = ['proposee' => 'b', 'choisie' => 'a', 'retenue' => 'g', 'non_retenue' => 'n', 'annulee' => 'n'];

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
     * @param  array<string, mixed>  $o
     * @return array<string, mixed>
     */
    public static function row(array $o): array
    {
        $open = in_array($o['status']['value'], ['ouverte', 'en_comparaison', 'acompte_attendu'], true);

        return [
            'id' => $o['id'],
            'reference' => $o['reference'],
            'site' => $o['site']['label'],
            'place' => implode(' · ', array_filter([$o['site']['city'] ?? null, $o['site']['commune'] ?? null])) ?: '—',
            'promoter' => $o['promoter']['name'] ?? '—',
            'price' => (int) $o['price'],
            'period' => trim(self::date($o['start_date']).' → '.self::date($o['end_date'])),
            'responses' => (int) $o['responses_count'],
            'counterProposals' => (int) $o['counter_proposals_count'],
            'retained' => $o['retained'] ? ['contractor' => $o['retained']['contractor']['name'] ?? '—', 'total' => (int) $o['retained']['total']] : null,
            'quoteReference' => $o['quote_request']['reference'] ?? null,
            'status' => self::status($o['status']),
            'deadline' => self::date($o['application_deadline']),
            'deadlineSoon' => $open && $o['application_deadline'] && Carbon::parse($o['application_deadline'])->lte(now()->addDays(2)),
        ];
    }

    /**
     * Fiche : l’offre, les réponses au prix promoteur, le comparatif, l’attribution et la chronologie.
     *
     * @param  array<string, mixed>  $o
     * @return array<string, mixed>
     */
    public static function offer(array $o): array
    {
        $money = fn (?int $amount): ?string => $amount === null ? null : number_format($amount, 0, ',', ' ').' FCFA';
        $percent = fn (float $value): string => str_replace('.', ',', (string) round($value, 2)).' %';
        $attribution = $o['attribution'];

        return [
            ...self::row($o),
            'description' => $o['description'],
            'paymentMode' => $o['payment_mode']['label'] ?? null,
            'promoterContact' => ['phone' => $o['promoter']['phone'] ?? null, 'email' => $o['promoter']['email'] ?? null],
            'rates' => ['commission' => $percent($o['rates']['commission_percent']), 'deposit' => $percent($o['rates']['deposit_percent'])],
            'deadlineIso' => $o['application_deadline'] ? Carbon::parse($o['application_deadline'])->toDateString() : null,
            'extensions' => (int) $o['extension_count'],
            'cancellationReason' => $o['cancellation_reason'],
            'quoteRequestId' => $o['quote_request']['id'] ?? null,
            'quoteLineId' => $o['quote_request']['line_ids'][0] ?? null,
            'responsesList' => array_map(fn (array $r): array => [
                'id' => $r['id'],
                'contractor' => $r['contractor']['name'] ?? 'Professionnel',
                'contractorId' => $r['contractor']['id'] ?? null,
                'contractorRef' => $r['contractor']['reference'] ?? null,
                'rating' => $r['contractor']['rating'] ?? null,
                'mode' => ['label' => $r['mode']['value'] === 'conforme' ? 'Conforme' : 'Contre-proposition', 'tone' => $r['mode']['value'] === 'conforme' ? 'g' : 'o'],
                'amount' => (int) $r['amount'],
                'commission' => (int) $r['commission'],
                'total' => (int) $r['total'],
                'deposit' => (int) $r['deposit'],
                'gap' => (int) $r['total'] - (int) $o['price'],
                'duration' => $r['duration_days'].' jours',
                'team' => $r['team_size'].' personne'.($r['team_size'] > 1 ? 's' : ''),
                'dates' => $r['start_date'] ? trim(self::date($r['start_date']).' → '.self::date($r['end_date'])) : null,
                'distance' => $r['distance_km'] !== null ? str_replace('.', ',', (string) round((float) $r['distance_km'], 1)).' km' : null,
                'message' => $r['message'],
                'status' => ['label' => $r['status']['label'], 'tone' => self::RESPONSE_TONES[$r['status']['value']] ?? 'n'],
                'shown' => (bool) $r['is_shown'],
                'competing' => in_array($r['status']['value'], ['proposee', 'choisie', 'retenue'], true),
                'quoteAuthor' => (bool) $r['is_quote_author'],
                'sentAt' => self::date($r['sent_at']),
            ], $o['responses']),
            'attribution' => $attribution ? [
                'mission' => $attribution['mission_reference'],
                'period' => trim(self::date($attribution['start_date']).' → '.self::date($attribution['end_date'])),
                'deposit' => $attribution['deposit'] ? [
                    'reference' => $attribution['deposit']['reference'],
                    'amount' => $money($attribution['deposit']['amount']),
                    'paid' => $money($attribution['deposit']['paid']),
                    'status' => $attribution['deposit']['status']['label'] ?? '—',
                ] : null,
            ] : null,
            'timeline' => array_map(QuotePresenter::event(...), $o['timeline']),
            'can' => [
                'extend' => (bool) $o['actions']['extend'],
                'cancel' => (bool) $o['actions']['cancel'],
                'remindPromoter' => (bool) $o['actions']['remind_promoter'],
                'remindContractors' => (bool) $o['actions']['remind_contractors'],
            ],
        ];
    }

    public static function date(?string $iso): ?string
    {
        return $iso ? Carbon::parse($iso)->timezone(config('app.timezone'))->translatedFormat('d M Y') : null;
    }
}
