<?php

namespace Tests\Feature\Admin;

use App\Enums\ClaimStatus;
use App\Enums\OrganizationMemberStatus;
use App\Enums\OrganizationRole;
use App\Models\Admin;
use App\Models\Claim;
use App\Models\CommercialProfile;
use App\Models\Notification;
use App\Models\User;
use App\Models\Worker;
use App\Support\Auth\AdminSessions;
use App\Support\Auth\DeviceContext;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Promoter\ConfirmedSite;
use Tests\Feature\Promoter\PromoterTestCase;

/**
 * Réclamations : dépôt dans l’application Promoteur, saisie et traitement au Back Office.
 */
class ClaimAdminTest extends PromoterTestCase
{
    use ConfirmedSite;

    private Admin $support;

    protected function setUp(): void
    {
        parent::setUp();

        $this->confirmSite();
        $this->support = Admin::factory()->role('support')->create();
    }

    private function as(Admin $admin): static
    {
        return $this->withAccessToken(app(AdminSessions::class)->open($admin, new DeviceContext('Chrome', null, 'web', null, '127.0.0.1', 'phpunit'))->accessToken);
    }

    private function deposit(array $data = []): TestResponse
    {
        return $this->postJson('/api/v1/promoter/claims', ['category' => 'paiement', 'subject' => 'Échéance payée deux fois', 'description' => 'J’ai été débité deux fois pour la même semaine.', 'site_id' => $this->site->id, ...$data]);
    }

    private function url(string $id, string $action = ''): string
    {
        return "/api/v1/admin/claims/{$id}".($action ? "/{$action}" : '');
    }

    public function test_a_promoter_files_a_claim_and_follows_it(): void
    {
        $id = $this->deposit(['assignment_id' => $this->assignmentOf('Kouamé Koffi')->id])
            ->assertCreated()
            ->assertJsonPath('data.status.value', 'ouverte')
            ->assertJsonPath('data.site.id', $this->site->id)
            ->json('data.id');

        $this->getJson('/api/v1/promoter/claims')
            ->assertOk()
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.category.label', 'Paiement')
            ->assertJsonPath('meta.sites.0.id', $this->site->id);
        $this->deposit()->assertUnprocessable()->assertJsonValidationErrors('subject');

        $this->as($this->support)->postJson($this->url($id, 'take'))->assertOk()->assertJsonPath('data.status.value', 'en_traitement')->assertJsonPath('data.owner', $this->support->fullName())->assertJsonPath('data.other_party.name', 'Kouamé Koffi');
        $this->postJson($this->url($id, 'resolve'), ['reason' => 'Le doublon a été remboursé.'])->assertOk()->assertJsonPath('data.status.value', 'resolue');
        $this->postJson($this->url($id, 'resolve'), ['reason' => 'Le doublon a été remboursé.'])->assertUnprocessable()->assertJsonValidationErrors('claim');

        $this->actingAsPromoter($this->promoter)->getJson("/api/v1/promoter/claims/{$id}")
            ->assertOk()
            ->assertJsonPath('data.status.value', 'resolue')
            ->assertJsonPath('data.answer', 'Le doublon a été remboursé.');
        $this->assertSame(2, Notification::query()->where('event_code', 'claim.updated')->where('recipient_id', $this->promoter->id)->count());

        $this->as($this->support)->postJson($this->url($id, 'close'))->assertOk()->assertJsonPath('data.status.value', 'close');
    }

    public function test_a_promoter_only_reaches_its_own_sites_and_claims(): void
    {
        $id = $this->deposit()->json('data.id');
        $other = User::factory()->promoter()->create();
        $this->actingAsPromoter($other);
        $other->promoterProfile()->firstOrCreate([]);

        $this->getJson("/api/v1/promoter/claims/{$id}")->assertNotFound();
        $this->getJson('/api/v1/promoter/claims')->assertOk()->assertJsonCount(0, 'data');
        $this->deposit()->assertUnprocessable()->assertJsonValidationErrors('site_id');
    }

    public function test_an_observer_collaborator_cannot_file_a_claim(): void
    {
        $this->subscribeOwner();
        $organization = $this->promoter->ownedOrganizations()->create(['legal_name' => 'ADJEMIN']);
        $observer = User::factory()->create(['user_type' => 'promoter']);
        $organization->members()->create(['user_id' => $observer->id, 'role' => OrganizationRole::Lecteur, 'status' => OrganizationMemberStatus::Actif, 'joined_at' => now()]);

        $this->actingAsPromoter($observer)->getJson('/api/v1/promoter/claims')->assertForbidden();
    }

