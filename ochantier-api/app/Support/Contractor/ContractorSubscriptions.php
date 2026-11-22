<?php

namespace App\Support\Contractor;

use App\Enums\BillingPeriod;
use App\Enums\PaymentTransactionPurpose;
use App\Enums\PaymentTransactionStatus;
use App\Enums\SubscriptionInvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Contractor;
use App\Models\PaymentOperator;
use App\Models\PaymentTransaction;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\Documents\SimplePdf;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Abonnement de l’entrepreneur (« Abonnement et factures ») : formule Solo gratuite, Essentiel ou Pro payée chaque
 * mois par Mobile Money.
 *
 * L’abonnement donne accès à l’équipe d’ouvriers (affectation, recherche d’effectifs, remplacement, dans la limite
 * de la formule par chantier), au pointage, au rapport de fin de chantier et aux collaborateurs du compte. À
 * l’échéance, une facture de renouvellement est émise ; impayée après GRACE_DAYS jours, l’abonnement expire. Une
 * résiliation garde l’accès jusqu’à la fin de la période. Les données restent consultables après l’expiration.
 */
class ContractorSubscriptions
{
    public const FREE_PLAN = 'entrepreneur_solo';

    /**
     * Délai de paiement d’une facture de renouvellement avant l’expiration (jours).
     */
    public const GRACE_DAYS = 7;

    /**
     * Abonnements qui donnent accès aux fonctions payantes.
     */
    private const LIVE = [SubscriptionStatus::Active, SubscriptionStatus::EnRetard];

    public function __construct(private ContractorPayments $payments, private ContractorNotifier $notifier) {}

    public function current(Contractor $contractor): ?Subscription
    {
        return Subscription::query()->with('plan')->where('contractor_id', $contractor->getKey())
            ->whereIn('status', array_map(fn (SubscriptionStatus $status) => $status->value, self::LIVE))
            ->latest('started_at')->latest()->first();
    }

    /**
     * Abonnement en cours de chaque entrepreneur, en une requête (listes du Back Office). Absent = formule Solo.
     *
     * @param  list<string>  $contractorIds
     * @return Collection<string, Subscription>
     */
    public function currentFor(array $contractorIds): Collection
    {
        return Subscription::query()->with('plan')->whereIn('contractor_id', $contractorIds)
            ->whereIn('status', array_map(fn (SubscriptionStatus $status) => $status->value, self::LIVE))
            ->orderByDesc('started_at')->latest()->get()
            ->unique('contractor_id')->keyBy('contractor_id');
    }

    public function isSubscribed(Contractor $contractor): bool
    {
        return $this->current($contractor) !== null;
    }

    /**
     * Places de la formule : utilisateurs du compte et ouvriers actifs par chantier (1 sans abonnement).
     */
    public function seats(Contractor $contractor): int
    {
        return $this->current($contractor)?->plan->max_users ?? 1;
    }

    /**
     * Refuse une fonction réservée aux abonnés.
     */
    public function ensureSubscribed(Contractor $contractor, string $feature): void
    {
        if (! $this->isSubscribed($contractor)) {
            throw new AccessDeniedHttpException($feature.' : fonction incluse dans l’abonnement Essentiel ou Pro. Vos données restent consultables.');
        }
    }

