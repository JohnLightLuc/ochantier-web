<?php

namespace App\Support\Admin;

use App\Models\Admin;
use App\Models\PaymentOperator;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Opérateurs de paiement (Paramètres) : ce que les payeurs se voient proposer et à quelles conditions. Un opérateur
 * n’est jamais effacé : désactivé, il n’est plus proposé et les paiements déjà demandés se terminent normalement.
 * Chaque changement est motivé et inscrit au journal d’audit.
 */
class PaymentOperators
{
    /**
     * Réglages modifiables d’un opérateur.
     *
     * @var list<string>
     */
    private const EDITABLE = ['is_active', 'supports_collection', 'supports_payout', 'collection_fee_percent', 'min_amount', 'max_amount', 'sort_order', 'notice'];

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function all(): Collection
    {
        return PaymentOperator::query()->orderBy('sort_order')->orderBy('created_at')->get()->map(fn (PaymentOperator $operator) => $this->row($operator));
    }

    /**
     * @param  array<string, mixed>  $data  réglages à changer, parmi EDITABLE
     * @return array<string, mixed>
     */
    public function update(PaymentOperator $operator, array $data, string $reason, Admin $admin, Request $request): array
    {
        return DB::transaction(function () use ($operator, $data, $reason, $admin, $request): array {
            $operator = PaymentOperator::query()->lockForUpdate()->findOrFail($operator->getKey());
            $before = $this->audited($operator);
            $operator->fill(collect($data)->only(self::EDITABLE)->all());

            if (! $operator->isDirty()) {
                throw ValidationException::withMessages(['reason' => 'Aucun réglage n’a changé.']);
            }

            if ($operator->min_amount !== null && $operator->max_amount !== null && $operator->min_amount > $operator->max_amount) {
                throw ValidationException::withMessages(['max_amount' => 'Le montant maximal doit être supérieur au montant minimal.']);
            }

            $operator->save();
            AdminAudit::record($admin, 'settings', 'settings.payment_operator_updated', $request, $operator, $before, [...$this->audited($operator), 'reason' => $reason]);

            return $this->row($operator);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function row(PaymentOperator $operator): array
    {
        return [
            'id' => $operator->id,
            'code' => $operator->code,
            'name' => $operator->name,
            'provider' => $operator->provider,
            'number_pattern' => $operator->number_pattern,
            ...$this->audited($operator),
            'updated_at' => $operator->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function audited(PaymentOperator $operator): array
    {
        return [
            'is_active' => (bool) $operator->is_active,
            'supports_collection' => (bool) $operator->supports_collection,
            'supports_payout' => (bool) $operator->supports_payout,
            'collection_fee_percent' => (float) $operator->collection_fee_percent,
            'min_amount' => $operator->min_amount === null ? null : (int) $operator->min_amount,
            'max_amount' => $operator->max_amount === null ? null : (int) $operator->max_amount,
            'sort_order' => (int) $operator->sort_order,
            'notice' => $operator->notice,
        ];
    }
}
