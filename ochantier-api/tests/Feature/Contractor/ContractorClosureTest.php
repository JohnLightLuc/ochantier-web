<?php

namespace Tests\Feature\Contractor;

use App\Enums\AssignmentStatus;
use App\Enums\ContractorVerificationStatus;
use App\Enums\StaffingRequestStatus;
use App\Models\Assignment;
use App\Models\Notification;
use App\Models\Offer;
use App\Models\Rating;
use App\Models\Site;
use App\Models\StaffingRequest;
use App\Models\Worker;
use Carbon\CarbonImmutable;

class ContractorClosureTest extends ContractorMarketTestCase
{
    private Offer $offer;

    private string $site;

    private Worker $mason;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contractor->update(['verification_status' => ContractorVerificationStatus::Valide]);
        $this->offer = $this->publishedContract('Résidence Les Acacias', 'Cocody');
        $this->site = $this->offer->site_id;

        $bid = $this->asContractor()->postJson("/api/v1/contractor/opportunities/offers/{$this->offer->id}/response", [
            'mode' => 'contre_proposition', 'amount' => 3000000, 'start_date' => $this->day(10), 'end_date' => $this->day(39), 'team_size' => 6,
        ])->json('data.id');
        $this->asPromoter()->postJson("/api/v1/promoter/sites/{$this->site}/contractor-offers/{$bid}/choose")->assertOk();
        $this->postJson('/api/v1/promoter/payments', ['contractor_bid_id' => $bid, 'operator_code' => 'orange_money', 'dial_code' => '225', 'phone_number' => '0712349999'], ['Idempotency-Key' => 'acompte-cloture'])->assertCreated();

        $this->mason = $this->worker('Yao Koffi', 'macon', 20000);
        $pending = $this->worker('Ali Ouattara', 'macon', 15000);
        $this->asContractor()->postJson("/api/v1/contractor/sites/{$this->site}/assignments", [
            'start_date' => $this->day(10), 'end_date' => $this->day(29),
            'workers' => [['worker_id' => $this->mason->id, 'role' => 'Chef maçon', 'daily_rate' => 20000]],
        ])->assertCreated();
        $this->artisan('ochantier:simulate-worker-agreement', ['decision' => 'accepter'])->assertSuccessful();
        $this->postJson("/api/v1/contractor/sites/{$this->site}/assignments", [
            'start_date' => $this->day(10), 'end_date' => $this->day(29),
            'workers' => [['worker_id' => $pending->id, 'role' => 'Maçon', 'daily_rate' => 15000]],
        ])->assertCreated();
        $this->postJson("/api/v1/contractor/sites/{$this->site}/staffing-requests", [
            'start_date' => $this->day(12), 'days' => 5, 'lines' => [['trade_id' => $this->trade('plombier'), 'headcount' => 1, 'daily_rate' => 18000]],
        ])->assertCreated();
        $this->postJson("/api/v1/contractor/sites/{$this->site}/steps", ['title' => 'Gros œuvre'])->assertCreated();

