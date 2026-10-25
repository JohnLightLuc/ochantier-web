<?php

namespace App\Http\Controllers\Api\V1\Promoter;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Promoter\PaymentRequest;
use App\Http\Resources\V1\Promoter\PaymentTransactionResource;
use App\Models\PaymentMethod;
use App\Models\PaymentOperator;
use App\Models\PaymentSchedule;
use App\Models\PaymentTransaction;
use App\Support\ApiResponse;
use App\Support\PhoneNumber;
use App\Support\Promoter\PromoterPayments;
use App\Support\Promoter\PromoterSubscriptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * « Paiements », « Détail paiement » et paiement Mobile Money d’un acompte, d’une échéance, d’un versement de
 * paiement flexible ou d’un abonnement.
 */
class PaymentController extends Controller
{
    /**
     * Onglets de « Paiements » : filtre => états.
     *
     * @var array<string, list<string>>
     */
    private const FILTERS = [
        'a_payer' => ['a_payer'],
        'en_retard' => ['en_retard'],
        'a_venir' => ['a_venir'],
        'effectues' => ['effectue'],
        'finalises' => ['finalise'],
    ];

    public function __construct(private PromoterPayments $payments, private PromoterSubscriptions $subscriptions) {}

    /**
     * Acomptes et échéances, les plus urgents d’abord. Filtres : filter (a_payer, en_retard, a_venir, effectues,
     * finalises), site_id. meta.totals : à régler, à venir, payés ; meta.counts : nombre par onglet ; meta.next :
     * prochain paiement à faire.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'filter' => ['nullable', 'in:'.implode(',', array_keys(self::FILTERS))],
            'site_id' => ['nullable', 'uuid'],
        ]);

        $rows = $this->payments->rows($request->user(), $filters['site_id'] ?? null);
        $sum = fn (array $states) => (int) $rows->whereIn('status.value', $states)->sum('amount');
        $pending = $rows->whereIn('status.value', ['en_retard', 'a_payer', 'a_venir']);

        return response()->json([
            'data' => $rows->when($filters['filter'] ?? null, fn ($rows, $filter) => $rows->whereIn('status.value', self::FILTERS[$filter]))->values(),
            'meta' => [
                'totals' => ['a_regler' => $sum(['a_payer', 'en_retard']), 'a_venir' => $sum(['a_venir']), 'payes' => $sum(['effectue', 'finalise'])],
                'counts' => ['tous' => $rows->count(), ...collect(self::FILTERS)->map(fn (array $states) => $rows->whereIn('status.value', $states)->count())->all()],
                'next' => $pending->first(),
            ],
        ]);
    }

    /**
     * « Détail paiement » d’une échéance : ligne, détail du montant, avancement des règlements de la réservation.
     */
    public function show(PaymentSchedule $schedule): JsonResponse
    {
        $row = $this->payments->scheduleGroup($schedule);
        $transaction = $row['transaction_reference'] ? PaymentTransaction::query()->where('reference', $row['transaction_reference'])->first() : null;

        return response()->json(['data' => [
            ...$row,
            'contract' => $this->payments->contractProgress($schedule),
            'lines' => $transaction?->metadata['lines'] ?? null,
            'total_paid' => $transaction ? $transaction->amount + $transaction->fees_amount : null,
        ]]);
    }

    /**
     * Moyens de paiement : opérateurs (avec leurs frais), numéros enregistrés, numéro du compte proposé par défaut.
     */
    public function options(Request $request): JsonResponse
    {
        $profile = ProfileController::profile($request);

        return ApiResponse::success([
            'operators' => $this->payments->operators()->map(fn (PaymentOperator $operator) => [
                'code' => $operator->code,
                'name' => $operator->name,
                'fee_percent' => (float) $operator->collection_fee_percent,
                'number_pattern' => $operator->number_pattern,
            ])->values(),
            'saved_numbers' => $profile->paymentMethods()->where('status', 'actif')->with('operator')->orderByDesc('is_default')->get()->map(fn (PaymentMethod $method) => [
                'id' => $method->id,
                'operator_code' => $method->operator?->code,
                'phone' => $method->account_number_masked,
                'is_default' => $method->is_default,
            ])->values(),
            'default_phone' => PhoneNumber::of($request->user())?->toArray(),
            'flexible' => [
                'available' => $this->subscriptions->allowsFlexiblePayment($profile),
                'min_amount' => PromoterPayments::FLEXIBLE_MIN,
                'installments' => [1, 2, 3],
                'interval_days' => 7,
            ],
        ]);
    }

    /**
     * Détail du montant avant de payer, avec les frais de l’opérateur choisi ; installments (2 ou 3) détaille le
     * paiement flexible (versement du jour et versements suivants).
     */
    public function quote(PaymentRequest $request): JsonResponse
    {
        $payable = $this->payments->split($this->payments->payable($request->user(), $request->target()), ProfileController::profile($request), $request->installments());

        return response()->json(['data' => [
            'label' => $payable['label'],
            'kind' => $payable['kind'],
            ...$this->payments->quote($payable, $request->operator()),
            'total_amount' => $payable['total_amount'] ?? $payable['amount'],
            'installments' => $payable['installments'] ?? null,
        ]]);
    }

    /**
     * Paie par Mobile Money. En-tête Idempotency-Key recommandé : la même clé renvoie le paiement déjà demandé.
     */
    public function store(PaymentRequest $request): JsonResponse
    {
        $profile = ProfileController::profile($request);
        $key = (string) ($request->header('Idempotency-Key') ?: Str::uuid7());
        $existing = PaymentTransaction::query()->where('idempotency_key', $key)->where('payer_type', $profile->getMorphClass())->where('payer_id', $profile->getKey())->first();

        if ($existing) {
            return PaymentTransactionResource::make($existing->load(['operator', 'receipts.mediaFile']))->response()->setStatusCode(200);
        }

        $payable = $this->payments->split($this->payments->payable($request->user(), $request->target()), $profile, $request->installments());
        $transaction = $this->payments->pay($request->user(), $profile, $payable, $request->operator(), $request->phoneNumber(), $key, $request->boolean('remember_number'));

        return PaymentTransactionResource::make($transaction->load(['operator', 'receipts.mediaFile']))->response()->setStatusCode(201);
    }

    /**
     * Statut d’un paiement (à interroger tant qu’il est en attente de validation sur le téléphone).
     */
    public function transaction(PaymentTransaction $transaction): PaymentTransactionResource
    {
        return PaymentTransactionResource::make($transaction->load(['operator', 'receipts.mediaFile']));
    }
}
