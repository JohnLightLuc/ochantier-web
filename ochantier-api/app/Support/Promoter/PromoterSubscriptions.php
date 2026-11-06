<?php

namespace App\Support\Promoter;

use App\Enums\OrganizationMemberStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\SubscriptionInvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Admin;
use App\Models\PaymentTransaction;
use App\Models\PromoterProfile;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\Media\MediaStore;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * « Abonnement » du promoteur : formule Solo gratuite par défaut, formules équipe payées chaque mois par Mobile
 * Money. Souscrire ou changer de formule ouvre une nouvelle période d’un mois dès le paiement. La résiliation coupe
 * le renouvellement : la formule reste active jusqu’à la fin de la période payée, puis le compte revient à Solo.
 *
 * Le renouvellement émet une facture à l’échéance ; impayée, l’abonnement passe « en retard » puis expire après
 * GRACE_DAYS jours (commande ochantier:renew-subscriptions).
 */
class PromoterSubscriptions
{
    /**
     * Abonnements en vigueur (le retard de paiement laisse l’accès pendant la relance).
     */
    public const CURRENT = [SubscriptionStatus::Essai, SubscriptionStatus::Active, SubscriptionStatus::EnRetard];

    /**
     * Abonnements qui occupent la place du compte : une suspension interdit d’en ouvrir un autre.
     */
    public const LIVE = [...self::CURRENT, SubscriptionStatus::Suspendue];

    public const FREE_PLAN = 'solo';

    public const GRACE_DAYS = 7;

    public function __construct(private MediaStore $media) {}

    public function current(PromoterProfile $profile): ?Subscription
    {
        return $profile->subscriptions()->whereIn('status', self::CURRENT)->with('plan')->latest('started_at')->latest()->first();
    }

    public function live(PromoterProfile $profile): ?Subscription
    {
        return $profile->subscriptions()->whereIn('status', self::LIVE)->with('plan')->latest('started_at')->latest()->first();
    }

    public function isSuspended(PromoterProfile $profile): bool
    {
        return $profile->subscriptions()->where('status', SubscriptionStatus::Suspendue)->exists();
    }

    public function isSubscribed(PromoterProfile $profile): bool
    {
        return $profile->subscriptions()->whereIn('status', self::CURRENT)->exists();
    }

    /**
     * Le paiement flexible est réservé aux abonnements à jour.
     */
    public function allowsFlexiblePayment(PromoterProfile $profile): bool
    {
        return $profile->subscriptions()->whereIn('status', [SubscriptionStatus::Essai, SubscriptionStatus::Active])->exists();
    }

    /**
     * Utilisateurs du compte : le propriétaire et les collaborateurs invités ou actifs de son organisation.
     */
    public function seatsUsed(PromoterProfile $profile): int
    {
        $organization = $profile->user->ownedOrganizations()->first();

        return 1 + (int) $organization?->members()
            ->whereIn('status', [OrganizationMemberStatus::Invite, OrganizationMemberStatus::Actif])
            ->where('user_id', '!=', $profile->user_id)
            ->count();
    }