    public function test_the_back_office_files_for_a_worker_found_by_phone(): void
    {
        $worker = $this->assignmentOf('Touré Ali')->worker;
        $body = ['claimant_type' => 'worker', 'dial_code' => $worker->dial_code, 'phone_number' => $worker->phone_number, 'site_reference' => $this->site->reference, 'category' => 'paiement', 'priority' => 'haute', 'subject' => 'Semaine non reversée', 'description' => 'L’ouvrier signale une semaine non reversée.'];

        $this->as($this->support)->postJson('/api/v1/admin/claims', [...$body, 'phone_number' => '0799999999'])->assertUnprocessable()->assertJsonPath('errors.phone_number.0', 'Aucun ouvrier avec ce numéro.');
        $this->postJson('/api/v1/admin/claims', [...$body, 'site_reference' => 'CHT-INCONNU'])->assertUnprocessable()->assertJsonValidationErrors('site_reference');

        $id = $this->postJson('/api/v1/admin/claims', $body)
            ->assertCreated()
            ->assertJsonPath('data.claimant.type_label', 'Ouvrier')
            ->assertJsonPath('data.priority.value', 'haute')
            ->assertJsonPath('data.site.id', $this->site->id)
            ->json('data.id');

        $this->assertInstanceOf(Worker::class, Claim::query()->findOrFail($id)->claimant);
        $this->getJson($this->url($id))->assertOk()->assertJsonPath('data.history.0.action', 'claims.created');
    }

    public function test_the_back_office_files_for_a_promoter_and_refuses_a_site_of_someone_else(): void
    {
        $user = User::factory()->promoter()->create(['dial_code' => '225', 'phone_number' => '0712345699']);
        $user->promoterProfile()->firstOrCreate([]);

        $this->as($this->support)->postJson('/api/v1/admin/claims', ['claimant_type' => 'promoter_profile', 'dial_code' => '225', 'phone_number' => '0712345699', 'site_reference' => $this->site->reference, 'category' => 'autre', 'priority' => 'normale', 'subject' => 'Demande sur un chantier', 'description' => 'Le promoteur a appelé le support.'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('site_id');
    }

    public function test_the_list_puts_urgent_open_claims_first_and_shows_overdue_ones(): void
    {
        $normal = $this->deposit()->json('data.id');
        $urgent = $this->deposit(['subject' => 'Accident sur le chantier', 'category' => 'securite'])->json('data.id');
        Claim::query()->whereKey($urgent)->update(['priority' => 'critique']);
        Claim::query()->whereKey($normal)->update(['due_at' => now()->subDay()]);

        $this->as($this->support)->getJson('/api/v1/admin/claims')
            ->assertOk()
            ->assertJsonPath('data.0.id', $urgent)
            ->assertJsonPath('data.1.overdue', true)
            ->assertJsonPath('meta.counts.overdue', 1)
            ->assertJsonPath('meta.counts.statuses.ouverte', 2);

        $this->getJson('/api/v1/admin/claims?overdue=1')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/admin/claims?category=securite')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/admin/claims?search=Accident')->assertOk()->assertJsonCount(1, 'data');

        $this->postJson($this->url($normal, 'priority'), ['priority' => 'haute'])->assertOk()->assertJsonPath('data.priority.value', 'haute');
    }

    public function test_a_rejection_tells_the_claimant_why_and_rights_are_enforced(): void
    {
        $id = $this->deposit()->json('data.id');
        $finance = Admin::factory()->role('finance')->create();

        $this->as($finance)->getJson($this->url($id))->assertOk();
        $this->postJson($this->url($id, 'take'))->assertForbidden();

        $this->as($this->support)->postJson($this->url($id, 'reject'), ['reason' => 'Paiement bien unique après contrôle.'])->assertOk()->assertJsonPath('data.can.close', true);
        $this->assertSame(ClaimStatus::Rejetee, Claim::query()->findOrFail($id)->status);
        $this->assertTrue(Notification::query()->where('event_code', 'claim.updated')->where('body', 'like', '%Paiement bien unique%')->exists());
    }

    public function test_a_commercial_is_notified_of_a_claim_filed_for_them(): void
    {
        $user = User::factory()->create(['user_type' => 'commercial', 'dial_code' => '225', 'phone_number' => '0512345699']);
        CommercialProfile::factory()->create(['user_id' => $user->id]);

        $this->as($this->support)->postJson('/api/v1/admin/claims', ['claimant_type' => 'commercial_profile', 'dial_code' => '225', 'phone_number' => '0512345699', 'category' => 'gain_commercial', 'priority' => 'normale', 'subject' => 'Commission manquante', 'description' => 'Une commission d’octobre manque.'])->assertCreated();

        $this->assertTrue(Notification::query()->where('event_code', 'claim.updated')->where('recipient_id', $user->id)->exists());
    }
}
