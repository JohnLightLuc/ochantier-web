<?php

namespace Tests\Feature\Promoter;

use App\Enums\MissionStatus;
use App\Enums\SiteStatus;
use App\Models\Rating;
use App\Models\RatingVersion;
use App\Models\User;
use Illuminate\Testing\TestResponse;

class RatingTest extends PromoterTestCase
{
    use ConfirmedSite;

    protected function setUp(): void
    {
        parent::setUp();

        $this->confirmSite();
    }

    public function test_workers_become_rateable_once_their_mission_has_ended(): void
    {
        $this->getJson("/api/v1/promoter/sites/{$this->site->id}/ratings")
            ->assertOk()
            ->assertJsonPath('data.show', false)
            ->assertJsonPath('data.total', 0)
            ->assertJsonPath('data.team', null)
            ->assertJsonPath('meta.tags.individuel.4', ['Ponctuel', 'Travail soigné', 'Respect des consignes', 'Bonne communication'])
            ->assertJsonPath('meta.labels.5', 'Excellent');

        $this->rate('Kouamé Koffi', 4, 'Travail soigné')
            ->assertJsonValidationErrors(['assignment_id' => 'Vous pourrez noter cet ouvrier à la fin de sa mission.']);
        $this->rateTeam(5, 'Équipe exemplaire')
            ->assertJsonValidationErrors(['scope' => 'Vous pourrez noter l’équipe à la fin du chantier.']);

        $this->afterPlannedEnd();

        $this->getJson("/api/v1/promoter/sites/{$this->site->id}/ratings")
            ->assertJsonPath('data.show', true)
            ->assertJsonPath('data.title', 'Missions terminées · à évaluer')
            ->assertJsonPath('data.subtitle', 'Notez les ouvriers dont la mission est terminée (0/3)')
            ->assertJsonPath('data.workers.*.name', ['Kouamé Koffi', 'Touré Ali', 'Serge Aka'])
            ->assertJsonPath('data.workers.0.can_rate', true)
            ->assertJsonPath('data.workers.0.subtitle', 'Maçon · mission terminée le '.$this->startDate->addDays(9)->locale('fr')->translatedFormat('j M Y'))
            ->assertJsonPath('data.team', null);
    }

    public function test_a_worker_rating_is_published_then_edited_with_its_history(): void
    {
        $this->afterPlannedEnd();
        $worker = $this->assignmentOf('Kouamé Koffi')->worker;

        $this->rate('Kouamé Koffi', 5, 'Ponctuel')
            ->assertJsonValidationErrors(['satisfaction_tag' => 'Sélectionnez une satisfaction proposée pour cette note.']);
        $this->rate('Kouamé Koffi', 0, 'Ponctuel')->assertJsonValidationErrors(['score' => 'Choisissez une note de 1 à 5.']);

        $rating = $this->rate('Kouamé Koffi', 4, 'Travail soigné', '  Très bon maçon  ')
            ->assertCreated()
            ->assertJsonPath('message', 'Merci, votre avis est publié')
            ->assertJsonPath('data.rated_count', 1)
            ->assertJsonPath('data.workers.0.rating.stars', '★★★★☆')
            ->assertJsonPath('data.workers.0.rating.tone', 'positive')
            ->assertJsonPath('data.workers.0.can_rate', false)
            ->assertJsonPath('meta.rating.summary', 'Travail soigné — Très bon maçon')
            ->assertJsonPath('meta.rating.editable', true)
            ->json('meta.rating.id');

        $worker->refresh();
        $this->assertSame('4.65', $worker->rating_avg);
        $this->assertSame(13, $worker->rating_count);

        $this->rate('Kouamé Koffi', 3, 'Travail correct')
            ->assertJsonValidationErrors(['assignment_id' => 'Vous avez déjà noté cet ouvrier : modifiez votre avis.']);

        $this->putJson("/api/v1/promoter/ratings/{$rating}", ['score' => 2, 'satisfaction_tag' => 'Retards fréquents'])
            ->assertOk()
            ->assertJsonPath('message', 'Votre avis est modifié')
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.tone', 'negative')
            ->assertJsonPath('data.comment', null);

        $version = RatingVersion::query()->sole();
        $this->assertSame([1, 4, 'Travail soigné', 'Très bon maçon'], [$version->version, $version->overall_score, $version->satisfaction_tag, $version->comment]);
        $this->assertSame('4.50', $worker->refresh()->rating_avg);
        $this->assertSame(13, $worker->rating_count);

        $this->travelTo(now()->addDays(31));
        $this->actingAsPromoter($this->promoter);
        $this->putJson("/api/v1/promoter/ratings/{$rating}", ['score' => 5, 'satisfaction_tag' => 'À recommander'])
            ->assertJsonValidationErrors(['rating' => 'Cet avis ne peut plus être modifié.']);
    }

