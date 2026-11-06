<?php

namespace App\Support;

use App\Support\Api\AdminApi;
use App\Support\Api\AdminUser;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Onglets de la fiche commercial (ouvriers, activité, zones, objectifs, gains, historique) : seul l’onglet affiché est lu dans
 * l’API (api/v1/admin/commercials/{id}/…), puis mis en forme.
 *
 * Paramètres d’URL : page, statut et mois (gains).
 */
class SalesAgentTabs
{
    public const LABELS = ['ouvriers' => 'Ouvriers suivis', 'activite' => 'Activité terrain', 'zones' => 'Zones', 'objectifs' => 'Objectifs', 'gains' => 'Gains et ajustements', 'historique' => 'Historique'];

    private const PROSPECT_TONES = ['nouveau' => 'b', 'a_contacter' => 'a', 'rendez_vous' => 'p', 'a_completer' => 'o', 'converti' => 'g', 'abandonne' => 'n', 'pret' => 'g'];

    private const PROSPECT_LABELS = ['nouveau' => 'Nouveau', 'a_contacter' => 'À contacter', 'rendez_vous' => 'Rendez-vous', 'a_completer' => 'À compléter', 'converti' => 'Converti', 'abandonne' => 'Abandonné'];

    private const REQUEST_TONES = ['en_attente' => 'a', 'acceptee' => 'g', 'refusee' => 'r', 'annulee' => 'n'];

    private const EARNING_TONES = ['en_attente' => 'a', 'confirmee' => 'b', 'versee' => 'g'];

    private const ADJUSTMENT_TONES = ['proposee' => 'a', 'approuvee' => 'g', 'refusee' => 'r', 'payee' => 'g', 'annulee' => 'n'];

    private const PAYOUT_TONES = ['a_payer' => 'a', 'en_traitement' => 'b', 'paye' => 'g', 'rejete' => 'r', 'annule' => 'n'];

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
            'gains' => $this->earnings($commercial, is_string($request->query('mois')) && preg_match('/^\d{4}-\d{2}$/', $request->query('mois')) ? $request->query('mois') : null),
            'historique' => $this->history($commercial, $page),
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
            'appreciations' => array_map(fn (array $a) => ['id' => $a['id'], 'body' => $a['body'], 'author' => $a['author'], 'date' => $this->day($a['created_at'])], $this->api->get("commercials/{$commercial}/appreciations")->json('data')),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function paymentMethod(string $commercial): ?array
    {
        $user = auth()->user();

        if (! $user instanceof AdminUser || ! $user->hasPermission('commercial_earnings.read')) {
            return null;
        }

        return [
            ...$this->api->get("commercials/{$commercial}/payment-method")->json('data'),
            'can' => ['create' => $user->hasPermission('commercial_earnings.update'), 'approve' => $user->hasPermission('commercial_earnings.approve')],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function earnings(string $commercial, ?string $month): array
    {
        $d = $this->api->get("commercials/{$commercial}/earnings", array_filter(['month' => $month]))->json('data');

        return [
            'kind' => 'earnings',
            'paymentMethod' => $this->paymentMethod($commercial),
            'month' => $d['month'],
            'monthLabel' => $d['month_label'],
            'months' => $d['months'],
            'totals' => ['confirmed' => $d['confirmed'], 'paid' => $d['paid'], 'pending' => $d['pending']],
            'history' => array_map(fn (array $e) => [
                'id' => $e['id'], 'worker' => $e['name'], 'workerId' => $e['worker_id'], 'amount' => $e['amount'],
                'status' => ['value' => $e['status']['value'], 'label' => $e['status']['label'], 'tone' => self::EARNING_TONES[$e['status']['value']] ?? 'n'], 'date' => $this->day($e['event_at']),
            ], $d['history']),
            'adjustments' => array_map(fn (array $a) => [
                'id' => $a['id'], 'kind' => $a['kind'], 'amount' => $a['amount'], 'reason' => $a['reason'], 'grantedOn' => $a['granted_on'], 'proposedBy' => $a['proposed_by']['full_name'], 'decidedBy' => $a['decided_by'], 'canDecide' => $a['can_decide'],
                'status' => ['value' => $a['status']['value'], 'label' => $a['status']['label'], 'tone' => self::ADJUSTMENT_TONES[$a['status']['value']] ?? 'n'],
            ], $d['adjustments']),
            'payouts' => array_map(fn (array $p) => [
                'id' => $p['id'], 'reference' => $p['reference'], 'start' => $p['period']['start'], 'end' => $p['period']['end'], 'amount' => $p['amount'],
                'status' => ['value' => $p['status']['value'], 'label' => $p['status']['label'], 'tone' => self::PAYOUT_TONES[$p['status']['value']] ?? 'n'], 'paidAt' => $this->day($p['paid_at']),
            ], $d['payouts']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function history(string $commercial, int $page): array
    {
        $response = $this->api->get("commercials/{$commercial}/history", ['page' => $page]);
        $today = Carbon::today(config('app.timezone'));

        return [
            'kind' => 'history',
            'pagination' => $this->pagination($response->json('meta'), count($response->json('data'))),
            'rows' => array_map(function (array $e) use ($today) {
                $at = Carbon::parse($e['at'])->timezone(config('app.timezone'));

                return [
                    'id' => $e['id'],
                    'day' => match (true) {
                        $at->isSameDay($today) => 'Aujourd’hui',
                        $at->isSameDay($today->copy()->subDay()) => 'Hier',
                        default => $at->translatedFormat('l j F Y'),
                    },
                    'time' => $at->format('H:i'),
                    'icon' => 'desktop_windows',
                    'tone' => 'b',
                    'author' => $e['author'],
                    'role' => null,
                    'action' => $e['what'],
                    'kind' => ['value' => 'admin', 'label' => 'Back Office', 'tone' => 'b'],
                    'target' => $e['detail'],
                ];
            }, $response->json('data')),
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
