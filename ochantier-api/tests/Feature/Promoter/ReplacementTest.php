<?php

namespace Tests\Feature\Promoter;

use App\Enums\AssignmentStatus;
use App\Enums\ReplacementStatus;
use App\Enums\SuggestionStatus;
use App\Enums\UnavailabilityRequestStatus;
use App\Models\Assignment;
use App\Models\Replacement;
use App\Models\Suggestion;
use App\Models\User;

class ReplacementTest extends PromoterTestCase
{
    use ConfirmedSite;

    protected function setUp(): void
    {
        parent::setUp();

        $this->confirmSite();
    }

    public function test_the_promoter_requests_a_replacement_with_a_reason(): void
    {
        $kouame = $this->assignmentOf('Kouamé Koffi');

        $this->postJson("/api/v1/promoter/assignments/{$kouame->id}/replacement", ['reason_code' => 'abandon'])
            ->assertJsonValidationErrors(['reason_code' => 'Motif inconnu.']);

        $this->postJson("/api/v1/promoter/assignments/{$kouame->id}/replacement", ['reason_code' => 'absences_repetees', 'comment' => 'Trois absences cette semaine'])
            ->assertCreated()
            ->assertJsonPath('data.status.value', 'recherche')
            ->assertJsonPath('data.reason.label', 'Absences répétées')
            ->assertJsonPath('data.worker.name', 'Kouamé Koffi')
            ->assertJsonPath('data.message', 'Nous cherchons un remplaçant disponible pour Kouamé Koffi.')
            ->assertJsonPath('data.candidates', []);

        $replacement = Replacement::query()->sole();
        $this->assertEquals(['trade_id' => $kouame->need->trade_id, 'max_promoter_daily_rate' => $kouame->promoter_daily_rate, 'from_date' => today()->addDays(5)->toDateString(), 'to_date' => today()->addDays(14)->toDateString()], $replacement->requested_criteria);
        $this->assertSame(UnavailabilityRequestStatus::RemplacementRecherche, $replacement->unavailabilityRequest->status);

        $this->postJson("/api/v1/promoter/assignments/{$kouame->id}/replacement", ['reason_code' => 'qualite'])
            ->assertJsonValidationErrors(['assignment' => 'Un remplacement de Kouamé Koffi est déjà en cours.']);

        $this->getJson("/api/v1/promoter/sites/{$this->site->id}/team")
            ->assertJsonPath('data.0.replacement.status.value', 'recherche')
            ->assertJsonPath('data.0.can_replace', false);
    }

