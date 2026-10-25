<?php

namespace Tests\Feature\Promoter;

use App\Enums\PaymentTransactionStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Assignment;
use App\Models\Mission;
use App\Models\PaymentTransaction;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;

class SiteReportTest extends PromoterTestCase
{
    use ConfirmedSite;

    protected function setUp(): void
    {
        parent::setUp();

        $this->confirmSite();
    }

    public function test_the_report_is_locked_until_the_site_has_ended_for_a_subscriber(): void
    {
        $report = "/api/v1/promoter/sites/{$this->site->id}/report";

        $this->getJson($report)
            ->assertOk()
            ->assertJsonPath('data.available', false)
            ->assertJsonPath('data.subtitle', 'Bilan complet · '.$this->site->reference)
            ->assertJsonPath('data.lock_message', 'À la fin du chantier · réservé aux comptes abonnés.')
            ->assertJsonMissingPath('data.attendance');
        $this->getJson("{$report}/pdf")->assertForbidden()->assertJsonPath('message', 'À la fin du chantier · réservé aux comptes abonnés.');

        $subscription = $this->subscribe();
        $this->getJson($report)->assertJsonPath('data.lock_message', 'Disponible au téléchargement à la fin du chantier.');

        $subscription->update(['status' => SubscriptionStatus::Resiliee]);
        $this->artisan('ochantier:simulate-site-end', ['site' => $this->site->reference])->assertSuccessful();
        $this->getJson($report)
            ->assertJsonPath('data.available', false)
            ->assertJsonPath('data.ended', true)
            ->assertJsonPath('data.lock_message', 'Réservé aux comptes abonnés : pointages, chronologie, bilan financier et évaluations en un PDF.');
    }

