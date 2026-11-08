<?php

namespace App\Http\Requests\V1\Promoter;

use App\Enums\ContractPaymentMode;
use App\Enums\WorkerVerificationStatus;
use App\Support\Promoter\OfferDraft;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Besoins du chantier (« Type d’offre », « Effectifs ouvriers », « Offre contractuelle » avec « Avez-vous un
 * devis ? », l’offre financière ou la demande de devis) : le brouillon est remplacé par ce qui est envoyé.
 */
class OfferDraftRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $daily = $this->input('type') === OfferDraft::DAILY;

        return [
            'lock_version' => ['sometimes', 'integer'],
            'type' => ['required', Rule::in([OfferDraft::DAILY, OfferDraft::CONTRACT])],
            'needs' => [$daily ? 'sometimes' : 'prohibited', 'array', 'max:20'],
            'needs.*.trade_id' => ['required', 'uuid', 'distinct', Rule::exists('trades', 'id')->where('is_active', true)],
            'needs.*.headcount' => ['required', 'integer', 'min:0', 'max:50'],
            'needs.*.daily_rate' => ['nullable', 'integer', 'min:1000', 'max:1000000'],
            'worker_ids' => [$daily ? 'sometimes' : 'prohibited', 'array', 'max:50'],
            'worker_ids.*' => ['uuid', 'distinct', Rule::exists('workers', 'id')->where('verification_status', WorkerVerificationStatus::Valide->value)->whereNull('merged_into_worker_id')->whereNull('deleted_at')],
            'contract' => [$daily ? 'prohibited' : 'sometimes', 'array'],
            'contract.amount' => ['nullable', 'integer', 'min:10000', 'max:100000000000'],
            'contract.payment_mode' => ['sometimes', Rule::enum(ContractPaymentMode::class)->only(OfferDraft::PAYMENT_MODES)],
            'contract.has_quote' => ['sometimes', 'nullable', 'boolean'],
            'contract.quote_request' => ['sometimes', 'array'],
            'contract.quote_request.trade_ids' => ['sometimes', 'array', 'max:20'],
            'contract.quote_request.trade_ids.*' => ['uuid', 'distinct', Rule::exists('trades', 'id')->where('is_active', true)->where('accepts_quotes', true)],
            'contract.quote_request.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'type.required' => 'Choisissez le type d’offre.',
            'type.in' => 'Type d’offre attendu : journaliere ou contractuelle.',
            'needs.prohibited' => 'Les effectifs par métier ne concernent que l’offre journalière.',
            'worker_ids.prohibited' => 'Le choix d’ouvriers ne concerne que l’offre journalière.',
            'contract.prohibited' => 'Le forfait ne concerne que l’offre contractuelle.',
            'needs.*.trade_id.distinct' => 'Ce métier figure déjà dans les besoins.',
            'needs.*.trade_id.exists' => 'Métier inconnu.',
            'needs.*.headcount.max' => '50 ouvriers au maximum par métier.',
            'needs.*.daily_rate.min' => 'Le tarif journalier doit être d’au moins 1 000 FCFA.',
            'worker_ids.*.exists' => 'Cet ouvrier n’est plus disponible à la réservation.',
            'contract.amount.min' => 'Le montant du forfait doit être d’au moins 10 000 FCFA.',
            'contract.payment_mode.enum' => 'Paiement du solde attendu : hebdomadaire ou par étape.',
            'contract.quote_request.trade_ids.*.distinct' => 'Ce corps de métier est déjà choisi.',
            'contract.quote_request.trade_ids.*.exists' => 'Corps de métier inconnu.',
            'contract.quote_request.notes.max' => 'Les précisions pour le technicien ne doivent pas dépasser 1 000 caractères.',
        ];
    }
}