    public function test_the_chosen_replacement_joins_the_next_day_at_the_same_rate(): void
    {
        $this->onWorkDay(2);
        $kouame = $this->assignmentOf('Kouamé Koffi');
        $this->point($kouame)->assertCreated();
        $replacementId = $this->postJson("/api/v1/promoter/assignments/{$kouame->id}/replacement", ['reason_code' => 'qualite'])->json('data.id');

        $this->worker('Bamba Issa', 'macon', 21000, rating: 4.6);
        $this->worker('Yao Paul', 'macon', 40000, rating: 5.0);
        $this->artisan('ochantier:simulate-replacement')->expectsOutputToContain('1 remplaçant(s) proposé(s)')->assertSuccessful();

        $tomorrow = today()->addDay();
        $candidates = $this->getJson("/api/v1/promoter/replacements/{$replacementId}")
            ->assertOk()
            ->assertJsonPath('data.status.value', 'propose')
            ->assertJsonPath('data.can_decide', true)
            ->assertJsonPath('data.candidates.*.name', ['Bamba Issa'])
            ->assertJsonPath('data.candidates.0.daily_rate', $kouame->promoter_daily_rate)
            ->assertJsonPath('data.message', 'Choisissez un remplaçant : il rejoint le chantier le '.$tomorrow->locale('fr')->translatedFormat('j M Y').'. Le pointage et la rémunération de Kouamé Koffi s’arrêtent la veille au soir.')
            ->json('data.candidates');

        $this->postJson("/api/v1/promoter/replacements/{$replacementId}/accept", ['suggestion_id' => fake()->uuid()])
            ->assertJsonValidationErrors(['suggestion_id' => 'Choisissez un remplaçant parmi ceux proposés.']);

        $this->postJson("/api/v1/promoter/replacements/{$replacementId}/accept", ['suggestion_id' => $candidates[0]['suggestion_id']])
            ->assertOk()
            ->assertJsonPath('message', 'Bamba Issa remplace Kouamé Koffi')
            ->assertJsonPath('data.status.value', 'valide')
            ->assertJsonPath('data.replacement.replaces', 'Kouamé Koffi')
            ->assertJsonPath('data.handover_date', $tomorrow->toDateString());

        $new = Assignment::query()->whereKeyNot($kouame->id)->where('replaces_assignment_id', $kouame->id)->sole();
        $this->assertSame(AssignmentStatus::Confirmee, $new->status);
        $this->assertSame($tomorrow->toDateString(), $new->planned_start_date->toDateString());
        $this->assertSame($kouame->promoter_daily_rate, $new->promoter_daily_rate);
        $this->assertSame(21000, $new->worker_net_daily_rate);
        $this->assertSame(AssignmentStatus::Remplacee, $kouame->refresh()->status);
        $this->assertSame(today()->endOfDay()->toDateTimeString(), $kouame->actual_end_at->toDateTimeString());
        $this->assertSame(SuggestionStatus::Selectionne, Suggestion::query()->whereKey($candidates[0]['suggestion_id'])->value('status'));

        // Kouamé termine sa journée ; Bamba est pointé à partir de demain.
        $this->getJson("/api/v1/promoter/sites/{$this->site->id}/attendance")->assertJsonPath('data.rows.0.name', 'Kouamé Koffi');
        $this->getJson("/api/v1/promoter/sites/{$this->site->id}/team")
            ->assertJsonPath('data.*.name', ['Bamba Issa', 'Touré Ali', 'Serge Aka'])
            ->assertJsonPath('data.0.is_upcoming', true);

        $this->onWorkDay(3);
        $this->getJson("/api/v1/promoter/sites/{$this->site->id}/attendance")->assertJsonPath('data.rows.*.name', ['Bamba Issa', 'Touré Ali', 'Serge Aka']);
        $this->point($kouame)->assertJsonValidationErrors(['assignment_id' => 'Cet ouvrier n’est pas affecté à ce chantier ce jour-là.']);
    }

    public function test_refused_candidates_are_not_proposed_again(): void
    {
        $kouame = $this->assignmentOf('Kouamé Koffi');
        $replacementId = $this->postJson("/api/v1/promoter/assignments/{$kouame->id}/replacement", ['reason_code' => 'indisponibilite'])->json('data.id');

        $this->postJson("/api/v1/promoter/replacements/{$replacementId}/refuse")
            ->assertJsonValidationErrors(['replacement' => 'Aucun remplaçant n’est proposé pour le moment.']);

        $this->worker('Bamba Issa', 'macon', 21000, rating: 4.6);
        $this->artisan('ochantier:simulate-replacement')->assertSuccessful();

        $this->postJson("/api/v1/promoter/replacements/{$replacementId}/refuse", ['comment' => 'Je préfère un ouvrier plus expérimenté'])
            ->assertOk()
            ->assertJsonPath('data.status.value', 'recherche')
            ->assertJsonPath('data.decision.value', 'refuse')
            ->assertJsonPath('data.candidates', []);

        $this->artisan('ochantier:simulate-replacement')->expectsOutputToContain('aucun remplaçant disponible')->assertSuccessful();
        $this->assertSame(ReplacementStatus::Recherche, Replacement::query()->sole()->status);
        $this->assertSame(SuggestionStatus::Ecarte, Suggestion::query()->whereHas('matchingRun', fn ($query) => $query->whereNotNull('replacement_id'))->sole()->status);
    }

    public function test_replacements_of_other_promoters_are_out_of_reach(): void
    {
        $kouame = $this->assignmentOf('Kouamé Koffi');
        $replacementId = $this->postJson("/api/v1/promoter/assignments/{$kouame->id}/replacement", ['reason_code' => 'autre'])->json('data.id');

        $this->actingAsPromoter(User::factory()->promoter()->create());
        $this->postJson("/api/v1/promoter/assignments/{$kouame->id}/replacement", ['reason_code' => 'autre'])->assertNotFound();
        $this->getJson("/api/v1/promoter/replacements/{$replacementId}")->assertNotFound();
    }
}
