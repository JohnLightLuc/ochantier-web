<?php

namespace Tests\Feature\Promoter;

use App\Enums\SubscriptionStatus;
use App\Models\Assignment;
use App\Models\Proposal;
use App\Models\Site;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use Carbon\CarbonImmutable;
use Database\Seeders\AdminAccessSeeder;
use Database\Seeders\CommercialOfferSeeder;
use Database\Seeders\PlatformRulesSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Chantier confirmé (acompte payé) de deux maçons et un peintre, démarrage dans 5 jours pour 10 jours, au
 * 5.37214, -3.95823 (rayon de pointage de 200 m).
 */
trait ConfirmedSite
{
    protected Site $site;

    protected CarbonImmutable $startDate;

    protected function confirmSite(): void
    {
        $proposal = $this->acceptProposal();
        $this->postJson('/api/v1/promoter/payments', ['proposal_id' => $proposal->id, 'operator_code' => 'orange_money', 'dial_code' => '225', 'phone_number' => '0712345634'])->assertCreated();
        $this->site->refresh();
    }

    /**
     * Même chantier, proposition acceptée mais acompte pas encore payé.
     */
    protected function acceptProposal(): Proposal
    {
        $this->publishedSite();
        $this->artisan('ochantier:simulate-matching')->assertSuccessful();

        $proposal = Proposal::query()->sole();
        $this->postJson("/api/v1/promoter/proposals/{$proposal->id}/accept")->assertOk();

        return $proposal->refresh();
    }

    /**
     * Chantier publié par le promoteur, avec ses besoins, avant tout matching.
     */
    protected function publishedSite(): void
    {
        $this->seed([AdminAccessSeeder::class, PlatformRulesSeeder::class, CommercialOfferSeeder::class]);
        $this->worker('Kouamé Koffi', 'macon', 22700, rating: 4.7);
        $this->worker('Touré Ali', 'macon', 23600, rating: 4.9);
        $this->worker('Serge Aka', 'peintre', 16400, rating: 4.7);

        $site = $this->postJson('/api/v1/promoter/sites', ['title' => 'Résidence Les Palmiers'])->assertCreated()->json('data');
        $this->post("/api/v1/promoter/sites/{$site['id']}/photo", ['photo' => $this->image()], ['Accept' => 'application/json'])->assertOk();
        $this->patchJson("/api/v1/promoter/sites/{$site['id']}", [
            'address' => 'Angré, Abidjan',
            'location' => ['latitude' => 5.37214, 'longitude' => -3.95823],
            'planned_start_date' => now()->addDays(5)->toDateString(),
            'planned_end_date' => now()->addDays(14)->toDateString(),
        ])->assertOk();
        $this->putJson("/api/v1/promoter/sites/{$site['id']}/offer", ['type' => 'journaliere', 'needs' => [
            ['trade_id' => $this->trade('macon')->id, 'headcount' => 2, 'daily_rate' => 25000],
            ['trade_id' => $this->trade('peintre')->id, 'headcount' => 1, 'daily_rate' => 18000],
        ]])->assertOk();
        $this->postJson("/api/v1/promoter/sites/{$site['id']}/publish")->assertOk();

        $this->site = Site::query()->findOrFail($site['id']);
        $this->startDate = CarbonImmutable::today()->addDays(5);
    }

    /**
     * Se place au jour de démarrage du chantier (+ $days), à l’heure donnée, avec une session valide.
     */
    protected function onWorkDay(int $days = 0, string $time = '08:00'): void
    {
        $this->travelTo($this->startDate->addDays($days)->setTimeFromTimeString($time));
        $this->actingAsPromoter($this->promoter);
    }

    protected function assignmentOf(string $name): Assignment
    {
        [$first] = explode(' ', $name);

        return Assignment::query()->whereHas('worker', fn ($query) => $query->where('first_name', $first))->latest()->firstOrFail();
    }

    /**
     * Pointage d’arrivée ou de départ, avec des preuves différentes à chaque appel.
     *
     * @return array<string, mixed>
     */
    protected function proof(Assignment $assignment, string $type = 'arrivee', array $overrides = []): array
    {
        static $size = 0;
        $size++;

        return [
            'assignment_id' => $assignment->id,
            'type' => $type,
            'client_uuid' => (string) Str::uuid(),
            'latitude' => 5.37220,
            'longitude' => -3.95830,
            'accuracy' => 12,
            'photo' => UploadedFile::fake()->image('ouvrier.jpg', 600 + $size, 800),
            'signature' => UploadedFile::fake()->image('signature.png', 300 + $size, 120),
            ...$overrides,
        ];
    }

    protected function point(Assignment $assignment, string $type = 'arrivee', array $overrides = []): TestResponse
    {
        return $this->post("/api/v1/promoter/sites/{$this->site->id}/attendance/events", $this->proof($assignment, $type, $overrides), ['Accept' => 'application/json']);
    }

    /**
     * Les collaborateurs n’accèdent au compte que sous un abonnement en vigueur.
     */
    private function subscribeOwner(): void
    {
        $this->seed(CommercialOfferSeeder::class);

        Subscription::query()->create([
            'reference' => 'ABO-TEST-0001',
            'promoter_profile_id' => $this->promoter->promoterProfile->id,
            'plan_id' => SubscriptionPlan::query()->where('code', 'pro')->value('id'),
            'status' => SubscriptionStatus::Active,
            'started_at' => today(),
            'current_period_start' => today(),
            'current_period_end' => today()->addMonth()->subDay(),
        ]);
    }
}
