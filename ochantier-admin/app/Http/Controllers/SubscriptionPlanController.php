<?php

namespace App\Http\Controllers;

use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Formules d’abonnement : création, modification (prix appliqué aux prochaines échéances) et suppression avec
 * migration des abonnés. La formule Solo n’est ni modifiable ni supprimable.
 */
class SubscriptionPlanController extends Controller
{
    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?SubscriptionPlan $plan): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60', Rule::unique('subscription_plans', 'name')->ignore($plan?->id)],
            'price' => ['required', 'integer', 'min:1000'],
            'max_users' => ['required', 'integer', 'min:2'],
            'popular' => ['required', 'boolean'],
            'features' => ['required', 'string', 'max:2000'],
        ], [
            'name.unique' => 'Une formule porte déjà ce nom.',
            'price.*' => 'Montant minimum : 1 000 FCFA.',
            'max_users.*' => 'Nombre entier, 2 minimum.',
        ]);
        $data['features'] = array_values(array_filter(array_map('trim', preg_split('/\R/', $data['features']))));
        if (! $data['features']) {
            throw ValidationException::withMessages(['features' => 'Ajoutez au moins un avantage.']);
        }
        if ($plan) {
            $over = Subscription::withCount('users')->where('plan_key', $plan->key)->get()->filter(fn (Subscription $s): bool => $s->users_count > $data['max_users'])->count();
            if ($over) {
                throw ValidationException::withMessages(['max_users' => "{$over} abonné(s) dépassent déjà cette limite."]);
            }
        }
        $data['name'] = trim($data['name']);

        return $data;
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, null);
        $key = Str::slug($data['name']) ?: 'formule';
        while (SubscriptionPlan::query()->where('key', $key)->exists()) {
            $key .= '-2';
        }
        if ($data['popular']) {
            SubscriptionPlan::query()->update(['popular' => false]);
        }
        SubscriptionPlan::create([
            ...$data, 'key' => $key, 'period' => 'par mois', 'subtitle' => "Jusqu’à {$data['max_users']} utilisateurs",
            'description' => "Formule {$data['name']} · jusqu’à {$data['max_users']} utilisateurs", 'position' => (int) SubscriptionPlan::query()->max('position') + 1,
        ]);
        Inertia::flash('toast', "Formule « {$data['name']} » créée.");

        return back();
    }

    public function update(Request $request, SubscriptionPlan $plan): RedirectResponse
    {
        abort_if($plan->isSolo(), 422, 'La formule Solo ne peut pas être modifiée.');
        $data = $this->validated($request, $plan);
        if ($data['popular']) {
            SubscriptionPlan::query()->whereKeyNot($plan->id)->update(['popular' => false]);
        }
        $plan->update([...$data, 'subtitle' => "Jusqu’à {$data['max_users']} utilisateurs", 'description' => str_replace($plan->name, $data['name'], $plan->description)]);
        Subscription::query()->where('plan_key', $plan->key)->get()->each(fn (Subscription $s) => $s->log("Formule {$data['name']} mise à jour : ".number_format($data['price'], 0, ',', ' ')." FCFA · {$data['max_users']} utilisateurs"));
        Inertia::flash('toast', "Formule « {$data['name']} » mise à jour.");

        return back();
    }

    /**
     * Suppression : les abonnés (et changements programmés) sont migrés vers la formule choisie, si elle les accepte.
     */
    public function destroy(Request $request, SubscriptionPlan $plan): RedirectResponse
    {
        abort_if($plan->isSolo(), 422, 'La formule Solo ne peut pas être supprimée.');
        $subscribers = Subscription::withCount('users')->where('plan_key', $plan->key)->get();
        $pending = Subscription::query()->where('pending_plan_key', $plan->key)->get();
        $target = null;
        if ($subscribers->isNotEmpty() || $pending->isNotEmpty()) {
            $key = $request->validate(['to' => ['required', Rule::exists('subscription_plans', 'key'), Rule::notIn([$plan->key])]], ['to.*' => 'Choisissez la formule de destination.'])['to'];
            $target = SubscriptionPlan::query()->where('key', $key)->firstOrFail();
            $need = max(1, (int) $subscribers->max('users_count'));
            if ($target->max_users < $need) {
                throw ValidationException::withMessages(['to' => "Un abonné compte {$need} utilisateurs : cette formule en autorise {$target->max_users}."]);
            }
        }

        DB::transaction(function () use ($plan, $subscribers, $pending, $target): void {
            foreach ($pending as $s) {
                $s->update(['pending_plan_key' => null, 'pending_at' => null]);
            }
            foreach ($subscribers as $s) {
                $s->update(['plan_key' => $target->key, ...($target->price ? [] : ['next_due' => null, 'payment_method' => null])]);
                $s->log("Formule {$plan->name} supprimée · migration vers {$target->name}");
            }
            $plan->delete();
        });
        Inertia::flash('toast', "Formule « {$plan->name} » supprimée".($subscribers->isNotEmpty() ? " · {$subscribers->count()} abonné(s) migré(s) vers {$target->name}" : '').'.');

        return back();
    }
}