    /**
     * Écran « Abonnement » : formule en cours, places, formules proposées, facturation et résiliation.
     *
     * @return array<string, mixed>
     */
    public function summary(PromoterProfile $profile): array
    {
        $subscription = $this->current($profile);
        $suspended = $subscription === null && $this->isSuspended($profile);
        $plans = $this->plans();
        $current = $subscription?->plan ?? $plans->firstWhere('code', self::FREE_PLAN);
        $seats = $this->seatsUsed($profile);
        $max = (int) ($current?->max_users ?? 1);
        $renewal = $subscription?->current_period_end?->copy()->addDay();
        $openInvoice = $subscription ? $this->openInvoice($subscription) : null;
        $lastPayment = $subscription ? PaymentTransaction::query()
            ->whereIn('subscription_invoice_id', $subscription->invoices()->select('id'))
            ->where('status', PaymentTransactionStatus::Reussie)
            ->with('operator')
            ->latest('completed_at')
            ->first() : null;

        return [
            'active' => $subscription !== null,
            'status' => $suspended ? ['value' => 'suspendu', 'label' => 'Abonnement suspendu'] : $this->statusOf($subscription),
            'plan' => $current ? $this->plan($current) : null,
            'subtitle' => match (true) {
                $subscription === null => 'Compte à utilisateur unique · propriétaire',
                ! $subscription->auto_renew => $this->money($current->price_amount).' / mois · actif jusqu’au '.$this->date($subscription->current_period_end),
                default => $this->money($current->price_amount).' / mois · renouvelé le '.$this->date($renewal),
            },
            'reference' => $subscription?->reference,
            'seats' => [
                'used' => $seats,
                'max' => $max,
                'label' => $seats.' / '.$max.' utilisateur'.($max > 1 ? 's' : ''),
                'percent' => min(100, (int) round($seats / $max * 100)),
                'left_label' => $subscription ? ($max > $seats ? ($max - $seats).' place'.($max - $seats > 1 ? 's restantes' : ' restante') : 'Limite atteinte') : null,
            ],
            'period' => $subscription ? [
                'start' => $subscription->current_period_start?->toDateString(),
                'end' => $subscription->current_period_end?->toDateString(),
            ] : null,
            'auto_renew' => (bool) $subscription?->auto_renew,
            'next_due_date' => $subscription?->auto_renew ? $renewal?->toDateString() : null,
            'next_due_label' => $subscription?->auto_renew ? $this->date($renewal) : null,
            'pending_change' => $subscription?->pendingPlan ? [
                'plan' => $subscription->pendingPlan->name,
                'price' => $subscription->pendingPlan->price_amount,
                'effective_at' => $subscription->pending_effective_at->toDateString(),
                'label' => 'Passage à '.$subscription->pendingPlan->name.' le '.$this->date($subscription->pending_effective_at),
            ] : null,
            'method' => $lastPayment?->operator?->name,
            'open_invoice' => $openInvoice ? $this->invoice($openInvoice) : null,
            'plans' => $plans->map(fn (SubscriptionPlan $plan) => [
                ...$this->plan($plan),
                'current' => $plan->is($current),
                ...$this->choice($plan, $subscription, $current, $seats),
                ...($suspended ? ['can_choose' => false, 'hint' => 'Abonnement suspendu : contactez O’Chantier.'] : []),
            ])->values()->all(),
            'invoices' => $subscription
                ? SubscriptionInvoice::query()
                    ->whereHas('subscription', fn (Builder $query) => $query->where('promoter_profile_id', $profile->getKey()))
                    ->where('status', SubscriptionInvoiceStatus::Payee)
                    ->with('paymentTransactions.receipts.mediaFile')
                    ->latest('period_start')
                    ->get()
                    ->map(fn (SubscriptionInvoice $invoice) => $this->invoice($invoice))
                    ->values()
                    ->all()
                : [],
            'cancellation' => $this->cancellation($subscription, $seats),
        ];
    }

    /**
     * Ce que règle le paiement d’une formule (souscription ou changement) ou d’une facture de renouvellement.
     *
     * @param  array{plan_code?: string, subscription_invoice_id?: string}  $target
     * @return array<string, mixed>
     */
    public function payable(PromoterProfile $profile, array $target): array
    {
        if ($this->isSuspended($profile)) {
            throw ValidationException::withMessages(['subscription' => 'Votre abonnement est suspendu : contactez O’Chantier.']);
        }

        $subscription = $this->current($profile);

        if (isset($target['subscription_invoice_id'])) {
            $invoice = SubscriptionInvoice::query()
                ->whereKey($target['subscription_invoice_id'])
                ->whereHas('subscription', fn (Builder $query) => $query->where('promoter_profile_id', $profile->getKey()))
                ->with(['plan', 'subscription'])
                ->first() ?? throw ValidationException::withMessages(['subscription_invoice_id' => 'Facture introuvable.']);

            if (! in_array($invoice->status, [SubscriptionInvoiceStatus::APayer, SubscriptionInvoiceStatus::EnRetard], true)) {
                throw ValidationException::withMessages(['subscription_invoice_id' => 'Cette facture est déjà réglée.']);
            }

            return $this->payableRow($invoice->plan, $invoice->label, $invoice->amount, ['subscription_invoice_id' => $invoice->getKey()], $invoice);
        }

        $plan = SubscriptionPlan::query()->where('code', $target['plan_code'] ?? '')->where('is_active', true)->first()
            ?? throw ValidationException::withMessages(['plan_code' => 'Formule inconnue.']);

        if ($plan->code === self::FREE_PLAN) {
            throw ValidationException::withMessages(['plan_code' => 'La formule Solo est gratuite : résiliez votre abonnement pour y revenir.']);
        }

        if ($subscription?->plan_id === $plan->getKey()) {
            throw ValidationException::withMessages(['plan_code' => 'C’est déjà votre formule actuelle.']);
        }

        if (($seats = $this->seatsUsed($profile)) > $plan->max_users) {
            throw ValidationException::withMessages(['plan_code' => 'Votre équipe compte '.$seats.' utilisateurs : choisissez une formule plus grande.']);
        }

        return $this->payableRow($plan, 'Abonnement '.$plan->name.' · 1 mois', $plan->price_amount, ['plan_code' => $plan->code]);
    }

