<?php

namespace App\Http\Resources;

use App\Models\Worker;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Profil complet d’un ouvrier (fiche). Les dates sont au format ISO.
 *
 * @mixin Worker
 */
class WorkerResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'code' => $this->code,
            'name' => $this->name,
            'firstName' => $this->first_name,
            'lastName' => $this->last_name,
            'gender' => $this->gender,
            'birthDate' => $this->birth_date?->format('Y-m-d'),
            'nationality' => $this->nationality,
            'phone' => $this->phone,
            'phoneSecondary' => $this->phone_secondary,
            'email' => $this->email,
            'address' => $this->address,
            'commune' => $this->commune,
            'city' => $this->city,
            'country' => $this->country,
            'emergencyContact' => $this->emergency_contact,
            'emergencyPhone' => $this->emergency_phone,
            'trade' => $this->trade,
            'secondaryTrades' => $this->secondary_trades,
            'specialties' => $this->specialties,
            'skills' => $this->skills,
            'certifications' => $this->certifications,
            'qualification' => $this->qualification,
            'experienceYears' => $this->experience_years,
            'experienceDescription' => $this->experience_description,
            'dailyRate' => $this->daily_rate,
            'mobility' => $this->mobility,
            'zones' => $this->zones,
            'worksSolo' => $this->works_solo,
            'worksInTeam' => $this->works_in_team,
            'acceptsContracts' => $this->accepts_contracts,
            'idType' => $this->id_type,
            'idNumber' => $this->id_number,
            'idIssuedAt' => $this->id_issued_at?->format('Y-m-d'),
            'idExpiresAt' => $this->id_expires_at?->format('Y-m-d'),
            'recruiter' => $this->recruiter,
            'salesZone' => $this->sales_zone,
            'acquisitionChannel' => $this->acquisition_channel,
            'salesNote' => $this->sales_note,
            'rating' => $this->rating,
            'reviewsCount' => $this->reviews_count,
            'registeredAt' => $this->registered_at->format('Y-m-d'),
            'status' => ['value' => $this->status->value, 'label' => $this->status->label(), 'tone' => $this->status->tone()],
            'availability' => ['value' => $this->availability->value, 'label' => $this->availability->label(), 'tone' => $this->availability->tone()],
            'suspensionReason' => $this->suspension_reason,
            'rejection' => $this->rejection,
            'identityMismatch' => $this->identity_mismatch,
            'possibleDuplicate' => $this->possible_duplicate,
        ];
    }
}
