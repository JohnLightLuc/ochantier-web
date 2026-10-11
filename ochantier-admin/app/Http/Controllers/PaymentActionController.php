<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\PaymentActivity;
use App\Models\PaymentItem;
use App\Models\PaymentNotification;
use App\Models\PaymentRefund;
use App\Models\PayoutBatch;
use App\Support\PaymentLedger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Actions du Back-office sur les paiements : reversements aux ouvriers (individuels ou par lot), remboursements aux
 * promoteurs, bonus, indemnités et retenues, rapprochements manuels, signalements et documents.
 *
 * Les prélèvements promoteurs et l’encaissement des commissions sont automatiques ; les taux se modifient dans Paramètres.
 */
class PaymentActionController extends Controller
{
    public const REFUND_TYPES = ['Remboursement total', 'Remboursement partiel', 'Remboursement de la prestation', 'Remboursement de la commission', 'Remboursement des frais', 'Remboursement exceptionnel'];

    public const RESPONSIBILITIES = ['Ouvrier', 'Promoteur', 'O’CHANTIER', 'Prestataire de paiement'];

    public const RECONCILE_MOTIFS = ['Frais prélevés par l’opérateur', 'Écart d’arrondi', 'Paiement complété en espèces', 'Référence externe corrigée', 'Autre'];

    public const ANOMALY_TYPES = ['Commission incorrecte', 'Montant incohérent', 'Statut interne différent du prestataire', 'Paiement confirmé mais non reçu', 'Coordonnées de paiement invalides', 'Autre'];

    /**
     * @var array<string, array<int, string>>
     */
    public const ITEM_TYPES = [
        'bonus' => ['Prime de performance', 'Prime de ponctualité', 'Prime de chantier', 'Prime exceptionnelle', 'Autre bonus autorisé'],
        'indemnity' => ['Transport', 'Repas', 'Déplacement', 'Équipement', 'Autre indemnité'],
        'deduction' => ['Absence non justifiée', 'Avance antérieure', 'Correction financière', 'Autre retenue autorisée'],
    ];

    public const DOCUMENTS = [
        'recu-promoteur' => ['Reçu de paiement promoteur', 'REC'], 'recu-reversement' => ['Reçu de reversement ouvrier', 'RRV'], 'facture' => ['Facture de service', 'FAC'],
        'releve-commission' => ['Relevé de commission', 'RCO'], 'remboursement' => ['Justificatif de remboursement', 'RMB'], 'justificatif' => ['Justificatif d’opération', 'JOP'],
    ];

    private function user(): string
    {
        return auth()->user()?->name ?? 'Système';
    }

    private function notify(string $type, ?string $recipient, string $channel, string $content): void
    {
        PaymentNotification::create(['type' => $type, 'recipient' => $recipient ?? '—', 'channel' => $channel, 'sent_at' => now(), 'content' => $content, 'status' => 'Envoyée']);
    }

    /**
     * Exécute un reversement (contrôles déjà vérifiés).
     */
    private function executePayout(PaymentLedger $ledger, Payment $p, ?string $batch = null): void
    {
        $net = $ledger->calc($p)['net'];
        $p->update(['payout_status' => 'Réussi', 'payout_at' => Payment::referenceDate(), 'payout_reference' => 'REV-'.(610000 + crc32($p->reference.now()) % 99999), 'payout_message' => null, 'batch_reference' => $batch ?? $p->batch_reference]);
        $p->addDocument('RRV', 'Reçu de reversement');
        $p->logActivity('paiement', 'a effectué le reversement', 'Validé', 'Réussi', PaymentLedger::money($net)." · {$p->worker_method} · {$p->payout_reference}");
        $this->notify('Reversement effectué', $p->worker?->name, 'SMS', PaymentLedger::money($net)." versés sur {$p->worker_method}");
    }

    /**
     * Reversement individuel à l’ouvrier, après les contrôles.
     */
    public function payout(Payment $payment): RedirectResponse
    {
        $ledger = new PaymentLedger;
        $p = $ledger->find($payment->reference);
        abort_if($p->isDeposit(), 422, 'Acompte : aucun reversement individuel.');
        $blocking = collect($ledger->payoutChecks($p))->reject(fn (array $c): bool => $c[1]);
        abort_if($blocking->isNotEmpty(), 422, 'Reversement bloqué : '.mb_strtolower($blocking->pluck(0)->implode(', ')).'.');

        $this->executePayout($ledger, $p);
        Inertia::flash('toast', "Reversement effectué · {$p->payout_reference}.");

        return back();
    }