    /**
     * Paiement d’abonnement réussi : souscription, changement de formule ou renouvellement, avec la facture payée.
     */
    public function settle(PaymentTransaction $transaction, PromoterProfile $profile, User $user, array $payable): SubscriptionInvoice
    {
        $today = CarbonImmutable::today();
        $plan = $payable['plan'];

        if ($invoice = $payable['invoice']) {
            $invoice = SubscriptionInvoice::query()->lockForUpdate()->findOrFail($invoice->getKey());
            $subscription = $invoice->subscription;
            $invoice->update(['status' => SubscriptionInvoiceStatus::Payee, 'paid_at' => now()]);
            $this->move($subscription, SubscriptionStatus::Active, 'Facture de renouvellement réglée', $user);
            $this->applyInvoicePlan($subscription, $invoice, $user);
            $subscription->update([
                'current_period_start' => $invoice->period_start,
                'current_period_end' => $invoice->period_end,
                'next_due_date' => $invoice->period_end->copy()->addDay(),
            ]);

            return $invoice;
        }

        $end = $today->addMonthNoOverflow()->subDay();
        $subscription = $this->current($profile);

        if ($subscription) {
            $subscription->statusHistories()->create(['from_status' => $subscription->status->value, 'to_status' => SubscriptionStatus::Active->value, 'from_plan_id' => $subscription->plan_id, 'to_plan_id' => $plan->getKey(), 'reason' => 'Changement de formule', 'actor_type' => $user->getMorphClass(), 'actor_id' => $user->getKey()]);
            $subscription->invoices()->whereIn('status', [SubscriptionInvoiceStatus::APayer, SubscriptionInvoiceStatus::EnRetard])->update(['status' => SubscriptionInvoiceStatus::Annulee]);
            $subscription->update([
                'previous_plan_id' => $subscription->plan_id,
                'plan_id' => $plan->getKey(),
                'status' => SubscriptionStatus::Active,
                'auto_renew' => true,
                'cancelled_at' => null,
                'cancellation_reason' => null,
                'pending_plan_id' => null,
                'pending_effective_at' => null,
            ]);
        } else {
            $subscription = $profile->subscriptions()->create([
                'reference' => sprintf('ABO-%s-%06d', now()->format('Y'), DB::scalar("SELECT nextval('subscription_reference_seq')")),
                'organization_id' => $profile->user->ownedOrganizations()->value('id'),
                'plan_id' => $plan->getKey(),
                'status' => SubscriptionStatus::Active,
                'started_at' => $today,
                'auto_renew' => true,
            ]);
            $subscription->statusHistories()->create(['from_status' => null, 'to_status' => SubscriptionStatus::Active->value, 'to_plan_id' => $plan->getKey(), 'reason' => 'Souscription', 'actor_type' => $user->getMorphClass(), 'actor_id' => $user->getKey()]);
        }

        $subscription->update(['current_period_start' => $today, 'current_period_end' => $end, 'next_due_date' => $end->addDay()]);

        return $subscription->invoices()->create([
            'reference' => $this->invoiceReference(),
            'plan_id' => $plan->getKey(),
            'label' => 'Abonnement '.$plan->name.' · '.$this->month($today),
            'period_start' => $today,
            'period_end' => $end,
            'amount' => $plan->price_amount,
            'due_date' => $today,
            'status' => SubscriptionInvoiceStatus::Payee,
            'paid_at' => now(),
        ]);
    }