        $this->travelTo(CarbonImmutable::today()->addDays(15)->setTime(10, 0));
    }

    public function test_closing_the_site_ends_the_team_and_unlocks_ratings_and_report(): void
    {
        $this->asContractor()->getJson("/api/v1/contractor/sites/{$this->site}/report")
            ->assertOk()
            ->assertJsonPath('data.available', false);
        $this->get("/api/v1/contractor/sites/{$this->site}/report/pdf")->assertForbidden();

        $this->artisan('ochantier:simulate-site-end', ['site' => $this->site])->assertSuccessful();

        $this->assertSame(AssignmentStatus::Terminee, Assignment::query()->where('worker_id', $this->mason->id)->sole()->status);
        $this->assertSame(AssignmentStatus::Annulee, Assignment::query()->where('worker_id', '!=', $this->mason->id)->sole()->status);
        $this->assertSame(StaffingRequestStatus::Annulee, StaffingRequest::query()->sole()->status);
        $this->assertSame(1, $this->contractor->refresh()->completed_sites_count);
        $this->assertTrue(Notification::query()->where('recipient_id', $this->user->id)->where('event_code', 'site.ended')->exists());

        $this->asContractor()->getJson('/api/v1/contractor/sites?tab=termines')->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/contractor/sites/{$this->site}")
            ->assertJsonPath('data.stage.value', 'termine')
            ->assertJsonPath('data.closure.report_available', true)
            ->assertJsonPath('data.closure.evaluation.total', 1);

        $summary = $this->getJson("/api/v1/contractor/sites/{$this->site}/ratings")
            ->assertOk()
            ->assertJsonPath('data.title', 'Évaluer l’équipe')
            ->assertJsonPath('data.workers.0.name', 'Yao Koffi')
            ->assertJsonPath('data.team.can_rate', true)
            ->assertJsonPath('meta.labels.5', 'Excellent')
            ->json('data');

        $this->postJson("/api/v1/contractor/sites/{$this->site}/ratings", ['scope' => 'individuel', 'assignment_id' => $summary['workers'][0]['assignment_id'], 'score' => 5, 'satisfaction_tag' => 'Ponctuel'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.satisfaction_tag.0', 'Sélectionnez une satisfaction proposée pour cette note.');
        $rating = $this->postJson("/api/v1/contractor/sites/{$this->site}/ratings", ['scope' => 'individuel', 'assignment_id' => $summary['workers'][0]['assignment_id'], 'score' => 5, 'satisfaction_tag' => 'Très professionnel', 'comment' => 'Excellent chef d’équipe.'])
            ->assertCreated()
            ->assertJsonPath('data.rated_count', 1)
            ->json('data.rating.id');
        $this->postJson("/api/v1/contractor/sites/{$this->site}/ratings", ['scope' => 'equipe', 'score' => 4, 'satisfaction_tag' => 'Bonne coordination'])->assertCreated();

        $this->assertSame(5.0, (float) $this->mason->refresh()->rating_avg);
        $this->putJson("/api/v1/contractor/ratings/{$rating}", ['score' => 4, 'satisfaction_tag' => 'Ponctuel'])
            ->assertOk()
            ->assertJsonPath('data.score', 4)
            ->assertJsonPath('data.version', 2);

        $report = $this->getJson("/api/v1/contractor/sites/{$this->site}/report")
            ->assertOk()
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.kpis.2.value', '0/1')
            ->assertJsonPath('data.finances.lines.0.amount', 3000000)
            ->assertJsonPath('data.evaluation.label', '1/1 ouvriers notés · équipe : 4/5')
            ->assertJsonPath('data.signature.contractor', 'Yao Serge Koffi')
            ->json('data');
        $this->assertSame('Payé pour les recherches d’effectifs', $report['finances']['lines'][4]['label']);

        $pdf = $this->get("/api/v1/contractor/sites/{$this->site}/report/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }

    public function test_the_promoter_team_rating_updates_the_contractor_average(): void
    {
        $this->artisan('ochantier:simulate-site-end', ['site' => $this->site])->assertSuccessful();

        $rating = $this->asPromoter()->postJson("/api/v1/promoter/sites/{$this->site}/ratings", ['scope' => 'equipe', 'score' => 5, 'satisfaction_tag' => 'Équipe exemplaire'])
            ->assertCreated()
            ->json('data.rating.id') ?? Rating::query()->where('author_user_id', $this->promoter->id)->value('id');

        $this->contractor->refresh();
        $this->assertSame(5.0, (float) $this->contractor->rating_avg);
        $this->assertSame(1, $this->contractor->rating_count);

        $this->putJson("/api/v1/promoter/ratings/{$rating}", ['score' => 3, 'satisfaction_tag' => 'Résultat correct'])->assertOk();
        $this->assertSame(3.0, (float) $this->contractor->refresh()->rating_avg);
        $this->assertSame(1, $this->contractor->rating_count);
        $this->assertSame('termine', Site::query()->findOrFail($this->site)->status->value);
    }

    private function day(int $days): string
    {
        return now()->addDays($days)->toDateString();
    }
}
