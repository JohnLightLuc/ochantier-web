<?php

namespace Tests\Feature\Admin;

use App\Enums\RatingScope;
use App\Enums\RatingStatus;
use App\Models\Admin;
use App\Models\Rating;
use App\Models\User;
use App\Models\Worker;
use App\Support\Auth\AdminSessions;
use App\Support\Auth\DeviceContext;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Promoter\ConfirmedSite;
use Tests\Feature\Promoter\PromoterTestCase;

/**
 * API du Back Office, module Ouvriers : onglets en lecture seule de la fiche (disponibilités, affectations,
 * pointages, gains, avis, remplacements, historique).
 */
class WorkerRelationsAdminTest extends PromoterTestCase
{
    use ConfirmedSite;

    private Worker $worker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->confirmSite();
        $this->worker = $this->assignmentOf('Kouamé Koffi')->worker;
    }

    private function asAdmin(?Admin $admin = null): static
    {
        return $this->withAccessToken(app(AdminSessions::class)->open($admin ?? Admin::factory()->role('operations')->create(), new DeviceContext('Chrome', null, 'web', null, '127.0.0.1', 'phpunit'))->accessToken);
    }

    private function url(string $tab): string
    {
        return "/api/v1/admin/workers/{$this->worker->id}/{$tab}";
    }

    /**
     * Nombre de requêtes SQL exécutées par un appel réussi.
     */
    private function queriesFor(string $url): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson($url)->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }

    /**
     * Premier jour du chantier : Kouamé pointe son arrivée et son départ, les deux autres sont absents, la journée
     * est validée par le promoteur.
     */
    private function workFirstDay(): void
    {
        $this->onWorkDay();
        $kouame = $this->assignmentOf('Kouamé Koffi');
        $this->point($kouame)->assertCreated();
        $this->travelTo(now()->setTimeFromTimeString('17:00'));
        $this->point($kouame, 'depart')->assertCreated();

        foreach (['Touré Ali', 'Serge Aka'] as $name) {
            $this->postJson("/api/v1/promoter/sites/{$this->site->id}/attendance/absences", ['assignment_id' => $this->assignmentOf($name)->id])->assertOk();
        }

        $this->postJson("/api/v1/promoter/sites/{$this->site->id}/attendance/".today()->toDateString().'/validate')->assertOk();
    }

    public function test_the_availability_and_assignments_show_the_confirmed_mission(): void
    {
        $this->asAdmin()->getJson($this->url('availability'))
            ->assertOk()
            ->assertJsonPath('data.current', null)
            ->assertJsonPath('data.upcoming.0.site.title', 'Résidence Les Palmiers')
            ->assertJsonPath('data.upcoming.0.status.value', 'confirmee');

        $this->getJson($this->url('assignments'))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.counts.confirmee', 1)
            ->assertJsonPath('data.0.site.title', 'Résidence Les Palmiers')
            ->assertJsonPath('data.0.trade', 'Maçon')
            ->assertJsonPath('data.0.worker_net_daily_rate', $this->assignmentOf('Kouamé Koffi')->worker_net_daily_rate);
        $this->getJson($this->url('assignments').'?status=terminee')->assertJsonPath('meta.total', 0);
    }

    public function test_validated_days_become_attendance_rows_and_earnings(): void
    {
        $this->workFirstDay();
        $rate = $this->assignmentOf('Kouamé Koffi')->worker_net_daily_rate;

        $this->asAdmin()->getJson($this->url('attendance'))
            ->assertOk()
            ->assertJsonPath('data.0.arrival', '08:00')
            ->assertJsonPath('data.0.departure', '17:00')
            ->assertJsonPath('data.0.status.value', 'valide')
            ->assertJsonPath('meta.counts.valide', 1)
            ->assertJsonPath('meta.presence_rate', 100);

        $this->getJson($this->url('earnings'))
            ->assertOk()
            ->assertJsonPath('data.0.validated_days', 1)
            ->assertJsonPath('data.0.earned', $rate)
            ->assertJsonPath('meta.totals', ['earned' => $rate, 'paid' => 0, 'remaining' => $rate])
            ->assertJsonPath('meta.payouts', []);

        $absent = $this->assignmentOf('Touré Ali')->worker;
        $this->getJson("/api/v1/admin/workers/{$absent->id}/attendance")->assertJsonPath('meta.presence_rate', 0)->assertJsonPath('data.0.status.value', 'absent');
    }

    public function test_ratings_replacements_and_history(): void
    {
        $assignment = $this->assignmentOf('Kouamé Koffi');
        Rating::query()->create(['rating_scope' => RatingScope::Individuel, 'mission_id' => $assignment->mission_id, 'assignment_id' => $assignment->id, 'worker_id' => $this->worker->id, 'site_id' => $this->site->id, 'author_user_id' => $this->promoter->id, 'overall_score' => 5, 'comment' => 'Très soigneux', 'status' => RatingStatus::Publie, 'published_at' => now()]);

        $this->asAdmin()->getJson($this->url('ratings'))
            ->assertOk()
            ->assertJsonPath('data.0.score', 5)
            ->assertJsonPath('data.0.site.title', 'Résidence Les Palmiers')
            ->assertJsonPath('meta.average', 5)
            ->assertJsonPath('meta.count', 1);

        $this->getJson($this->url('replacements'))->assertOk()->assertJsonPath('data.unavailabilities', [])->assertJsonPath('data.replaced_others', []);

        $this->postJson("/api/v1/admin/workers/{$this->worker->id}/suspension", ['reason' => 'Retards répétés.'])->assertOk();

        $this->getJson($this->url('history'))
            ->assertOk()
            ->assertJsonPath('data.0.origin.value', 'admin')
            ->assertJsonPath('data.0.what', 'a suspendu l’ouvrier')
            ->assertJsonPath('data.0.detail', 'Retards répétés.')
            ->assertJsonFragment(['what' => 'a affecté l’ouvrier']);
        $this->getJson($this->url('history').'?origin=mission')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.origin.value', 'mission');
        $this->getJson($this->url('history').'?origin=inconnu')->assertJsonValidationErrors(['origin' => 'Filtre inconnu.']);
    }

    public function test_the_history_names_the_commercials_without_one_query_per_status_change(): void
    {
        $record = fn (string $from, string $to) => $this->worker->statusHistories()->create([
            'from_status' => $from,
            'to_status' => $to,
            'actor_type' => (new User)->getMorphClass(),
            'actor_id' => User::factory()->create(['first_name' => 'Awa', 'last_name' => 'Traoré'])->id,
        ]);
        $record('brouillon', 'soumis');
        $this->asAdmin()->getJson($this->url('history'))
            ->assertOk()
            ->assertJsonFragment(['origin' => ['value' => 'commercial', 'label' => 'Commercial'], 'author' => 'Awa Traoré', 'what' => 'a transmis le dossier']);
        $queries = $this->queriesFor($this->url('history'));

        $record('a_corriger', 'soumis');
        $record('a_corriger', 'soumis');

        $this->assertSame($queries, $this->queriesFor($this->url('history')));
    }

    public function test_the_tabs_need_the_read_permission(): void
    {
        foreach (['availability', 'assignments', 'attendance', 'earnings', 'ratings', 'replacements', 'history'] as $tab) {
            $this->asAdmin(Admin::factory()->create())->getJson($this->url($tab))->assertForbidden();
        }
    }
}
