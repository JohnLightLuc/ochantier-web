<?php

namespace Tests\Feature\Admin;

use App\Enums\RatingModerationStatus;
use App\Enums\RatingScope;
use App\Enums\RatingStatus;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Dispute;
use App\Models\PromoterProfile;
use App\Models\Rating;
use App\Models\User;
use App\Support\Auth\AdminSessions;
use App\Support\Auth\DeviceContext;
use App\Support\Promoter\PromoterActivity;
use Tests\Feature\Promoter\ConfirmedSite;
use Tests\Feature\Promoter\PromoterTestCase;

/**
 * API du Back Office, module Promoteurs : onglets en lecture seule de la fiche (chantiers, offres, échéancier, avis,
 * litiges, activité).
 */
class PromoterRelationsAdminTest extends PromoterTestCase
{
    use ConfirmedSite;

    private PromoterProfile $profile;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->confirmSite();
        $this->profile = $this->promoter->promoterProfile;
        $this->admin = Admin::factory()->role('operations')->create(['first_name' => 'Awa', 'last_name' => 'Koné']);
    }

    private function asAdmin(): static
    {
        return $this->withAccessToken(app(AdminSessions::class)->open($this->admin, new DeviceContext('Chrome', null, 'web', null, '127.0.0.1', 'phpunit'))->accessToken);
    }

    private function url(string $tab): string
    {
        return "/api/v1/admin/promoters/{$this->profile->id}/{$tab}";
    }

    public function test_the_sites_and_offers_of_the_account_are_listed(): void
    {
        $this->asAdmin()->getJson($this->url('sites'))
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Résidence Les Palmiers')
            ->assertJsonPath('data.0.status.value', 'confirme')
            ->assertJsonPath('data.0.offers_count', 1)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.counts.all', 1)
            ->assertJsonPath('meta.counts.confirme', 1);

        $this->getJson($this->url('sites').'?status=termine')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson($this->url('sites').'?status=inconnu')->assertUnprocessable();

        $this->getJson($this->url('offers'))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.site.title', 'Résidence Les Palmiers')
            ->assertJsonPath('data.0.workers_count', 3)
            ->assertJsonCount(2, 'data.0.needs');
        $this->getJson($this->url('offers').'?site_id='.fake()->uuid())->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_the_payment_schedule_matches_what_the_promoter_sees(): void
    {
        $mine = $this->getJson('/api/v1/promoter/payments')->assertOk();

        $this->asAdmin()->getJson($this->url('payments'))
            ->assertOk()
            ->assertJsonCount(count($mine->json('data')), 'data')
            ->assertJsonPath('meta.totals.payes', $mine->json('meta.totals.payes'))
            ->assertJsonPath('meta.totals.a_regler', $mine->json('meta.totals.a_regler'))
            ->assertJsonMissingPath('data.0.can_pay');
    }

    public function test_ratings_and_disputes_of_the_account_sites_are_listed(): void
    {
        Rating::query()->create(['rating_scope' => RatingScope::Equipe, 'site_id' => $this->site->id, 'author_user_id' => $this->promoter->id, 'overall_score' => 4, 'comment' => 'Bon travail', 'status' => RatingStatus::Publie, 'published_at' => now()]);
        Rating::query()->create(['rating_scope' => RatingScope::Equipe, 'site_id' => $this->site->id, 'author_user_id' => User::factory()->promoter()->create()->id, 'overall_score' => 1, 'status' => RatingStatus::Masque, 'moderation_status' => RatingModerationStatus::Masque, 'moderation_reason' => 'Propos injurieux', 'published_at' => now()->subDay()]);
        Dispute::query()->create(['reference' => 'LIT-2026-0001', 'category' => 'paiement', 'opened_by_type' => $this->promoter->getMorphClass(), 'opened_by_id' => $this->promoter->id, 'site_id' => $this->site->id, 'description' => 'Ouvrier absent deux jours', 'urgency' => 'haute']);

        $this->asAdmin()->getJson($this->url('ratings'))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.score', 4)
            ->assertJsonPath('data.0.status.value', 'publie')
            ->assertJsonPath('data.1.moderation.reason', 'Propos injurieux')
            ->assertJsonPath('meta.count', 1)
            ->assertJsonPath('meta.hidden_count', 1)
            ->assertJsonPath('meta.average', 4);

        $this->getJson($this->url('disputes'))
            ->assertOk()
            ->assertJsonPath('data.0.reference', 'LIT-2026-0001')
            ->assertJsonPath('data.0.opened_by.kind', 'promoter')
            ->assertJsonPath('data.0.urgency.value', 'haute')
            ->assertJsonPath('data.0.status.value', 'ouvert');
    }

    public function test_the_activity_merges_promoter_and_admin_actions(): void
    {
        AuditLog::query()->create(['actor_type' => $this->promoter->getMorphClass(), 'actor_id' => $this->promoter->id, 'action' => 'sites.publish', 'module' => PromoterActivity::MODULE, 'subject_type' => $this->profile->getMorphClass(), 'subject_id' => $this->profile->id, 'after_values' => ['site' => 'Résidence Les Palmiers', 'role' => 'Titulaire'], 'created_at' => now()->subHour()]);
        $this->asAdmin()->postJson("/api/v1/admin/promoters/{$this->profile->id}/messages", ['title' => 'Bienvenue', 'body' => 'Votre chantier est confirmé.'])->assertOk();

        $this->getJson($this->url('activity'))
            ->assertOk()
            ->assertJsonPath('data.0.kind.value', 'admin')
            ->assertJsonPath('data.0.author', 'Awa Koné')
            ->assertJsonPath('data.0.what', 'a envoyé un message')
            ->assertJsonPath('data.1.kind.value', 'promoter')
            ->assertJsonPath('data.1.what', 'a publié le chantier')
            ->assertJsonPath('data.1.site', 'Résidence Les Palmiers');

        $this->getJson($this->url('activity').'?author=promoter')->assertOk()->assertJsonMissing(['kind' => ['value' => 'admin', 'label' => 'Administrateur']]);
        $this->getJson($this->url('activity').'?author=admin')->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_the_tabs_need_the_read_permission(): void
    {
        $this->asAdmin()->getJson('/api/v1/admin/promoters/'.fake()->uuid().'/sites')->assertNotFound();

        $this->admin = Admin::factory()->create();

        foreach (['sites', 'offers', 'payments', 'ratings', 'disputes', 'activity'] as $tab) {
            $this->asAdmin()->getJson($this->url($tab))->assertForbidden();
        }
    }
}