    /**
     * Lot de reversements : les opérations éligibles sont exécutées, les autres sont bloquées ; un compte invalide échoue.
     */
    public function batch(Request $request): RedirectResponse
    {
        $refs = $request->validate(['refs' => ['required', 'array', 'min:1'], 'refs.*' => ['string']])['refs'];
        $ledger = new PaymentLedger;
        $list = $ledger->all()->whereIn('reference', $refs)->reject(fn (Payment $p): bool => $p->isDeposit())->values();
        abort_if($list->isEmpty(), 422, 'Sélectionnez des opérations à reverser.');
        $eligible = $list->filter(fn (Payment $p): bool => collect($ledger->payoutChecks($p))->every(fn (array $c): bool => $c[1]))->values();
        abort_if($eligible->isEmpty(), 422, 'Aucune opération éligible : vérifiez les contrôles avant reversement.');
        $reference = 'LOT-2026-'.sprintf('%04d', PayoutBatch::query()->count() + 41);

        $results = DB::transaction(function () use ($eligible, $ledger, $reference): array {
            return $eligible->map(function (Payment $p) use ($ledger, $reference): array {
                if (strlen(preg_replace('/\D/', '', (string) $p->worker?->phone)) < 6) {
                    $p->update(['payout_status' => 'Échoué', 'payout_message' => 'Compte bénéficiaire invalide', 'batch_reference' => $reference]);
                    $p->logActivity('rejet', 'a signalé un échec de reversement', 'Validé', 'Échoué', $reference);

                    return ['id' => $p->id, 'result' => 'Échoué'];
                }
                $this->executePayout($ledger, $p, $reference);

                return ['id' => $p->id, 'result' => 'Réussi'];
            })->all();
        });
        $done = collect($results)->where('result', 'Réussi');
        $byId = $eligible->keyBy('id');
        PayoutBatch::create([
            'reference' => $reference, 'executed_at' => now(), 'author' => $this->user(), 'results' => $results,
            'blocked' => $list->diff($eligible)->pluck('id')->values()->all(),
            'net' => (int) $done->sum(fn (array $r): int => $ledger->calc($byId[$r['id']])['net']),
            'commission' => (int) $eligible->sum(fn (Payment $p): int => $ledger->calc($p)['com']),
            'status' => $done->count() === count($results) ? 'Terminé' : 'Partiellement terminé',
        ]);
        PaymentActivity::create(['occurred_at' => now(), 'author' => "{$this->user()} · Administrateur finance", 'category' => 'paiement', 'action' => 'a exécuté un lot de reversements', 'new_value' => $reference, 'comment' => count($results).' opération(s)', 'origin' => 'Back-office']);
        $failed = count($results) - $done->count();
        Inertia::flash('toast', "{$reference} · {$done->count()} réussi(s), {$failed} échoué(s), ".($list->count() - $eligible->count()).' bloqué(s).');

        return back();
    }

    /**
     * Relance : un reversement échoué passe « À reprendre » (coordonnées à vérifier) ; un paiement échoué est remis en attente.
     */
    public function retry(Payment $payment): RedirectResponse
    {
        if ($payment->payout_status === 'Échoué') {
            $payment->update(['payout_status' => 'À reprendre']);
            $payment->logActivity('paiement', 'a remis le reversement à reprendre', 'Échoué', 'À reprendre');
            Inertia::flash('toast', 'Reversement à reprendre · vérifiez les coordonnées.');

            return back();
        }
        abort_unless($payment->pay_status === 'Échoué', 422, 'Aucun échec à relancer.');
        $payment->update(['pay_status' => 'En attente']);
        $payment->logActivity('paiement', 'a relancé le paiement', 'Échoué', 'En attente', "Relance n° {$payment->attempts}");
        $this->notify('Paiement attendu', $payment->site->promoter_name, 'SMS', "Relance · {$payment->reference}");
        Inertia::flash('toast', 'Paiement relancé · nouvelle tentative possible.');

        return back();
    }