    /**
     * « Résilier l’abonnement » : plus de renouvellement, la formule reste active jusqu’à la fin de la période.
     */
    public function cancel(PromoterProfile $profile, User $user, ?string $reason = null): Subscription
    {
        $subscription = $this->current($profile) ?? throw ValidationException::withMessages(['subscription' => 'Vous êtes déjà sur la formule Solo.']);
        $refusal = $this->cancellation($subscription, $this->seatsUsed($profile))['refusal'];

        if ($refusal) {
            throw ValidationException::withMessages(['subscription' => $refusal]);
        }

        return $this->stopRenewal($subscription, $reason, $user);
    }

    /**
     * Coupe le renouvellement : sans période en cours à honorer (retard, suspension), la résiliation est immédiate.
     */
    public function stopRenewal(Subscription $subscription, ?string $reason, User|Admin $actor): Subscription
    {
        $subscription->update(['auto_renew' => false, 'cancelled_at' => now(), 'cancellation_reason' => $reason, 'pending_plan_id' => null, 'pending_effective_at' => null]);
        $subscription->invoices()->whereIn('status', [SubscriptionInvoiceStatus::APayer, SubscriptionInvoiceStatus::EnRetard])->update(['status' => SubscriptionInvoiceStatus::Annulee]);

        if (in_array($subscription->status, [SubscriptionStatus::EnRetard, SubscriptionStatus::Suspendue], true)) {
            $this->move($subscription, SubscriptionStatus::Resiliee, $subscription->status === SubscriptionStatus::EnRetard ? 'Résiliation pendant un retard de paiement' : 'Résiliation d’un abonnement suspendu', $actor);
        }

        return $subscription->refresh();
    }

