<?php

namespace App\Http\Controllers\Api\V1\Worker;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\PaymentResource;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Rémunérations de l’ouvrier : net après commission (Z) et retenues, statut du reversement.
 */
class PaymentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return PaymentResource::collection(
            Payment::query()
                ->where('worker_id', $request->user()->id)
                ->whereNotIn('calc_status', ['Annulé', 'Estimé'])
                ->with('site', 'items', 'refunds')
                ->latest('date')
                ->paginate(20)
        );
    }

    public function show(Request $request, Payment $payment): PaymentResource
    {
        abort_unless($payment->worker_id === $request->user()->id, 404);

        return PaymentResource::make($payment->load('site', 'items', 'refunds'));
    }
}
