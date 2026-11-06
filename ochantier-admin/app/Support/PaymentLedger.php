<?php

namespace App\Support;

use App\Enums\WorkerStatus;
use App\Models\Payment;
use App\Models\PaymentItem;
use App\Models\PaymentRefund;
use Illuminate\Support\Collection;

/**
 * Registre des opérations financières : calcul des montants (prestation, bonus, indemnités, retenues,
 * commission O’CHANTIER partagée Y/Z, frais, taxes, total promoteur, net ouvrier), statut de la commission,
 * anomalies (qui dépendent des autres opérations : doublons, références externes) et contrôles avant reversement.
 *
 * Les opérations sont chargées une fois ; les calculs sont mis en cache par opération.
 */
class PaymentLedger
{
    /**
     * Couleur des statuts du module.
     *
     * @var array<string, string>
     */
    public const TONES = [
        'Non calculé' => 'n', 'Calculé' => 'b', 'À vérifier' => 'o', 'En attente de validation' => 'a', 'Validé' => 'g', 'Correction demandée' => 'a', 'Corrigé' => 'p', 'Rejeté' => 'r', 'Annulé' => 'n',
        'Non initié' => 'n', 'En attente' => 'a', 'Initié' => 'b', 'En cours' => 'b', 'Réussi' => 'g', 'Échoué' => 'r', 'Suspendu' => 'r', 'Partiellement remboursé' => 'o', 'Remboursé' => 'o', 'Extourné' => 'r',
        'Non disponible' => 'n', 'À préparer' => 'a', 'Programmé' => 'b', 'À reprendre' => 'o', 'Estimée' => 'n', 'Calculée' => 'b', 'À encaisser' => 'a', 'Encaissée' => 'g', 'Partiellement encaissée' => 'o',
        'Suspendue' => 'r', 'Remboursée' => 'o', 'Partiellement remboursée' => 'o', 'Extournée' => 'r', 'Corrigée' => 'p', 'Annulée' => 'n', 'Non rapproché' => 'n', 'Correspondance trouvée' => 'b',
        'Rapproché automatiquement' => 'g', 'Écart détecté' => 'r', 'Rapproché manuellement' => 'g', 'Demandé' => 'a', 'Effectué' => 'g', 'Approuvée' => 'g', 'En attente d’approbation' => 'a',
        'Information' => 'b', 'Faible' => 'n', 'Modérée' => 'a', 'Élevée' => 'o', 'Critique' => 'r', 'Émis' => 'b', 'Terminé' => 'g', 'Partiellement terminé' => 'a', 'Rejetée' => 'r', 'Conforme' => 'g',
    ];

    public const LEVELS = ['Critique', 'Élevée', 'Modérée', 'Faible', 'Information'];

    public const RELATIONS = ['worker', 'site.promoter', 'items', 'refunds'];

    /** @var Collection<int, Payment> */
    private Collection $payments;

    /** @var array<int, array<string, mixed>> */
    private array $calcs = [];

    /** @var array<string, int> */
    private array $keys;

    /** @var array<string, int> */
    private array $externalRefs;

    /**
     * @param  Collection<int, Payment>|null  $payments
     */
    public function __construct(?Collection $payments = null)
    {
        $this->payments = $payments ?? Payment::with(self::RELATIONS)->orderBy('id')->get();
        $this->keys = $this->payments->reject(fn (Payment $p): bool => $p->calc_status === 'Annulé')->countBy(fn (Payment $p): string => $p->key())->all();
        $this->externalRefs = $this->payments->filter(fn (Payment $p): bool => (bool) $p->external_reference)->countBy('external_reference')->all();
    }

    /**
     * @return Collection<int, Payment>
     */
    public function all(): Collection
    {
        return $this->payments;
    }

    public function find(string $reference): ?Payment
    {
        return $this->payments->firstWhere('reference', $reference);
    }

    public static function tone(string $label): string
    {
        return self::TONES[$label] ?? 'n';
    }

    /**
     * @return array{label: string, tone: string}
     */
    public static function badge(string $label): array
    {
        return ['label' => $label, 'tone' => self::tone($label)];
    }

