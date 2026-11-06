<?php

namespace App\Support\Admin;

use App\Enums\PaymentMethodStatus;
use App\Enums\PaymentMethodType;
use App\Models\Admin;
use App\Models\CommercialProfile;
use App\Models\PaymentMethod;
use App\Models\PaymentOperator;
use App\Models\Worker;
use App\Support\PhoneNumber;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Numéro Mobile Money qui reçoit les reversements d’un ouvrier ou d’un commercial. Saisi au Back Office, il n’est
 * utilisable qu’une fois confirmé par une autre personne : c’est le point sensible d’un détournement de reversement.
 */
class PayoutMethods
{
    /**
     * @return array<string, mixed>
     */
    public function overview(Worker|CommercialProfile $owner, Admin $admin): array
    {
        $methods = $this->methods($owner)->with('operator')->get();
        $contact = $owner instanceof Worker ? $owner : $owner->user;

        return [
            'current' => ($current = $methods->first(fn (PaymentMethod $method) => $method->is_default)) ? $this->row($current, $admin) : null,
            'pending' => ($pending = $methods->first(fn (PaymentMethod $method) => $method->verified_at === null)) ? $this->row($pending, $admin) : null,
            'operators' => $this->operators(),
            'default_number' => ['dial_code' => $contact->dial_code, 'phone_number' => $contact->phone_number],
        ];
    }

    /**
     * Numéros en attente de confirmation, du plus ancien au plus récent.
     */
    public function pending(int $perPage): LengthAwarePaginator
    {
        return PaymentMethod::query()
            ->whereIn('owner_type', [(new Worker)->getMorphClass(), (new CommercialProfile)->getMorphClass()])
            ->where('status', PaymentMethodStatus::Actif)
            ->whereNull('verified_at')
            ->with(['operator', 'owner'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->paginate($perPage);
    }

    /**
     * Moyen confirmé qui reçoit les reversements, ou null.
     */
    public function current(Worker|CommercialProfile $owner): ?PaymentMethod
    {
        return $this->methods($owner)->where('is_default', true)->whereNotNull('verified_at')->with('operator')->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function row(PaymentMethod $method, Admin $admin): array
    {
        $author = $method->created_by_type === $admin->getMorphClass() ? Admin::query()->find($method->created_by_id) : null;

        return [
            'id' => $method->id,
            'operator' => $method->operator?->name,
            'number' => $method->account_number_masked,
            'holder' => $method->account_holder_name,
            'verified_at' => $method->verified_at?->toIso8601String(),
            'proposed_by' => $author ? trim($author->first_name.' '.$author->last_name) : null,
            'proposed_at' => $method->created_at?->toIso8601String(),
            'can_decide' => $method->verified_at === null && $method->created_by_id !== $admin->getKey(),
        ];
    }

    public function propose(Worker|CommercialProfile $owner, PaymentOperator $operator, PhoneNumber $phone, Admin $admin, Request $request): PaymentMethod
    {
        return DB::transaction(function () use ($owner, $operator, $phone, $admin, $request): PaymentMethod {
            $owner::query()->whereKey($owner->getKey())->lockForUpdate()->first();
            $methods = $this->methods($owner)->get();

            if ($methods->contains(fn (PaymentMethod $method) => $method->verified_at === null)) {
                throw ValidationException::withMessages(['payout' => 'Un numéro attend déjà sa confirmation.']);
            }

            if ($methods->contains(fn (PaymentMethod $method) => $method->operator_id === $operator->getKey() && $method->account_number_encrypted === $phone->e164())) {
                throw ValidationException::withMessages(['payout' => 'Ce numéro est déjà enregistré.']);
            }

            $method = $owner->paymentMethods()->create([
                'method_type' => PaymentMethodType::MobileMoney,
                'operator_id' => $operator->getKey(),
                'account_holder_name' => $this->holder($owner),
                'account_number_encrypted' => $phone->e164(),
                'account_number_masked' => $phone->masked(),
                'is_default' => false,
                'status' => PaymentMethodStatus::Actif,
                'created_by_type' => $admin->getMorphClass(),
                'created_by_id' => $admin->getKey(),
            ]);

            AdminAudit::record($admin, 'payouts', 'payouts.method_proposed', $request, $owner, null, ['method_id' => $method->id, 'operator' => $operator->code, 'number' => $phone->masked()]);

            return $method;
        });
    }

    public function approve(Worker|CommercialProfile $owner, PaymentMethod $method, Admin $admin, Request $request): void
    {
        DB::transaction(function () use ($owner, $method, $admin, $request): void {
            $method = $this->pendingMethod($owner, $method, $admin);

            $this->methods($owner)->where('is_default', true)->update(['is_default' => false, 'status' => PaymentMethodStatus::Desactive]);
            $method->update(['is_default' => true, 'verified_at' => now(), 'verification_method' => 'controle_manuel']);

            AdminAudit::record($admin, 'payouts', 'payouts.method_approved', $request, $owner, null, ['method_id' => $method->id, 'number' => $method->account_number_masked]);
        });
    }

    public function reject(Worker|CommercialProfile $owner, PaymentMethod $method, string $reason, Admin $admin, Request $request): void
    {
        DB::transaction(function () use ($owner, $method, $reason, $admin, $request): void {
            $method = $this->pendingMethod($owner, $method, $admin);

            $method->update(['status' => PaymentMethodStatus::Desactive]);
            $method->delete();

            AdminAudit::record($admin, 'payouts', 'payouts.method_rejected', $request, $owner, null, ['method_id' => $method->id, 'number' => $method->account_number_masked, 'reason' => $reason]);
        });
    }

    /**
     * @return Builder<PaymentMethod>
     */
    private function methods(Worker|CommercialProfile $owner): Builder
    {
        return PaymentMethod::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getKey())
            ->where('status', PaymentMethodStatus::Actif);
    }

    private function holder(Worker|CommercialProfile $owner): string
    {
        $person = $owner instanceof Worker ? $owner : $owner->user;

        return trim($person->first_name.' '.$person->last_name);
    }

    /**
     * Seule une autre personne que l’auteur décide d’un numéro en attente.
     */
    private function pendingMethod(Worker|CommercialProfile $owner, PaymentMethod $method, Admin $admin): PaymentMethod
    {
        $locked = $this->methods($owner)->whereKey($method->getKey())->whereNull('verified_at')->lockForUpdate()->first();

        if (! $locked) {
            throw ValidationException::withMessages(['payout' => 'Ce numéro a déjà été traité.']);
        }

        if ($locked->created_by_id === $admin->getKey()) {
            throw ValidationException::withMessages(['payout' => 'Une autre personne doit confirmer ce numéro.']);
        }

        return $locked;
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    public function operators(): array
    {
        return PaymentOperator::query()
            ->where('is_active', true)
            ->where('supports_payout', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (PaymentOperator $operator) => ['id' => $operator->id, 'name' => $operator->name])
            ->all();
    }
}
