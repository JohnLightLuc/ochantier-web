<?php

namespace Tests\Feature\Commercial;

use App\Enums\CommercialEarningStatus;
use App\Enums\WorkerVerificationStatus;
use App\Models\CommercialEarning;
use App\Models\CommercialPayout;
use App\Models\CommercialProfile;
use App\Models\Notification;
use App\Models\User;
use App\Models\Worker;
use Database\Seeders\AdminAccessSeeder;
use Database\Seeders\PlatformRulesSeeder;

class CommercialActivityTest extends CommercialTestCase
{
    private const NBSP = "\u{00A0}";

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([AdminAccessSeeder::class, PlatformRulesSeeder::class]);
        $this->commercial->update(['first_name' => 'Adama', 'last_name' => 'Coulibaly']);
    }

    public function test_gains_show_confirmed_pending_and_paid_commissions(): void
    {
        $validated = $this->submit('0700000011', 'Aïcha', 'Koné');
        $this->submit('0700000012', 'Marc', 'Kouassi');
        $this->artisan('ochantier:simulate-worker-review', ['worker' => $validated->reference, 'decision' => 'valider'])->assertSuccessful();

        $month = now()->format('Y-m');
        $this->getJson('/api/v1/commercial/earnings')
            ->assertOk()
            ->assertJsonPath('data.month', $month)
            ->assertJsonPath('data.confirmed', 25000)
            ->assertJsonPath('data.paid', 0)
            ->assertJsonPath('data.pending', 25000)
            ->assertJsonPath('data.pending_label', '25'.self::NBSP.'000'.self::NBSP.'FCFA')
            ->assertJsonPath('data.tabs.*.count', [2, 1, 1])
            ->assertJsonPath('data.history.*.status.value', ['confirmee', 'en_attente'])
            ->assertJsonPath('data.history.0.name', 'Aïcha Koné')
            ->assertJsonPath('data.history.0.amount_label', '+25'.self::NBSP.'000'.self::NBSP.'FCFA')
            ->assertJsonPath('data.history.0.subtitle', 'Dossier validé · '.today()->locale('fr')->translatedFormat('j M'))
            ->assertJsonPath('data.history.1.subtitle', 'Dossier en traitement · '.today()->locale('fr')->translatedFormat('j M'))
            ->assertJsonPath('meta.months.0.value', $month);
        $this->getJson('/api/v1/commercial/earnings?tab=en_attente')->assertJsonCount(1, 'data.history');
        $this->getJson('/api/v1/commercial/earnings?month=2026-13')->assertJsonValidationErrors(['month' => 'Mois invalide (AAAA-MM).']);

        $this->artisan('ochantier:simulate-commercial-payout')->assertSuccessful();
        $this->artisan('ochantier:simulate-commercial-payout')->expectsOutput('Aucune commission confirmée à verser.')->assertSuccessful();

        $payout = CommercialPayout::query()->sole();
        $this->assertSame(25000, $payout->total_amount);
        $this->assertSame(CommercialEarningStatus::Paye, CommercialEarning::query()->where('worker_id', $validated->id)->sole()->status);
        $this->getJson('/api/v1/commercial/earnings')
            ->assertJsonPath('data.paid', 25000)
            ->assertJsonPath('data.paid_percent', 100)
            ->assertJsonPath('data.history.0.status', ['value' => 'versee', 'label' => 'Versée']);
        $this->assertSame('25'.self::NBSP.'000'.self::NBSP.'FCFA versés sur votre compte Mobile Money.', Notification::query()->where('event_code', 'payout.paid')->sole()->body);

        $this->getJson('/api/v1/commercial/home')
            ->assertJsonPath('data.earnings.acquired', 25000)
            ->assertJsonPath('data.earnings.pending', 25000)
            ->assertJsonPath('data.earnings.paid', 25000)
            ->assertJsonPath('data.earnings.paid_label', '25'.self::NBSP.'000'.self::NBSP.'F');
    }

    public function test_the_home_gathers_the_week_the_objective_and_prospects_to_follow(): void
    {
        $this->createProspect(['phone_number' => '0700000021', 'first_name' => 'Fatou', 'last_name' => 'Diallo']);
        $corrected = $this->submit('0700000022', 'Issa', 'Traoré');
        $this->artisan('ochantier:simulate-worker-review', ['worker' => $corrected->reference, 'decision' => 'corriger'])->assertSuccessful();
        $number = $this->commercial->commercialProfile->employee_number;

        $this->getJson('/api/v1/commercial/home')
            ->assertOk()
            ->assertJsonPath('data.greeting', ['title' => 'Bonjour, Adama', 'subtitle' => 'Voici vos priorités du jour'])
            ->assertJsonPath('data.unread_notifications', 1)
            ->assertJsonPath('data.corrections', ['count' => 1, 'label' => '1 dossier à corriger'])
            ->assertJsonPath('data.week.*.count', [2, 1, 0, 1])
            ->assertJsonPath('data.objective', null)
            ->assertJsonPath('data.to_follow.*.full_name', ['Fatou Diallo']);

        $this->artisan('ochantier:simulate-commercial-objective', ['commercial' => $number, 'target' => 3])->assertSuccessful();
        $validated = $this->submit('0700000023', 'Yao', 'Serge');
        $this->artisan('ochantier:simulate-worker-review', ['worker' => $validated->reference, 'decision' => 'valider'])->assertSuccessful();

        $this->getJson('/api/v1/commercial/home')
            ->assertJsonPath('data.objective.label', '1 / 3')
            ->assertJsonPath('data.objective.percent', 33)
            ->assertJsonPath('data.objective.message', 'Plus que 2 ouvriers validés pour atteindre votre objectif.');
        $this->getJson('/api/v1/commercial/objectives')
            ->assertOk()
            ->assertJsonPath('data.current.target', 3)
            ->assertJsonPath('data.current.achieved', 1)
            ->assertJsonCount(1, 'data.history');
    }

    public function test_the_ranking_is_computed_on_the_workers_validated_this_month(): void
    {
        $leader = CommercialProfile::factory()->for(User::factory()->state(['user_type' => 'commercial', 'first_name' => 'Ange', 'last_name' => 'Koffi']))->create();
        $this->validatedWorkerOf($leader, '0700000031');
        $this->validatedWorkerOf($leader, '0700000032');
        CommercialProfile::factory()->for(User::factory()->state(['user_type' => 'commercial', 'first_name' => 'Zoé', 'last_name' => 'Yao']))->create();
        $worker = $this->submit('0700000033', 'Paul', 'N’Guessan');
        $this->artisan('ochantier:simulate-worker-review', ['worker' => $worker->reference, 'decision' => 'valider'])->assertSuccessful();

        $this->getJson('/api/v1/commercial/ranking')
            ->assertOk()
            ->assertJsonPath('data.rank', 2)
            ->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.label', '2ᵉ sur 3 commerciaux')
            ->assertJsonPath('data.validated', 1)
            ->assertJsonPath('data.leaders.*.name', ['Ange Koffi', 'Adama Coulibaly', 'Zoé Yao'])
            ->assertJsonPath('data.leaders.1.is_me', true)
            ->assertJsonPath('data.published', []);
    }

    private function submit(string $phone, string $first, string $last): Worker
    {
        $id = $this->readyProspect(['phone_number' => $phone, 'first_name' => $first, 'last_name' => $last]);
        $this->postJson("/api/v1/commercial/prospects/{$id}/submit")->assertOk();

        return Worker::query()->where('prospect_id', $id)->sole();
    }

    private function validatedWorkerOf(CommercialProfile $commercial, string $phone): Worker
    {
        return Worker::query()->create([
            'reference' => 'OUV-R'.substr($phone, -4),
            'origin_commercial_id' => $commercial->id,
            'current_commercial_id' => $commercial->id,
            'first_name' => 'Ouvrier',
            'last_name' => $phone,
            'dial_code' => '225',
            'phone_number' => $phone,
            'residence_commune_id' => $this->commune(),
            'estimated_daily_rate' => 15000,
            'verification_status' => WorkerVerificationStatus::Valide,
            'validated_at' => now(),
        ]);
    }
}
