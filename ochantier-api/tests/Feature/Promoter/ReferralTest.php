<?php

namespace Tests\Feature\Promoter;

use App\Enums\ReferralStatus;
use App\Models\Notification;
use App\Models\PromoterProfile;
use App\Models\Referral;
use App\Models\User;

class ReferralTest extends PromoterTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->promoter->update(['first_name' => 'Nathanaël', 'last_name' => 'Yao']);
    }

    public function test_the_referral_page_gives_a_code_and_a_link(): void
    {
        $this->getJson('/api/v1/promoter/referrals')
            ->assertOk()
            ->assertJsonPath('data.code', 'NATH'.now()->format('Y'))
            ->assertJsonPath('data.link', 'https://ochantier.ci/i/NATH'.now()->format('Y'))
            ->assertJsonPath('data.share_message', 'Rejoins-moi sur O’CHANTIER pour trouver des ouvriers vérifiés pour tes chantiers : https://ochantier.ci/i/NATH'.now()->format('Y'))
            ->assertJsonPath('data.stats.label', '0 envoyée · 0 inscrit(e)')
            ->assertJsonPath('data.invitations', []);

        $other = User::factory()->promoter()->create(['first_name' => 'Nathalie']);
        $this->actingAsPromoter($other);
        $this->assertNotSame('NATH'.now()->format('Y'), $this->getJson('/api/v1/promoter/referrals')->json('data.code'));
    }

    public function test_an_invited_number_that_registers_becomes_a_referral(): void
    {
        $this->postJson('/api/v1/promoter/referrals', ['dial_code' => '225', 'phone_number' => '0708091011', 'name' => 'Koffi'])
            ->assertCreated()
            ->assertJsonPath('message', 'Invitation envoyée par SMS au +225 07 08 09 10 11')
            ->assertJsonPath('data.invitations.0.name', 'Koffi')
            ->assertJsonPath('data.invitations.0.status', ['value' => 'envoyee', 'label' => 'Envoyée']);
        $this->assertStringContainsString('Nathanaël Yao vous invite', collect($this->sms->sent)->last()['message']);
        $this->postJson('/api/v1/promoter/referrals', ['dial_code' => '225', 'phone_number' => '0708091011'])->assertCreated();
        $this->assertSame(1, Referral::query()->count());
        $this->postJson('/api/v1/promoter/referrals', ['dial_code' => '225', 'phone_number' => '0712345634'])
            ->assertJsonValidationErrors(['phone_number' => 'Ce numéro a déjà un compte O’CHANTIER.']);

        $this->register('0708091011');

        $referral = Referral::query()->sole();
        $this->assertSame(ReferralStatus::Inscrite, $referral->status);
        $this->assertSame($this->promoter->promoterProfile->id, User::query()->where('phone', '+2250708091011')->sole()->promoterProfile->referred_by_profile_id);
        $this->assertSame('referral.registered', Notification::query()->where('recipient_id', $this->promoter->id)->sole()->event_code);

        $this->actingAsPromoter($this->promoter);
        $this->getJson('/api/v1/promoter/referrals')
            ->assertJsonPath('data.stats.label', '1 envoyée · 1 inscrit(e)')
            ->assertJsonPath('data.invitations.0.status.label', 'Inscrit');
    }

    public function test_a_promoter_registers_with_the_referral_code_of_the_link(): void
    {
        $code = $this->getJson('/api/v1/promoter/referrals')->json('data.code');

        $this->postJson('/api/v1/auth/promoter/register', ['profile_kind' => 'particulier', 'dial_code' => '225', 'phone_number' => '0708091011', 'accept_terms' => true, 'referral_code' => 'INCONNU'])
            ->assertJsonValidationErrors(['referral_code' => 'Code parrain inconnu.']);

        $this->register('0708091011', $code);

        $referral = Referral::query()->sole();
        $this->assertSame(['lien', ReferralStatus::Inscrite], [$referral->channel, $referral->status]);
        $this->assertSame($this->promoter->promoterProfile->id, PromoterProfile::query()->whereKey($referral->referredUser->promoterProfile->id)->value('referred_by_profile_id'));
    }

    private function register(string $phone, ?string $code = null): void
    {
        $this->postJson('/api/v1/auth/promoter/register', array_filter(['profile_kind' => 'particulier', 'dial_code' => '225', 'phone_number' => $phone, 'accept_terms' => true, 'referral_code' => $code]))->assertAccepted();
        $this->postJson('/api/v1/auth/promoter/register/verify', ['dial_code' => '225', 'phone_number' => $phone, 'code' => '123456', ...$this->device()])->assertCreated();
    }
}
