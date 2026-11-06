<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\Rating;
use App\Support\Auth\AdminSessions;
use App\Support\Auth\DeviceContext;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Promoter\ConfirmedSite;
use Tests\Feature\Promoter\PromoterTestCase;

/**
 * API du Back Office, module Avis & litiges : avis et modération.
 */
class RatingAdminTest extends PromoterTestCase
{
    use ConfirmedSite;

    private Admin $moderator;

    private Rating $good;

    private Rating $bad;

    protected function setUp(): void
    {
        parent::setUp();

        $this->confirmSite();
        $this->moderator = Admin::factory()->role('operations')->create();
        $this->onWorkDay(10);
        $this->rate('Kouamé Koffi', 5, 'Travail exceptionnel', 'Très bon maçon');
        $this->rate('Touré Ali', 1, 'Travail bâclé', 'Commentaire déplacé');
        $this->good = Rating::query()->where('assignment_id', $this->assignmentOf('Kouamé Koffi')->id)->firstOrFail();
        $this->bad = Rating::query()->where('assignment_id', $this->assignmentOf('Touré Ali')->id)->firstOrFail();
    }

    private function as(Admin $admin): static
    {
        return $this->withAccessToken(app(AdminSessions::class)->open($admin, new DeviceContext('Chrome', null, 'web', null, '127.0.0.1', 'phpunit'))->accessToken);
    }

    private function rate(string $worker, int $score, string $tag, ?string $comment = null): TestResponse
    {
        return $this->postJson("/api/v1/promoter/sites/{$this->site->id}/ratings", ['scope' => 'individuel', 'assignment_id' => $this->assignmentOf($worker)->id, 'score' => $score, 'satisfaction_tag' => $tag, 'comment' => $comment])->assertCreated();
    }

    private function url(Rating $rating, string $action = ''): string
    {
        return "/api/v1/admin/ratings/{$rating->id}".($action ? "/{$action}" : '');
    }

    public function test_the_list_shows_ratings_with_filters_and_counts(): void
    {
        $this->as($this->moderator)->getJson('/api/v1/admin/ratings')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.counts.published', 2)
            ->assertJsonPath('meta.counts.average', 3)
            ->assertJsonPath('data.0.site.title', $this->site->label);

        $this->getJson('/api/v1/admin/ratings?score=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.target.name', 'Touré Ali');
        $this->getJson('/api/v1/admin/ratings?search=maçon')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->good->id);

        $this->getJson($this->url($this->good))->assertOk()->assertJsonPath('data.can', ['report' => true, 'keep' => false, 'hide' => true, 'restore' => false]);
    }

    public function test_a_reported_rating_comes_first_and_can_be_kept(): void
    {
        $this->as($this->moderator)->postJson($this->url($this->good, 'report'), ['reason' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson($this->url($this->good, 'report'), ['reason' => 'Doute sur l’auteur'])
            ->assertOk()
            ->assertJsonPath('data.moderation.value', 'signale')
            ->assertJsonPath('data.can.keep', true);
        $this->postJson($this->url($this->good, 'report'), ['reason' => 'Doute sur l’auteur'])->assertUnprocessable()->assertJsonValidationErrors('rating');

        $this->getJson('/api/v1/admin/ratings')->assertOk()->assertJsonPath('data.0.id', $this->good->id)->assertJsonPath('meta.counts.reported', 1);

        $this->postJson($this->url($this->good, 'keep'), ['reason' => 'Auteur vérifié'])
            ->assertOk()
            ->assertJsonPath('data.moderation.value', 'maintenu')
            ->assertJsonPath('data.status.value', 'publie')
            ->assertJsonPath('data.history.0.reason', 'Auteur vérifié');
        $this->postJson($this->url($this->good, 'keep'), ['reason' => 'Auteur vérifié'])->assertUnprocessable();
    }

    public function test_hiding_removes_a_rating_from_the_worker_average_and_restoring_brings_it_back(): void
    {
        $worker = $this->bad->worker;
        $worker->update(['rating_avg' => 3, 'rating_count' => 3]);

        $this->as($this->moderator)->postJson($this->url($this->bad, 'hide'), ['reason' => 'Propos injurieux'])
            ->assertOk()
            ->assertJsonPath('data.status.value', 'masque')
            ->assertJsonPath('data.comment', 'Commentaire déplacé');

        $worker->refresh();
        $this->assertEquals(4, $worker->rating_avg, 'Les notes antérieures restent dans la moyenne.');
        $this->assertSame(2, (int) $worker->rating_count);
        $this->assertTrue(Notification::query()->where('event_code', 'rating.moderated')->where('body', 'like', '%Propos injurieux%')->exists());

        $this->actingAsPromoter($this->promoter)->getJson('/api/v1/promoter/ratings')->assertOk()->assertJsonPath('meta.count', 1);
        $this->putJson("/api/v1/promoter/ratings/{$this->bad->id}", ['score' => 5, 'satisfaction_tag' => 'Travail exceptionnel'])->assertUnprocessable();

        $this->as($this->moderator)->postJson($this->url($this->bad, 'hide'), ['reason' => 'Propos injurieux'])->assertUnprocessable();
        $this->postJson($this->url($this->bad, 'restore'), ['reason' => 'Propos finalement acceptables'])->assertOk()->assertJsonPath('data.status.value', 'publie');

        $worker->refresh();
        $this->assertSame(3, (int) $worker->rating_count);
        $this->assertEquals(3, $worker->rating_avg);
    }

    public function test_suspicion_is_traced_and_does_not_change_publication(): void
    {
        $this->as($this->moderator)->postJson($this->url($this->good, 'suspicion'), ['suspected' => true, 'reason' => 'Avis coordonnés'])
            ->assertOk()
            ->assertJsonPath('data.suspected', true)
            ->assertJsonPath('data.verification.value', 'suspect')
            ->assertJsonPath('data.status.value', 'publie');

        $this->getJson('/api/v1/admin/ratings?suspected=1')->assertOk()->assertJsonCount(1, 'data');

        $this->postJson($this->url($this->good, 'suspicion'), ['suspected' => false, 'reason' => 'Contrôle effectué'])->assertOk()->assertJsonPath('data.verification.value', 'verifie');
        $this->assertSame(2, AuditLog::query()->where('module', 'ratings')->count());
    }

    public function test_rights_separate_reporting_from_deciding(): void
    {
        $direction = Admin::factory()->role('direction')->create();

        $this->as($direction)->getJson($this->url($this->good))->assertOk();
        $this->postJson($this->url($this->good, 'report'), ['reason' => 'Doute sur l’auteur'])->assertForbidden();
        $this->postJson($this->url($this->good, 'hide'), ['reason' => 'Propos injurieux'])->assertForbidden();
    }
}
