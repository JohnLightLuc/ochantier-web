<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;

/**
 * @mixin \App\Models\SalesAgent
 */
class SalesAgentResource extends Resource
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
            'phone' => $this->phone,
            'email' => $this->email,
            'type' => $this->type,
            'job_title' => $this->job_title,
            'agency' => $this->agencyLabel(),
            'zone' => $this->zone,
            'secondary_zones' => $this->secondary_zones,
            'communes' => $this->communes,
            'priority_trades' => $this->priority_trades,
            'supervisor' => $this->supervisor,
            'started_at' => $this->date($this->started_at),
            'weekly_target' => $this->weekly_target,
            'monthly_target' => $this->monthly_target,
            'recruited_total' => $this->recruited_total,
            'status' => $this->status($this->status),
            'availability' => $this->status($this->availability),
        ];
    }
}
