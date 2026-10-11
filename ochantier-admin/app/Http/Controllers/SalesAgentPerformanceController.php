<?php

namespace App\Http\Controllers;

use App\Enums\SalesAgentActivityCategory;
use App\Models\SalesAgent;
use App\Models\SalesAgentObjective;
use App\Support\SalesAgentInsights;
use App\Support\SalesPerformance;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Objectifs, appréciations, correction du score, bonus, retenues et statut des gains.
 */
class SalesAgentPerformanceController extends Controller
{
    public const BONUS_TYPES = ['Bonus Commercial du mois', 'Bonus d’objectif', 'Prime exceptionnelle'];

    public const DEDUCTION_TYPES = ['Profils dupliqués', 'Profil frauduleux', 'Avance sur commission', 'Autre'];

    public const OBJECTIVE_TYPES = ['Hebdomadaire', 'Mensuel', 'Trimestriel'];

    /**
     * Transitions de statut d’une ligne de gain : action → [statuts de départ, statut d’arrivée, libellé du journal].
     */
    private const PAYOUT_ACTIONS = [
        'valider' => [['En calcul', 'En attente de validation'], 'Validé', 'a validé un gain'],
        'suspendre' => [['Validé', 'Programmé', 'En attente de validation', 'En calcul'], 'Suspendu', 'a suspendu un paiement'],
        'reprendre' => [['Suspendu'], 'En attente de validation', 'a repris un paiement'],
    ];