    public static function money(int|float $amount): string
    {
        return number_format((float) $amount, 0, ',', ' ').' FCFA';
    }

    /**
     * Règle de commission en vigueur (taux Y et Z de Paramètres).
     *
     * @return array<string, mixed>
     */
    public static function config(): array
    {
        $cfg = config('admin.payments');
        $y = (int) config('admin.need_rules.promoter_commission');
        $z = (int) config('admin.worker_commission_rate');

        return [...$cfg, 'y' => $y, 'z' => $z, 'ok' => $y > 0 || $z > 0];
    }

    /**
     * Règle de commission affichée dans Finance.
     *
     * @return array<string, mixed>
     */
    public static function rule(): array
    {
        $cfg = self::config();

        return [...$cfg['rule'], 'y' => $cfg['y'], 'z' => $cfg['z'], 'previous' => $cfg['previous_rules'], 'refundRule' => $cfg['refund_rule']];
    }

    /**
     * Montants d’une opération.
     *
     * @return array<string, mixed>
     */
    public function calc(Payment $p): array
    {
        return $this->calcs[$p->id] ??= self::calculate($p);
    }

    /**
     * Forget cached calculations (after an update).
     */
    public function refresh(Payment $p): void
    {
        unset($this->calcs[$p->id]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function calculate(Payment $p): array
    {
        $cfg = self::config();
        $sum = fn (string $kind): int => (int) $p->items->where('kind', $kind)->whereIn('status', ['Validé', 'Approuvée'])->sum('amount');
        $gross = $p->isDeposit() ? (int) $p->fixed_amount : $p->days * $p->daily_rate;
        $bonus = $sum('bonus');
        $indemnity = $sum('indemnity');
        $deduction = $sum('deduction');
        $y = $p->isDeposit() ? $cfg['y'] + $cfg['z'] : $cfg['y'];
        $z = $p->isDeposit() ? 0 : $cfg['z'];
        $comP = (int) round($gross * $y / 100);
        $comO = (int) round($gross * $z / 100);
        $raw = $comP + $comO;
        $com = $raw;
        if ($cfg['rule']['min'] && $com < $cfg['rule']['min']) {
            $com = $cfg['rule']['min'];
        }
        if ($cfg['rule']['max'] && $com > $cfg['rule']['max']) {
            $com = $cfg['rule']['max'];
        }
        if ($com !== $raw) {
            $comO = (int) round($com * ($raw ? $comO / $raw : 0));
            $comP = $com - $comO;
        }
        $fee = $cfg['fees'][$p->method] ?? [0, 0];
        $sub = $gross + $bonus + $indemnity + $comP;
        $fees = (int) round($sub * $fee[0] / 100) + $fee[1];
        $tax = (int) round($sub * $cfg['tax'] / 100);
        $promoterTotal = $sub + $fees + $tax;
        $net = $p->isDeposit() ? 0 : $gross + $bonus + $indemnity - $deduction - $comO;
        $paid = in_array($p->pay_status, Payment::PAID, true);
        $received = $paid ? ($p->received_amount ?? $promoterTotal) : 0;
        $done = $p->refunds->where('status', 'Effectué');
        $refunded = (int) $done->sum('amount');
        $comRefunded = min($com, (int) $done->sum(fn (PaymentRefund $r): int => $r->commission_impact ?? (int) round($com * $r->amount / max(1, $promoterTotal))));
        $comReversed = $p->pay_status === 'Extourné' ? $com - $comRefunded : 0;
        $comCollected = $paid ? $com : 0;
        $comNet = max(0, $comCollected - $comRefunded - $comReversed);
        $gap = $paid ? $received - $promoterTotal : 0;
        $check = $promoterTotal - ($net + $com + $fees + $tax + ($p->isDeposit() ? $gross + $bonus + $indemnity - $comO : $deduction));

        $comStatus = match (true) {
            $p->calc_status === 'Annulé' || $p->pay_status === 'Annulé' => 'Annulée',
            $p->pay_status === 'Suspendu' => 'Suspendue',
            $p->pay_status === 'Extourné' => 'Extournée',
            $paid && $com && $comRefunded >= $com => 'Remboursée',
            $paid && $comRefunded > 0 => 'Partiellement remboursée',
            $paid && $gap < 0 => 'Partiellement encaissée',
            $paid => 'Encaissée',
            in_array($p->calc_status, ['Validé', 'Corrigé'], true) => 'À encaisser',
            $p->calc_status === 'En attente de validation' => 'En attente de validation',
            in_array($p->calc_status, ['Calculé', 'À vérifier', 'Correction demandée'], true) => 'Calculée',
            default => 'Estimée',
        };

        return [
            'gross' => $gross, 'bonus' => $bonus, 'indemnity' => $indemnity, 'deduction' => $deduction, 'base' => $gross, 'y' => $y, 'z' => $z,
            'raw' => $raw, 'comP' => $comP, 'comO' => $comO, 'com' => $com, 'fees' => $fees, 'feeRate' => $fee, 'tax' => $tax, 'promoterTotal' => $promoterTotal,
            'net' => $net, 'paid' => $paid, 'received' => $received, 'refunded' => $refunded, 'comRefunded' => $comRefunded, 'comReversed' => $comReversed,
            'comCollected' => $comCollected, 'comNet' => $comNet, 'check' => $check, 'gap' => $gap, 'comStatus' => $comStatus, 'double' => $promoterTotal >= $cfg['double_validation'],
        ];
    }

    /**
     * Anomalies détectées (et signalées) sur une opération.
     *
     * @return array<int, array{type: string, level: string, detail: string}>
     */
    public function anomalies(Payment $p): array
    {
        $c = $this->calc($p);
        $out = [];
        $add = function (string $type, string $level, string $detail) use (&$out): void {
            $out[] = ['type' => $type, 'level' => $level, 'detail' => $detail];
        };
        if ($c['check']) {
            $add('Somme des composantes incorrecte', 'Critique', 'Écart de '.self::money($c['check']).' dans la ventilation.');
        }
        if (! self::config()['ok']) {
            $add('Règle de commission absente', 'Élevée', 'Aucun taux Y/Z actif dans Paramètres.');
        }
        if ($p->open_days && in_array($p->calc_status, ['Validé', 'Corrigé'], true)) {
            $add('Paiement sans pointage clôturé', 'Élevée', "{$p->open_days} journée(s) non clôturée(s).");
        }
        if ($p->external_reference && ($this->externalRefs[$p->external_reference] ?? 0) > 1) {
            $add('Référence externe dupliquée', 'Critique', "{$p->external_reference} utilisée par une autre opération.");
        }
        if (($this->keys[$p->key()] ?? 0) > 1 && $p->calc_status !== 'Annulé') {
            $add('Paiement en double', 'Critique', 'Même affectation, même période, même type.');
        }
        if ($c['gap'] < 0) {
            $add('Montant incohérent', 'Modérée', 'Reçu '.self::money($c['received']).' pour '.self::money($c['promoterTotal']).' attendus.');
        }
        if ($p->pay_status === 'Échoué' && $p->attempts >= 2) {
            $add('Échec répété', 'Modérée', "{$p->attempts} tentatives · ".($p->provider_message ?? ''));
        }
        if ($p->payout_status === 'Échoué') {
            $add('Coordonnées de paiement invalides', 'Élevée', $p->payout_message ?: 'Reversement refusé par le prestataire.');
        }
        if ($c['paid'] && $c['refunded'] > $c['received']) {
            $add('Remboursement supérieur au paiement', 'Critique', self::money($c['refunded']).' remboursés.');
        }
        $since = $p->validated_at ?? $p->period_end;
        if ($p->pay_status === 'En attente' && $since->diffInDays(Payment::referenceDate()) > 5) {
            $add('Opération bloquée trop longtemps', 'Faible', 'En attente depuis le '.$since->format('d/m/Y').'.');
        }
        foreach ($p->reported_anomalies as $a) {
            $out[] = $a;
        }

        return $out;
    }

    /**
     * Contrôles avant reversement à l’ouvrier.
     *
     * @return array<int, array{0: string, 1: bool}>
     */
    public function payoutChecks(Payment $p): array
    {
        $c = $this->calc($p);
        $status = $p->worker?->status;
        $sameDone = $this->payments->contains(fn (Payment $x): bool => $x->id !== $p->id && $x->key() === $p->key() && $x->payout_status === 'Réussi');

        return [
            ['Identité de l’ouvrier', $status === WorkerStatus::Validated],
            ['Compte actif', $status && ! in_array($status, [WorkerStatus::Suspended, WorkerStatus::Archived], true)],
            ['Moyen de reversement valide', (bool) $p->worker_method],
            ['Numéro confirmé', $p->payout_status !== 'Échoué'],
            ['Calcul validé', in_array($p->calc_status, ['Validé', 'Corrigé'], true)],
            ['Paiement promoteur confirmé', $c['paid'] && $p->pay_status !== 'Remboursé'],
            ['Absence de blocage', $p->pay_status !== 'Suspendu' && ! collect($this->anomalies($p))->contains('level', 'Critique')],
            ['Absence de reversement identique', $p->payout_status !== 'Réussi' && ! $sameDone],
        ];
    }

    /**
     * Parcours de validation (13 étapes).
     *
     * @return array<int, array{0: string, 1: bool, 2: string}>
     */
    public function checklist(Payment $p): array
    {
        $c = $this->calc($p);
        $blocking = collect($this->anomalies($p))->whereIn('level', ['Critique', 'Élevée']);
        $pendingDeduction = $p->items->contains(fn (PaymentItem $i): bool => $i->kind === 'deduction' && $i->status === 'En attente d’approbation');
        $cfg = self::config();

        return [
            ['Génération du calcul', true, $p->source],
            ['Vérification des pointages', ! $p->open_days && ($p->isDeposit() || $p->days > 0), $p->isDeposit() ? 'Échéancier du chantier' : "{$p->days} journée(s) clôturée(s)".($p->open_days ? " · {$p->open_days} non clôturée(s)" : '').($p->attendance_refs ? '' : ' · archive')],
            ['Vérification de l’affectation', $p->isDeposit() || (bool) $p->assignment_reference, $p->assignment_reference ?: "Chantier {$p->site->name}"],
            ['Vérification des tarifs', $p->isDeposit() || $p->daily_rate > 0, $p->isDeposit() ? self::money((int) $p->fixed_amount) : self::money($p->daily_rate).' / jour (affectation)'],
            ['Bonus et indemnités', true, self::money($c['bonus'] + $c['indemnity']).' validés'],
            ['Retenues', ! $pendingDeduction, self::money($c['deduction']).' approuvés'.($pendingDeduction ? ' · retenue en attente' : '')],
            ['Commission O’CHANTIER', $cfg['ok'], self::money($c['com']).' · '.$cfg['rule']['version']],
            ['Frais externes', true, self::money($c['fees']).' ('.($p->method ?? '—').')'],
            ['Montant promoteur', true, self::money($c['promoterTotal'])],
            ['Montant net ouvrier', $p->isDeposit() || $c['net'] > 0, $p->isDeposit() ? 'Acompte · aucun reversement' : self::money($c['net'])],
            ['Contrôle des anomalies', $blocking->isEmpty() && ! $c['check'], $blocking->isNotEmpty() ? $blocking->pluck('type')->implode(', ') : 'Aucune anomalie bloquante'],
            ['Validation administrative', in_array($p->calc_status, ['Validé', 'Corrigé'], true), $p->calc_status],
            ['Mise à disposition pour paiement', $p->calc_status === 'Validé', $p->pay_status],
        ];
    }

    /**
     * Impact d’un remboursement sur la commission selon sa règle.
     */
    public function refundImpact(Payment $p, PaymentRefund $r): int
    {
        $c = $this->calc($p);

        return match ($r->rule) {
            'Commission intégralement remboursée' => min($c['com'], $r->amount),
            'Commission non remboursable' => 0,
            default => (int) round($c['com'] * $r->amount / max(1, $c['promoterTotal'])),
        };
    }
}
