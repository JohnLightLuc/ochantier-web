<?php

namespace Tests\Feature;

use App\Enums\PromoterPaymentStatus;
use App\Enums\PromoterStatus;
use App\Models\Promoter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromoterAccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_suspension_requires_a_reason(): void
    {
        $promoter = Promoter::factory()->create();

        $this->post("/promoteurs/{$promoter->slug}/suspension", ['category' => 'Autre', 'reason' => 'court'])
            ->assertSessionHasErrors('reason');

        $this->assertSame(PromoterStatus::Active, $promoter->fresh()->status);
    }

    public function test_account_can_be_suspended_and_reactivated(): void
    {
        $promoter = Promoter::factory()->create();

        $this->post("/promoteurs/{$promoter->slug}/suspension", ['category' => 'Retards de paiement', 'reason' => 'Trois échéances impayées.'])
            ->assertRedirect()
            ->assertInertiaFlash('toast', "Compte {$promoter->name} suspendu.");

        $promoter->refresh();
        $this->assertSame(PromoterStatus::Suspended, $promoter->status);
        $this->assertSame('Retards de paiement — Trois échéances impayées.', $promoter->suspension_reason);

        $this->delete("/promoteurs/{$promoter->slug}/suspension")->assertRedirect();

        $promoter->refresh();
        $this->assertSame(PromoterStatus::Active, $promoter->status);
        $this->assertNull($promoter->suspended_at);
        $this->assertSame(['a réactivé le compte', 'a suspendu le compte'], $promoter->activities()->pluck('action')->all());
    }

    public function test_pending_account_can_be_validated(): void
    {
        $promoter = Promoter::factory()->pending()->create();

        $this->post("/promoteurs/{$promoter->slug}/validation")->assertRedirect();

        $promoter->refresh();
        $this->assertSame(PromoterStatus::Active, $promoter->status);
        $this->assertSame(PromoterPaymentStatus::UpToDate, $promoter->payment_status);
    }

    public function test_bulk_validation_only_touches_pending_accounts(): void
    {
        $pending = Promoter::factory()->pending()->count(2)->create();
        $suspended = Promoter::factory()->suspended()->create();

        $this->post('/promoteurs/validation-groupee', ['ids' => [...$pending->pluck('id'), $suspended->id]])
            ->assertInertiaFlash('toast', '2 comptes validés.');

        $this->assertSame(2, Promoter::where('status', PromoterStatus::Active)->count());
        $this->assertSame(PromoterStatus::Suspended, $suspended->fresh()->status);
    }

    public function test_notification_requires_channel_subject_and_message(): void
    {
        $promoter = Promoter::factory()->create();

        $this->post("/promoteurs/{$promoter->slug}/notifications", ['channels' => [], 'subject' => '', 'message' => ''])
            ->assertSessionHasErrors(['channels', 'subject', 'message']);

        $this->post("/promoteurs/{$promoter->slug}/notifications", ['channels' => ['SMS', 'E-mail'], 'subject' => 'KYC', 'message' => 'Merci de mettre à jour votre attestation.'])
            ->assertInertiaFlash('toast', "Notification envoyée à {$promoter->representative} (SMS, E-mail).");
    }
}
