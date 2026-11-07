<?php

namespace Tests\Feature\Admin;

use App\Enums\PricingVariable;
use App\Models\Admin;
use App\Models\MatchingWeightSet;
use App\Models\PricingParameter;
use App\Models\Setting;
use App\Models\SettingHistory;
use App\Models\Trade;
use App\Support\Admin\Refunds;
use App\Support\Auth\AdminSessions;
use App\Support\Auth\DeviceContext;
use App\Support\Pricing\PricingRules;
use Tests\Feature\Promoter\ConfirmedSite;
use Tests\Feature\Promoter\PromoterTestCase;

/**
 * API du Back Office, module Paramètres : réglages, commissions, barème et métiers.
 */
class SettingsAdminTest extends PromoterTestCase
{
    use ConfirmedSite;

    private Admin $super;

    private Admin $direction;

    protected function setUp(): void
    {
        parent::setUp();

        $this->confirmSite();
        $this->super = Admin::factory()->role('super_admin')->create();
        $this->direction = Admin::factory()->role('direction')->create();
    }

    private function as(Admin $admin): static
    {
        return $this->withAccessToken(app(AdminSessions::class)->open($admin, new DeviceContext('Chrome', null, 'web', null, '127.0.0.1', 'phpunit'))->accessToken);
    }

    private function propose(array $body = []): string
    {
        $this->as($this->super)->postJson('/api/v1/admin/settings/rates', ['code' => 'Y', 'value' => 9.5, 'effective_from' => now()->toDateString(), 'reason' => 'Nouvelle grille tarifaire', ...$body])->assertCreated();

        return PricingParameter::query()->where('status', 'en_attente_approbation')->sole()->id;
    }

    private function rate(PricingVariable $code): float
    {
        return (float) PricingParameter::query()->where('code', $code)->where('scope', 'global')->where('status', 'actif')->where('effective_from', '<=', now())->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', now()))->latest('effective_from')->value('value');
    }

    public function test_only_settings_that_act_are_listed_and_a_change_is_applied_and_kept(): void
    {
        $data = $this->as($this->super)->getJson('/api/v1/admin/settings')->assertOk()->json('data');

        $keys = collect($data['groups'])->pluck('fields')->flatten(1)->pluck('key');
        $this->assertContains('payments.dual_validation_threshold', $keys);
        $this->assertNotContains('needs.processing_hours', $keys);
        $this->assertSame(1000000, app(Refunds::class)->threshold());

        $this->postJson('/api/v1/admin/settings/values', ['key' => 'payments.dual_validation_threshold', 'value' => 500000, 'reason' => 'Contrôle renforcé'])->assertOk();

        $this->assertSame(500000, app(Refunds::class)->threshold());
        $this->assertSame(1000000, SettingHistory::query()->sole()->old_value);
        $this->getJson('/api/v1/admin/settings')->assertJsonPath('data.history.0.reason', 'Contrôle renforcé');
    }

    public function test_values_are_validated(): void
    {
        $url = '/api/v1/admin/settings/values';

        $this->as($this->super)->postJson($url, ['key' => 'needs.processing_hours', 'value' => 10, 'reason' => 'Réglage sans effet'])->assertUnprocessable()->assertJsonValidationErrors('key');
        $this->postJson($url, ['key' => 'attendance.rounding_minutes', 'value' => 0, 'reason' => 'Valeur trop basse'])->assertUnprocessable()->assertJsonValidationErrors('value');
        $this->postJson($url, ['key' => 'attendance.rounding_minutes', 'value' => 'abc', 'reason' => 'Valeur non numérique'])->assertUnprocessable();
        $this->postJson($url, ['key' => 'support.email', 'value' => 'pas-un-mail', 'reason' => 'Adresse invalide'])->assertUnprocessable();
        $this->postJson($url, ['key' => 'attendance.rounding_minutes', 'value' => 5, 'reason' => 'Même valeur'])->assertUnprocessable()->assertJsonPath('errors.value.0', 'Cette valeur est déjà en place.');
        $this->postJson($url, ['key' => 'attendance.rounding_minutes', 'value' => 10])->assertUnprocessable()->assertJsonValidationErrors('reason');
    }

    public function test_a_rate_needs_a_second_person_and_applies_from_then_on(): void
    {
        $id = $this->propose();
        $this->assertSame(8.0, $this->rate(PricingVariable::Y));

        $this->postJson("/api/v1/admin/settings/rates/{$id}/approve")->assertUnprocessable()->assertJsonPath('errors.pricing.0', 'Une autre personne doit valider ce taux.');
        $this->as($this->direction)->postJson("/api/v1/admin/settings/rates/{$id}/approve")->assertOk();

        $this->assertSame(9.5, $this->rate(PricingVariable::Y));
        $this->assertSame(9.5, app(PricingRules::class)->value(PricingVariable::Y));
        $this->getJson('/api/v1/admin/settings')->assertJsonPath('data.pricing.current.1.value', 9.5);
    }

