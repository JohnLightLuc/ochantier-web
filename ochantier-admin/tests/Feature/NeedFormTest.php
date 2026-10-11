<?php

namespace Tests\Feature;

use App\Enums\NeedAssignmentStatus;
use App\Models\Need;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class NeedFormTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['name' => 'N. Yao']));
        $this->site = Site::factory()->create(['name' => 'Tour Horizon', 'slug' => 'tour-horizon', 'promoter_name' => 'Ivoire Properties']);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $changes = []): array
    {
        return [
            'promoter' => 'Ivoire Properties', 'site' => 'tour-horizon', 'source' => 'Back-office', 'district' => 'Plateau', 'contact' => 'Ing. Brou', 'contact_phone' => '07 11 22 33 44',
            'lines' => [
                ['trade_group' => 'Gros œuvre', 'trade' => 'Maçon', 'specialty' => 'Chaînages', 'quantity' => '4', 'experience' => 'Expérimenté', 'skills' => ['Lecture de plans'], 'own_equipment' => '1'],
                ['trade_group' => 'Électricité', 'trade' => 'Électricien', 'specialty' => 'Courant fort', 'quantity' => '2', 'experience' => 'Intermédiaire'],
            ],
            'type' => 'Besoin hebdomadaire', 'urgency' => 'high', 'starts_on' => '2026-10-12', 'ends_on' => '2026-11-07', 'working_days' => ['Lun', 'Mar'], 'start_time' => '07:30', 'end_time' => '17:00',
            'break_time' => '12:00 – 13:00', 'application_delay' => '5 jours', 'weeks' => '4',
            'pay_type' => 'Journalier', 'daily_cost' => '12 000', 'transport' => '1000',
            'id_card' => '1', 'briefing' => '1', 'ppe' => ['Casque'],
            'deadline' => '2026-10-05', 'visibility' => 'Publique', 'auto_renew' => '1',
            ...$changes,
        ];
    }

    public function test_create_page_lists_sites_by_promoter(): void
    {
        $this->get('/besoins/nouveau-besoin')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Needs/Form')
            ->where('need', null)
            ->where('reference', 'BES-2026-0141')
            ->where('options.sites.Ivoire Properties.0.value', 'tour-horizon')
            ->has('options.trades.Maçon.specialties'));
    }

    public function test_need_is_created_with_one_published_offer_per_trade(): void
    {
        Storage::fake();

        $this->post('/besoins', $this->payload(['doc_specs' => UploadedFile::fake()->create('cdc.pdf', 100, 'application/pdf')]))->assertRedirect('/besoins/bes-2026-0141');

        $need = Need::where('slug', 'bes-2026-0141')->with('lines', 'offers', 'documents')->firstOrFail();
        $this->assertSame(['Maçon', 'Électricien'], $need->lines->pluck('trade')->all());
        $this->assertSame(12000, $need->lines[0]->daily_cost);
        $this->assertTrue($need->lines[0]->own_equipment);
        $this->assertSame(['BES-2026-0141/1', 'BES-2026-0141/2'], $need->offers->pluck('reference')->all());
        $this->assertSame('2026-10-05', $need->offers[0]->deadline->format('Y-m-d'));
        $this->assertSame('Transport 1000 FCFA/jour', $need->offers[0]->benefits);
        $this->assertSame('0711223344', $need->contact_phone);
        $this->assertSame('07:30 – 17:00 · pause 12:00 – 13:00', $need->working_hours);
        $this->assertSame(['Pièce d’identité', 'Briefing obligatoire'], $need->requirements);
        $this->assertSame('Cahier des charges', $need->documents->sole()->type);
        $this->assertSame(Need::SEARCHING, $need->load('offers.line', 'offers.assignments', 'assignments')->status());
        $this->assertDatabaseHas('need_activities', ['need_id' => $need->id, 'category' => 'creation']);
    }

    public function test_validation_rules_follow_the_mockup(): void
    {
        $this->post('/besoins', $this->payload([
            'site' => 'inconnu', 'contact_phone' => '0711', 'starts_on' => '2026-09-01', 'end_time' => '06:00', 'urgency' => 'critical', 'weeks' => '',
            'lines' => [['trade_group' => 'Gros œuvre', 'trade' => 'Maçon', 'quantity' => '0', 'experience' => 'Expert'], ['trade_group' => 'Gros œuvre', 'trade' => 'Maçon', 'quantity' => '2', 'experience' => 'Expert']],
            'daily_cost' => 'douze', 'deadline' => '2026-12-01',
        ]))->assertSessionHasErrors(['site', 'contact_phone', 'starts_on', 'end_time', 'justification', 'coverage_deadline', 'follow_up', 'weeks', 'lines.0.quantity', 'lines.1.trade', 'daily_cost', 'deadline']);

        $this->assertSame(0, Need::count());
    }

    public function test_need_is_edited_and_lines_with_found_workers_are_kept(): void
    {
        $this->post('/besoins', $this->payload());
        $need = Need::where('slug', 'bes-2026-0141')->firstOrFail();

        $this->get('/besoins/bes-2026-0141/modifier')->assertOk()->assertInertia(fn (Assert $page) => $page->where('values.site', 'tour-horizon')->where('values.lines.1.trade', 'Électricien')->where('values.deadline', '2026-10-05'));

        $need->offers()->where('reference', 'BES-2026-0141/2')->first()->assignments()->create([
            'need_id' => $need->id, 'reference' => 'AFF-1', 'worker_name' => 'Issa Traoré', 'trade' => 'Électricien', 'status' => NeedAssignmentStatus::ToContact, 'source' => 'Manuel', 'selected_on' => '2026-09-23',
        ]);
        $payload = $this->payload();
        $this->post('/besoins/bes-2026-0141', [...$payload, 'lines' => [$payload['lines'][0]]])->assertSessionHasErrors('lines');

        $payload['lines'][0]['quantity'] = '6';
        $this->post('/besoins/bes-2026-0141', [...$payload, 'urgency' => 'low', 'starts_on' => '2026-09-01', 'deadline' => '2026-09-25'])->assertSessionHasErrors('deadline');
        $this->post('/besoins/bes-2026-0141', [...$payload, 'urgency' => 'low'])->assertRedirect('/besoins/bes-2026-0141');
        $this->assertSame(6, $need->lines()->first()->quantity);
        $this->assertSame('low', $need->fresh()->urgency->value);
        $this->assertSame(2, $need->offers()->count());

        $need->update(['manual_status' => 'suspended']);
        $this->get('/besoins/bes-2026-0141/modifier')->assertForbidden();
    }
}
