<?php

namespace App\Http\Resources\V1\Admin;

use App\Enums\PaymentScheduleType;
use App\Http\Resources\V1\Resource;
use App\Models\ContractorBid;
use App\Models\Offer;
use App\Support\Admin\ContractOfferDirectory;
use App\Support\Admin\ContractOfferOperations;
use App\Support\PhoneNumber;
use Illuminate\Http\Request;

/**
 * Fiche d’une offre contractuelle dans le Back Office : l’offre et son chantier, la demande de devis d’origine, les
 * réponses des professionnels (prix promoteur, commission, acompte), l’attribution, la chronologie et les décisions
 * possibles.
 *
 * @mixin Offer
 */
class ContractOfferDetailResource extends Resource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $directory = app(ContractOfferDirectory::class);
        $promoterUser = $this->site->promoterProfile?->user;
        $origin = $directory->quoteOrigin($this->resource);
        $shown = $directory->shownBidIds($this->resource);
        $status = (string) $this->display_status;
        $mission = $this->needs->flatMap->missions->first();
        $deposit = $mission?->paymentSchedules->firstWhere('schedule_type', PaymentScheduleType::Acompte);
        $open = in_array($status, ['ouverte', 'en_comparaison', 'acompte_attendu', 'expiree'], true);

        return [
            ...(new ContractOfferListResource($this->resource))->toArray($request),
            'description' => $this->description,
            'promoter' => [
                'name' => $directory->userName($promoterUser),
                'phone' => $promoterUser ? PhoneNumber::of($promoterUser)?->toArray()['phone'] : null,
                'email' => $promoterUser?->email,
            ],
            'rates' => $directory->rates(),
            'extension_count' => (int) $this->extension_count,
            'cancellation_reason' => $this->cancellation_reason,
            'milestones_count' => $this->milestones->count(),
            'quote_request' => $origin ? ['id' => $origin['id'], 'reference' => $origin['reference'], 'line_ids' => $origin['line_ids']] : null,
            'responses' => $this->contractorBids->map(function (ContractorBid $bid) use ($directory, $shown, $origin): array {
                $prices = $directory->prices($bid);

                return [
                    'id' => $bid->id,
                    'contractor' => $bid->contractor ? [...$bid->contractor->only(['id', 'reference', 'name']), 'rating' => $bid->contractor->rating_avg !== null ? (float) $bid->contractor->rating_avg : null] : null,
                    'mode' => $this->status($bid->response_mode),
                    'amount' => $bid->amount,
                    'commission' => $prices['commission'],
                    'total' => $prices['total'],
                    'deposit' => $prices['deposit'],
                    'duration_days' => $bid->duration_days,
                    'team_size' => $bid->team_size,
                    'start_date' => $this->date($bid->start_date),
                    'end_date' => $this->date($bid->end_date),
                    'distance_km' => $bid->distance_km,
                    'message' => $bid->message,
                    'status' => $this->status($bid->status),
                    'is_shown' => in_array($bid->id, $shown, true),
                    'is_quote_author' => $origin !== null && in_array($bid->contractor_id, $origin['contractor_ids'], true),
                    'chosen_at' => $bid->chosen_at?->toIso8601String(),
                    'sent_at' => $bid->sent_at?->toIso8601String(),
                ];
            })->values(),
            'attribution' => $mission ? [
                'mission_reference' => $mission->reference,
                'start_date' => $this->date($mission->planned_start_date),
                'end_date' => $this->date($mission->planned_end_date),
                'deposit' => $deposit ? ['reference' => $deposit->reference, 'amount' => (int) $deposit->amount_due, 'paid' => (int) $deposit->amount_paid, 'status' => $this->status($deposit->status)] : null,
            ] : null,
            'timeline' => $directory->timeline($this->resource),
            'actions' => [
                'extend' => $open,
                'cancel' => $open,
                'remind_promoter' => in_array($status, ContractOfferOperations::REMINDABLE['promoter'], true),
                'remind_contractors' => in_array($status, ContractOfferOperations::REMINDABLE['contractors'], true),
            ],
        ];
    }
}
