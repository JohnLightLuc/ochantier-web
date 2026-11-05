<?php

namespace App\Support;

use App\Support\Api\AdminApi;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Onglets de la fiche chantier : seul l’onglet affiché est lu dans l’API (api/v1/admin/sites/{id}/…), puis mis en forme.
 */
class SiteTabs
{
    public const LABELS = ['equipe' => 'Équipe et pointages', 'incidents' => 'Incidents', 'documents' => 'Documents', 'historique' => 'Historique'];

    private const INCIDENT_TONES = ['ouvert' => 'r', 'en_traitement' => 'a', 'resolu' => 'g', 'clos' => 'n'];

    private const SEVERITY_TONES = ['mineure' => 'n', 'moderee' => 'a', 'grave' => 'o', 'critique' => 'r'];

    private const ORIGINS = ['admin' => ['Back Office', 'b', 'desktop_windows'], 'promoteur' => ['Promoteur', 'g', 'apartment'], 'systeme' => ['Système', 'n', 'memory']];

    public function __construct(private AdminApi $api) {}

    /**
     * @return array<string, mixed>
     */
    public function load(string $site, string $tab, Request $request): array
    {
        return match ($tab) {
            'equipe' => $this->team($site),
            'incidents' => $this->incidents($site),
            'documents' => $this->documents($site),
            'historique' => $this->history($site, max(1, (int) $request->query('page', '1'))),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function team(string $site): array
    {
        $d = $this->api->get("sites/{$site}/team")->json('data');

        return [
            'kind' => 'team',
            'members' => array_map(fn (array $m) => [
                'id' => $m['assignment_id'], 'workerId' => $m['worker_id'], 'name' => $m['name'], 'trade' => $m['trade']['label'], 'role' => $m['role'], 'since' => $m['since'],
                'dailyRate' => $m['daily_rate'], 'status' => $m['status']['label'], 'replacement' => $m['replacement']['label'] ?? null, 'replaces' => $m['replaces'],
            ], $d['members']),
            'today' => ['label' => $d['today']['label'], 'isOpen' => $d['today']['is_open'], 'closedMessage' => $d['today']['closed_message'], 'present' => $d['today']['stats']['present'], 'absent' => $d['today']['stats']['absent'], 'total' => $d['today']['stats']['total'], 'pending' => $d['today']['stats']['pending'], 'validated' => $d['today']['validated']],
            'days' => array_map(fn (array $day) => ['date' => $day['date'], 'label' => $day['label'], 'present' => $day['present'], 'total' => $day['total'], 'rate' => $day['presence_rate'], 'validated' => $day['validated']], $d['days']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function incidents(string $site): array
    {
        $response = $this->api->get("sites/{$site}/incidents");
        $meta = $response->json('meta');

        return [
            'kind' => 'incidents',
            'types' => $meta['types'],
            'severities' => $meta['severities'],
            'rows' => array_map(fn (array $i) => [
                'id' => $i['id'], 'reference' => $i['reference'], 'type' => $i['type']['label'], 'description' => $i['description'], 'resolution' => $i['resolution'], 'resolvedBy' => $i['resolved_by'],
                'severity' => [...$i['severity'], 'tone' => self::SEVERITY_TONES[$i['severity']['value']] ?? 'n'],
                'status' => [...$i['status'], 'tone' => self::INCIDENT_TONES[$i['status']['value']] ?? 'n'],
                'occurredAt' => $this->day($i['occurred_at']), 'resolvedAt' => $this->day($i['resolved_at']),
            ], $response->json('data')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function documents(string $site): array
    {
        return [
            'kind' => 'documents',
            'rows' => array_map(fn (array $d) => [
                'id' => $d['id'], 'label' => $d['type']['label'], 'addedAt' => $this->day($d['created_at']), 'version' => $d['version'],
                'files' => array_map(fn (array $f) => ['name' => $f['name'], 'url' => $f['url']], $d['files']),
            ], $this->api->get("sites/{$site}/documents")->json('data')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function history(string $site, int $page): array
    {
        $response = $this->api->get("sites/{$site}/history", ['page' => $page]);
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
                    'time' => $at->format('H:i'),
                    'icon' => $icon,
                    'tone' => $tone,
                    'author' => $e['author'],
                    'role' => null,
                    'action' => $e['what'],
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
