<?php

namespace Database\Factories;

use App\Enums\NeedUrgency;
use App\Enums\PromoterOfferProfileStatus;
use App\Models\PromoterOffer;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PromoterOffer>
 */
class PromoterOfferFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $number = sprintf('%03d', fake()->unique()->numberBetween(100, 999));

        return [
            'slug' => "ofp-2026-{$number}",
            'reference' => "OFP-2026-{$number}",
            'site_id' => Site::factory(),
            'trade' => 'Maçon',
            'specialty' => 'Maçonnerie générale',
            'urgency' => NeedUrgency::Normal,
            'sent_on' => '2026-09-21',
            'starts_on' => '2026-09-28',
            'ends_on' => '2026-10-11',
            'daily_cost' => 12000,
            'message' => 'Profils déjà appréciés sur la phase gros œuvre.',
        ];
    }

    /**
     * Profils choisis par le promoteur, un par statut donné.
     */
    public function withProfiles(PromoterOfferProfileStatus ...$statuses): static
    {
        return $this->afterCreating(function (PromoterOffer $offer) use ($statuses): void {
            foreach ($statuses as $i => $status) {
                $offer->profiles()->create([
                    'name' => "Profil {$i}", 'trade' => $offer->trade, 'commune' => 'Yopougon', 'experience' => 5, 'rating' => 4.5,
                    'daily_cost' => 12000, 'availability' => 'Disponible', 'status' => $status, 'source' => 'Candidature',
                    'assignment_reference' => $status === PromoterOfferProfileStatus::Accepted ? "AFF-OFP-{$offer->id}-{$i}" : null,
                ]);
            }
        });
    }
}
