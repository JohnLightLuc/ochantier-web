<?php

namespace App\Support\Admin;

use App\Enums\PaymentMethodStatus;
use App\Enums\PaymentMethodType;
use App\Models\Admin;
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
 * Numéro Mobile Money qui reçoit les reversements d’un ouvrier. Saisi au Back Office, il n’est utilisable qu’une fois
 * confirmé par une autre personne : c’est le point sensible d’un détournement de reversement.
 */
class WorkerPaymentMethods
{
    /**
     * @return array<string, mixed>
     */
    public function overview(Worker $worker, Admin $admin): array
    {
        $methods = $this->methods($worker)->with('operator')->get();

        return [
            'current' => ($current = $methods->first(fn (PaymentMethod $method) => $method->is_default)) ? $this->row($current, $admin) : null,
            'pending' => ($pending = $methods->first(fn (PaymentMethod $method) => $method->verified_at === null)) ? $this->row($pending, $admin) : null,
            'operators' => $this->operators(),
            'default_number' => ['dial_code' => $worker->dial_code, 'phone_number' => $worker->phone_number],
        ];
    }

    /**
     * Numéros en attente de confirmation, du plus ancien au plus récent.
     */
    public function pending(int $perPage): LengthAwarePaginator
    {
        return PaymentMethod::query()
            ->where('owner_type', (new Worker)->getMorphClass())
            ->where('status', PaymentMethodStatus::Actif)
            ->whereNull('verified_at')
            ->with(['operator', 'owner'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->paginate($perPage);
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

    public function propose(Worker $worker, PaymentOperator $operator, PhoneNumber $phone, Admin $admin, Request $request): PaymentMethod
    {
        return DB::transaction(function () use ($worker, $operator, $phone, $admin, $request): PaymentMethod {
            Worker::query()->whereKey($worker->getKey())->lockForUpdate()->first();
            $methods = $this->methods($worker)->get();

            if ($methods->contains(fn (PaymentMethod $method) => $method->verified_at === null)) {
                throw ValidationException::withMessages(['payout' => 'Un numéro attend déjà sa confirmation pour cet ouvrier.']);
            }

            if ($methods->contains(fn (PaymentMethod $method) => $method->operator_id === $operator->getKey() && $method->account_number_encrypted === $phone->e164())) {
                throw ValidationException::withMessages(['payout' => 'Ce numéro est déjà celui de cet ouvrier.']);
            }

            $method = $worker->paymentMethods()->create([
                'method_type' => PaymentMethodType::MobileMoney,
                'operator_id' => $operator->getKey(),
                'account_holder_name' => trim($worker->first_name.' '.$worker->last_name),
                'account_number_encrypted' => $phone->e164(),
                'account_number_masked' => $phone->masked(),
                'is_default' => false,
                'status' => PaymentMethodStatus::Actif,
                'created_by_type' => $admin->getMorphClass(),
                'created_by_id' => $admin->getKey(),
            ]);

            AdminAudit::record($admin, 'payouts', 'payouts.method_proposed', $request, $worker, null, ['method_id' => $method->id, 'operator' => $operator->code, 'number' => $phone->masked()]);

            return $method;
        });
    }

    public function approve(Worker $worker, PaymentMethod $method, Admin $admin, Request $request): void
    {
        DB::transaction(function () use ($worker, $method, $admin, $request): void {
            $method = $this->pendingMethod($worker, $method, $admin);

            $this->methods($worker)->where('is_default', true)->update(['is_default' => false, 'status' => PaymentMethodStatus::Desactive]);
            $method->update(['is_default' => true, 'verified_at' => now(), 'verification_method' => 'controle_manuel']);

            AdminAudit::record($admin, 'payouts', 'payouts.method_approved', $request, $worker, null, ['method_id' => $method->id, 'number' => $method->account_number_masked]);
        });
    }

    public function reject(Worker $worker, PaymentMethod $method, string $reason, Admin $admin, Request $request): void
    {
        DB::transaction(function () use ($worker, $method, $reason, $admin, $request): void {
            $method = $this->pendingMethod($worker, $method, $admin);

            $method->update(['status' => PaymentMethodStatus::Desactive]);
            $method->delete();

            AdminAudit::record($admin, 'payouts', 'payouts.method_rejected', $request, $worker, null, ['method_id' => $method->id, 'number' => $method->account_number_masked, 'reason' => $reason]);
        });
    }

    /**
     * @return Builder<PaymentMethod>
     */
    private function methods(Worker $worker): Builder
    {
        return PaymentMethod::query()
            ->where('owner_type', $worker->getMorphClass())
            ->where('owner_id', $worker->getKey())
            ->where('status', PaymentMethodStatus::Actif);
    }

    /**
     * Seule une autre personne que l’auteur décide d’un numéro en attente.
     */
    private function pendingMethod(Worker $worker, PaymentMethod $method, Admin $admin): PaymentMethod
    {
        $locked = $this->methods($worker)->whereKey($method->getKey())->whereNull('verified_at')->lockForUpdate()->first();

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
    private function operators(): array
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
