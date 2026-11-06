<?php

namespace App\Support\Admin;

use App\Enums\BillingPeriod;
use App\Enums\SubscriptionStatus;
use App\Models\Admin;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Support\Promoter\PromoterNotifier;
use App\Support\Promoter\PromoterSubscriptions;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Catalogue des formules, toutes mensuelles. Solo est fixe. Un nouveau prix s’applique à la prochaine facture. Une
 * formule n’est jamais effacée : retirée, elle n’est plus proposée et ses abonnés la gardent.
 */
class SubscriptionPlans
{
    public function __construct(private PromoterNotifier $notifier) {}

    /**
     * @return Collection<int, SubscriptionPlan>
     */
    public function catalogue(): Collection
    {
        return SubscriptionPlan::query()
            ->withCount([
                'subscriptions as subscribers_count' => fn ($query) => $query->whereIn('status', PromoterSubscriptions::LIVE),
                'subscriptions as paying_count' => fn ($query) => $query->where('status', SubscriptionStatus::Active)->where('auto_renew', true),
            ])
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * @param  array{name: string, price_amount: int, max_users: int, features: list<string>, is_featured: bool}  $data
     */
    public function create(array $data, Admin $admin, Request $request): SubscriptionPlan
    {
        return DB::transaction(function () use ($data, $admin, $request): SubscriptionPlan {
            $plan = SubscriptionPlan::query()->create([
                ...$this->values($data),
                'code' => $this->code($data['name']),
                'billing_period' => BillingPeriod::Mois,
                'sort_order' => (int) SubscriptionPlan::query()->max('sort_order') + 10,
                'is_active' => true,
            ]);
            $this->feature($plan);
            AdminAudit::record($admin, 'subscriptions', 'subscriptions.plan_created', $request, $plan, null, $this->audited($plan));

            return $plan;
        });
    }

    /**
     * @param  array{name: string, price_amount: int, max_users: int, features: list<string>, is_featured: bool}  $data
     */
    public function update(SubscriptionPlan $plan, array $data, Admin $admin, Request $request): void
    {
        $this->ensureEditable($plan);

        DB::transaction(function () use ($plan, $data, $admin, $request): void {
            $plan = SubscriptionPlan::query()->lockForUpdate()->findOrFail($plan->getKey());
            $largest = (int) Subscription::query()->where('plan_id', $plan->getKey())->whereIn('status', PromoterSubscriptions::LIVE)->max(DB::raw(SubscriptionDirectory::seatsSql()));

            if ($largest > $data['max_users']) {
                throw ValidationException::withMessages(['max_users' => 'Un abonné compte déjà '.$largest.' utilisateurs sur cette formule.']);
            }

            $before = $this->audited($plan);
            $oldPrice = $plan->price_amount;
            $plan->update($this->values($data));
            $this->feature($plan);
            AdminAudit::record($admin, 'subscriptions', 'subscriptions.plan_updated', $request, $plan, $before, $this->audited($plan));

            if ($plan->price_amount !== $oldPrice) {
                $this->announcePrice($plan);
            }
        });
    }

    public function retire(SubscriptionPlan $plan, Admin $admin, Request $request): void
    {
        $this->ensureEditable($plan);
        $this->ensure($plan->is_active, 'Cette formule est déjà retirée.');

        $plan->update(['is_active' => false, 'is_featured' => false]);
        AdminAudit::record($admin, 'subscriptions', 'subscriptions.plan_retired', $request, $plan);
    }

    public function restore(SubscriptionPlan $plan, Admin $admin, Request $request): void
    {
        $this->ensureEditable($plan);
        $this->ensure(! $plan->is_active, 'Cette formule est déjà proposée.');

        $plan->update(['is_active' => true]);
        AdminAudit::record($admin, 'subscriptions', 'subscriptions.plan_restored', $request, $plan);
    }

    /**
     * @param  array{name: string, price_amount: int, max_users: int, features: list<string>, is_featured: bool}  $data
     * @return array<string, mixed>
     */
    private function values(array $data): array
    {
        return [
            'name' => $data['name'],
            'subtitle' => 'Jusqu’à '.$data['max_users'].' utilisateurs',
            'price_amount' => $data['price_amount'],
            'max_users' => $data['max_users'],
            'features' => $data['features'],
            'is_featured' => $data['is_featured'],
        ];
    }

    private function feature(SubscriptionPlan $plan): void
    {
        if ($plan->is_featured) {
            SubscriptionPlan::query()->whereKeyNot($plan->getKey())->where('is_featured', true)->update(['is_featured' => false]);
        }
    }

    /**
     * Les abonnés sont prévenus du nouveau prix, sauf ceux qui partent ou changent de formule.
     */
    private function announcePrice(SubscriptionPlan $plan): void
    {
        Subscription::query()
            ->where('plan_id', $plan->getKey())
            ->whereIn('status', PromoterSubscriptions::CURRENT)
            ->where('auto_renew', true)
            ->whereNull('pending_plan_id')
            ->with('promoterProfile.user')
            ->each(fn (Subscription $subscription) => $this->notifier->subscriptionUpdated($subscription, 'Nouveau prix de votre formule',
                'La formule '.$plan->name.' passe à '.number_format($plan->price_amount, 0, ',', "\u{00A0}")."\u{00A0}FCFA par mois à votre prochain renouvellement."));
    }

    private function code(string $name): string
    {
        $base = Str::limit(Str::slug($name, '_'), 26, '') ?: 'formule';
        $code = $base;
        $suffix = 2;

        while (SubscriptionPlan::query()->where('code', $code)->exists()) {
            $code = $base.'_'.$suffix++;
        }

        return $code;
    }

    /**
     * @return array<string, mixed>
     */
    private function audited(SubscriptionPlan $plan): array
    {
        return $plan->only(['name', 'price_amount', 'max_users', 'features', 'is_featured']);
    }

    private function ensureEditable(SubscriptionPlan $plan): void
    {
        $this->ensure($plan->code !== PromoterSubscriptions::FREE_PLAN, 'La formule Solo ne peut pas être modifiée.');
    }

    private function ensure(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['plan' => $message]);
        }
    }
}
