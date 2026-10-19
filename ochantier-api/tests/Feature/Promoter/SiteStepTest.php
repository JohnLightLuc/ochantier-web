<?php

namespace Tests\Feature\Promoter;

use App\Models\SiteStep;
use App\Models\User;

class SiteStepTest extends PromoterTestCase
{
    use ConfirmedSite;

    public function test_the_chronology_is_locked_until_the_deposit_is_paid(): void
    {
        $site = $this->postJson('/api/v1/promoter/sites', ['title' => 'Villa Riviera'])->assertCreated()->json('data');

        $this->getJson("/api/v1/promoter/sites/{$site['id']}/steps")
            ->assertOk()
            ->assertJsonPath('data.editable', false)
            ->assertJsonPath('data.lock_message', 'Disponible après validation de la réservation (paiement de l’acompte)')
            ->assertJsonPath('data.count_label', 'Aucune étape');

        $this->postJson("/api/v1/promoter/sites/{$site['id']}/steps", ['title' => 'Fondations'])
            ->assertJsonValidationErrors(['step' => 'La chronologie sera disponible après le paiement de l’acompte.']);
    }

    public function test_steps_are_started_then_validated_one_at_a_time(): void
    {
        $this->confirmSite();
        $url = "/api/v1/promoter/sites/{$this->site->id}/steps";

        $this->postJson($url, ['title' => ''])->assertJsonValidationErrors(['title' => 'Donnez un nom à l’étape.']);
        $this->postJson($url, ['title' => 'Fondations'])->assertCreated()->assertJsonPath('message', 'Étape ajoutée : Fondations');
        $this->postJson($url, ['title' => 'Gros œuvre'])->assertCreated();
        $this->postJson($url, ['title' => 'Toiture'])
            ->assertJsonPath('data.count_label', '0/3 étapes validées')
            ->assertJsonPath('data.steps.*.sequence', [1, 2, 3])
            ->assertJsonPath('data.steps.0.actions', ['demarrer', 'retirer']);

        [$foundations, $structure, $roof] = SiteStep::query()->orderBy('sequence')->get()->all();

        $this->postJson("{$url}/{$foundations->id}/start")
            ->assertOk()
            ->assertJsonPath('data.steps.0.status.value', 'en_cours')
            ->assertJsonPath('data.steps.0.subtitle', 'En cours depuis le '.today()->locale('fr')->translatedFormat('j M Y'))
            ->assertJsonPath('data.steps.0.actions', ['valider']);

        $this->postJson("{$url}/{$structure->id}/start")->assertJsonValidationErrors(['step' => 'Validez d’abord « Fondations ».']);
        $this->deleteJson("{$url}/{$foundations->id}")->assertJsonValidationErrors(['step' => 'Une étape démarrée ne peut plus être retirée.']);
        $this->postJson("{$url}/{$structure->id}/complete")->assertJsonValidationErrors(['step' => 'Démarrez d’abord cette étape.']);

        $this->postJson("{$url}/{$foundations->id}/complete")
            ->assertOk()
            ->assertJsonPath('message', 'Étape validée : Fondations')
            ->assertJsonPath('data.done', 1)
            ->assertJsonPath('data.progress_percent', 33)
            ->assertJsonPath('data.steps.0.actions', []);

        $this->deleteJson("{$url}/{$roof->id}")->assertOk()->assertJsonPath('data.count_label', '1/2 étapes validées');

        $this->getJson("/api/v1/promoter/sites/{$this->site->id}/tracking")
            ->assertJsonPath('data.kpis.steps_done', 1)
            ->assertJsonPath('data.kpis.steps_total', 2)
            ->assertJsonPath('data.chronology.count_label', '1/2 étapes validées');
    }

    public function test_steps_of_other_sites_are_out_of_reach(): void
    {
        $this->confirmSite();
        $step = $this->postJson("/api/v1/promoter/sites/{$this->site->id}/steps", ['title' => 'Fondations'])->json('data.steps.0.id');
        $other = $this->postJson('/api/v1/promoter/sites', ['title' => 'Villa Riviera'])->json('data.id');

        $this->postJson("/api/v1/promoter/sites/{$other}/steps/{$step}/start")->assertNotFound();

        $this->actingAsPromoter(User::factory()->promoter()->create());
        $this->postJson("/api/v1/promoter/sites/{$this->site->id}/steps/{$step}/start")->assertNotFound();
    }
}
