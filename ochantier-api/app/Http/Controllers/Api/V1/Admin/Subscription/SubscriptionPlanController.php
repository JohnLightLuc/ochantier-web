<?php

namespace App\Http\Controllers\Api\V1\Admin\Subscription;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Admin\Subscription\SubscriptionPlanRequest;
use App\Http\Resources\V1\Admin\SubscriptionPlanResource;
use App\Models\SubscriptionPlan;
use App\Support\Admin\SubscriptionPlans;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Catalogue des formules d’abonnement.
 */
class SubscriptionPlanController extends Controller
{
    public function __construct(private SubscriptionPlans $plans) {}

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(SubscriptionPlanResource::collection($this->plans->catalogue())->resolve($request));
    }

    public function store(SubscriptionPlanRequest $request): JsonResponse
    {
        $plan = $this->plans->create($request->plan(), $request->user(), $request);

        return ApiResponse::success((new SubscriptionPlanResource($plan))->resolve($request), 'Formule créée.', 201);
    }

    public function update(SubscriptionPlanRequest $request, string $planId): JsonResponse
    {
        $plan = SubscriptionPlan::query()->findOrFail($planId);
        $this->plans->update($plan, $request->plan(), $request->user(), $request);

        return ApiResponse::success((new SubscriptionPlanResource($plan->refresh()))->resolve($request), 'Formule mise à jour.');
    }

    public function retire(Request $request, string $planId): JsonResponse
    {
        $plan = SubscriptionPlan::query()->findOrFail($planId);
        $this->plans->retire($plan, $request->user(), $request);

        return ApiResponse::success((new SubscriptionPlanResource($plan))->resolve($request), 'Formule retirée : elle n’est plus proposée.');
    }

    public function restore(Request $request, string $planId): JsonResponse
    {
        $plan = SubscriptionPlan::query()->findOrFail($planId);
        $this->plans->restore($plan, $request->user(), $request);

        return ApiResponse::success((new SubscriptionPlanResource($plan))->resolve($request), 'Formule de nouveau proposée.');
    }
}