    public function test_confirmed_missions_keep_their_frozen_rate(): void
    {
        $snapshot = $this->assignmentOf('Kouamé Koffi')->mission->pricingSnapshot;
        $before = (float) $snapshot->y_rate;

        $id = $this->propose(['value' => 12]);
        $this->as($this->direction)->postJson("/api/v1/admin/settings/rates/{$id}/approve")->assertOk();

        $this->assertSame($before, (float) $snapshot->fresh()->y_rate);
    }

    public function test_a_future_rate_waits_for_its_date(): void
    {
        $id = $this->propose(['effective_from' => now()->addDays(10)->toDateString()]);
        $this->as($this->direction)->postJson("/api/v1/admin/settings/rates/{$id}/approve")->assertOk();

        $this->assertSame(8.0, $this->rate(PricingVariable::Y));
        $this->travel(11)->days();
        $this->assertSame(9.5, $this->rate(PricingVariable::Y));
    }

    public function test_one_proposal_at_a_time_and_a_refusal_changes_nothing(): void
    {
        $id = $this->propose();
        $this->postJson('/api/v1/admin/settings/rates', ['code' => 'Y', 'value' => 10, 'effective_from' => now()->toDateString(), 'reason' => 'Deuxième proposition'])->assertUnprocessable()->assertJsonValidationErrors('pricing');

        $this->as($this->direction)->postJson("/api/v1/admin/settings/rates/{$id}/refuse", ['reason' => 'Trop tôt'])->assertOk();
        $this->assertSame(8.0, $this->rate(PricingVariable::Y));
        $this->postJson("/api/v1/admin/settings/rates/{$id}/approve")->assertUnprocessable();
        $this->postJson('/api/v1/admin/settings/rates', ['code' => 'Y', 'value' => 9, 'effective_from' => now()->subDay()->toDateString(), 'reason' => 'Date passée'])->assertForbidden();
        $this->as($this->super)->postJson('/api/v1/admin/settings/rates', ['code' => 'Y', 'value' => 9, 'effective_from' => now()->subDay()->toDateString(), 'reason' => 'Date passée'])->assertUnprocessable();
    }

    public function test_a_new_matching_scale_is_a_new_version(): void
    {
        $weights = ['metier' => 30, 'disponibilite' => 20, 'experience' => 10, 'proximite' => 15, 'cout' => 10, 'qualite_profil' => 5, 'note' => 5, 'historique' => 5];
        $url = '/api/v1/admin/settings/matching';
        $body = ['weights' => $weights, 'radius' => 25, 'radius_max' => 50, 'tolerance_max' => 20, 'reason' => 'Le métier compte davantage'];

        $this->as($this->super)->postJson($url, [...$body, 'weights' => [...$weights, 'metier' => 31]])->assertUnprocessable()->assertJsonValidationErrors('weights');
        $this->postJson($url, [...$body, 'radius_max' => 10])->assertUnprocessable()->assertJsonValidationErrors('radius_max');
        $version = (int) MatchingWeightSet::query()->max('version');

        $this->postJson($url, $body)->assertOk()->assertJsonPath('data.matching.version', $version + 1)->assertJsonPath('data.matching.weights.0.value', 30)->assertJsonPath('data.matching.radius', 25);

        $this->assertSame(1, MatchingWeightSet::query()->where('is_active', true)->count());
        $this->assertEqualsWithDelta(0.3, MatchingWeightSet::query()->where('is_active', true)->sole()->weights['metier'], 0.001);
    }

    public function test_trades_are_added_renamed_and_retired(): void
    {
        $this->as($this->super)->postJson('/api/v1/admin/settings/trades', ['label' => 'Étancheur'])->assertCreated();
        $this->postJson('/api/v1/admin/settings/trades', ['label' => 'Étancheur'])->assertUnprocessable()->assertJsonValidationErrors('label');
        $trade = Trade::query()->where('code', 'etancheur')->sole();

        $this->patchJson("/api/v1/admin/settings/trades/{$trade->id}", ['label' => 'Étancheur toiture'])->assertOk();
        $this->postJson("/api/v1/admin/settings/trades/{$trade->id}/toggle")->assertOk();

        $this->assertFalse($trade->fresh()->is_active);
        $this->assertSame('Étancheur toiture', $trade->fresh()->label);
    }

    public function test_integrations_show_the_real_state_without_secrets(): void
    {
        $response = $this->as($this->super)->getJson('/api/v1/admin/settings')->assertOk();

        $this->assertStringContainsString('Simulée', $response->json('data.integrations.runtime.0.value'));
        $this->assertTrue($response->json('data.integrations.runtime.0.warning'));
        $this->assertStringNotContainsString('secret', $response->getContent());
    }

    public function test_rights_are_enforced(): void
    {
        $this->as($this->direction)->getJson('/api/v1/admin/settings')->assertOk();
        $this->postJson('/api/v1/admin/settings/values', ['key' => 'support.hours', 'value' => 'Toute la semaine', 'reason' => 'Nouveaux horaires'])->assertForbidden();
        $this->as(Admin::factory()->role('support')->create())->getJson('/api/v1/admin/settings')->assertForbidden();
        $this->assertSame('Du lundi au samedi, de 8 h à 18 h', Setting::query()->find('support.hours')->value);
    }
}
