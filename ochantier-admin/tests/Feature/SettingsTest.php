<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Models\Setting;
use App\Models\SettingChange;
use App\Models\User;
use App\Models\Worker;
use App\Support\Settings;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['name' => 'N. Yao', 'email' => 'n.yao@ochantier.ci']);
        $this->seed(SettingsSeeder::class);
        $this->admin->refresh();
        $this->actingAs($this->admin);
    }

    public function test_every_section_renders(): void
    {
        $this->get('/parametres')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Settings/Page')->where('section', 'commissions')->has('nav', 10)->where('values.z', 10)->where('values', fn ($values): bool => $values['z.on'] === true)->where('values.rsx', [80]));

        foreach (array_diff(array_keys(Settings::SECTIONS), ['utilisateurs', 'audit']) as $section) {
            $this->get("/parametres/{$section}")->assertOk()->assertInertia(fn (Assert $page) => $page->component('Settings/Page')->where('section', $section));
        }
        $this->get('/parametres/integrations')->assertInertia(fn (Assert $page) => $page->has('tables.0.rows', 16));
        $this->get('/parametres/inconnu')->assertNotFound();
    }

    public function test_saving_commissions_updates_the_rules_used_by_the_modules_and_the_audit(): void
    {
        $this->post('/parametres/commissions', ['values' => ['z' => '12', 'x.on' => false, 'rsx' => [70, 90]]])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(12, config('admin.worker_commission_rate'));
        $this->assertSame(0, config('admin.need_rules.deposit_rate'));
        $this->assertSame(12, Setting::query()->where('key', 'z')->value('value'));
        $this->assertDatabaseHas('setting_changes', ['section' => 'Commissions & frais', 'action' => 'Commission ouvrier (Z) modifié de 10 % à 12 %', 'author' => 'N. Yao']);
        $this->assertDatabaseHas('setting_changes', ['action' => 'Acompte initial promoteur (X) inactif']);
        $this->get('/parametres/commissions')->assertInertia(fn (Assert $page) => $page->where('values.z', 12)->where('values.x', 30)->where('values.rsx', [70, 90])->where('lastChange.author', 'N. Yao'));

        $count = SettingChange::count();
        $this->post('/parametres/commissions', ['values' => ['z' => '12']])->assertSessionHasNoErrors();
        $this->assertSame($count, SettingChange::count());
    }

    public function test_invalid_values_are_rejected(): void
    {
        $this->post('/parametres/commissions', ['values' => ['z' => '150', 'y' => '7.5', 'rsx' => [40]]])->assertSessionHasErrors(['z', 'y', 'rsx']);
        $this->post('/parametres/matching', ['values' => ['mx.w.metier' => 30, 'mx.rayon' => 50, 'mx.s.1' => 90]])->assertSessionHasErrors(['group.0', 'mx.rayon', 'mx.s.1']);
        $this->post('/parametres/general', ['values' => ['plat.email' => 'pas-un-email', 'plat.nom' => ' ']])->assertSessionHasErrors(['plat.email', 'plat.nom']);
        $this->post('/parametres/matching', ['values' => ['aff.confDelai' => 72]])->assertSessionHasErrors(['aff.confDelai']);

        $this->assertSame(0, Setting::count());
        $this->assertSame(10, config('admin.worker_commission_rate'));
        $this->post('/parametres/audit', ['values' => []])->assertNotFound();
    }

    public function test_matching_weights_and_fees_are_applied(): void
    {
        $this->post('/parametres/matching', ['values' => ['mx.w.metier' => 30, 'mx.w.cout' => 5, 'mx.rayon' => 25]])->assertSessionHasNoErrors();
        $this->assertSame(30, config('admin.matching.weights.metier.1'));
        $this->assertSame(25, config('admin.matching.radius'));

        $this->post('/parametres/paiements', ['values' => ['fee.Wave.p' => '1,5']])->assertSessionHasNoErrors();
        $this->assertSame(1.5, config('admin.payments.fees.Wave.0'));
    }

    public function test_trades_can_be_added_and_used_trades_are_protected(): void
    {
        Worker::factory()->create(['trade' => 'Soudeur', 'status' => 'validated']);
        $catalog = Settings::tradeCatalog();

        $without = array_values(array_filter($catalog, fn (array $t): bool => $t['name'] !== 'Soudeur'));
        $this->post('/parametres/metiers', ['values' => ['trades' => $without]])->assertSessionHasErrors('trades');
        $off = array_map(fn (array $t): array => $t['name'] === 'Soudeur' ? ['name' => 'Soudeur', 'active' => false] : $t, $catalog);
        $this->post('/parametres/metiers', ['values' => ['trades' => $off]])->assertSessionHasErrors('trades');

        $this->post('/parametres/metiers', ['values' => ['trades' => [...array_map(fn (array $t): array => $t['name'] === 'Coffreur' ? ['name' => 'Coffreur', 'active' => false] : $t, $catalog), ['name' => 'Étancheur', 'active' => true]]]])->assertSessionHasNoErrors();
        $this->assertContains('Étancheur', config('admin.trades'));
        $this->assertNotContains('Coffreur', config('admin.trades'));
        $this->assertDatabaseHas('setting_changes', ['action' => 'Métiers ajoutés : Étancheur · désactivés : Coffreur']);
    }

    public function test_integrations_can_be_tested_and_suspended(): void
    {
        $signature = Integration::query()->where('name', 'Signature électronique')->first();
        $this->post("/parametres/integrations/{$signature->id}/test")->assertSessionHasNoErrors();
        $this->assertSame('Erreur', $signature->fresh()->status);
        $this->assertFalse($signature->fresh()->test_ok);
        $this->assertDatabaseHas('setting_changes', ['action' => 'Test de connexion Signature électronique en échec', 'new_value' => 'HTTP 401 · certificat expiré']);

        $sms = Integration::query()->where('name', 'Passerelle SMS')->first();
        $this->post("/parametres/integrations/{$sms->id}/test");
        $this->assertTrue($sms->fresh()->test_ok);
        $this->post("/parametres/integrations/{$sms->id}/statut");
        $this->assertSame('Suspendu', $sms->fresh()->status);
        $this->post("/parametres/integrations/{$sms->id}/test")->assertSessionHasErrors('integration');

        $this->get('/parametres/integrations')->assertInertia(fn (Assert $page) => $page->where('tables.0.rows', fn ($rows) => ! str_contains(json_encode($rows), $sms->fresh()->secret)));
    }

}
