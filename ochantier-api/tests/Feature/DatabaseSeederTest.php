<?php

namespace Tests\Feature;

use App\Models\AdminPermission;
use App\Models\AdminRole;
use App\Models\LegalDocument;
use App\Models\Trade;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Tests\Feature\Auth\AuthTestCase;

class DatabaseSeederTest extends AuthTestCase
{
    public function test_seeding_twice_does_not_duplicate_reference_data(): void
    {
        $this->seed(DatabaseSeeder::class);
        $counts = [Trade::query()->count(), AdminPermission::query()->count(), User::query()->count()];

        $this->seed(DatabaseSeeder::class);

        $this->assertSame($counts, [Trade::query()->count(), AdminPermission::query()->count(), User::query()->count()]);
        $this->assertSame(AdminPermission::query()->count(), AdminRole::query()->where('code', 'super_admin')->firstOrFail()->permissions()->count());
    }

    public function test_demo_accounts_can_sign_in(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->postJson('/api/v1/auth/commercial/login', ['dial_code' => '225', 'phone_number' => '0500000001', 'password' => 'motdepasse1'])->assertAccepted();
        $this->postJson('/api/v1/auth/commercial/login/verify', ['dial_code' => '225', 'phone_number' => '0500000001', 'code' => '123456', ...$this->device()])
            ->assertOk()
            ->assertJsonPath('data.user.must_change_password', false);

        $this->postJson('/api/v1/auth/promoter/login', ['email' => 'promoteur@demo.ochantier.ci'])->assertAccepted();
        $this->postJson('/api/v1/auth/promoter/login/verify', ['email' => 'promoteur@demo.ochantier.ci', 'code' => '123456', ...$this->device('Pixel 8')])
            ->assertOk();
    }

    public function test_a_new_promoter_consents_to_the_seeded_legal_documents(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->postJson('/api/v1/auth/promoter/register', ['profile_kind' => 'particulier', 'dial_code' => '225', 'phone_number' => '0712345678', 'accept_terms' => true])->assertAccepted();
        $this->postJson('/api/v1/auth/promoter/register/verify', ['dial_code' => '225', 'phone_number' => '0712345678', 'code' => '123456', ...$this->device()])->assertCreated();

        $consents = User::query()->where('phone', '+2250712345678')->firstOrFail()->consents()->pluck('legal_document_id');

        $this->assertEqualsCanonicalizing(LegalDocument::query()->whereIn('code', ['cgu', 'privacy'])->pluck('id')->all(), $consents->all());
    }
}