    /**
     * Fin de période : facture de renouvellement, retard, expiration ou fin d’un abonnement résilié.
     *
     * @return array{invoiced: int, late: int, expired: int, ended: int}
     */
    public function renew(?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $counts = ['invoiced' => 0, 'late' => 0, 'expired' => 0, 'ended' => 0];

        Subscription::query()
            ->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::Essai, SubscriptionStatus::EnRetard])
            ->whereDate('current_period_end', '<', $today)
            ->with(['plan', 'pendingPlan'])
            ->each(function (Subscription $subscription) use ($today, &$counts): void {
                DB::transaction(function () use ($subscription, $today, &$counts): void {
                    if (! $subscription->auto_renew) {
                        $subscription->update(['pending_plan_id' => null, 'pending_effective_at' => null]);
                        $this->move($subscription, SubscriptionStatus::Resiliee, 'Fin de la période après résiliation');
                        $counts['ended']++;

                        return;
                    }

                    $invoice = $this->openInvoice($subscription) ?? $this->issueRenewal($subscription, $today, $counts);

                    if ($subscription->status !== SubscriptionStatus::EnRetard) {
                        $this->move($subscription, SubscriptionStatus::EnRetard, 'Facture de renouvellement non réglée');
                        $invoice->update(['status' => SubscriptionInvoiceStatus::EnRetard]);
                        $counts['late']++;
                    }

                    if ($invoice->due_date->copy()->addDays(self::GRACE_DAYS)->lessThan($today)) {
                        $invoice->update(['status' => SubscriptionInvoiceStatus::Annulee]);
                        $subscription->update(['pending_plan_id' => null, 'pending_effective_at' => null]);
                        $this->move($subscription, SubscriptionStatus::Expiree, 'Facture impayée après '.self::GRACE_DAYS.' jours');
                        $counts['expired']++;
                    }
                });
            });

        return $counts;
    }

    /**
     * @return array<string, mixed>
     */
    public function invoice(SubscriptionInvoice $invoice): array
    {
        $receipt = $invoice->paymentTransactions->where('status', PaymentTransactionStatus::Reussie)->flatMap->receipts->first();

        return [
            'id' => $invoice->id,
            'reference' => $invoice->reference,
            'label' => $invoice->label,
            'amount' => $invoice->amount,
            'amount_label' => $this->money($invoice->amount),
            'status' => ['value' => $invoice->status->value, 'label' => $invoice->status->label()],
            'period' => ['start' => $invoice->period_start->toDateString(), 'end' => $invoice->period_end->toDateString()],
            'due_date' => $invoice->due_date->toDateString(),
            'date_label' => $this->date($invoice->paid_at ?? $invoice->due_date),
            'receipt_url' => $receipt?->mediaFile ? $this->media->temporaryUrl($receipt->mediaFile) : null,
        ];
    }

    /**
     * @return Collection<int, SubscriptionPlan>
     */
    private function plans(): Collection
    {
        return SubscriptionPlan::query()->where('is_active', true)->orderBy('sort_order')->get();
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
            'price' => $plan->price_amount,
            'price_label' => $plan->price_amount ? $this->money($plan->price_amount) : 'Gratuit',
            'period_label' => $plan->price_amount ? 'par mois' : 'pour toujours',
            'max_users' => $plan->max_users,
            'seats_label' => $plan->max_users === 1 ? '1 utilisateur' : 'Jusqu’à '.$plan->max_users.' utilisateurs',
            'features' => $plan->features ?? [],
            'is_featured' => $plan->is_featured,
        ];
    }

    /**
     * Bouton d’une formule : souscrire, changer, revenir à Solo (résilier) ou formule actuelle.
     *
     * @return array{action: string|null, button_label: string, hint: string, can_choose: bool}
     */
    private function choice(SubscriptionPlan $plan, ?Subscription $subscription, ?SubscriptionPlan $current, int $seats): array
    {
        $tooSmall = $seats > $plan->max_users;

        return match (true) {
            $plan->code === self::FREE_PLAN && $subscription === null => ['action' => null, 'button_label' => 'Formule actuelle', 'hint' => 'Gratuit · propriétaire uniquement', 'can_choose' => false],
            $plan->code === self::FREE_PLAN => [
                'action' => 'cancel',
                'button_label' => 'Revenir à la formule Solo',
                'hint' => $this->cancellation($subscription, $seats)['refusal'] ?? 'Votre formule reste active jusqu’à la fin de la période payée.',
                'can_choose' => $this->cancellation($subscription, $seats)['refusal'] === null,
            ],
            $plan->is($current) => ['action' => null, 'button_label' => 'Formule actuelle', 'hint' => $subscription?->auto_renew ? 'Renouvellement automatique le '.$this->date($subscription->current_period_end->copy()->addDay()) : 'Renouvellement désactivé', 'can_choose' => false],
            default => [
                'action' => 'subscribe',
                'button_label' => ($subscription ? 'Passer à ' : 'Souscrire à ').$plan->name.' · '.$this->money($plan->price_amount).'/mois',
                'hint' => $tooSmall ? 'Votre équipe compte '.$seats.' utilisateurs : choisissez une formule plus grande.' : 'Paiement mensuel par Mobile Money · sans engagement',
                'can_choose' => ! $tooSmall,
            ],
        };
    }

    /**
     * @return array{possible: bool, refusal: string|null, scheduled: bool, ends_on: string|null}
     */
    private function cancellation(?Subscription $subscription, int $seats): array
    {
        $scheduled = $subscription !== null && ! $subscription->auto_renew;

        return [
            'possible' => $subscription !== null && ! $scheduled && $seats <= 1,
            'refusal' => match (true) {
                $subscription === null => 'Vous êtes déjà sur la formule Solo.',
                $scheduled => 'La résiliation est déjà programmée.',
                $seats > 1 => 'Retirez d’abord les '.($seats - 1).' utilisateur(s) invité(s).',
                default => null,
            },
            'scheduled' => $scheduled,
            'ends_on' => $scheduled ? $subscription->current_period_end?->toDateString() : null,
        ];
    }

    /**
     * @return array{value: string, label: string}
     */
    private function statusOf(?Subscription $subscription): array
    {
        return match (true) {
            $subscription === null => ['value' => 'gratuit', 'label' => 'Gratuit'],
            $subscription->status === SubscriptionStatus::EnRetard => ['value' => 'en_retard', 'label' => 'Paiement en retard'],
            ! $subscription->auto_renew => ['value' => 'resiliation_programmee', 'label' => 'Résiliation programmée'],
            default => ['value' => 'actif', 'label' => 'Actif'],
        };
    }

    /**
     * @param  array<string, string>  $target
     * @return array<string, mixed>
     */
    private function payableRow(SubscriptionPlan $plan, string $label, int $amount, array $target, ?SubscriptionInvoice $invoice = null): array
    {
        return [
            'kind' => 'abonnement',
            'label' => $label,
            'amount' => $amount,
            'site' => null,
            'proposal' => null,
            'schedules' => collect(),
            'plan' => $plan,
            'invoice' => $invoice,
            'target' => $target,
            'lines' => [['label' => $label, 'amount' => $amount]],
            'done_text' => 'Votre formule '.$plan->name.' est active.'.($plan->max_users > 1 ? ' Invitez jusqu’à '.($plan->max_users - 1).' collaborateurs.' : ''),
        ];
    }

    /**
     * @param  array{invoiced: int, late: int, expired: int, ended: int}  $counts
     */
    private function issueRenewal(Subscription $subscription, CarbonImmutable $today, array &$counts): SubscriptionInvoice
    {
        $start = CarbonImmutable::parse($subscription->current_period_end)->addDay();
        $plan = $this->renewalPlan($subscription);
        $counts['invoiced']++;

        return tap($subscription->invoices()->create([
            'reference' => $this->invoiceReference(),
            'plan_id' => $plan->getKey(),
            'label' => 'Abonnement '.$plan->name.' · '.$this->month($start),
            'period_start' => $start,
            'period_end' => $start->addMonthNoOverflow()->subDay(),
            'amount' => $plan->price_amount,
            'due_date' => $start->max($today),
            'status' => SubscriptionInvoiceStatus::APayer,
        ]), fn (SubscriptionInvoice $invoice) => app(PromoterNotifier::class)->invoiceIssued($invoice));
    }

    /**
     * Formule de la prochaine période : le changement programmé, sauf si l’équipe a grandi entre-temps.
     */
    private function renewalPlan(Subscription $subscription): SubscriptionPlan
    {
        $pending = $subscription->pendingPlan;

        if (! $pending) {
            return $subscription->plan;
        }

        if ($this->seatsUsed($subscription->promoterProfile) <= $pending->max_users) {
            return $pending;
        }

        $subscription->statusHistories()->create(['from_status' => $subscription->status->value, 'to_status' => $subscription->status->value, 'to_plan_id' => $pending->getKey(), 'reason' => 'Passage à '.$pending->name.' annulé : équipe trop grande pour cette formule', 'actor_type' => 'systeme']);
        $subscription->update(['pending_plan_id' => null, 'pending_effective_at' => null]);

        return $subscription->plan;
    }

    /**
     * La facture réglée porte la formule de la période qu’elle ouvre.
     */
    private function applyInvoicePlan(Subscription $subscription, SubscriptionInvoice $invoice, User $user): void
    {
        if ($invoice->plan_id !== $subscription->plan_id) {
            $subscription->statusHistories()->create(['from_status' => $subscription->status->value, 'to_status' => $subscription->status->value, 'from_plan_id' => $subscription->plan_id, 'to_plan_id' => $invoice->plan_id, 'reason' => 'Changement de formule programmé', 'actor_type' => $user->getMorphClass(), 'actor_id' => $user->getKey()]);
            $subscription->update(['previous_plan_id' => $subscription->plan_id, 'plan_id' => $invoice->plan_id]);
        }

        if ($subscription->pending_plan_id === $invoice->plan_id) {
            $subscription->update(['pending_plan_id' => null, 'pending_effective_at' => null]);
        }
    }

    public function openInvoice(Subscription $subscription): ?SubscriptionInvoice
    {
        return $subscription->invoices()
            ->whereIn('status', [SubscriptionInvoiceStatus::APayer, SubscriptionInvoiceStatus::EnRetard])
            ->with('paymentTransactions.receipts.mediaFile')
            ->latest('period_start')
            ->first();
    }

    public function move(Subscription $subscription, SubscriptionStatus $status, string $reason, User|Admin|null $user = null): void
    {
        if ($subscription->status === $status) {
            return;
        }

        $subscription->statusHistories()->create([
            'from_status' => $subscription->status->value,
            'to_status' => $status->value,
            'reason' => $reason,
            'actor_type' => $user?->getMorphClass() ?? 'systeme',
            'actor_id' => $user?->getKey(),
        ]);
        $subscription->update(['status' => $status]);
    }

    private function invoiceReference(): string
    {
        return sprintf('FAB-%s-%06d', now()->format('Y'), DB::scalar("SELECT nextval('subscription_invoice_reference_seq')"));
    }

    private function money(int $amount): string
    {
        return number_format($amount, 0, ',', "\u{00A0}")."\u{00A0}FCFA";
    }

    private function month(CarbonInterface $date): string
    {
        return $date->locale('fr')->translatedFormat('M Y');
    }

    private function date(CarbonInterface $date): string
    {
        return $date->locale('fr')->translatedFormat('j M Y');
    }
}