    public function test_the_team_is_rated_once_the_site_has_ended(): void
    {
        $this->onWorkDay(2);
        $this->artisan('ochantier:simulate-site-end', ['site' => $this->site->reference])
            ->expectsOutput('Résidence Les Palmiers : chantier terminé, 2 mission(s) clôturée(s).')
            ->assertSuccessful();

        $this->assertSame(SiteStatus::Termine, $this->site->refresh()->status);
        $this->assertSame([MissionStatus::Terminee], $this->site->missions->pluck('status')->unique()->values()->all());

        $this->getJson("/api/v1/promoter/sites/{$this->site->id}/ratings")
            ->assertJsonPath('data.title', 'Évaluer l’équipe')
            ->assertJsonPath('data.subtitle', 'Chantier terminé — notez chaque ouvrier et l’équipe (0/3 notés)')
            ->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.team.title', 'Équipe globale')
            ->assertJsonPath('data.team.subtitle', '3 ouvriers · Résidence Les Palmiers')
            ->assertJsonPath('data.team.can_rate', true);

        $this->rateTeam(4, 'Équipe exemplaire')->assertJsonValidationErrors(['satisfaction_tag']);
        $this->rateTeam(5, 'Équipe exemplaire', 'Livré en avance')
            ->assertCreated()
            ->assertJsonPath('data.team.rating.score', 5)
            ->assertJsonPath('data.team.rating.summary', 'Équipe exemplaire — Livré en avance')
            ->assertJsonPath('data.team.can_rate', false);
        $this->rateTeam(4, 'Bonne coordination')
            ->assertJsonValidationErrors(['scope' => 'Vous avez déjà noté l’équipe : modifiez votre avis.']);

        $rating = Rating::query()->sole();
        $this->assertSame([$this->site->id, null, null], [$rating->site_id, $rating->team_id, $rating->worker_id]);

        $this->artisan('ochantier:simulate-site-end', ['site' => $this->site->reference])->assertFailed();
    }

    public function test_notes_and_reviews_list_the_published_ratings(): void
    {
        $this->artisan('ochantier:simulate-site-end', ['site' => $this->site->reference])->assertSuccessful();
        $this->rate('Kouamé Koffi', 5, 'À recommander')->assertCreated();
        $this->travel(1)->minutes();
        $this->rate('Serge Aka', 3, 'Finitions à améliorer')->assertCreated();
        $this->rateTeam(4, 'Bonne coordination')->assertCreated();

        $this->getJson('/api/v1/promoter/ratings')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.average', 4)
            ->assertJsonPath('meta.average_label', '4,0')
            ->assertJsonPath('meta.count_label', '3 avis publiés')
            ->assertJsonPath('meta.tile_label', '3 avis · 4,0 ★')
            ->assertJsonPath('meta.distribution.*.count', [1, 1, 1, 0, 0])
            ->assertJsonPath('meta.distribution.0.percent', 33)
            ->assertJsonPath('meta.counts', ['all' => 3, 'individuel' => 2, 'equipe' => 1]);

        $this->getJson('/api/v1/promoter/ratings?scope=equipe')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.kind', 'team')
            ->assertJsonPath('data.0.name', 'Équipe · Résidence Les Palmiers')
            ->assertJsonPath('data.0.subtitle', '3 ouvriers');

        $this->getJson('/api/v1/promoter/ratings?scope=individuel')
            ->assertJsonPath('data.*.name', ['Serge Aka', 'Kouamé Koffi'])
            ->assertJsonPath('data.0.subtitle', 'Peintre · Résidence Les Palmiers')
            ->assertJsonPath('data.0.tone', 'neutral');

        $this->getJson('/api/v1/promoter/ratings?scope=autre')->assertJsonValidationErrors(['scope' => 'Filtre inconnu.']);
    }

    public function test_ratings_of_other_promoters_are_out_of_reach(): void
    {
        $this->afterPlannedEnd();
        $rating = $this->rate('Kouamé Koffi', 4, 'Ponctuel')->json('meta.rating.id');

        $this->actingAsPromoter(User::factory()->promoter()->create());
        $this->putJson("/api/v1/promoter/ratings/{$rating}", ['score' => 1, 'satisfaction_tag' => 'Travail bâclé'])->assertNotFound();
        $this->getJson("/api/v1/promoter/sites/{$this->site->id}/ratings")->assertNotFound();
        $this->getJson('/api/v1/promoter/ratings')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.tile_label', 'Aucun avis');
    }

    /**
     * Lendemain de la fin prévue des missions (10 jours à partir du démarrage).
     */
    private function afterPlannedEnd(): void
    {
        $this->onWorkDay(10);
    }

    private function rate(string $worker, int $score, string $tag, ?string $comment = null): TestResponse
    {
        return $this->postJson("/api/v1/promoter/sites/{$this->site->id}/ratings", [
            'scope' => 'individuel',
            'assignment_id' => $this->assignmentOf($worker)->id,
            'score' => $score,
            'satisfaction_tag' => $tag,
            'comment' => $comment,
        ]);
    }

    private function rateTeam(int $score, string $tag, ?string $comment = null): TestResponse
    {
        return $this->postJson("/api/v1/promoter/sites/{$this->site->id}/ratings", ['scope' => 'equipe', 'score' => $score, 'satisfaction_tag' => $tag, 'comment' => $comment]);
    }
}
