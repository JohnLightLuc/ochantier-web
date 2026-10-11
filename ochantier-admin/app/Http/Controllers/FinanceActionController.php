<?php

namespace App\Http\Controllers;

use App\Models\FinanceAudit;
use App\Models\FinanceClosing;
use App\Models\FinanceReport;
use App\Models\FinanceResolution;
use App\Models\FinanceView;
use App\Models\PaymentNotification;
use App\Support\FinanceInsights;
use App\Support\FinanceLedger;
use App\Support\FinanceReports;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Actions de Finance & Reporting : synchronisation, vues enregistrées et partagées, résolution des écarts de
 * rapprochement et des doublons, signalements, clôture et réouverture des périodes, génération des rapports et exports.
 *
 * Le module reste en lecture analytique : aucune donnée source du module Paiements n’est modifiée. Chaque action est
 * soumise au rôle financier et tracée dans le journal d’audit.
 */
class FinanceActionController extends Controller
{
    public const RECIPIENTS = ['Direction générale', 'Contrôle de gestion', 'Responsable paiements', 'Responsable commercial'];

    private function authorizeRight(string $right): void
    {
        abort_unless(FinanceInsights::can($right), 403, FinanceInsights::denied());
    }

    private function user(): string
    {
        return (auth()->user()?->name ?? 'Système').' · '.FinanceInsights::role();
    }

    public function sync(): RedirectResponse
    {
        $at = now()->format('H:i');
        FinanceAudit::record('a relancé la synchronisation', 'Données', null, $at, 'Aucune opération recréée', 'controle');
        Inertia::flash('toast', "Données actualisées à {$at} · aucune opération dupliquée.");

        return back();
    }

