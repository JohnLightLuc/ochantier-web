<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;

/**
 * @mixin \App\Models\Worker
 */
class WorkerResource extends Resource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'code' => $this->code,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'name' => $this->name,
            'photo_url' => $this->photo_path ? asset('storage/'.$this->photo_path) : null,
            'gender' => $this->gender,
            'birth_date' => $this->date($this->birth_date),
            'nationality' => $this->nationality,
            'phone' => $this->phone,
            'phone_secondary' => $this->phone_secondary,
            'email' => $this->email,
            'address' => $this->address,
            'commune' => $this->commune,
            'city' => $this->city,
            'country' => $this->country,
            'emergency_contact' => $this->emergency_contact,
            'emergency_phone' => $this->emergency_phone,
            'trade' => $this->trade,
            'secondary_trades' => $this->secondary_trades,
            'specialties' => $this->specialties,
            'skills' => $this->skills,
            'certifications' => $this->certifications,
            'qualification' => $this->qualification,
            'experience_years' => $this->experience_years,
            'experience_description' => $this->experience_description,
            'daily_rate' => $this->daily_rate,
            'mobility' => $this->mobility,
            'zones' => $this->zones,
            'works_solo' => $this->works_solo,
            'works_in_team' => $this->works_in_team,
            'accepts_contracts' => $this->accepts_contracts,
            'status' => $this->status($this->status),
            'availability' => $this->status($this->availability),
            'rejection' => $this->rejection,
            'suspension_reason' => $this->suspension_reason,
            'rating' => $this->rating,
            'reviews_count' => $this->reviews_count,
            'registered_at' => $this->date($this->registered_at),
            'documents' => DocumentResource::collection($this->whenLoaded('documents')),
            'experiences' => ExperienceResource::collection($this->whenLoaded('experiences')),
        ];
    }
}