    /**
     * Demande de remboursement au promoteur (dans la limite du montant reçu non encore remboursé).
     */
    public function requestRefund(Request $request, Payment $payment): RedirectResponse
    {
        $ledger = new PaymentLedger;
        $p = $ledger->find($payment->reference);
        $c = $ledger->calc($p);
        abort_unless($c['paid'], 422, 'Remboursement impossible : aucun paiement reçu.');
        $left = $c['received'] - (int) $p->refunds->where('status', '!=', 'Rejeté')->sum('amount');
        abort_if($left <= 0, 422, 'Double remboursement bloqué : montant déjà intégralement remboursé.');
        $data = $request->validate([
            'type' => ['required', Rule::in(self::REFUND_TYPES)],
            'amount' => ['required_unless:type,Remboursement total', 'nullable', 'integer', 'min:1', "max:{$left}"],
            'motif' => ['required', 'string', 'min:3', 'max:255'],
            'responsibility' => ['required', Rule::in(self::RESPONSIBILITIES)],
            'proof' => ['nullable', 'string', 'max:120'],
        ], ['amount.*' => 'Montant invalide : maximum '.PaymentLedger::money($left).'.', 'motif.*' => 'Le motif est obligatoire.']);
        $amount = $data['type'] === 'Remboursement total' ? $left : (int) $data['amount'];

        $p->refunds()->create([
            'type' => $data['type'], 'amount' => $amount, 'motif' => $data['motif'], 'beneficiary' => $p->site->promoter_name, 'responsibility' => $data['responsibility'],
            'rule' => $data['type'] === 'Remboursement de la commission' ? 'Commission intégralement remboursée' : PaymentLedger::config()['refund_rule'],
            'proof' => $data['proof'] ?? null, 'author' => $this->user(), 'date' => Payment::referenceDate(), 'status' => 'Demandé',
            'timing' => $p->payout_status === 'Réussi' ? 'Après reversement' : 'Avant reversement',
        ]);
        $p->logActivity('paiement', 'a demandé un remboursement', null, PaymentLedger::money($amount), "{$data['type']} · {$data['motif']}");
        $this->notify('Remboursement demandé', 'A. Koné', 'Notification interne', "{$p->reference} · ".PaymentLedger::money($amount));
        Inertia::flash('toast', 'Remboursement demandé · approbation requise.');

        return back();
    }

