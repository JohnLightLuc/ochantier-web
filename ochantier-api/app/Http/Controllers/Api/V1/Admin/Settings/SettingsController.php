<?php

namespace App\Http\Controllers\Api\V1\Admin\Settings;

use App\Enums\PricingVariable;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Admin\Promoter\PromoterReasonRequest;
use App\Models\PricingParameter;
use App\Models\Trade;
use App\Support\Admin\PlatformSettings;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Paramètres : réglages, commissions, barème de matching, métiers et état des connexions.
 */
class SettingsController extends Controller
{
    public function __construct(private PlatformSettings $settings) {}

    public function show(): JsonResponse
    {
        return ApiResponse::success($this->settings->overview());
    }

    public function saveValue(PromoterReasonRequest $request): JsonResponse
    {
        $data = $request->validate(['key' => ['required', 'string', Rule::in(array_keys(PlatformSettings::FIELDS))], 'value' => ['required']], ['value.required' => 'Saisissez une valeur.']);
        $this->settings->saveValue($data['key'], $data['value'], $request->reason(), $request->user(), $request);

        return ApiResponse::success($this->settings->overview(), 'Réglage enregistré.');
    }

    public function proposeRate(PromoterReasonRequest $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', Rule::enum(PricingVariable::class)],
            'value' => ['required', 'numeric', 'min:0', 'max:100'],
            'effective_from' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
        ], ['value.*' => 'Saisissez un pourcentage entre 0 et 100.', 'effective_from.*' => 'Choisissez une date à venir.']);

        $this->settings->proposeRate([...$data, 'reason' => $request->reason()], $request->user(), $request);

        return ApiResponse::success($this->settings->overview(), 'Taux proposé : une autre personne doit le valider.', 201);
    }

    public function approveRate(Request $request, string $rateId): JsonResponse
    {
        $this->settings->approveRate(PricingParameter::query()->findOrFail($rateId), $request->user(), $request);

        return ApiResponse::success($this->settings->overview(), 'Taux validé. Les missions déjà confirmées gardent leur taux figé.');
    }

    public function refuseRate(PromoterReasonRequest $request, string $rateId): JsonResponse
    {
        $this->settings->refuseRate(PricingParameter::query()->findOrFail($rateId), $request->reason(), $request->user(), $request);

        return ApiResponse::success($this->settings->overview(), 'Proposition refusée.');
    }

    public function saveMatching(PromoterReasonRequest $request): JsonResponse
    {
        $data = $request->validate([
            'weights' => ['required', 'array'],
            'weights.*' => ['required', 'integer', 'min:0', 'max:100'],
            'radius' => ['required', 'integer', 'min:1', 'max:500'],
            'radius_max' => ['required', 'integer', 'min:1', 'max:500'],
            'tolerance_max' => ['required', 'integer', 'min:0', 'max:100'],
        ]);

        $this->settings->saveMatching([...$data, 'reason' => $request->reason()], $request->user(), $request);

        return ApiResponse::success($this->settings->overview(), 'Nouveau barème en place pour les prochains matchings.');
    }

    public function storeTrade(Request $request): JsonResponse
    {
        $data = $request->validate(['label' => ['required', 'string', 'max:120']], ['label.required' => 'Indiquez le métier.']);
        $this->settings->createTrade($data['label'], $request->user(), $request);

        return ApiResponse::success($this->settings->overview(), 'Métier ajouté.', 201);
    }

    public function updateTrade(Request $request, string $tradeId): JsonResponse
    {
        $data = $request->validate(['label' => ['required', 'string', 'max:120']], ['label.required' => 'Indiquez le métier.']);
        $this->settings->renameTrade(Trade::query()->findOrFail($tradeId), $data['label'], $request->user(), $request);

        return ApiResponse::success($this->settings->overview(), 'Métier renommé.');
    }

    public function toggleTrade(Request $request, string $tradeId): JsonResponse
    {
        $this->settings->toggleTrade(Trade::query()->findOrFail($tradeId), $request->user(), $request);

        return ApiResponse::success($this->settings->overview(), 'Métier mis à jour.');
    }
}