    public function test_the_report_gathers_attendance_chronology_finances_and_ratings(): void
    {
        $this->onWorkDay();
        $kouame = $this->assignmentOf('Kouamé Koffi');
        $this->point($kouame)->assertCreated();
        $this->travelTo(now()->setTimeFromTimeString('17:00'));
        $this->point($kouame, 'depart')->assertCreated();
        $this->postJson("/api/v1/promoter/sites/{$this->site->id}/attendance/absences", ['assignment_id' => $this->assignmentOf('Touré Ali')->id])->assertOk();
        $this->postJson("/api/v1/promoter/sites/{$this->site->id}/attendance/absences", ['assignment_id' => $this->assignmentOf('Serge Aka')->id])->assertOk();
        $this->postJson("/api/v1/promoter/sites/{$this->site->id}/attendance/".today()->toDateString().'/validate')->assertOk();

        $step = $this->postJson("/api/v1/promoter/sites/{$this->site->id}/steps", ['title' => 'Fondations'])->json('data.steps.0.id');
        $this->postJson("/api/v1/promoter/sites/{$this->site->id}/steps/{$step}/start")->assertOk();
        $this->postJson("/api/v1/promoter/sites/{$this->site->id}/steps/{$step}/complete")->assertOk();

        $this->artisan('ochantier:simulate-site-end', ['site' => $this->site->reference])->assertSuccessful();
        $this->postJson("/api/v1/promoter/sites/{$this->site->id}/ratings", ['scope' => 'equipe', 'score' => 4, 'satisfaction_tag' => 'Bonne coordination'])->assertCreated();
        $this->subscribe();

        $missions = Mission::query()->with('pricingSnapshot')->get();
        $works = (int) $missions->sum('pricingSnapshot.total_promoter_amount');
        $deposit = PaymentTransaction::query()->where('status', PaymentTransactionStatus::Reussie)->sole();
        $today = today()->locale('fr')->translatedFormat('j M Y');

        $this->getJson("/api/v1/promoter/sites/{$this->site->id}/report")
            ->assertOk()
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.lock_message', null)
            ->assertJsonPath('data.site.title', 'Résidence Les Palmiers')
            ->assertJsonPath('data.kpis.1', ['label' => 'Présence', 'value' => '33 %'])
            ->assertJsonPath('data.kpis.2', ['label' => 'Étapes', 'value' => '1/1'])
            ->assertJsonPath('data.description.details.3', ['label' => 'Offre', 'value' => 'Journalière'])
            ->assertJsonPath('data.attendance.summary', '3 ouvrier(s) · 1 jour ouvré')
            ->assertJsonPath('data.attendance.presence_label', 'Présence globale 33 %')
            ->assertJsonPath('data.attendance.workers.0', ['name' => 'Kouamé Koffi', 'role' => 'Maçon', 'days' => '1/1', 'rate' => '100 %'])
            ->assertJsonPath('data.attendance.workers.1.rate', '0 %')
            ->assertJsonPath('data.chronology.0.title', 'Fondations')
            ->assertJsonPath('data.chronology.0.dates', $today.' → '.$today)
            ->assertJsonPath('data.finances.lines.0', ['label' => 'Montant des travaux', 'amount' => $works])
            ->assertJsonPath('data.finances.lines.2', ['label' => 'Montant réglé', 'amount' => $deposit->amount])
            ->assertJsonPath('data.finances.lines.3', ['label' => 'Frais opérateurs', 'amount' => $deposit->fees_amount])
            ->assertJsonPath('data.finances.lines.4', ['label' => 'Reste à payer', 'amount' => $works - $deposit->amount])
            ->assertJsonPath('data.finances.total_disbursed', $deposit->amount + $deposit->fees_amount)
            ->assertJsonPath('data.evaluation.label', 'Équipe : 4/5')
            ->assertJsonPath('data.evaluation.stars', '★★★★☆');

        $pdf = $this->get("/api/v1/promoter/sites/{$this->site->id}/report/pdf")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'attachment; filename="Rapport-'.$this->site->reference.'.pdf"')
            ->getContent();
        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringContainsString('Kouam'.mb_convert_encoding('é', 'Windows-1252', 'UTF-8').' Koffi', $pdf);
    }

    public function test_invoices_list_the_receipts_and_the_cost_of_the_site(): void
    {
        $deposit = PaymentTransaction::query()->with('receipts')->sole();
        $total = $deposit->amount + $deposit->fees_amount;
        $daily = (int) Assignment::query()->sum('promoter_daily_rate');
        $forecast = (int) Mission::query()->with('pricingSnapshot')->get()->sum('pricingSnapshot.total_promoter_amount');

        $this->getJson("/api/v1/promoter/sites/{$this->site->id}/invoices")
            ->assertOk()
            ->assertJsonPath('data.count_label', '1 facture')
            ->assertJsonPath('data.invoices.0.number', $deposit->receipts->sole()->number)
            ->assertJsonPath('data.invoices.0.title', $deposit->metadata['label'])
            ->assertJsonPath('data.invoices.0.amount', $total)
            ->assertJsonPath('data.invoices.0.method', 'Orange Money')
            ->assertJsonPath('data.total', $total)
            ->assertJsonPath('data.cost.daily_labor', $daily)
            ->assertJsonPath('data.cost.contract_amount', 0)
            ->assertJsonPath('data.cost.forecast_total', $forecast);

        $other = $this->postJson('/api/v1/promoter/sites', ['title' => 'Villa Riviera'])->json('data.id');
        $this->getJson("/api/v1/promoter/sites/{$other}/invoices")
            ->assertJsonPath('data.count_label', 'Aucune facture')
            ->assertJsonPath('data.cost.forecast_total', 0);

        $this->actingAsPromoter(User::factory()->promoter()->create());
        $this->getJson("/api/v1/promoter/sites/{$this->site->id}/invoices")->assertNotFound();
        $this->getJson("/api/v1/promoter/sites/{$this->site->id}/report")->assertNotFound();
    }

    private function subscribe(): Subscription
    {
        return Subscription::query()->create([
            'reference' => 'ABO-TEST-0001',
            'promoter_profile_id' => $this->promoter->promoterProfile->id,
            'plan_id' => SubscriptionPlan::query()->value('id'),
            'status' => SubscriptionStatus::Active,
            'started_at' => today(),
        ]);
    }
}