    /**
     * Approuve (et exécute) ou rejette un remboursement ; la commission nette est recalculée selon la règle.
     */
    public function decideRefund(Request $request, Payment $payment, PaymentRefund $refund, string $decision): RedirectResponse
    {
        abort_unless($refund->status === 'Demandé', 422, 'Ce remboursement a déjà été traité.');
        $comment = trim($request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']], ['reason.*' => 'Le commentaire est obligatoire.'])['reason']);
        $ledger = new PaymentLedger;
        $p = $ledger->find($payment->reference);
        $c = $ledger->calc($p);

        if ($decision === 'rejet') {
            $refund->update(['status' => 'Rejeté', 'approver' => $this->user()]);
            $p->logActivity('rejet', 'a rejeté un remboursement', null, PaymentLedger::money($refund->amount), $comment);
            Inertia::flash('toast', 'Remboursement rejeté.');

            return back();
        }

        $impact = $ledger->refundImpact($p, $refund);
        $refund->update(['status' => 'Effectué', 'approver' => $this->user(), 'commission_impact' => $impact]);
        $total = (int) $p->refunds()->where('status', 'Effectué')->sum('amount');
        $status = $total >= $c['received'] ? 'Remboursé' : 'Partiellement remboursé';
        $p->update(['pay_status' => $status, 'payout_status' => $status === 'Remboursé' && ! $p->isDeposit() && $p->payout_status !== 'Réussi' ? 'Annulé' : $p->payout_status]);
        $p->addDocument('RMB', 'Note de remboursement');
        $p->logActivity('paiement', 'a exécuté un remboursement', PaymentLedger::money($c['comNet']).' (commission nette)', PaymentLedger::money(max(0, $c['comNet'] - $impact)), "{$refund->type} · ".PaymentLedger::money($refund->amount)." · {$comment}");
        $this->notify('Remboursement validé', $refund->beneficiary, 'E-mail', PaymentLedger::money($refund->amount)." · {$p->reference}");
        Inertia::flash('toast', 'Remboursement exécuté · commission nette recalculée.');

        return back();
    }

    /**
     * Ajoute un bonus, une indemnité (validés) ou une retenue (soumise à approbation) sur une opération non payée.
     */
    public function addItem(Request $request, Payment $payment, string $kind): RedirectResponse
    {
        $ledger = new PaymentLedger;
        abort_if($ledger->calc($ledger->find($payment->reference))['paid'], 422, 'Opération déjà payée : utilisez une demande de correction.');
        $deduction = $kind === 'deduction';
        $data = $request->validate([
            'type' => ['required', Rule::in(self::ITEM_TYPES[$kind])],
            'amount' => ['required', 'integer', 'min:1'],
            'motif' => ['required', 'string', 'min:3', 'max:255'],
            'base' => [$deduction ? 'required' : 'nullable', 'string', 'max:255'],
            'proof' => [$deduction ? 'required' : 'nullable', 'string', 'max:120'],
        ], ['amount.*' => 'Montant invalide.']);

        $payment->items()->create([...$data, 'kind' => $kind, 'author' => $this->user(), 'date' => Payment::referenceDate(), 'status' => $deduction ? 'En attente d’approbation' : 'Validé']);
        $label = ['bonus' => 'un bonus', 'indemnity' => 'une indemnité', 'deduction' => 'une retenue'][$kind];
        $payment->logActivity('modification', "a ajouté {$label}", null, PaymentLedger::money($data['amount']), "{$data['type']} · {$data['motif']}".($deduction ? '' : ' · calcul recalculé et revalidé automatiquement'));
        Inertia::flash('toast', ucfirst($label).' de '.PaymentLedger::money($data['amount']).' ajouté(e)'.($deduction ? ' · approbation requise.' : '.'));

        return back();
    }

    public function decideItem(Request $request, Payment $payment, PaymentItem $item, string $decision): RedirectResponse
    {
        abort_unless($item->status === 'En attente d’approbation', 422, 'Cette retenue a déjà été traitée.');
        $comment = trim($request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']], ['reason.*' => 'Le commentaire est obligatoire.'])['reason']);
        $status = $decision === 'approbation' ? 'Approuvée' : 'Rejetée';

        $item->update(['status' => $status, 'approver' => $this->user()]);
        $payment->logActivity('validation', $decision === 'approbation' ? 'a approuvé une retenue' : 'a rejeté une retenue', null, PaymentLedger::money($item->amount), $comment);
        Inertia::flash('toast', 'Retenue '.mb_strtolower($status).'.');

        return back();
    }

    public function reconcile(Request $request, Payment $payment): RedirectResponse
    {
        $ledger = new PaymentLedger;
        abort_unless($ledger->calc($ledger->find($payment->reference))['paid'], 422, 'Aucun encaissement à rapprocher.');
        $data = $request->validate([
            'motif' => ['required', Rule::in(self::RECONCILE_MOTIFS)],
            'comment' => ['required', 'string', 'min:3', 'max:500'],
            'proof' => ['required', 'string', 'max:120'],
        ], ['comment.*' => 'Le commentaire est obligatoire.', 'proof.*' => 'Le justificatif est obligatoire.']);
        $note = "{$data['motif']} · {$data['comment']} · {$data['proof']}";

        $old = $payment->reconciliation;
        $payment->update(['reconciliation' => 'Rapproché manuellement', 'reconciliation_note' => $note]);
        $payment->logActivity('validation', 'a rapproché manuellement', $old, 'Rapproché manuellement', $note);
        Inertia::flash('toast', 'Opération rapprochée manuellement.');

        return back();
    }

    public function reportAnomaly(Request $request, Payment $payment): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(self::ANOMALY_TYPES)],
            'level' => ['required', Rule::in(PaymentLedger::LEVELS)],
            'detail' => ['required', 'string', 'min:3', 'max:500'],
        ], ['detail.*' => 'La description est obligatoire.']);

        $payment->update(['reported_anomalies' => [...$payment->reported_anomalies, $data]]);
        $payment->logActivity('litige', 'a signalé une anomalie', null, $data['type'], $data['detail']);
        Inertia::flash('toast', 'Anomalie signalée.');

        return back();
    }

    /**
     * Document de l’opération (HTML imprimable, sans donnée bancaire complète).
     */
    public function document(Payment $payment, string $document): Response
    {
        [$type] = self::DOCUMENTS[$document];
        $ledger = new PaymentLedger;
        $p = $ledger->find($payment->reference);
        $html = view('exports.payment-document', ['type' => $type, 'payment' => $p, 'calc' => $ledger->calc($p), 'author' => $this->user(), 'generatedAt' => now()->format('d/m/Y H:i')])->render();
        $p->logActivity('document', 'a généré un document', null, $type);

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="'.str($type)->slug().'-'.$p->reference.'.html"']);
    }
}
