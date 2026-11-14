<?php

namespace Tests\Feature\Commercial;

use App\Enums\CommercialEarningStatus;
use App\Enums\WorkerVerificationStatus;
use App\Models\CommercialEarning;
use App\Models\Notification;
use App\Models\User;
use App\Models\VerificationCorrection;
use App\Models\Worker;
use Database\Seeders\AdminAccessSeeder;
use Database\Seeders\PlatformRulesSeeder;
use Illuminate\Support\Str;

class WorkerReviewTest extends CommercialTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([AdminAccessSeeder::class, PlatformRulesSeeder::class]);
    }

    public function test_a_submitted_dossier_is_listed_with_an_estimated_commission(): void
    {
        $worker = $this->submit();

        $earning = CommercialEarning::query()->sole();
        $this->assertSame([CommercialEarningStatus::Estime, 25000, $worker->id], [$earning->status, $earning->amount, $earning->worker_id]);

        $this->getJson('/api/v1/commercial/workers')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.tabs.*.key', ['tous', 'attente', 'corriger', 'valide'])
            ->assertJsonPath('meta.tabs.1.count', 1)
            ->assertJsonPath('data.0.name', 'Jean Kouassi')
            ->assertJsonPath('data.0.trade.code', 'macon')
            ->assertJsonPath('data.0.commune.name', 'Cocody')
            ->assertJsonPath('data.0.status', ['value' => 'attente', 'label' => 'En attente'])
            ->assertJsonPath('data.0.date_label', 'Transmis le '.today()->locale('fr')->translatedFormat('j M Y'))
            ->assertJsonPath('data.0.cta', 'Voir le dossier')
            ->assertJsonPath('data.0.can_correct', false);

        $this->getJson('/api/v1/commercial/workers?filter=valide')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/commercial/workers?search=kouassi')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/commercial/workers?filter=inconnu')->assertJsonValidationErrors(['filter' => 'Filtre inconnu.']);
        $this->patchJson("/api/v1/commercial/prospects/{$worker->prospect_id}", ['daily_rate' => 9000])
            ->assertJsonValidationErrors(['prospect' => 'Dossier transmis : la modification se fait via le Back office.']);
    }

    public function test_a_correction_reopens_the_dossier_until_it_is_sent_again(): void
    {
        $worker = $this->submit();

        $this->artisan('ochantier:simulate-worker-review', ['worker' => $worker->reference, 'decision' => 'corriger', '--motif' => 'Pièce d’identité à remplacer', '--piece' => 'identity'])
            ->expectsOutput("Jean Kouassi ({$worker->reference}) : À corriger.")
            ->assertSuccessful();

        $this->getJson('/api/v1/commercial/workers?filter=corriger')
            ->assertJsonPath('data.0.status.value', 'corriger')
            ->assertJsonPath('data.0.issue', 'Pièce d’identité à remplacer')
            ->assertJsonPath('data.0.cta', 'Corriger')
            ->assertJsonPath('data.0.can_correct', true);
        $this->getJson("/api/v1/commercial/prospects/{$worker->prospect_id}")
            ->assertJsonPath('data.hint', 'Correction demandée par le Back office')
            ->assertJsonPath('data.submission.status.value', 'corriger')
            ->assertJsonPath('data.submission.corrections.0.reason', 'Pièce d’identité à remplacer')
            ->assertJsonPath('data.submission.corrections.0.document_type.code', 'worker_cni')
            ->assertJsonPath('data.submission.can_correct', true)
            ->assertJsonPath('data.can_submit', true);

        $notification = Notification::query()->where('recipient_id', $this->commercial->id)->sole();
        $this->assertSame(['worker.correction', 'Jean Kouassi — Pièce d’identité à remplacer.'], [$notification->event_code, $notification->body]);
        $this->assertStringContainsString('Correction demandée', collect($this->sms->sent)->last()['message']);

        $this->postJson("/api/v1/commercial/prospects/{$worker->prospect_id}/abandon", ['reason_code' => 'injoignable'])
            ->assertJsonValidationErrors(['prospect']);
        $this->uploadIdentity($worker->prospect_id, 'CI0099999999');
        $this->postJson("/api/v1/commercial/prospects/{$worker->prospect_id}/submit")
            ->assertOk()
            ->assertJsonPath('message', 'Dossier corrigé renvoyé au Back office')
            ->assertJsonPath('data.status.value', 'soumis');

        $this->assertSame(WorkerVerificationStatus::Soumis, $worker->refresh()->verification_status);
        $this->assertSame(2, $worker->verificationReviews()->count());
        $this->assertNotNull(VerificationCorrection::query()->sole()->resolved_at);
        $this->assertSame(1, CommercialEarning::query()->count());
        $this->postJson("/api/v1/commercial/prospects/{$worker->prospect_id}/submit")->assertJsonValidationErrors(['prospect']);
    }

    public function test_validation_confirms_the_commission_and_refusal_cancels_it(): void
    {
        $worker = $this->submit();

        $this->artisan('ochantier:simulate-worker-review', ['worker' => $worker->reference, 'decision' => 'valider'])->assertSuccessful();

        $this->assertSame(CommercialEarningStatus::Acquis, CommercialEarning::query()->sole()->status);
        $this->getJson('/api/v1/commercial/workers?filter=valide')
            ->assertJsonPath('data.0.status.label', 'Validé')
            ->assertJsonPath('data.0.cta', 'Voir le profil')
            ->assertJsonPath('data.0.date_label', 'Validé le '.today()->locale('fr')->translatedFormat('j M Y'));
        $this->assertSame('Jean Kouassi a été validé(e). Commission de 25'."\u{00A0}".'000'."\u{00A0}".'FCFA confirmée.', Notification::query()->sole()->body);
        $this->artisan('ochantier:simulate-worker-review', ['worker' => $worker->reference, 'decision' => 'refuser'])->assertFailed();

        $other = $this->submit('0700000077');
        $this->artisan('ochantier:simulate-worker-review', ['worker' => $other->reference, 'decision' => 'refuser', '--motif' => 'Identité non vérifiable'])->assertSuccessful();

        $this->assertSame(CommercialEarningStatus::Annule, CommercialEarning::query()->where('worker_id', $other->id)->sole()->status);
        $this->getJson('/api/v1/commercial/workers')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.status', ['value' => 'refuse', 'label' => 'Refusé'])
            ->assertJsonPath('data.0.issue', 'Identité non vérifiable');
        $this->artisan('ochantier:simulate-worker-review', ['worker' => 'OUV-999999', 'decision' => 'valider'])->assertFailed();
    }

    public function test_the_commercial_opens_the_detail_of_a_submitted_worker(): void
    {
        $worker = $this->submit();

        $this->getJson("/api/v1/commercial/workers/{$worker->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $worker->id)
            ->assertJsonPath('data.prospect_id', $worker->prospect_id)
            ->assertJsonPath('data.reference', $worker->reference)
            ->assertJsonPath('data.name', 'Jean Kouassi')
            ->assertJsonPath('data.status', ['value' => 'attente', 'label' => 'En attente'])
            ->assertJsonPath('data.trade.code', 'macon')
            ->assertJsonPath('data.commune.name', 'Cocody')
            ->assertJsonPath('data.identity.birth_place', 'Bouaké')
            ->assertJsonPath('data.activity.daily_rate', 15000)
            ->assertJsonPath('data.activity.trades.0.trade.code', 'macon')
            ->assertJsonPath('data.activity.trades.0.is_primary', true)
            ->assertJsonPath('data.submission.status.value', 'attente')
            ->assertJsonPath('data.commission', ['amount' => 25000, 'status' => ['value' => 'estime', 'label' => CommercialEarningStatus::Estime->label()]])
            ->assertJsonPath('data.performance', null);

        $this->artisan('ochantier:simulate-worker-review', ['worker' => $worker->reference, 'decision' => 'valider'])->assertSuccessful();

        $this->getJson("/api/v1/commercial/workers/{$worker->id}")
            ->assertJsonPath('data.status.value', 'valide')
            ->assertJsonPath('data.cta', 'Voir le profil')
            ->assertJsonPath('data.commission.status.value', 'acquis')
            ->assertJsonPath('data.performance.rating_count', 0)
            ->assertJsonPath('data.performance.missions_completed', 0);
    }

    public function test_the_detail_of_another_commercials_worker_is_not_found(): void
    {
        $worker = $this->submit();

        $this->actingAsCommercial(User::factory()->commercial()->create(['dial_code' => '225', 'phone_number' => '0500000002']));

        $this->getJson("/api/v1/commercial/workers/{$worker->id}")->assertNotFound();
        $this->getJson('/api/v1/commercial/workers/'.Str::uuid())->assertNotFound();
    }

    private function submit(string $phone = '0700000099'): Worker
    {
        $id = $this->readyProspect(['phone_number' => $phone]);
        $this->postJson("/api/v1/commercial/prospects/{$id}/submit")->assertOk();

        return Worker::query()->where('prospect_id', $id)->sole();
    }
}
