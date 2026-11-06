<?php

namespace Tests\Feature;

use App\Support\Api\ApiTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Avis branchés sur l’API (api/v1/admin/ratings).
 */
class RatingTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'http://localhost:8001/api/v1/admin';

    private const RATING = '01a16ab6-0000-7000-8000-0000000000a1';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.ochantier_api.url' => self::API]);
        $this->withSession([]);
        $this->startSession();
        app(ApiTokens::class)->store(['access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'expires_in' => 900, 'session_id' => '9a0e0000-0000-4000-8000-000000000001', 'admin' => ['id' => '1', 'full_name' => 'Awa Opérations', 'email' => 'a.kone@ochantier.ci', 'roles' => [], 'permissions' => ['ratings.read', 'ratings.update', 'ratings.validate']]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rating(): array
    {
        return [
            'id' => self::RATING,
            'scope' => ['value' => 'individuel', 'label' => 'Individuel'],
            'target' => ['kind' => 'worker', 'id' => 'w1', 'name' => 'Touré Ali'],
            'score' => 1,
            'satisfaction_tag' => 'Travail bâclé',
            'comment' => 'Commentaire déplacé',
            'site' => ['id' => 's1', 'title' => 'Résidence Les Palmiers'],
            'promoter' => ['id' => 'p1', 'name' => 'BTP Plateau'],
            'author' => 'Yao Koffi',
            'status' => ['value' => 'publie', 'label' => 'Publié'],
            'moderation' => ['value' => 'signale', 'label' => 'Signalé', 'reason' => 'Propos injurieux', 'by' => 'Awa Opérations'],
            'verification' => ['value' => 'non_verifie', 'label' => 'Non vérifié'],
            'suspected' => false,
            'reported_at' => '2026-11-05T10:00:00+00:00',
            'published_at' => '2026-11-01T10:00:00+00:00',
            'version' => 1,
        ];
    }

    public function test_the_list_translates_filters_and_shows_counts(): void
    {
        Http::fake([self::API.'/ratings?*' => Http::response(['data' => [$this->rating()], 'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'total' => 1, 'counts' => ['published' => 1, 'hidden' => 0, 'reported' => 1, 'suspected' => 0, 'average' => 1.0]]])]);

        $this->get('/avis-litiges/avis?moderation=signale&note=1&type=individuel')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Quality/Ratings')
                ->where('kpis.reported', 1)
                ->where('ratings.data.0.moderation.tone', 'r')
                ->where('filters.note', '1'));

        Http::assertSent(fn (ClientRequest $request) => $request['moderation'] === 'signale' && $request['score'] === '1' && $request['scope'] === 'individuel');
    }

    public function test_the_detail_page_shows_versions_history_and_actions(): void
    {
        Http::fake([self::API.'/ratings/'.self::RATING => Http::response(['data' => [
            ...$this->rating(),
            'versions' => [['version' => 1, 'score' => 2, 'satisfaction_tag' => 'Qualité insuffisante', 'comment' => null, 'date' => '2026-11-01T10:00:00+00:00']],
            'history' => [['id' => '1', 'action' => 'ratings.reported', 'reason' => 'Propos injurieux', 'by' => 'Awa Opérations', 'date' => '2026-11-05T10:00:00+00:00']],
            'can' => ['report' => false, 'keep' => true, 'hide' => true, 'restore' => false],
        ]])]);

        $this->get('/avis-litiges/avis/'.self::RATING)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Quality/Rating')
                ->where('rating.history.0.label', 'Avis signalé')
                ->where('rating.actions.keep', true)
                ->where('can.decide', true));
    }

    public function test_actions_reach_the_api_and_refusals_become_messages(): void
    {
        Http::fake([
            self::API.'/ratings/'.self::RATING.'/hide' => Http::response(['message' => 'Avis masqué.', 'data' => []]),
            self::API.'/ratings/'.self::RATING.'/restore' => Http::response(['message' => 'Refusé.', 'errors' => ['rating' => ['Cet avis n’est pas masqué.']]], 422),
            self::API.'/ratings/'.self::RATING.'/suspicion' => Http::response(['message' => 'Doute levé sur cet avis.', 'data' => []]),
        ]);
        $base = '/avis-litiges/avis/'.self::RATING;

        $this->from($base)->post("{$base}/masquage", ['reason' => 'Propos injurieux'])->assertRedirect($base);
        Http::assertSent(fn (ClientRequest $request) => str_ends_with($request->url(), '/hide') && $request['reason'] === 'Propos injurieux');

        $this->from($base)->post("{$base}/retablissement", ['reason' => 'Vérification faite'])->assertRedirect($base)->assertSessionHasNoErrors();

        $this->from($base)->post("{$base}/suspicion", ['reason' => 'Contrôle effectué', 'suspected' => '0'])->assertRedirect($base);
        Http::assertSent(fn (ClientRequest $request) => str_ends_with($request->url(), '/suspicion') && $request['suspected'] === '0');
    }

    public function test_old_pages_lead_to_the_new_list(): void
    {
        $this->get('/avis-litiges/moderation')->assertRedirect('/avis-litiges/avis?moderation=signale');
        $this->get('/avis-litiges/signalements')->assertRedirect('/avis-litiges/avis?moderation=signale');
        $this->get('/avis-litiges/avis/AV-2026-001/notes')->assertRedirect('/avis-litiges/avis');
    }
}