    /**
     * Écran « Abonnement » : formule en cours, formules proposées, facturation, factures et actions.
     *
     * @return array<string, mixed>
     */
    public function summary(Contractor $contractor): array
    {
        $current = $this->current($contractor);
        $last = Subscription::query()->with('plan')->where('contractor_id', $contractor->getKey())->latest('started_at')->latest()->first();
        $expired = ! $current && $last && $last->status === SubscriptionStatus::Expiree;
        $plan = $current?->plan;
        $left = $current ? max(0, (int) CarbonImmutable::today()->diffInDays($current->current_period_end, false)) : 0;
        $invoices = SubscriptionInvoice::query()->whereHas('subscription', fn ($query) => $query->where('contractor_id', $contractor->getKey()))->latest('period_start')->latest()->get();
        $open = $current ? $this->openInvoice($current) : null;

        return [
            'current' => [
                'plan' => $plan ? $this->plan($plan) : $this->plan($this->freePlan()),
                'status' => match (true) {
                    $current && ! $current->auto_renew => ['value' => 'resilie', 'label' => 'Résilié'],
                    $current?->status === SubscriptionStatus::EnRetard => ['value' => 'en_retard', 'label' => 'Paiement en attente'],
                    $current !== null => ['value' => 'actif', 'label' => 'Actif'],
                    $expired => ['value' => 'expire', 'label' => 'Expiré'],
                    default => ['value' => 'gratuit', 'label' => 'Gratuit'],
                },
                'subscribed' => $current !== null,
                'line' => match (true) {
                    $current !== null => number_format($plan->price_amount, 0, ',', "\u{00A0}")."\u{00A0}FCFA / mois · ".($current->auto_renew ? 'renouvelé le ' : 'se termine le ').$current->current_period_end->format('d/m/Y'),
                    $expired => $last->plan->name.' expiré le '.$last->current_period_end?->format('d/m/Y').' · retour à Solo',
                    default => 'Gratuit · 1 utilisateur',
                },
                'period_end' => $current?->current_period_end?->toDateString(),
                'days_left' => $left,
                'auto_renew' => $current?->auto_renew,
                'seats' => $this->seats($contractor),
            ],
            'plans' => SubscriptionPlan::query()->where('audience', 'contractor')->where('is_active', true)->orderBy('sort_order')->get()
                ->map(fn (SubscriptionPlan $item) => [...$this->plan($item), 'current' => ($plan?->code ?? self::FREE_PLAN) === $item->code])->values()->all(),
            'open_invoice' => $open ? $this->invoice($open) : null,
            'operators' => $this->payments->operators(),
            'invoices' => $invoices->map(fn (SubscriptionInvoice $invoice) => $this->invoice($invoice))->values()->all(),
            'actions' => [
                'cancel' => $current !== null && $current->auto_renew,
                'reactivate' => $current !== null && ! $current->auto_renew,
            ],
            'notice' => 'Sans engagement · résiliable à tout moment. En production, seule la confirmation du paiement Mobile Money active les droits.',
        ];
    }

    /**
     * Détail du paiement : nouvelle formule (un mois) ou facture de renouvellement en attente.
     *
     * @return array<string, mixed>
     */
    public function quote(Contractor $contractor, string $planCode, PaymentOperator $operator): array
    {
        [$plan, $amount] = $this->target($contractor, $planCode);

        return $this->payments->quote([['label' => 'Abonnement '.$plan->name.' — 1 mois', 'amount' => $amount]], $amount, $operator);
    }

    /**
     * « Souscrire » ou « Passer à … » : une nouvelle période d’un mois commence au paiement ; la facture en attente
     * de la même formule est réglée.
     */
    public function subscribe(Contractor $contractor, User $user, string $planCode, PaymentOperator $operator, PhoneNumber $phone, string $idempotencyKey): PaymentTransaction
    {
        if ($existing = PaymentTransaction::query()->where('idempotency_key', $idempotencyKey)->first()) {
            return $existing;
        }

        [$plan, $amount] = $this->target($contractor, $planCode);
        $current = $this->current($contractor);
        $pending = Subscription::query()->where('contractor_id', $contractor->getKey())->where('plan_id', $plan->getKey())->where('status', SubscriptionStatus::Suspendue->value)->latest()->first();
        $open = match (true) {
            $current && $current->plan_id === $plan->getKey() => $this->openInvoice($current),
            $pending !== null => $this->openInvoice($pending),
            default => null,
        };

        $invoice = DB::transaction(function () use ($contractor, $plan, $amount, $current, $pending, $open): SubscriptionInvoice {
            if ($open) {
                return $open;
            }

            $subscription = $current && $current->plan_id === $plan->getKey() ? $current : $pending ?? Subscription::query()->create([
                'reference' => sprintf('ABO-%s-%06d', now()->format('Y'), DB::scalar("SELECT nextval('subscription_reference_seq')")),
                'contractor_id' => $contractor->getKey(),
                'plan_id' => $plan->getKey(),
                'previous_plan_id' => $current?->plan_id,
                'status' => SubscriptionStatus::Suspendue,
                'started_at' => today()->toDateString(),
                'auto_renew' => true,
            ]);
            $start = $subscription->is($current) ? CarbonImmutable::parse($current->current_period_end)->addDay() : CarbonImmutable::today();

            return $subscription->invoices()->create([
                'reference' => sprintf('FAB-%s-%06d', now()->format('Y'), DB::scalar("SELECT nextval('subscription_invoice_reference_seq')")),
                'plan_id' => $plan->getKey(),
                'label' => 'Abonnement '.$plan->name.' — 1 mois',
                'period_start' => $start->toDateString(),
                'period_end' => $start->addMonth()->subDay()->toDateString(),
                'amount' => $amount,
                'due_date' => today()->toDateString(),
                'status' => SubscriptionInvoiceStatus::APayer,
            ]);
        });

        $transaction = $this->payments->pay($contractor, $user, $invoice->label, [['label' => $invoice->label, 'amount' => $amount]], $amount, $operator, $phone, $idempotencyKey,
            ['subscription_invoice_id' => $invoice->id, 'kind' => 'abonnement'],
            fn (PaymentTransaction $transaction) => $this->settle($invoice, $current),
            PaymentTransactionPurpose::Abonnement, ['subscription_invoice_id' => $invoice->getKey()]);

        if ($transaction->status === PaymentTransactionStatus::Reussie) {
            $this->notifier->notify($contractor, 'subscription.paid', 'Abonnement '.$plan->name.' activé',
                'Équipe, pointage et rapport de fin de chantier sont déverrouillés jusqu’au '.$invoice->refresh()->period_end->format('d/m/Y').'.', ['type' => 'subscription', 'id' => $invoice->subscription_id], $invoice);
        }

        return $transaction;
    }

