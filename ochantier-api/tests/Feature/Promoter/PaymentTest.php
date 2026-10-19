<?php

namespace Tests\Feature\Promoter;

use App\Enums\AssignmentStatus;
use App\Enums\MissionStatus;
use App\Enums\NeedStatus;
use App\Enums\OfferStatus;
use App\Enums\PaymentScheduleStatus;
use App\Enums\PaymentScheduleType;
use App\Enums\SelectionStatus;
use App\Enums\SiteStatus;
use App\Enums\WorkerReservationStatus;
use App\Models\Mission;
use App\Models\Offer;
use App\Models\PaymentSchedule;
use App\Models\PaymentTransaction;
use App\Models\Proposal;
use App\Models\Selection;
use App\Models\Site;
use App\Models\User;
use App\Models\WorkerReservation;
use Database\Seeders\AdminAccessSeeder;
use Database\Seeders\CommercialOfferSeeder;
use Database\Seeders\PlatformRulesSeeder;
use Illuminate\Support\Facades\Storage;

class PaymentTest extends PromoterTestCase
{
    private Proposal $proposal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([AdminAccessSeeder::class, PlatformRulesSeeder::class, CommercialOfferSeeder::class]);
        $this->worker('Kouamé Koffi', 'macon', 22700, rating: 4.7);
        $this->worker('Touré Ali', 'macon', 23600, rating: 4.9);
        $this->worker('Serge Aka', 'peintre', 16400, rating: 4.7);
        $this->proposal = $this->acceptedProposal();
    }

    public function test_the_payment_methods_are_listed_with_their_fees(): void
    {
        $this->getJson('/api/v1/promoter/payments/options')
            ->assertOk()
            ->assertJsonPath('operators.*.code', ['orange_money', 'mtn_momo', 'moov_money', 'wave'])
            ->assertJsonPath('operators.0.fee_percent', 1.5)
            ->assertJsonPath('default_phone.phone', '+225 07 12 34 56 34')
            ->assertJsonPath('saved_numbers', []);
    }

    public function test_the_deposit_quote_details_the_amount_and_the_operator_fees(): void
    {
        // Ouvriers : (26 000 + 25 000 + 18 000) × 10 jours ; commission Y 8 % ; acompte X 30 % par métier, arrondi au millier
        // (maçons 165 000, peintre 58 000).
        $this->postJson('/api/v1/promoter/payments/quote', ['proposal_id' => $this->proposal->id, 'operator_code' => 'orange_money'])
            ->assertOk()
            ->assertJsonPath('data.kind', 'acompte')
            ->assertJsonPath('data.label', 'Acompte 30 % · Résidence Les Acacias')
            ->assertJsonPath('data.lines.*.label', [
                'Coût journalier des ouvriers · 3 ouvriers',
                'Coût des ouvriers (10 jours)',
                'Commission O’CHANTIER 8 %',
                'Total de la réservation',
                'Acompte 30 %',
                'Frais opérateur Orange Money 1,5 %',
            ])
            ->assertJsonPath('data.lines.*.amount', [69000, 690000, 55200, 745200, 223000, 3345])
            ->assertJsonPath('data.amount', 223000)
            ->assertJsonPath('data.fees', 3345)
            ->assertJsonPath('data.total', 226345);

        $this->postJson('/api/v1/promoter/payments/quote', ['proposal_id' => $this->proposal->id])
            ->assertJsonValidationErrors(['operator_code' => 'Choisissez un moyen de paiement.']);
    }

    public function test_paying_the_deposit_confirms_the_team_and_plans_the_installments(): void
    {
        $this->getJson('/api/v1/promoter/payments')
            ->assertJsonPath('data.0.key', 'proposal:'.$this->proposal->id)
            ->assertJsonPath('data.0.status.value', 'a_payer')
            ->assertJsonPath('data.0.when', 'À régler pour valider l’offre')
            ->assertJsonPath('meta.totals.a_regler', 223000);

        $response = $this->postJson('/api/v1/promoter/payments', $this->deposit(['remember_number' => true]), ['Idempotency-Key' => 'cle-acompte-1'])
            ->assertCreated()
            ->assertJsonPath('data.status.value', 'reussie')
            ->assertJsonPath('data.amount', 223000)
            ->assertJsonPath('data.fees_amount', 3345)
            ->assertJsonPath('data.total_amount', 226345)
            ->assertJsonPath('data.operator.name', 'Orange Money')
            ->assertJsonPath('data.phone', '+225 07 •• •• •• 34')
            ->assertJsonPath('data.message', 'Équipe confirmée. Démarrage prévu le '.now()->addDays(5)->locale('fr')->translatedFormat('j M Y').'.');

        $this->assertMatchesRegularExpression('/^PAY-\d{4}-\d{6}$/', $response->json('data.reference'));
        $this->assertMatchesRegularExpression('/^REC-\d{4}-\d{6}$/', $response->json('data.receipt.number'));
        $pdf = $this->get($response->json('data.receipt.url'))->assertOk()->streamedContent();
        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringContainsString('Re'.mb_convert_encoding('ç', 'Windows-1252', 'UTF-8').'u de paiement', $pdf);

        $missions = Mission::query()->with(['assignments', 'paymentSchedules', 'pricingSnapshot'])->get();
        $this->assertCount(2, $missions);
        $this->assertTrue($missions->every(fn (Mission $mission) => $mission->status === MissionStatus::Confirmee && $mission->deposit_paid_at));
        $this->assertSame(3, $missions->sum(fn (Mission $mission) => $mission->assignments->count()));
        $this->assertTrue($missions->flatMap->assignments->every(fn ($assignment) => $assignment->status === AssignmentStatus::Confirmee));

        foreach ($missions as $mission) {
            $deposit = $mission->paymentSchedules->firstWhere('schedule_type', PaymentScheduleType::Acompte);
            $weekly = $mission->paymentSchedules->where('schedule_type', PaymentScheduleType::Hebdomadaire);
            $this->assertSame(PaymentScheduleStatus::Payee, $deposit->status);
            $this->assertCount(2, $weekly);
            $this->assertSame($mission->pricingSnapshot->total_promoter_amount, $deposit->amount_due + (int) $weekly->sum('amount_due'));
            $this->assertSame($mission->pricingSnapshot->deposit_amount, (int) $weekly->sum('deposit_deduction_amount'));
        }

        $transaction = PaymentTransaction::query()->sole();
        $this->assertNull($transaction->payment_schedule_id);
        $this->assertCount(2, $transaction->metadata['allocations']);
        $this->assertSame(223000, (int) $transaction->allocations()->sum('amount'));

        $this->assertSame(SiteStatus::Confirme, Site::query()->sole()->status);
        $this->assertSame(OfferStatus::Pourvue, Offer::query()->sole()->status);
        $this->assertTrue(Selection::query()->get()->every(fn ($selection) => $selection->status === SelectionStatus::Confirme));
        $this->assertTrue(WorkerReservation::query()->get()->every(fn ($reservation) => $reservation->status === WorkerReservationStatus::Convertie));
        $this->assertSame(NeedStatus::Confirme, $missions->first()->need->status);

        $this->getJson("/api/v1/promoter/reservations/{$this->proposal->offer_id}")
            ->assertJsonPath('data.state.value', 'confirmee')
            ->assertJsonPath('data.can_pay_deposit', false);

        $this->getJson('/api/v1/promoter/payments/options')->assertJsonPath('saved_numbers.0.phone', '+225 07 •• •• •• 34');
    }

    public function test_the_same_idempotency_key_never_pays_twice(): void
    {
        $first = $this->postJson('/api/v1/promoter/payments', $this->deposit(), ['Idempotency-Key' => 'cle-unique'])->assertCreated()->json('data.id');

        $this->postJson('/api/v1/promoter/payments', $this->deposit(), ['Idempotency-Key' => 'cle-unique'])
            ->assertOk()
            ->assertJsonPath('data.id', $first)
            ->assertJsonPath('data.status.value', 'reussie');

        $this->postJson('/api/v1/promoter/payments', $this->deposit(), ['Idempotency-Key' => 'autre-cle'])
            ->assertJsonValidationErrors(['proposal_id' => 'L’acompte de cette proposition est déjà payé.']);

        $this->assertSame(1, PaymentTransaction::query()->count());
        $this->assertSame(2, Mission::query()->count());
    }

    public function test_a_failed_payment_can_be_retried(): void
    {
        $this->postJson('/api/v1/promoter/payments', $this->deposit(['phone_number' => '0712340000']))
            ->assertCreated()
            ->assertJsonPath('data.status.value', 'echouee')
            ->assertJsonPath('data.failure.code', 'solde_insuffisant')
            ->assertJsonPath('data.message', 'Solde insuffisant sur ce compte Orange Money.')
            ->assertJsonPath('data.receipt', null);

        $this->assertSame(0, Mission::query()->count());

        $this->postJson('/api/v1/promoter/payments', $this->deposit())->assertCreated()->assertJsonPath('data.status.value', 'reussie');
    }

    public function test_the_number_must_belong_to_the_operator(): void
    {
        $this->postJson('/api/v1/promoter/payments', $this->deposit(['phone_number' => '0512345678']))
            ->assertJsonValidationErrors(['phone_number' => 'Ce numéro n’est pas un numéro Orange Money.']);

        $this->postJson('/api/v1/promoter/payments', $this->deposit(['operator_code' => 'mtn_momo', 'phone_number' => '0512345678']))->assertCreated();
    }

    public function test_the_deposit_can_no_longer_be_paid_once_the_hold_has_expired(): void
    {
        WorkerReservation::query()->update(['expires_at' => now()->subMinute()]);

        $this->postJson('/api/v1/promoter/payments', $this->deposit())
            ->assertJsonValidationErrors(['proposal_id' => 'Le délai de paiement de l’acompte est dépassé : les ouvriers ne sont plus réservés. Une nouvelle proposition vous sera faite.']);
    }

    public function test_installments_are_listed_and_paid_week_by_week(): void
    {
        $this->postJson('/api/v1/promoter/payments', $this->deposit())->assertCreated();

        $list = $this->getJson('/api/v1/promoter/payments')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.*.short', ['Échéance 1/2', 'Échéance 2/2', 'Acompte (30 %)'])
            ->assertJsonPath('data.*.status.value', ['a_venir', 'a_venir', 'effectue'])
            ->assertJsonPath('data.0.who', '3 ouvriers')
            ->assertJsonPath('data.0.when', 'Échéance du '.now()->addDays(11)->locale('fr')->translatedFormat('j M Y'))
            ->assertJsonPath('data.2.method', 'Orange Money')
            ->assertJsonPath('meta.totals.payes', 223000)
            ->assertJsonPath('meta.counts.a_venir', 2)
            ->json();

        $week = $list['data'][0];
        $this->assertSame(745200 - 223000, $week['amount'] + $list['data'][1]['amount']);
        $this->assertSame(1, $list['meta']['counts']['effectues']);

        $quote = $this->postJson('/api/v1/promoter/payments/quote', ['schedule_ids' => $week['target']['schedule_ids'], 'operator_code' => 'wave'])
            ->assertOk()
            ->assertJsonPath('data.lines.*.label', ['Coût des ouvriers', 'Commission O’CHANTIER 8 %', 'Part de l’acompte déduite', 'Montant à régler', 'Frais opérateur Wave 1,5 %'])
            ->assertJsonPath('data.amount', $week['amount']);

        $this->postJson('/api/v1/promoter/payments', ['schedule_ids' => $week['target']['schedule_ids'], 'operator_code' => 'wave', 'dial_code' => '225', 'phone_number' => '0712345634'])
            ->assertCreated()
            ->assertJsonPath('data.status.value', 'reussie')
            ->assertJsonPath('data.total_amount', $quote->json('data.total'))
            ->assertJsonPath('data.message', 'Échéance 1/2 du '.now()->addDays(11)->locale('fr')->translatedFormat('j M Y').' réglée.');

        $schedule = substr($week['key'], strlen('schedule:'));
        $this->getJson("/api/v1/promoter/payments/schedules/{$schedule}")
            ->assertOk()
            ->assertJsonPath('data.status.value', 'effectue')
            ->assertJsonPath('data.contract', ['total' => 745200, 'paid' => 223000 + $week['amount'], 'rest' => 745200 - 223000 - $week['amount']])
            ->assertJsonPath('data.lines.4.label', 'Frais opérateur Wave 1,5 %');

        $this->postJson('/api/v1/promoter/payments', ['schedule_ids' => $week['target']['schedule_ids'], 'operator_code' => 'wave', 'dial_code' => '225', 'phone_number' => '0712345634'])
            ->assertJsonValidationErrors(['schedule_ids' => 'Cette échéance est déjà payée.']);
    }

    public function test_an_unpaid_installment_past_its_due_date_is_late(): void
    {
        $this->postJson('/api/v1/promoter/payments', $this->deposit())->assertCreated();
        PaymentSchedule::query()->where('schedule_type', PaymentScheduleType::Hebdomadaire)->where('sequence', 1)->update(['due_date' => now()->subDays(2)->toDateString()]);

        $this->getJson('/api/v1/promoter/payments?filter=en_retard')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.when', 'En retard · échéance du '.now()->subDays(2)->locale('fr')->translatedFormat('j M Y'))
            ->assertJsonPath('meta.next.status.value', 'en_retard');
    }

    public function test_payments_of_other_promoters_are_out_of_reach(): void
    {
        $this->postJson('/api/v1/promoter/payments', $this->deposit())->assertCreated();
        $schedule = PaymentSchedule::query()->where('schedule_type', PaymentScheduleType::Hebdomadaire)->first();
        $transaction = PaymentTransaction::query()->sole();

        $this->actingAsPromoter(User::factory()->promoter()->create());
        $this->getJson("/api/v1/promoter/payments/schedules/{$schedule->id}")->assertNotFound();
        $this->getJson("/api/v1/promoter/payments/transactions/{$transaction->id}")->assertNotFound();
        $this->postJson('/api/v1/promoter/payments/quote', ['schedule_ids' => [$schedule->id], 'operator_code' => 'wave'])
            ->assertJsonValidationErrors(['schedule_ids' => 'Échéance introuvable.']);
        $this->getJson('/api/v1/promoter/payments')->assertJsonCount(0, 'data');
    }

    /**
     * @return array<string, mixed>
     */
    private function deposit(array $overrides = []): array
    {
        return ['proposal_id' => $this->proposal->id, 'operator_code' => 'orange_money', 'dial_code' => '225', 'phone_number' => '0712345634', ...$overrides];
    }

    private function acceptedProposal(): Proposal
    {
        Storage::fake(config('filesystems.media'));
        $site = $this->postJson('/api/v1/promoter/sites', ['title' => 'Résidence Les Acacias'])->assertCreated()->json('data');
        $this->post("/api/v1/promoter/sites/{$site['id']}/photo", ['photo' => $this->image()], ['Accept' => 'application/json'])->assertOk();
        $this->patchJson("/api/v1/promoter/sites/{$site['id']}", [
            'address' => 'Angré, Abidjan',
            'planned_start_date' => now()->addDays(5)->toDateString(),
            'planned_end_date' => now()->addDays(14)->toDateString(),
        ])->assertOk();
        $this->putJson("/api/v1/promoter/sites/{$site['id']}/offer", ['type' => 'journaliere', 'needs' => [
            ['trade_id' => $this->trade('macon')->id, 'headcount' => 2, 'daily_rate' => 25000],
            ['trade_id' => $this->trade('peintre')->id, 'headcount' => 1, 'daily_rate' => 18000],
        ]])->assertOk();
        $this->postJson("/api/v1/promoter/sites/{$site['id']}/publish")->assertOk();
        $this->artisan('ochantier:simulate-matching')->assertSuccessful();

        $proposal = Proposal::query()->sole();
        $this->postJson("/api/v1/promoter/proposals/{$proposal->id}/accept")->assertOk();

        return $proposal->refresh();
    }
}
