<?php

namespace App\Support\Admin;

use App\Enums\OrganizationMemberStatus;
use App\Enums\SubscriptionStatus;
use App\Models\PromoterProfile;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Support\Promoter\PromoterSubscriptions;
use Illuminate\Database\Eloquent\Builder;

/**
 * Abonnements du Back Office : une ligne par abonnement. Un compte Solo n’a pas d’abonnement, donc pas de ligne.
 */
class SubscriptionDirectory
{
    /**
     * @param  array<string, mixed>  $filters  search, status, plan
     * @return Builder<Subscription>
     */
    public function subscriptions(array $filters): Builder
    {
        $search = isset($filters['search']) ? '%'.addcslashes(trim($filters['search']), '%_\\').'%' : null;

        return Subscription::query()
            ->join('promoter_profiles', 'promoter_profiles.id', '=', 'subscriptions.promoter_profile_id')
            ->join('users', 'users.id', '=', 'promoter_profiles.user_id')
            ->leftJoin('organizations', 'organizations.id', '=', 'subscriptions.organization_id')
            ->select('subscriptions.*')
            ->selectRaw($this->promoterName().' as promoter_name')
            ->selectRaw("concat_ws(' ', users.first_name, users.last_name) as owner_name")
            ->selectRaw(self::seatsSql().' as seats_used')
            ->with(['plan', 'pendingPlan'])
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('subscriptions.status', $status))
            ->when($filters['plan'] ?? null, fn (Builder $query, string $code) => $query->whereHas('plan', fn (Builder $query) => $query->where('code', $code)))
            ->when($search, fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('subscriptions.reference', 'ilike', $search)
                ->orWhereRaw($this->promoterName().' ilike ?', [$search])
                ->orWhereRaw("concat_ws(' ', users.first_name, users.last_name) ilike ?", [$search])))
            ->latest('subscriptions.created_at')
            ->orderBy('subscriptions.id');
    }

    /**
     * @return array{statuses: array<string, int>, paying: int, promoters: int, monthly_revenue: int}
     */
    public function counts(): array
    {
        $statuses = Subscription::query()->toBase()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return [
            'statuses' => collect(SubscriptionStatus::cases())->mapWithKeys(fn (SubscriptionStatus $status) => [$status->value => (int) $statuses->get($status->value, 0)])->all(),
            'paying' => Subscription::query()->whereIn('status', PromoterSubscriptions::CURRENT)->count(),
            'promoters' => PromoterProfile::query()->count(),
            'monthly_revenue' => (int) Subscription::query()
                ->join('subscription_plans', 'subscription_plans.id', '=', 'subscriptions.plan_id')
                ->where('subscriptions.status', SubscriptionStatus::Active)
                ->where('subscriptions.auto_renew', true)
                ->sum('subscription_plans.price_amount'),
        ];
    }

    /**
     * @return list<array{code: string, name: string}>
     */
    public function planOptions(): array
    {
        return SubscriptionPlan::query()->where('code', '!=', PromoterSubscriptions::FREE_PLAN)->orderBy('sort_order')->get(['code', 'name'])
            ->map(fn (SubscriptionPlan $plan) => ['code' => $plan->code, 'name' => $plan->name])->all();
    }

    /**
     * Le propriétaire et ses collaborateurs invités ou actifs (même règle que PromoterSubscriptions::seatsUsed).
     */
    public static function seatsSql(): string
    {
        $statuses = "'".OrganizationMemberStatus::Invite->value."', '".OrganizationMemberStatus::Actif->value."'";

        return '(1 + (select count(*) from organization_members'
            .' join organizations as owned on owned.id = organization_members.organization_id'
            .' where owned.owner_user_id = (select user_id from promoter_profiles as owner where owner.id = subscriptions.promoter_profile_id)'
            .' and organization_members.user_id <> owned.owner_user_id'
            ." and organization_members.status in ({$statuses})))";
    }

    private function promoterName(): string
    {
        return "coalesce(nullif(organizations.trade_name, ''), organizations.legal_name, concat_ws(' ', users.first_name, users.last_name))";
    }
}