    /**
     * « Résilier » : l’accès reste ouvert jusqu’à la fin de la période, sans renouvellement.
     */
    public function cancel(Contractor $contractor, ?string $reason = null): Subscription
    {
        $current = $this->current($contractor) ?? throw ValidationException::withMessages(['subscription' => 'Vous n’avez pas d’abonnement en cours.']);

        if (! $current->auto_renew) {
            throw ValidationException::withMessages(['subscription' => 'Cet abonnement est déjà résilié.']);
        }

        $current->update(['auto_renew' => false, 'cancelled_at' => now(), 'cancellation_reason' => $reason]);

        return $current;
    }

    public function reactivate(Contractor $contractor): Subscription
    {
        $current = $this->current($contractor) ?? throw ValidationException::withMessages(['subscription' => 'Vous n’avez pas d’abonnement en cours.']);
        $current->update(['auto_renew' => true, 'cancelled_at' => null, 'cancellation_reason' => null]);

        return $current;
    }

    /**
     * Fin de période des abonnements des entrepreneurs : résilié, il se termine ; sinon une facture de
     * renouvellement est émise (paiement en attente), puis il expire après GRACE_DAYS jours sans paiement.
     *
     * @return array{renewal_invoiced: int, ended: int, expired: int}
     */
    public function renew(?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $counts = ['renewal_invoiced' => 0, 'ended' => 0, 'expired' => 0];

        Subscription::query()->with(['plan', 'contractor.user'])->whereNotNull('contractor_id')
            ->whereIn('status', [SubscriptionStatus::Active->value, SubscriptionStatus::EnRetard->value])
            ->whereDate('current_period_end', '<', $today)
            ->each(function (Subscription $subscription) use ($today, &$counts): void {
                if (! $subscription->auto_renew) {
                    $subscription->update(['status' => SubscriptionStatus::Resiliee]);
                    $counts['ended']++;

                    return;
                }

                $invoice = $this->openInvoice($subscription);

                if (! $invoice) {
                    $start = CarbonImmutable::parse($subscription->current_period_end)->addDay();
                    $invoice = $subscription->invoices()->create([
                        'reference' => sprintf('FAB-%s-%06d', now()->format('Y'), DB::scalar("SELECT nextval('subscription_invoice_reference_seq')")),
                        'plan_id' => $subscription->plan_id,
                        'label' => 'Abonnement '.$subscription->plan->name.' — 1 mois',
                        'period_start' => $start->toDateString(),
                        'period_end' => $start->addMonth()->subDay()->toDateString(),
                        'amount' => $subscription->plan->price_amount,
                        'due_date' => $today->toDateString(),
                        'status' => SubscriptionInvoiceStatus::EnRetard,
                    ]);
                    $subscription->update(['status' => SubscriptionStatus::EnRetard, 'next_due_date' => $today->toDateString()]);
                    $this->notifier->notify($subscription->contractor, 'subscription.renewal', 'Renouvelez votre abonnement',
                        'Votre abonnement '.$subscription->plan->name.' est arrivé à échéance. Réglez la facture sous '.self::GRACE_DAYS.' jours pour garder l’accès.', ['type' => 'subscription', 'id' => $subscription->id], $invoice);
                    $counts['renewal_invoiced']++;
                }

                if ($invoice->due_date->copy()->addDays(self::GRACE_DAYS)->lessThan($today)) {
                    $invoice->update(['status' => SubscriptionInvoiceStatus::Annulee]);
                    $subscription->update(['status' => SubscriptionStatus::Expiree]);
                    $this->notifier->notify($subscription->contractor, 'subscription.expired', 'Abonnement expiré',
                        'Vos données restent consultables. Renouvelez pour reprendre les saisies.', ['type' => 'subscription', 'id' => $subscription->id], $subscription);
                    $counts['expired']++;
                }
            });

        return $counts;
    }

    public function findInvoice(Contractor $contractor, string $id): SubscriptionInvoice
    {
        $invoice = str($id)->isUuid()
            ? SubscriptionInvoice::query()->with(['subscription.plan', 'plan'])->whereKey($id)->whereHas('subscription', fn ($query) => $query->where('contractor_id', $contractor->getKey()))->first()
            : null;

        abort_unless($invoice, 404, 'Facture introuvable.');

        return $invoice;
    }

