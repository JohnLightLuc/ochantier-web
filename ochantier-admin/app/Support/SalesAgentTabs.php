<?php

namespace App\Support;

use App\Support\Api\AdminApi;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Onglets terrain de la fiche commercial (ouvriers, activité, zones, objectifs) : seul l’onglet affiché est lu dans
 * l’API (api/v1/admin/commercials/{id}/…), puis mis en forme.
 *
 * Paramètres d’URL : page et statut.
 */
class SalesAgentTabs
{
    public const LABELS = ['ouvriers' => 'Ouvriers suivis', 'activite' => 'Activité terrain', 'zones' => 'Zones', 'objectifs' => 'Objectifs'];

    private const PROSPECT_TONES = ['nouveau' => 'b', 'a_contacter' => 'a', 'rendez_vous' => 'p', 'a_completer' => 'o', 'converti' => 'g', 'abandonne' => 'n', 'pret' => 'g'];

    private const PROSPECT_LABELS = ['nouveau' => 'Nouveau', 'a_contacter' => 'À contacter', 'rendez_vous' => 'Rendez-vous', 'a_completer' => 'À compléter', 'converti' => 'Converti', 'abandonne' => 'Abandonné'];

    private const REQUEST_TONES = ['en_attente' => 'a', 'acceptee' => 'g', 'refusee' => 'r', 'annulee' => 'n'];

    public function __construct(private AdminApi $api) {}

    /**
     * @return array<string, mixed>
     */
    public function load(string $commercial, string $tab, Request $request): array
    {
        $page = max(1, (int) $request->query('page', '1'));
        $status = is_string($request->query('statut')) && $request->query('statut') !== '' ? $request->query('statut') : null;

        return match ($tab) {
            'ouvriers' => $this->workers($commercial, $page, array_key_exists((string) $status, WorkerPresenter::STATUS_LABELS) ? $status : null),
            'activite' => $this->prospects($commercial, $page, array_key_exists((string) $status, self::PROSPECT_LABELS) ? $status : null),
            'zones' => $this->zones($commercial),
            'objectifs' => $this->objectives($commercial),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function workers(string $commercial, int $page, ?string $status): array
    {
        $response = $this->api->get("commercials/{$commercial}/workers", array_filter(['page' => $page, 'status' => $status]));
        $meta = $response->json('meta');

        return [
            'kind' => 'workers',
            'status' => $status,
            'filters' => $this->filters(WorkerPresenter::STATUS_LABELS, $meta['counts']),
            'pagination' => $this->pagination($meta, count($response->json('data'))),
            'rows' => array_map(fn (array $w) => [
                'id' => $w['id'], 'name' => $w['full_name'], 'code' => $w['reference'], 'trade' => $w['trade']['label'] ?? null, 'commune' => $w['commune']['name'] ?? null,
                'status' => WorkerPresenter::status($w['status']), 'submittedAt' => $this->day($w['submitted_at']),
            ], $response->json('data')),
            'transfers' => array_map(fn (array $t) => [...$t, 'at' => $this->day($t['at'])], $meta['transfers']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function prospects(string $commercial, int $page, ?string $status): array
    {
        $response = $this->api->get("commercials/{$commercial}/prospects", array_filter(['page' => $page, 'status' => $status]));
        $meta = $response->json('meta');

        return [
            'kind' => 'prospects',
            'status' => $status,
            'filters' => $this->filters(self::PROSPECT_LABELS, $meta['counts']),
            'pagination' => $this->pagination($meta, count($response->json('data'))),
            'rows' => array_map(fn (array $p) => [
                'id' => $p['id'], 'name' => $p['full_name'], 'phone' => $p['phone'] ?? null, 'trade' => $p['trade']['label'] ?? null, 'commune' => $p['commune']['name'] ?? null,
                'status' => ['value' => $p['stage']['value'], 'label' => $p['stage']['label'], 'tone' => self::PROSPECT_TONES[$p['stage']['value']] ?? 'n'],
                'percent' => $p['completion']['percent'], 'hint' => $p['hint'], 'createdAt' => $this->day($p['created_at']), 'lastFollowupAt' => $this->day($p['last_followup_at']),
            ], $response->json('data')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function zones(string $commercial): array
    {
        return [
            'kind' => 'zones',
            'requests' => array_map(fn (array $r) => [
                'id' => $r['id'], 'communes' => $r['communes'], 'status' => ['value' => $r['status']['value'], 'label' => $r['status']['label'], 'tone' => self::REQUEST_TONES[$r['status']['value']] ?? 'n'],
                'createdAt' => $this->day($r['created_at']), 'comment' => $r['decision_comment'], 'decidedBy' => $r['decided_by'], 'decidedAt' => $this->day($r['decided_at']),
            ], $this->api->get("commercials/{$commercial}/zone-requests")->json('data.requests')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function objectives(string $commercial): array
    {
        return [
            'kind' => 'objectives',
            'rows' => array_map(fn (array $o) => [
                'month' => substr($o['period']['start'], 0, 7), 'label' => $o['period_label'], 'target' => $o['target'], 'achieved' => $o['achieved'], 'percent' => $o['percent'],
                'comment' => $o['comment'], 'canEdit' => $o['can_edit'],
            ], $this->api->get("commercials/{$commercial}/objectives")->json('data')),
            'currentMonth' => now()->format('Y-m'),
        ];
    }

    /**
     * @param  array<string, string>  $labels
     * @param  array<string, int>  $counts
     * @return list<array{label: string, value: string|null, count: int}>
     */
    private function filters(array $labels, array $counts): array
    {
        $visible = array_filter($labels, fn (string $status) => ($counts[$status] ?? 0) > 0, ARRAY_FILTER_USE_KEY);

        return [
            ['label' => 'Tous', 'value' => null, 'count' => array_sum($counts)],
            ...array_map(fn (string $value, string $label) => ['label' => $label, 'value' => $value, 'count' => $counts[$value]], array_keys($visible), $visible),
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, int>
     */
    private function pagination(array $meta, int $count): array
    {
        $from = $count ? ($meta['current_page'] - 1) * $meta['per_page'] + 1 : 0;

        return ['currentPage' => $meta['current_page'], 'lastPage' => $meta['last_page'], 'from' => $from, 'to' => $from ? $from + $count - 1 : 0, 'total' => $meta['total']];
    }

    private function day(?string $date): ?string
    {
        return $date ? Carbon::parse($date)->format('Y-m-d') : null;
    }
}
