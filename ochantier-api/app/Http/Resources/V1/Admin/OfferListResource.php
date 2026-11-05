<?php

namespace App\Http\Resources\V1\Admin;

use App\Http\Resources\V1\Resource;
use App\Models\Offer;
use Illuminate\Http\Request;

/**
 * Ligne de la liste des besoins du Back Office.
 *
 * @mixin Offer
 */
class OfferListResource extends Resource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $site = $this->site;
        $user = $site->promoterProfile?->user;
        $organization = $site->organization;

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'title' => $this->title,
            'type' => $this->status($this->offer_type),
            'urgency' => $this->status($this->urgency),
            'status' => $this->status($this->status),
            'site' => ['id' => $site->id, 'reference' => $site->reference, 'label' => $site->label, 'commune' => $site->commune?->name],
            'promoter' => ['id' => $site->promoter_profile_id, 'name' => $organization ? ($organization->trade_name ?: $organization->legal_name) : trim($user?->first_name.' '.$user?->last_name)],
            'main_trade' => $this->main_trade,
            'trades_count' => (int) $this->trades_count,
            'coverage' => ['required' => (int) $this->required_workers, 'assigned' => (int) $this->assigned_workers, 'percent' => (int) $this->coverage_percent],
            'proposal_pending' => (bool) $this->has_pending_proposal,
            'deposit_expected' => (bool) $this->has_accepted_proposal && (int) $this->assigned_workers === 0,
            'start_date' => $this->date($this->start_date),
            'end_date' => $this->date($this->end_date),
            'application_deadline' => $this->application_deadline?->toIso8601String(),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
        ];
    }
}
