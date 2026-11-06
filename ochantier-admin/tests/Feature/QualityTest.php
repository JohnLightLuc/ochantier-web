<?php

namespace Tests\Feature;

use App\Http\Controllers\DisputeController;
use App\Models\Claim;
use App\Models\Dispute;
use App\Models\QualityActivity;
use App\Models\QualityNotification;
use App\Models\Review;
use App\Models\User;
use App\Support\QualityInsights;
use Database\Seeders\AttendanceSeeder;
use Database\Seeders\FinanceSeeder;
use Database\Seeders\PaymentSeeder;
use Database\Seeders\PromoterSeeder;
use Database\Seeders\QualitySeeder;
use Database\Seeders\SiteSeeder;
use Database\Seeders\WorkerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class QualityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['name' => 'N. Yao']));
        QualityInsights::forgetPrestations();
        $this->seed([PromoterSeeder::class, WorkerSeeder::class, SiteSeeder::class, AttendanceSeeder::class, PaymentSeeder::class, FinanceSeeder::class, QualitySeeder::class]);
    }

    private function dispute(string $status): Dispute
    {
        return Dispute::query()->where('status', $status)->firstOrFail();
    }

    public function test_review_score_and_inclusion_follow_the_weighted_criteria(): void
    {
        $review = Review::query()->where('kind', 'Ouvrier')->where('status', 'Publié')->where('is_test', false)->firstOrFail();
        $review->criteria = ['qualite' => 5, 'ponct' => 3];
        $this->assertSame(round((5 * 20 + 3 * 10) / 30, 2), $review->score());
        $this->assertTrue($review->isCounted());

        $this->assertFalse(Review::query()->where('is_test', true)->firstOrFail()->isCounted());
        $this->assertFalse(Review::query()->whereNotNull('duplicate_of')->firstOrFail()->isCounted());
        $this->assertSame(6, Review::query()->where('kind', 'Interne')->count());
        $this->assertGreaterThan(0, Review::query()->where('kind', 'Équipe')->count());
    }

    public function test_dispute_sla_exposure_and_automatic_priority(): void
    {
        $this->assertSame(22, Dispute::query()->count());
        $this->assertSame('Critique', Dispute::autoPriority('Sécurité', 350000, QualityInsights::today()->format('Y-m-d'), false)['level']);
        $this->assertSame('Faible', Dispute::autoPriority('Autre', 0, QualityInsights::today()->format('Y-m-d'), false)['level']);

        $info = $this->dispute('Informations demandées');
        $this->assertSame('Suspendu avec motif', $info->sla()['status']);
        $this->assertSame('Terminé', $this->dispute('Clôturé')->sla()['status']);

        $absence = Dispute::query()->where('category', 'Absence')->firstOrFail();
        $this->assertGreaterThan(0, $absence->exposure()['suspended']);
        $this->assertSame(0, $this->dispute('Clôturé')->exposure()['amount']);
    }

    public function test_every_page_renders(): void
    {
        $this->get('/avis-litiges')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Quality/Page')->has('kpis', 8)->has('tabs', 3)->has('tiles', 6)->has('charts', 3)->has('sheets.avg'));
        $this->get('/avis-litiges?vue=litiges')->assertOk()->assertInertia(fn (Assert $page) => $page->has('tiles', 12)->has('flow.steps', 5));
        $this->get('/avis-litiges?vue=expo')->assertOk()->assertInertia(fn (Assert $page) => $page->has('flow.steps', 4));
        foreach (['litiges', 'litiges/nouveau', 'mediations', 'recours', 'rapports', 'notifications', 'audit'] as $path) {
            $this->get("/avis-litiges/{$path}")->assertOk()->assertInertia(fn (Assert $page) => $page->component('Quality/Page')->where('missing', null));
        }

        $dispute = $this->dispute('Médiation en cours');
        foreach (array_keys(DisputeController::TABS) as $tab) {
            $this->get("/avis-litiges/litiges/{$dispute->reference}".($tab ? "/{$tab}" : ''))->assertOk()->assertInertia(fn (Assert $page) => $page->has('profile')->has('tabs', 12));
        }
        $this->get('/avis-litiges/litiges/LIT-9999')->assertNotFound();
    }

    public function test_filters_restrict_reviews_and_disputes(): void
    {
        $site = Dispute::query()->with('site')->firstOrFail()->site->name;
        $ins = new QualityInsights(['chantier' => $site, 'periode' => 'annee']);

        $this->assertTrue($ins->disputesScoped->every(fn (Dispute $d): bool => $d->site->name === $site));
        $this->assertTrue($ins->reviewsScoped->every(fn (Review $r): bool => $r->site->name === $site));
        $this->get('/avis-litiges/litiges?chantier='.urlencode($site))->assertInertia(fn (Assert $page) => $page->where('alerts.0.title', 'Filtres appliqués (1)'));
    }

    public function test_dispute_workflow_from_admissibility_to_resolution(): void
    {
        $p = QualityInsights::prestations()->first();
        $this->post('/avis-litiges/litiges', [
            'source' => 'Chantier', 'claimant' => 'Promoteur de la prestation', 'prestation' => $p['opRef'], 'category' => 'Dommage matériel', 'subcategory' => 'Échafaudage', 'subject' => 'Échafaudage endommagé',
            'description' => 'Échafaudage endommagé lors du démontage du lot.', 'facts_at' => QualityInsights::today()->subDays(2)->format('Y-m-d'), 'amount' => 80000, 'wish' => 'Règlement financier', 'urgent' => 'Non',
            'proof' => UploadedFile::fake()->create('constat.pdf', 120, 'application/pdf'), 'sincere' => '1', 'consent' => '1',
        ])->assertRedirect();
        $d = Dispute::query()->latest('id')->firstOrFail();
        $url = "/avis-litiges/litiges/{$d->reference}";
        $this->assertSame('Recevabilité en cours', $d->status);
        $this->assertCount(1, $d->proofs);

        $checks = array_fill_keys(array_keys(Dispute::ADMISSIBILITY), 'Conforme');
        $this->post("{$url}/recevabilite", [...$checks, 'pieces' => 'Non conforme', 'decision' => 'Recevable · ouvrir la procédure contradictoire', 'reason' => 'Contrôles effectués'])->assertSessionHasErrors('decision');
        $this->post("{$url}/recevabilite", [...$checks, 'decision' => 'Recevable · ouvrir la procédure contradictoire', 'reason' => 'Contrôles effectués'])->assertRedirect();
        $this->assertSame('Réponse de la partie adverse attendue', $d->fresh()->status);

        $this->post("{$url}/decision", ['facts' => 'Échafaudage endommagé.', 'requests' => 'Indemnisation demandée.', 'retained' => 'Constat', 'analysis' => 'Le constat établit le dommage pendant le démontage du lot.', 'rules' => 'CG art. 12', 'result' => 'Demande acceptée', 'measure' => 'Retenue justifiée', 'delay' => '7 jours'])->assertRedirect();
        $this->post("{$url}/decision/rendu")->assertSessionHasErrors('status');

        $this->post("{$url}/declarations", ['by' => $d->respondent['name'], 'type' => 'Réponse', 'content' => 'Nous contestons la responsabilité du dommage.', 'visibility' => 'Parties'])->assertRedirect();
        $this->assertNotNull($d->fresh()->responded_at);
        $this->post("{$url}/decision", ['facts' => 'Échafaudage endommagé.', 'requests' => 'Indemnisation demandée.', 'retained' => 'Constat', 'analysis' => 'Le constat établit le dommage pendant le démontage du lot.', 'rules' => 'CG art. 12', 'result' => 'Demande acceptée', 'measure' => 'Retenue justifiée', 'delay' => '7 jours'])->assertRedirect();
        $this->post("{$url}/decision/rendu")->assertRedirect()->assertSessionHasNoErrors();
        $d->refresh();
        $this->assertSame('En attente d’exécution', $d->status);
        $this->assertSame('Rendue', $d->decision['status']);
        $request = $d->financial_requests[0];
        $this->assertSame('Retenue justifiée', $request['type']);

        $this->post("{$url}/execution")->assertSessionHasErrors('status');
        $this->post("{$url}/demandes/fin/{$request['ref']}/synchronisation")->assertRedirect();
        $this->post("{$url}/demandes/fin/{$request['ref']}/synchronisation")->assertRedirect();
        $this->assertSame('Exécutée', $d->fresh()->financial_requests[0]['status']);
        $this->post("{$url}/execution")->assertRedirect();
        $this->assertSame('Résolu', $d->fresh()->status);
        $this->post("{$url}/cloture", ['reason' => 'Toutes les mesures exécutées'])->assertRedirect();
        $this->assertSame('Clôturé', $d->fresh()->status);
        $this->assertGreaterThan(8, QualityActivity::query()->where('subject_reference', $d->reference)->count());
    }

    public function test_mediation_agreement_creates_execution_requests(): void
    {
        $d = $this->dispute('Médiation en cours');
        $url = "/avis-litiges/litiges/{$d->reference}/mediation";
        $this->post("{$url}/accord", ['claimant' => 'Retire sa demande après versement.', 'respondent' => 'Verse le complément convenu.', 'amount' => 40000, 'replacement' => 'Aucun', 'delay' => '7 jours', 'payments' => 'Ajustement créditeur', 'reviews' => 'Aucune', 'effective_at' => QualityInsights::today()->addDays(3)->format('Y-m-d')])->assertRedirect();
        $this->post("{$url}/validation", ['who' => 'Les deux parties'])->assertRedirect();
        $d->refresh();
        $this->assertSame('Accord validé', $d->mediation['status']);
        $this->assertSame('Accord trouvé', $d->status);
        $this->assertSame('Ajustement créditeur', collect($d->financial_requests)->last()['type']);
    }

    public function test_appeal_and_reports(): void
    {
        $d = $this->dispute('Recours ouvert');
        $index = count($d->appeals) - 1;
        $this->post("/avis-litiges/litiges/{$d->reference}/recours/{$index}/recevable")->assertRedirect();
        $this->post("/avis-litiges/litiges/{$d->reference}/recours/{$index}/decision", ['outcome' => 'Décision confirmée', 'reason' => 'Le certificat ne modifie pas l’appréciation des faits.'])->assertRedirect();
        $this->assertSame('Décision confirmée', $d->fresh()->appeals[$index]['status']);

        $csv = $this->get('/avis-litiges/rapports/generer?rapport=liste-litiges&format=CSV');
        $csv->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('Référence;Ouverture;Demandeur', $csv->getContent());
        $this->assertDatabaseHas('quality_activities', ['action' => 'a exporté « Liste des litiges »']);

        $this->post('/avis-litiges/messages', ['reference' => $d->reference, 'to' => $d->claimant['name'], 'channel' => 'SMS', 'message' => 'Votre recours a été examiné.'])->assertRedirect();
        $this->assertDatabaseHas('quality_notifications', ['recipient' => $d->claimant['name'], 'channel' => 'SMS']);
        $this->assertGreaterThan(15, QualityNotification::query()->count());
    }

    public function test_restricted_role_cannot_moderate_or_decide(): void
    {
        config(['admin.quality.role' => 'Lecture seule']);
        $d = $this->dispute('Décision en préparation');

        $this->post("/avis-litiges/litiges/{$d->reference}/decision/rendu")->assertForbidden();
        $this->get("/avis-litiges/litiges/{$d->reference}")->assertOk()->assertInertia(fn (Assert $page) => $page->where('profile.buttons.0.action.type', 'error'));
    }
}
