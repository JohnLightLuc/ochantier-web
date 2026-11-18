<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupportTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_support_contacts_are_public(): void
    {
        Setting::query()->create(['key' => 'support.opening_days', 'value' => 'Du lundi au samedi']);
        Setting::query()->create(['key' => 'support.working_hours', 'value' => 'De 8 h à 18 h']);
        Setting::query()->create(['key' => 'support.phone', 'value' => '+225 27 22 00 00 00']);
        Setting::query()->create(['key' => 'support.whatsapp', 'value' => '+225 07 00 00 00 00']);
        Setting::query()->create(['key' => 'support.email', 'value' => 'support@ochantier.ci']);

        $this->getJson('/api/v1/support')
            ->assertOk()
            ->assertExactJsonStructure(['success', 'message', 'data' => ['opening_days', 'working_hours', 'phone', 'whatsapp', 'email']])
            ->assertJsonPath('data.opening_days', 'Du lundi au samedi')
            ->assertJsonPath('data.working_hours', 'De 8 h à 18 h')
            ->assertJsonPath('data.phone', '+225 27 22 00 00 00')
            ->assertJsonPath('data.whatsapp', '+225 07 00 00 00 00')
            ->assertJsonPath('data.email', 'support@ochantier.ci');
    }

    public function test_a_missing_contact_is_null(): void
    {
        Setting::query()->create(['key' => 'support.email', 'value' => 'support@ochantier.ci']);

        $this->getJson('/api/v1/support')
            ->assertOk()
            ->assertJsonPath('data.phone', null)
            ->assertJsonPath('data.opening_days', null)
            ->assertJsonPath('data.email', 'support@ochantier.ci');
    }
}
