<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Registre des commissions O’CHANTIER PRO (api/v1/admin/pro-commissions) mis en forme pour le Back Office : onglets
 * « Commissions » des demandes de devis et des offres contractuelles, fiche d’un professionnel.
 */
class ProCommissionPresenter
{
    private const STATUS_TONES = ['a_collecter' => 'a', 'collectee' => 'g', 'remboursee' => 'n'];

    private const PAYOUT_TONES = ['a_payer' => 'a', 'en_traitement' => 'b', 'paye' => 'g', 'rejete' => 'r', 'annule' => 'n'];

    /**
     * @param  array<string, mixed>  $l
     * @return array<string, mixed>
     */
    public static function line(array $l): array
    {
        $href = match ($l['opportunity']['type']) {
            'demande_devis' => '/demandes-devis/'.$l['opportunity']['id'],
            'offre_contractuelle' => '/offres-contractuelles/'.$l['opportunity']['id'],
            default => null,
        };

        return [
            'id' => $l['id'],
            'source' => $l['source']['label'],
            'reference' => $l['reference'],
            'label' => $l['label'],
            'href' => $href,
            'site' => $l['site']['label'],
            'contractor' => $l['contractor']['name'],
            'contractorId' => $l['contractor']['id'],
            'payer' => $l['payer'],
            'base' => (int) $l['base'],
            'rate' => str_replace('.', ',', (string) round((float) $l['rate'], 2)).' %',
            'commission' => (int) $l['commission'],
            'net' => $l['professional_net'],
            'status' => ['label' => $l['status']['label'], 'tone' => self::STATUS_TONES[$l['status']['value']] ?? 'n'],
            'date' => $l['date'] ? Carbon::parse($l['date'])->timezone(config('app.timezone'))->translatedFormat('d M Y') : null,
            'payout' => $l['payout'] ? ['reference' => $l['payout']['reference'], 'id' => $l['payout']['id'], 'label' => $l['payout']['status']['label'], 'tone' => self::PAYOUT_TONES[$l['payout']['status']['value']] ?? 'n'] : null,
            'serviceDone' => (bool) $l['service_done'],
        ];
    }

    /**
     * Registre filtré : lignes et totaux.
     *
     * @param  array<string, mixed>  $response  Réponse de l’API (data, meta).
     * @return array<string, mixed>
     */
    public static function ledger(array $response): array
    {
        $totals = $response['meta']['totals'];

        return [
            'lines' => array_map(self::line(...), $response['data']),
            'total' => (int) $response['meta']['total'],
            'currentPage' => (int) $response['meta']['current_page'],
            'lastPage' => (int) $response['meta']['last_page'],
            'totals' => [
                'collected' => (int) $totals['commission']['collectee'],
                'toCollect' => (int) $totals['commission']['a_collecter'],
                'collectedAmount' => (int) $totals['collected_amount'],
                'professionalNet' => (int) $totals['professional_net'],
            ],
        ];
    }
}