    public function storeObjective(Request $request, SalesAgent $salesAgent): RedirectResponse
    {
        abort_unless($salesAgent->status->wasActivated() && ! $salesAgent->isArchived(), 422, 'Objectif impossible pour ce compte.');

        $data = $request->validate([
            'type' => ['required', Rule::in(self::OBJECTIVE_TYPES)],
            'label' => ['required', 'string', 'max:120'],
            'starts_on' => ['required', 'date', 'after_or_equal:today'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'target' => ['required', 'integer', 'min:1', 'max:5000'],
        ], [
            '*.required' => 'Ce champ est obligatoire.',
            'starts_on.after_or_equal' => 'Seuls les objectifs futurs peuvent être créés.',
            'ends_on.after_or_equal' => 'La fin doit suivre le début.',
            'target.*' => 'Nombre entier positif.',
        ]);

        $salesAgent->objectives()->create([...$data, 'label' => trim($data['label']), 'closed' => false]);
        $salesAgent->logActivity(SalesAgentActivityCategory::Objective, 'a attribué un objectif', null, "{$data['target']} profils", trim($data['label']));
        Inertia::flash('toast', 'Objectif enregistré.');

        return back();
    }

    /**
     * Seule la cible d’un objectif futur et non clôturé est modifiable.
     */
    public function updateObjective(Request $request, SalesAgent $salesAgent, SalesAgentObjective $objective): RedirectResponse
    {
        abort_if($objective->closed || $objective->starts_on->format('Y-m-d') <= SalesPerformance::referenceDate()->format('Y-m-d'), 422, 'Seul un objectif futur peut être modifié.');

        $target = (int) $request->validate(['target' => ['required', 'integer', 'min:1', 'max:5000']], ['target.*' => 'Nombre entier positif.'])['target'];
        $old = $objective->target;
        $objective->update(['target' => $target]);
        $salesAgent->logActivity(SalesAgentActivityCategory::Objective, 'a modifié un objectif', "{$old} profils", "{$target} profils", $objective->label);
        Inertia::flash('toast', 'Objectif enregistré.');

        return back();
    }

    public function closeObjective(SalesAgent $salesAgent, SalesAgentObjective $objective, SalesPerformance $performance): RedirectResponse
    {
        abort_if($objective->closed, 422, 'Cet objectif est déjà clôturé.');

        $line = collect((new SalesAgentInsights($salesAgent, $performance))->objectives())->firstWhere('id', $objective->id);
        $objective->update(['closed' => true]);
        $salesAgent->logActivity(SalesAgentActivityCategory::Objective, 'a clôturé un objectif', 'En cours', $line['progress'] >= 100 ? 'Atteint' : 'Non atteint', "{$objective->label} · {$line['progress']} %");
        Inertia::flash('toast', 'Période clôturée.');

        return back();
    }

    public function storeAppreciation(Request $request, SalesAgent $salesAgent): RedirectResponse
    {
        $text = trim($request->validate(['text' => ['required', 'string', 'min:10', 'max:1000']], ['text.*' => 'Ce champ est obligatoire (10 caractères minimum).'])['text']);

        $salesAgent->appreciations()->create(['author' => $request->user()?->name ?? 'Système', 'text' => $text]);
        $salesAgent->logActivity(SalesAgentActivityCategory::Objective, 'a ajouté une appréciation', null, null, $text);
        Inertia::flash('toast', 'Appréciation enregistrée.');

        return back();
    }

    /**
     * Correction administrative du nombre de profils validés retenus pour un mois. Un mois clôturé exige une autorisation.
     */
    public function correctScore(Request $request, SalesAgent $salesAgent, string $month, SalesPerformance $performance): RedirectResponse
    {
        abort_unless(in_array($month, SalesPerformance::months(), true) && $performance->row($salesAgent, $month), 404);

        $closed = $performance->isClosed($month);
        $data = $request->validate([
            'delta' => ['required', 'regex:/^[-+]?\d{1,3}$/'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'authorization' => [$closed ? 'required' : 'nullable', 'string', 'max:160'],
        ], ['*.required' => 'Ce champ est obligatoire.', 'delta.regex' => 'Nombre entier.', 'reason.min' => 'Justification trop courte (10 caractères minimum).']);

        $delta = (int) $data['delta'];
        $adjustments = $salesAgent->score_adjustments ?? [];
        $adjustments[$month] = ($adjustments[$month] ?? 0) + $delta;
        $salesAgent->update(['score_adjustments' => $adjustments]);
        $salesAgent->logActivity(SalesAgentActivityCategory::Score, 'a corrigé le score', null, "{$data['delta']} profil(s) validé(s)", SalesPerformance::monthLabel($month).' · '.trim($data['reason']).(! empty($data['authorization']) ? " · autorisation : {$data['authorization']}" : ''));
        Inertia::flash('toast', 'Correction enregistrée · score recalculé.');

        return back();
    }

    /**
     * Bonus en attente de validation. Le bonus Commercial du mois d’un mois clôturé exige une autorisation et crée une récompense.
     */
    public function storeBonus(Request $request, SalesAgent $salesAgent, SalesPerformance $performance): RedirectResponse
    {
        abort_if($salesAgent->isArchived(), 403);

        $month = in_array($request->input('month'), SalesPerformance::months(), true) ? $request->input('month') : null;
        $closed = $month && $performance->isClosed($month);
        $this->normalizeAmount($request);
        $data = $request->validate([
            'type' => ['required', Rule::in(self::BONUS_TYPES)],
            'amount' => ['required', 'integer', 'min:1', 'max:10000000'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'authorization' => [$closed ? 'required' : 'nullable', 'string', 'max:160'],
        ], ['*.required' => 'Ce champ est obligatoire.', 'amount.*' => 'Montant entier en FCFA.', 'reason.min' => 'Motif trop court (5 caractères minimum).']);

        DB::transaction(function () use ($salesAgent, $data, $month): void {
            $salesAgent->adjustments()->create(['granted_on' => today(), 'type' => $data['type'], 'amount' => (int) $data['amount'], 'reason' => trim($data['reason']), 'status' => 'En attente de validation']);
            if ($data['type'] === 'Bonus Commercial du mois' && $month) {
                $salesAgent->rewards()->create(['month' => $month, 'label' => 'Commercial du mois · '.SalesPerformance::monthLabel($month), 'amount' => (int) $data['amount']]);
            }
            $salesAgent->logActivity(SalesAgentActivityCategory::Bonus, 'a attribué un bonus', null, number_format((int) $data['amount'], 0, ',', ' ').' FCFA', "{$data['type']} · ".trim($data['reason']).(! empty($data['authorization']) ? " · autorisation : {$data['authorization']}" : ''));
        });
        Inertia::flash('toast', 'Bonus attribué · en attente de validation.');

        return back();
    }

    public function storeDeduction(Request $request, SalesAgent $salesAgent): RedirectResponse
    {
        abort_if($salesAgent->isArchived(), 403);

        $this->normalizeAmount($request);
        $data = $request->validate([
            'type' => ['required', Rule::in(self::DEDUCTION_TYPES)],
            'amount' => ['required', 'integer', 'min:1', 'max:10000000'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ], ['*.required' => 'Ce champ est obligatoire.', 'amount.*' => 'Montant entier en FCFA.', 'reason.min' => 'Justification trop courte (10 caractères minimum).']);

        $salesAgent->adjustments()->create(['granted_on' => today(), 'type' => "Retenue · {$data['type']}", 'amount' => -(int) $data['amount'], 'reason' => trim($data['reason']), 'status' => 'En attente de validation']);
        $salesAgent->logActivity(SalesAgentActivityCategory::Deduction, 'a ajouté une retenue', null, '− '.number_format((int) $data['amount'], 0, ',', ' ').' FCFA', "{$data['type']} · ".trim($data['reason']));
        Inertia::flash('toast', 'Retenue enregistrée.');

        return back();
    }

    /**
     * Retire les espaces des montants saisis (« 100 000 »).
     */
    private function normalizeAmount(Request $request): void
    {
        if (is_string($request->input('amount'))) {
            $request->merge(['amount' => str_replace([' ', ' ', ' '], '', $request->input('amount'))]);
        }
    }

    /**
     * Valide, suspend ou reprend une ligne de gain (gain mensuel ou bonus / retenue).
     */
    public function updatePayout(Request $request, SalesAgent $salesAgent, string $ref, string $action, SalesPerformance $performance): RedirectResponse
    {
        [$from, $to, $log] = self::PAYOUT_ACTIONS[$action];
        $line = collect((new SalesAgentInsights($salesAgent->load('adjustments'), $performance))->payouts())->firstWhere('ref', $ref);
        abort_unless($line, 404);
        abort_unless(in_array($line['status']['label'], $from, true), 422, 'Action impossible pour ce statut.');

        $comment = null;
        if ($action === 'suspendre') {
            $comment = trim($request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']], ['reason.*' => 'Ce champ est obligatoire (5 caractères minimum).'])['reason']);
        }

        if ($line['adjustmentId']) {
            $salesAgent->adjustments()->whereKey($line['adjustmentId'])->update(['status' => $to]);
        } else {
            $salesAgent->update(['payout_statuses' => [...($salesAgent->payout_statuses ?? []), $ref => $to]]);
        }
        $salesAgent->logActivity(SalesAgentActivityCategory::Payment, $log, $line['status']['label'], $to, "{$ref} · ".number_format($line['net'], 0, ',', ' ').' FCFA'.($comment ? " · {$comment}" : ''));
        Inertia::flash('toast', 'Gain '.mb_strtolower($to).'.');

        return back();
    }

    /**
     * Relevé CSV des gains et paiements.
     */
    public function statement(SalesAgent $salesAgent, SalesPerformance $performance): StreamedResponse
    {
        $lines = (new SalesAgentInsights($salesAgent->load('adjustments'), $performance))->payouts();

        return response()->streamDownload(function () use ($lines): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\u{FEFF}");
            fputcsv($out, ['Référence', 'Période', 'Type', 'Profils', 'Base', 'Commissions', 'Bonus', 'Retenues', 'Net', 'Statut'], ';');
            foreach ($lines as $l) {
                fputcsv($out, [$l['ref'], $l['period'], $l['type'], $l['profiles'], $l['fixed'], $l['commissions'], $l['bonus'], $l['deduction'], $l['net'], $l['status']['label']], ';');
            }
            fclose($out);
        }, "releve-{$salesAgent->slug}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Justificatif texte d’une ligne de gain.
     */
    public function receipt(SalesAgent $salesAgent, string $ref, SalesPerformance $performance): HttpResponse
    {
        $line = collect((new SalesAgentInsights($salesAgent->load('adjustments'), $performance))->payouts())->firstWhere('ref', $ref);
        abort_unless($line, 404);

        $content = "O’CHANTIER — Justificatif de gain\n{$ref}\n{$salesAgent->name} ({$salesAgent->code})\nPériode : {$line['period']}\nNet : ".number_format($line['net'], 0, ',', ' ')." FCFA\nStatut : {$line['status']['label']}\n";

        return response($content, 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"justificatif-{$ref}.txt\""]);
    }

    /**
     * Rapport de performance sur les six derniers mois.
     */
    public function report(SalesAgent $salesAgent, SalesPerformance $performance): HttpResponse
    {
        $history = (new SalesAgentInsights($salesAgent, $performance))->history();
        $lines = array_map(fn (array $h): string => $h['label'].' : '.($h['summary'] ? "score {$h['summary']['score']}, rang {$h['summary']['rank']}, {$h['summary']['metrics']['validated']} validés" : 'non classé'), $history);
        $content = "{$salesAgent->name} · rapport de performance\n".implode("\n", $lines)."\n";

        return response($content, 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"rapport-{$salesAgent->slug}.txt\""]);
    }
}