    /**
     * Enregistre la vue courante (filtres, base temporelle, période).
     */
    public function storeView(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:80'],
            'filtres' => ['nullable', 'array'],
            'filtres.*' => ['string'],
            'base' => ['nullable', Rule::in(array_keys(FinanceLedger::BASES))],
            'periode' => ['nullable', Rule::in(array_keys(FinanceInsights::PERIODS))],
        ]);
        $filters = array_intersect_key($data['filtres'] ?? [], FinanceInsights::FILTERS);
        FinanceView::create(['name' => trim($data['name']), 'filters' => $filters, 'basis' => $data['base'] ?? 'op', 'period' => $data['periode'] ?? 'mois', 'author' => auth()->user()?->name ?? 'Système', 'shared' => []]);
        FinanceAudit::record('a enregistré une vue', 'Filtres', null, trim($data['name']), $this->summary($filters));
        Inertia::flash('toast', 'Vue enregistrée.');

        return back();
    }

    public function shareView(Request $request): RedirectResponse
    {
        $this->authorizeRight('rap');
        $data = $request->validate(['to' => ['required', Rule::in(self::RECIPIENTS)], 'filtres' => ['nullable', 'array']]);
        FinanceAudit::record('a partagé une vue', 'Filtres', null, $data['to'], $this->summary(array_intersect_key($data['filtres'] ?? [], FinanceInsights::FILTERS)), 'diffusion');
        Inertia::flash('toast', "Vue partagée avec {$data['to']}.");

        return back();
    }

    /**
     * @param  array<string, string>  $filters
     */
    private function summary(array $filters): string
    {
        return collect($filters)->map(fn (string $v, string $k): string => FinanceInsights::FILTERS[$k]." : {$v}")->implode(' · ') ?: 'sans filtre';
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(string $reference): array
    {
        $x = (new FinanceLedger)->find($reference);
        abort_unless($x !== null, 404);

        return $x;
    }

    /**
     * Marque un écart de rapprochement comme traité (justification obligatoire).
     */
    public function resolveGap(Request $request, string $reference): RedirectResponse
    {
        $this->authorizeRight('close');
        $x = $this->entry($reference);
        abort_if($x['dup'] || $x['recFixed'], 422);
        $data = $request->validate(['reason' => ['required', 'string', 'min:10']]);
        FinanceResolution::create(['reference' => $x['ref'], 'kind' => FinanceResolution::GAP, 'reason' => $data['reason'], 'author' => $this->user()]);
        FinanceAudit::record('a résolu un écart de rapprochement', "Rapprochement {$x['opRef']}", $x['recSt'], 'Écart traité', $data['reason'], 'anomalie');
        Inertia::flash('toast', 'Écart marqué comme traité · tracé dans l’audit.');

        return back();
    }

    /**
     * Confirme l’exclusion d’un doublon : l’opération reste exclue des indicateurs.
     */
    public function confirmDuplicate(Request $request, string $reference): RedirectResponse
    {
        $this->authorizeRight('close');
        $x = $this->entry($reference);
        abort_unless($x['dup'] && ! $x['dupOk'], 422);
        $data = $request->validate(['reason' => ['required', 'string', 'min:10']]);
        FinanceResolution::create(['reference' => $x['ref'], 'kind' => FinanceResolution::DUPLICATE, 'reason' => $data['reason'], 'author' => $this->user()]);
        FinanceAudit::record('a validé l’exclusion d’un doublon', "Rapprochement {$x['opRef']}", 'Doublon à confirmer', 'Exclusion validée', $data['reason'], 'anomalie');
        Inertia::flash('toast', 'Doublon confirmé · aucune opération comptée deux fois.');

        return back();
    }

    /**
     * Signale un écart sur une commission au responsable paiements.
     */
    public function reportGap(Request $request, string $reference): RedirectResponse
    {
        $x = $this->entry($reference);
        $data = $request->validate(['reason' => ['required', 'string', 'min:10']]);
        FinanceAudit::record('a signalé un écart', "Commission {$x['ref']}", null, 'Écart signalé', $data['reason'], 'anomalie');
        PaymentNotification::create(['type' => 'Écart signalé', 'recipient' => 'Responsable paiements', 'channel' => 'Notification interne', 'sent_at' => now(), 'content' => "{$x['ref']} · {$x['opRef']} · {$data['reason']}", 'status' => 'Envoyée']);
        Inertia::flash('toast', 'Écart signalé · responsable paiements notifié.');

        return back();
    }

    /**
     * Cycle de clôture d’un mois : préparation, contrôle, clôture (instantané versionné) et réouverture.
     */
    public function closing(Request $request, string $month, string $action): RedirectResponse
    {
        abort_unless(in_array($month, FinanceLedger::months(), true), 404);
        $this->authorizeRight($action === 'reouverture' ? 'reopen' : 'close');
        $closing = FinanceClosing::query()->firstOrNew(['month' => $month], ['status' => 'Ouverte', 'version' => 0, 'history' => []]);
        $label = FinanceLedger::monthLabel($month, true);
        $short = FinanceLedger::monthLabel($month);
        $from = $closing->status;
        $allowed = ['preparation' => ['Ouverte'], 'controle' => ['En préparation'], 'cloture' => ['À contrôler', 'Rouverte avec autorisation'], 'reouverture' => ['Clôturée']][$action];
        if (! in_array($from, $allowed, true)) {
            throw ValidationException::withMessages(['status' => "Action impossible : {$label} est au statut « {$from} »."]);
        }
        $reason = $action === 'reouverture' ? $request->validate(['reason' => ['required', 'string', 'min:15']])['reason'] : null;

        switch ($action) {
            case 'preparation':
            case 'controle':
                $closing->fill(['status' => $action === 'preparation' ? 'En préparation' : 'À contrôler'])->save();
                FinanceAudit::record($action === 'preparation' ? 'a lancé la préparation de la clôture' : 'a soumis la période au contrôle', "Clôture {$short}", $from, $closing->status, null, 'statut');
                Inertia::flash('toast', "{$label} · {$closing->status}.");
                break;
            case 'cloture':
                $ledger = new FinanceLedger;
                $entries = $ledger->entries()->filter(fn (array $x): bool => str_starts_with($x['d']['op'], $month));
                $a = FinanceLedger::aggregate($entries);
                $notValidated = $entries->filter(fn (array $x): bool => ! $x['valid'] && ! $x['test'] && $x['payStatus'] !== 'Annulé')->count();
                $blocking = array_filter(FinanceController::closingChecks($month, $a, $notValidated), fn (array $c): bool => $c[3] && ! $c[1]);
                if ($blocking) {
                    throw ValidationException::withMessages(['status' => 'Clôture impossible : '.implode(', ', array_map(fn (array $c): string => mb_strtolower($c[0]), $blocking)).'.']);
                }
                $version = $closing->version + 1;
                $snapshot = $ledger->snapshot($month, $this->user(), FinanceLedger::today()->format('Y-m-d'), $version);
                $closing->fill(['status' => 'Clôturée', 'version' => $version, 'history' => $closing->snapshot ? [$closing->snapshot, ...$closing->history] : $closing->history, 'snapshot' => $snapshot])->save();
                FinanceAudit::record('a clôturé la période', "Clôture {$short}", $from, 'Clôturée', "Instantané v{$version} · règles {$snapshot['ruleV']}", 'statut');
                Inertia::flash('toast', "{$label} clôturé · instantané v{$version} enregistré.");
                break;
            default:
                $closing->fill(['status' => 'Rouverte avec autorisation', 'reason' => $reason])->save();
                FinanceAudit::record('a rouvert la période', "Clôture {$short}", 'Clôturée', 'Rouverte avec autorisation', "{$reason} · instantané v{$closing->version} conservé", 'statut');
                Inertia::flash('toast', 'Période rouverte · motif enregistré dans l’audit.');
        }

        return back();
    }

    /**
     * Génère un rapport du catalogue (période, base et filtres de la requête) et l’enregistre dans l’historique.
     */
    public function generate(Request $request): Response
    {
        $this->authorizeRight('export');
        $data = $request->validate([
            'rapport' => ['required', Rule::in(array_keys(FinanceReports::CATALOG))],
            'format' => ['required', Rule::in(FinanceReports::FORMATS)],
            'regroupement' => ['nullable', 'string'],
            'niveau' => ['nullable', Rule::in(['Synthèse', 'Détaillé'])],
            'graphiques' => ['nullable', Rule::in(['Oui', 'Non'])],
            'definitions' => ['nullable', Rule::in(['Oui', 'Non'])],
        ]);
        $id = FinanceReports::GROUPS[$data['regroupement'] ?? ''] ?? $data['rapport'];
        [$name, $right] = FinanceReports::CATALOG[$id];
        $this->authorizeRight($right);
        $insights = new FinanceInsights(new FinanceLedger, $request->query());
        $reports = new FinanceReports($insights);
        [$columns, $rows] = $reports->build($id);
        $extra = [];
        if (($data['definitions'] ?? 'Oui') === 'Oui') {
            $extra[] = ['Définitions', collect(FinanceInsights::definitions())->filter(fn (array $k): bool => FinanceInsights::can($k['perm']))->take(12)->map(fn (array $k): string => "{$k['name']} = {$k['formula']}")->implode(' | ')];
        }
        if (filled($data['regroupement'] ?? null) && $data['regroupement'] !== 'Aucun') {
            $extra[] = ['Regroupement', $data['regroupement']];
        }
        if (filled($data['niveau'] ?? null)) {
            $extra[] = ['Niveau de détail', $data['niveau']];
        }
        if (($data['graphiques'] ?? 'Non') === 'Oui') {
            $extra[] = ['Graphiques', 'Séries des graphiques incluses sous forme de tableaux'];
        }
        FinanceReport::create(['name' => $name, 'format' => $data['format'], 'period' => $insights->period['range'], 'basis' => $insights->basisLabel(), 'author' => auth()->user()?->name ?? 'Système', 'rows' => count($rows), 'generated_at' => now()]);
        FinanceAudit::record("a généré « {$name} »", 'Rapports', null, $data['format'], count($rows).' ligne(s) · '.($insights->filterSummary() ?: 'sans filtre').' · base '.$insights->basisLabel(), 'document');

        return FinanceReports::file($name, $data['format'], $reports->meta($name, $extra), $columns, $rows);
    }

    /**
     * Détail d’une commission (CSV).
     */
    public function commissionExport(Request $request, string $reference): Response
    {
        $this->authorizeRight('export');
        $insights = new FinanceInsights(new FinanceLedger, $request->query());
        $x = $insights->ledger->find($reference);
        abort_unless($x !== null, 404);
        $cfg = config('admin.finance');
        $margin = $x['comNet'] - $x['feeOut'] - (int) round($x['comNet'] * $cfg['sales_cost_rate'] / 100) - $x['feeLost'] - ($x['ok'] ? $cfg['direct_cost'] : 0);
        FinanceAudit::record('a exporté le détail d’une commission', "Commission {$x['ref']}", null, 'CSV', null, 'document');
        $name = "Détail commission {$x['ref']}";

        return FinanceReports::file($name, 'CSV', (new FinanceReports($insights))->meta("Détail de la commission {$x['ref']}"), ['Champ', 'Valeur'], [
            ['Commission', $x['ref']], ['Paiement source', $x['opRef']], ['Promoteur', $x['promoter']], ['Chantier', $x['site']], ['Ouvrier', FinanceInsights::workerName($x)], ['Base', $x['gross']], ['Règle', $x['ruleVersion']],
            ['Taux Y', $x['y']], ['Taux Z', $x['z']], ['Brute', $x['com']], ['Encaissée', $x['comEnc']], ['Remboursée', $x['comRef']], ['Extournée', $x['comExt']], ['Nette', $x['comNet']],
            ['Frais opérateurs', $x['feeIn'] + $x['feeOut']], ['Marge contributive', FinanceInsights::can('marge') ? $margin : FinanceInsights::HIDDEN], ['Statut', $x['stShow']],
        ]);
    }

    /**
     * Instantané de clôture d’un mois (tableau Excel).
     */
    public function snapshotExport(string $month): Response
    {
        $this->authorizeRight('export');
        $closing = FinanceClosing::query()->where('month', $month)->firstOrFail();
        $s = $closing->snapshot;
        abort_unless($s !== null, 404);
        $label = FinanceLedger::monthLabel($month, true);
        FinanceAudit::record('a exporté un instantané', 'Clôture '.FinanceLedger::monthLabel($month), null, "v{$s['v']}", null, 'document');

        return FinanceReports::file("Instantané {$label} v{$s['v']}", 'Excel', [['Période', $label], ['Version', "v{$s['v']}"], ['Clôturé le', FinanceInsights::date($s['at'])], ['Par', $s['by']], ['Version des règles', $s['ruleV']], ['Devise', 'FCFA']], ['Indicateur', 'Montant'], [
            ['Volume d’affaires', $s['vol']], ['Commissions promoteurs', $s['comP']], ['Commissions ouvriers', $s['comO']], ['Commissions nettes', $s['comNet']], ['Frais opérateurs', $s['fees']], ['Remboursements', $s['ref']],
            ['Reversements', $s['rev']], ['Créances', $s['cre']], ['Dettes envers les ouvriers', $s['dette']], ['Marge contributive', FinanceInsights::can('marge') ? $s['marge'] : FinanceInsights::HIDDEN], ['Anomalies', $s['anom']],
        ]);
    }

    /**
     * Journal d’audit (CSV).
     */
    public function auditExport(): Response
    {
        $this->authorizeRight('export');
        $this->authorizeRight('audit');

        return FinanceReports::file('Journal d’audit Finance', 'CSV', [['Export', FinanceLedger::today()->format('d/m/Y')]], ['Date', 'Heure', 'Utilisateur', 'Action', 'Ancienne valeur', 'Nouvelle valeur', 'Périmètre et motif', 'Origine'],
            FinanceAudit::query()->latest('occurred_at')->latest('id')->get()->map(fn (FinanceAudit $e): array => [$e->occurred_at->format('d/m/Y'), $e->occurred_at->format('H:i'), $e->author, $e->action, $e->old_value, $e->new_value, $e->comment, $e->origin])->all());
    }
}