    /**
     * Facture en PDF.
     */
    public function invoicePdf(Contractor $contractor, SubscriptionInvoice $invoice): string
    {
        return (new SimplePdf)
            ->title('Facture '.$invoice->reference)
            ->text('O’CHANTIER · émise le '.$invoice->created_at?->format('d/m/Y'), 10)
            ->heading('Client')
            ->row('Entrepreneur', $contractor->name.' ('.$contractor->reference.')')
            ->heading('Détail')
            ->row($invoice->label, number_format($invoice->amount, 0, ',', "\u{00A0}")."\u{00A0}FCFA")
            ->row('Période', $invoice->period_start->format('d/m/Y').' → '.$invoice->period_end->format('d/m/Y'))
            ->row('Statut', $invoice->status->label(), true)
            ->text($invoice->paid_at ? 'Payée le '.$invoice->paid_at->format('d/m/Y').'.' : 'À régler avant le '.$invoice->due_date->format('d/m/Y').'.')
            ->render();
    }

    /**
     * Paiement réussi : la facture est payée et la période commence (ou se prolonge pour un renouvellement).
     */
    private function settle(SubscriptionInvoice $invoice, ?Subscription $previous): void
    {
        $subscription = $invoice->subscription;
        $invoice->update(['status' => SubscriptionInvoiceStatus::Payee, 'paid_at' => now()]);

        if ($previous && ! $previous->is($subscription)) {
            $previous->update(['status' => SubscriptionStatus::Resiliee, 'auto_renew' => false, 'cancelled_at' => now(), 'cancellation_reason' => 'Changement de formule']);
        }

        $subscription->update([
            'status' => SubscriptionStatus::Active,
            'current_period_start' => $subscription->current_period_start && $subscription->is($previous) ? $subscription->current_period_start : $invoice->period_start,
            'current_period_end' => $invoice->period_end,
            'next_due_date' => $invoice->period_end->copy()->addDay()->toDateString(),
            'auto_renew' => true,
        ]);
    }

    /**
     * Formule visée et montant à payer.
     *
     * @return array{0: SubscriptionPlan, 1: int}
     */
    private function target(Contractor $contractor, string $code): array
    {
        $plan = SubscriptionPlan::query()->where('audience', 'contractor')->where('code', $code)->where('is_active', true)->first();

        if (! $plan || $plan->code === self::FREE_PLAN) {
            throw ValidationException::withMessages(['plan_code' => $plan ? 'La formule Solo est gratuite : résiliez votre abonnement pour y revenir.' : 'Formule inconnue.']);
        }

        $current = $this->current($contractor);

        if ($current && $current->plan_id === $plan->getKey() && ! $this->openInvoice($current)) {
            throw ValidationException::withMessages(['plan_code' => 'Vous avez déjà la formule '.$plan->name.'.']);
        }

        return [$plan, (int) $plan->price_amount];
    }

    private function openInvoice(Subscription $subscription): ?SubscriptionInvoice
    {
        return $subscription->invoices()->whereIn('status', [SubscriptionInvoiceStatus::APayer->value, SubscriptionInvoiceStatus::EnRetard->value])->latest('period_start')->first();
    }

    private function freePlan(): SubscriptionPlan
    {
        return SubscriptionPlan::query()->where('code', self::FREE_PLAN)->first() ?? new SubscriptionPlan(['code' => self::FREE_PLAN, 'name' => 'Solo', 'price_amount' => 0, 'max_users' => 1, 'features' => [], 'billing_period' => BillingPeriod::Mois]);
    }

    /**
     * @return array<string, mixed>
     */
    private function plan(SubscriptionPlan $plan): array
    {
        return [
            'code' => $plan->code,
            'name' => $plan->name,
            'subtitle' => $plan->subtitle,
            'price_amount' => (int) $plan->price_amount,
            'period' => $plan->price_amount > 0 ? 'par mois' : 'pour toujours',
            'seats' => (int) $plan->max_users,
            'features' => $plan->features ?? [],
            'recommended' => (bool) $plan->is_featured,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function invoice(SubscriptionInvoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'reference' => $invoice->reference,
            'label' => $invoice->label,
            'amount' => $invoice->amount,
            'status' => ['value' => $invoice->status->value, 'label' => $invoice->status->label()],
            'period' => ['start' => $invoice->period_start->toDateString(), 'end' => $invoice->period_end->toDateString()],
            'due_date' => $invoice->due_date->toDateString(),
            'paid_at' => $invoice->paid_at?->toIso8601String(),
        ];
    }
}
