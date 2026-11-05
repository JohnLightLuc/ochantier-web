<?php

namespace App\Support;

use App\Support\Api\AdminApi;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Onglets de la fiche d’un besoin : seul l’onglet affiché est lu dans l’API (api/v1/admin/offers/{id}/…).
 */
class OfferTabs
{
    public const LABELS = ['ouvriers' => 'Propositions et ouvriers', 'documents' => 'Documents', 'historique' => 'Historique'];

    private const PROPOSAL_TONES = ['a_valider' => 'a', 'acceptee' => 'g', 'refusee' => 'r', 'expiree' => 'n', 'annulee' => 'n'];

    private const ORIGINS = ['admin' => ['Back Office', 'b', 'desktop_windows'], 'promoteur' => ['Promoteur', 'g', 'apartment'], 'systeme' => ['Système', 'n', 'memory']];

    public function __construct(private AdminApi $api) {}

    /**
     * @return array<string, mixed>
     */
    public function load(string $offer, string $tab, Request $request): array
    {
        return match ($tab) {
            'ouvriers' => $this->workers($offer),
            'documents' => $this->documents($offer),
            'historique' => $this->history($offer, max(1, (int) $request->query('page', '1'))),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function workers(string $offer): array
    {
        $d = $this->api->get("offers/{$offer}/workers")->json('data');

        return [
            'kind' => 'workers',
            'proposals' => array_map(fn (array $p) => [
                'id' => $p['id'], 'reference' => $p['reference'], 'status' => [...$p['status'], 'tone' => self::PROPOSAL_TONES[$p['status']['value']] ?? 'n'], 'requested' => $p['requested'], 'proposed' => $p['proposed'],
                'partial' => $p['is_partial'], 'total' => $p['total_amount'], 'deposit' => $p['deposit_amount'], 'publishedAt' => $this->day($p['published_at']), 'decidedAt' => $this->day($p['decided_at']), 'refusal' => $p['refusal'],
            ], $d['proposals']),
            'assigned' => array_map(fn (array $a) => ['id' => $a['id'], 'workerId' => $a['worker']['id'], 'name' => $a['worker']['full_name'], 'trade' => $a['trade'], 'status' => $a['status']['label'], 'startsOn' => $a['start_date'], 'endsOn' => $a['end_date']], $d['assigned']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function documents(string $offer): array
    {
        return [
            'kind' => 'documents',
            'rows' => array_map(fn (array $d) => ['id' => $d['id'], 'label' => $d['type']['label'], 'addedAt' => $this->day($d['created_at']), 'files' => array_map(fn (array $f) => ['name' => $f['name'], 'url' => $f['url']], $d['files'])], $this->api->get("offers/{$offer}/documents")->json('data')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function history(string $offer, int $page): array
    {
        $response = $this->api->get("offers/{$offer}/history", ['page' => $page]);
        $meta = $response->json('meta');
        $count = count($response->json('data'));
        $from = $count ? ($meta['current_page'] - 1) * $meta['per_page'] + 1 : 0;
        $today = Carbon::today(config('app.timezone'));

        return [
            'kind' => 'history',
            'pagination' => ['currentPage' => $meta['current_page'], 'lastPage' => $meta['last_page'], 'from' => $from, 'to' => $from ? $from + $count - 1 : 0, 'total' => $meta['total']],
            'rows' => array_map(function (array $e) use ($today) {
                $at = Carbon::parse($e['at'])->timezone(config('app.timezone'));
                [$label, $tone, $icon] = self::ORIGINS[$e['origin']] ?? ['—', 'n', 'history'];

                return [
                    'id' => $e['id'],
                    'day' => match (true) {
                        $at->isSameDay($today) => 'Aujourd’hui',
                        $at->isSameDay($today->copy()->subDay()) => 'Hier',
                        default => $at->translatedFormat('l j F Y'),
                    },
                    'time' => $at->format('H:i'), 'icon' => $icon, 'tone' => $tone, 'author' => $e['author'], 'role' => null, 'action' => $e['what'],
                    'kind' => ['value' => $e['origin'], 'label' => $label, 'tone' => $tone],
                    'target' => implode(' · ', array_filter([$e['to'] ? ($e['from'] ?? '—').' → '.$e['to'] : null, $e['detail']])) ?: null,
                ];
            }, $response->json('data')),
        ];
    }

    private function day(?string $date): ?string
    {
        return $date ? Carbon::parse($date)->format('Y-m-d') : null;
    }
}
