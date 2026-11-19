<?php

namespace Tests\Feature;

use App\Support\Api\ApiTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Pages Offres contractuelles branchées sur l’API (api/v1/admin/contract-offers) : liste, fiche et décisions.
 */
class ContractOffersTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'http://localhost:8001/api/v1/admin';

    private const ID = '01a1f50c-0000-7000-8000-0000000000f1';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.ochantier_api.url' => self::API]);
        $this->withSession([]);
        $this->startSession();
        $this->signIn(['offers.read', 'offers.update', 'offers.validate']);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function signIn(array $permissions): void
    {
        app(ApiTokens::class)->store([
            'access_token' => 'access-1',
            'refresh_token' => 'refresh-1',
            'expires_in' => 900,
            'session_id' => '9a0e0000-0000-4000-8000-000000000001',
            'admin' => ['id' => '1', 'full_name' => 'Awa Koné', 'email' => 'a.kone@ochantier.ci', 'roles' => [], 'permissions' => $permissions, 'must_change_password' => false],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(array $overrides = []): array
    {
        return [
            'id' => self::ID, 'reference' => 'OF-2026-0040', 'title' => 'Résidence Les Acacias',
            'site' => ['id' => 's1', 'label' => 'Résidence Les Acacias', 'commune' => 'Cocody', 'city' => 'Abidjan'], 'promoter' => ['name' => 'Awa Kouamé'],
            'price' => 3300000, 'payment_mode' => ['value' => 'par_etape', 'label' => 'Par étape'], 'start_date' => '2026-11-25', 'end_date' => '2026-12-24',
            'status' => ['value' => 'en_comparaison', 'label' => 'En comparaison'], 'responses_count' => 2, 'counter_proposals_count' => 1,
            'retained' => null, 'quote_request' => ['id' => 'q1', 'reference' => 'DV-2026-0012'],
            'application_deadline' => '2026-11-21T10:00:00+00:00', 'published_at' => '2026-11-18T10:00:00+00:00',
            ...$overrides,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(array $overrides = []): array
    {
        $response = fn (string $id, string $name, string $mode, int $amount, int $commission, bool $author) => [
            'id' => $id, 'contractor' => ['id' => 'c'.$id, 'reference' => 'ENT-0001'.$id, 'name' => $name, 'rating' => 4.5], 'mode' => ['value' => $mode, 'label' => $mode],
            'amount' => $amount, 'commission' => $commission, 'total' => $amount + $commission, 'deposit' => 1000000, 'duration_days' => 30, 'team_size' => 6,
            'start_date' => '2026-11-25', 'end_date' => '2026-12-24', 'distance_km' => 3.2, 'message' => null, 'status' => ['value' => 'proposee', 'label' => 'Proposée'],
            'is_shown' => true, 'is_quote_author' => $author, 'chosen_at' => null, 'sent_at' => '2026-11-19T10:00:00+00:00',
        ];

        return [
            ...$this->row(),
            'description' => 'Villa R+1.',
            'promoter' => ['name' => 'Awa Kouamé', 'phone' => '+225 07 12 34 99 99', 'email' => null],
            'rates' => ['commission_percent' => 10, 'deposit_percent' => 30],
            'extension_count' => 0, 'cancellation_reason' => null, 'milestones_count' => 3,
            'quote_request' => ['id' => 'q1', 'reference' => 'DV-2026-0012', 'line_ids' => ['l1']],
            'responses' => [$response('1', 'BATI-CI Construction', 'conforme', 3000000, 300000, true), $response('2', 'Ivoire Bâtiment', 'contre_proposition', 3200000, 320000, false)],
            'attribution' => null,
            'timeline' => [['id' => 'reponse:1', 'origin' => 'professionnel', 'author' => 'BATI-CI Construction', 'what' => 'a accepté les conditions', 'detail' => '3 000 000 FCFA · 30 jours', 'at' => '2026-11-19T10:00:00+00:00']],
            'actions' => ['extend' => true, 'cancel' => true, 'remind_promoter' => true, 'remind_contractors' => true],
            ...$overrides,
        ];
    }

    public function test_the_list_translates_the_tab_and_the_filters_for_the_api(): void
    {
        Http::fake([self::API.'/contract-offers*' => Http::response(['data' => [$this->row()], 'meta' => [
            'current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'total' => 1,
            'counts' => ['ouverte' => 1, 'en_comparaison' => 2, 'acompte_attendu' => 1, 'attribuee' => 4, 'expiree' => 1, 'suspendue' => 0, 'cloturee' => 0, 'annulee' => 1],
            'rates' => ['commission_percent' => 10, 'deposit_percent' => 30],
            'trades' => [['code' => 'macon', 'label' => 'Maçon']],
            'cities' => [['id' => 'ci1', 'name' => 'Abidjan']],
        ]])]);

        $this->get('/offres-contractuelles?onglet=ouvertes&q=acacias&ville=ci1')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('ContractOffers/Index')
                ->where('offers.data.0.reference', 'OF-2026-0040')
                ->where('offers.data.0.quoteReference', 'DV-2026-0012')
                ->where('offers.data.0.period', '25 nov. 2026 → 24 déc. 2026')
                ->where('offers.data.0.status', ['value' => 'en_comparaison', 'label' => 'En comparaison', 'tone' => 'p'])
                ->where('tabs.0.count', 10)
                ->where('tabs.1', ['key' => 'ouvertes', 'label' => 'Ouvertes', 'icon' => 'inbox', 'count' => 3])
                ->where('tabs.4.count', 2)
                ->where('kpis', ['open' => 3, 'comparing' => 2, 'deposit' => 1, 'attributed' => 4])
                ->where('rates', ['commission' => '10 %', 'deposit' => '30 %']));

        Http::assertSent(function (ClientRequest $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $query === ['search' => 'acacias', 'city_id' => 'ci1', 'status' => 'ouverte,en_comparaison', 'per_page' => '20'];
        });
    }

    public function test_the_fiche_compares_the_responses_at_the_promoter_price(): void
    {
        Http::fake([self::API.'/contract-offers/'.self::ID => Http::response(['data' => $this->detail()])]);

        $this->get('/offres-contractuelles/'.self::ID)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('ContractOffers/Show')
                ->where('offer.quoteLineId', 'l1')
                ->where('offer.rates', ['commission' => '10 %', 'deposit' => '30 %'])
                ->where('offer.responsesList.0.mode', ['label' => 'Conforme', 'tone' => 'g'])
                ->where('offer.responsesList.0.quoteAuthor', true)
                ->where('offer.responsesList.0.gap', 0)
                ->where('offer.responsesList.1.gap', 220000)
                ->where('offer.responsesList.1.competing', true)
                ->where('offer.timeline.0.category.label', 'Professionnel')
                ->where('offer.can.remindContractors', true)
                ->where('header.crumbs.1.label', 'OF-2026-0040 · Résidence Les Acacias'));
    }

    public function test_decisions_are_sent_to_the_api_and_a_refusal_becomes_an_error_notification(): void
    {
        Http::fake([
            self::API.'/contract-offers/'.self::ID.'/extension' => Http::response(['message' => 'Date limite prolongée.', 'data' => []]),
            self::API.'/contract-offers/'.self::ID.'/cancellation' => Http::response(['message' => 'Le promoteur est en train de payer l’acompte : réessayez dans quelques minutes.', 'errors' => ['offer' => ['Le promoteur est en train de payer l’acompte : réessayez dans quelques minutes.']]], 422),
            self::API.'/contract-offers/'.self::ID.'/reminders' => Http::response(['message' => 'Relance envoyée à 3 professionnels.', 'data' => []]),
        ]);
        $page = '/offres-contractuelles/'.self::ID;

        $this->from($page)->post($page.'/prolongation', ['deadline' => '2026-12-15'])->assertSessionHas('inertia.flash_data.toast', 'Date limite prolongée.');
        $this->from($page)->post($page.'/annulation', ['category' => 'Demande du promoteur', 'reason' => 'Chantier reporté.'])
            ->assertRedirect($page)
            ->assertSessionHas('inertia.flash_data.toastError', 'Le promoteur est en train de payer l’acompte : réessayez dans quelques minutes.');
        $this->from($page)->post($page.'/relance', ['target' => 'contractors'])->assertSessionHas('inertia.flash_data.toast', 'Relance envoyée à 3 professionnels.');

        Http::assertSent(fn (ClientRequest $r) => str_ends_with($r->url(), '/cancellation') && $r['reason'] === 'Demande du promoteur · Chantier reporté.');
        Http::assertSent(fn (ClientRequest $r) => str_ends_with($r->url(), '/reminders') && $r->data() === ['target' => 'contractors']);
    }

    public function test_the_buttons_follow_the_admin_permissions(): void
    {
        $this->signIn(['offers.read']);
        Http::fake([self::API.'/contract-offers/'.self::ID => Http::response(['data' => $this->detail()])]);

        $this->get('/offres-contractuelles/'.self::ID)->assertInertia(fn (Assert $page) => $page->where('can', ['update' => false, 'validate' => false]));
    }
}
