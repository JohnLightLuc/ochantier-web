<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\PaymentOperator;
use App\Support\Auth\AdminSessions;
use App\Support\Auth\DeviceContext;
use Database\Seeders\AdminAccessSeeder;
use Database\Seeders\CommercialOfferSeeder;
use Tests\Feature\Promoter\PromoterTestCase;

/**
 * API du Back Office, module Paramètres : opérateurs de paiement proposés aux payeurs.
 */
class PaymentOperatorAdminTest extends PromoterTestCase
{
    private Admin $super;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([AdminAccessSeeder::class, CommercialOfferSeeder::class]);
        $this->super = Admin::factory()->role('super_admin')->create();
    }

    private function as(Admin $admin): static
    {
        return $this->withAccessToken(app(AdminSessions::class)->open($admin, new DeviceContext('Chrome', null, 'web', null, '127.0.0.1', 'phpunit'))->accessToken);
    }

    private function url(string $code): string
    {
        return '/api/v1/admin/settings/payment-operators/'.PaymentOperator::query()->where('code', $code)->sole()->id;
    }

    public function test_operators_are_listed_and_a_change_reaches_the_payers(): void
    {
        $this->as($this->super)->getJson('/api/v1/admin/settings/payment-operators')
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('data.0.code', 'orange_money')
            ->assertJsonPath('data.0.collection_fee_percent', 1.5)
            ->assertJsonPath('data.0.min_amount', null);

        $this->patchJson($this->url('orange_money'), ['collection_fee_percent' => 2, 'min_amount' => 500, 'max_amount' => 2000000, 'notice' => 'Validation plus lente ce soir.', 'reason' => 'Nouvelle grille de l’opérateur'])
            ->assertOk()
            ->assertJsonPath('data.collection_fee_percent', 2)
            ->assertJsonPath('data.max_amount', 2000000);

        $audit = AuditLog::query()->where('action', 'settings.payment_operator_updated')->sole();
        $this->assertSame(1.5, (float) $audit->before_values['collection_fee_percent']);
        $this->assertSame('Nouvelle grille de l’opérateur', $audit->after_values['reason']);

        $this->actingAsPromoter($this->promoter)->getJson('/api/v1/promoter/payments/options')
            ->assertOk()
            ->assertJsonPath('data.operators.0.code', 'orange_money')
            ->assertJsonPath('data.operators.0.fee_percent', 2)
            ->assertJsonPath('data.operators.0.min_amount', 500)
            ->assertJsonPath('data.operators.0.max_amount', 2000000)
            ->assertJsonPath('data.operators.0.notice', 'Validation plus lente ce soir.');
    }

    public function test_a_disabled_operator_is_no_longer_offered(): void
    {
        $this->as($this->super)->patchJson($this->url('wave'), ['is_active' => false, 'reason' => 'Incident chez l’opérateur'])->assertOk()->assertJsonPath('data.is_active', false);

        $this->actingAsPromoter($this->promoter)->getJson('/api/v1/promoter/payments/options')->assertJsonPath('data.operators.*.code', ['orange_money', 'mtn_momo', 'moov_money']);
        $this->postJson('/api/v1/promoter/payments/quote', ['plan_code' => 'equipe', 'operator_code' => 'wave'])->assertJsonValidationErrors(['operator_code' => 'Moyen de paiement indisponible.']);
    }

    public function test_the_display_order_and_the_amount_limits_apply(): void
    {
        $this->as($this->super)->patchJson($this->url('wave'), ['sort_order' => 0, 'reason' => 'Wave en premier'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->patchJson($this->url('orange_money'), ['sort_order' => 5, 'max_amount' => 10000, 'reason' => 'Plafond provisoire'])->assertOk();

        $this->actingAsPromoter($this->promoter)->getJson('/api/v1/promoter/payments/options')->assertJsonPath('data.operators.*.code', ['mtn_momo', 'moov_money', 'wave', 'orange_money']);
        $this->postJson('/api/v1/promoter/payments/quote', ['plan_code' => 'equipe', 'operator_code' => 'orange_money'])->assertJsonValidationErrors(['operator_code']);
        $this->postJson('/api/v1/promoter/payments/quote', ['plan_code' => 'equipe', 'operator_code' => 'wave'])->assertOk();
    }

    public function test_changes_are_validated_and_need_a_reason(): void
    {
        $url = $this->url('orange_money');

        $this->as($this->super)->patchJson($url, ['collection_fee_percent' => 2])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->patchJson($url, ['collection_fee_percent' => 25, 'reason' => 'Frais trop élevés'])->assertUnprocessable()->assertJsonValidationErrors('collection_fee_percent');
        $this->patchJson($url, ['min_amount' => 5000, 'max_amount' => 1000, 'reason' => 'Bornes inversées'])->assertUnprocessable()->assertJsonValidationErrors('max_amount');
        $this->patchJson($url, ['collection_fee_percent' => 1.5, 'reason' => 'Même valeur'])->assertUnprocessable()->assertJsonPath('errors.reason.0', 'Aucun réglage n’a changé.');

        $this->assertSame(0, AuditLog::query()->where('action', 'settings.payment_operator_updated')->count());
    }

    public function test_rights_are_enforced(): void
    {
        $this->as(Admin::factory()->role('direction')->create())->getJson('/api/v1/admin/settings/payment-operators')->assertOk();
        $this->patchJson($this->url('wave'), ['is_active' => false, 'reason' => 'Sans droit de modification'])->assertForbidden();
        $this->as(Admin::factory()->role('support')->create())->getJson('/api/v1/admin/settings/payment-operators')->assertForbidden();

        $this->assertTrue(PaymentOperator::query()->where('code', 'wave')->sole()->is_active);
    }
}
