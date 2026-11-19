<?php

namespace Tests\Feature;

use App\Support\Api\ApiTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Pages Demandes de devis branchées sur l’API (api/v1/admin/quote-requests) : liste par métier, fiche et décisions.
 */
class QuotesTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'http://localhost:8001/api/v1/admin';

    private const ID = '01a1f40c-0000-7000-8000-0000000000e1';

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
            'id' => self::ID, 'reference' => 'DV-2026-0012', 'quote_request_id' => 'q1', 'trade' => ['code' => 'electricien', 'label' => 'Électricien'],
            'site' => ['id' => 's1', 'label' => 'Villa Riviera', 'commune' => 'Cocody', 'city' => 'Abidjan'], 'promoter' => ['name' => 'Awa Kouamé'],
            'status' => ['value' => 'visite_programmee', 'label' => 'Visite programmée'], 'bids_count' => 3,
            'selected' => ['contractor' => ['id' => 'c1', 'reference' => 'ENT-00012', 'name' => 'BATI-CI Construction'], 'amount' => 27500, 'service_fee_amount' => 2500, 'visit_date' => '2026-11-22', 'visit_slot' => '10:00 – 12:00'],
            'quote_total_amount' => null, 'response_deadline' => '2026-11-21T10:00:00+00:00', 'submitted_at' => '2026-11-18T10:00:00+00:00',
            ...$overrides,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(array $overrides = []): array
    {
        return [
            ...$this->row(),
            'request' => ['status' => ['value' => 'commande', 'label' => 'Devis commandé'], 'notes' => 'Tableau et prises.', 'trades_count' => 2, 'other_lines' => [['id' => 'l2', 'trade' => 'Plombier']], 'ordered_at' => '2026-11-19T09:00:00+00:00', 'received_at' => null, 'cancelled_at' => null],
            'promoter' => ['name' => 'Awa Kouamé', 'phone' => '+225 07 12 34 99 99', 'email' => null],
            'bids' => [
                ['id' => 'b1', 'contractor' => ['id' => 'c1', 'reference' => 'ENT-00012', 'name' => 'BATI-CI Construction', 'rating' => 4.7], 'fee_amount' => 25000, 'service_fee_percent' => 10, 'service_fee_amount' => 2500, 'amount' => 27500, 'visit_date' => '2026-11-22', 'visit_slot' => '10:00 – 12:00', 'visit_duration' => '2 h', 'distance_km' => 4.25, 'comment' => null, 'status' => ['value' => 'choisie', 'label' => 'Choisie'], 'is_selected' => true, 'is_shown' => false, 'created_at' => '2026-11-18T11:00:00+00:00'],
                ['id' => 'b2', 'contractor' => ['id' => 'c2', 'reference' => 'ENT-00013', 'name' => 'Ivoire Élec', 'rating' => null], 'fee_amount' => 40000, 'service_fee_percent' => 10, 'service_fee_amount' => 4000, 'amount' => 44000, 'visit_date' => '2026-11-21', 'visit_slot' => '08:00 – 10:00', 'visit_duration' => null, 'distance_km' => null, 'comment' => null, 'status' => ['value' => 'non_retenue', 'label' => 'Non retenue'], 'is_selected' => false, 'is_shown' => false, 'created_at' => '2026-11-18T12:00:00+00:00'],
            ],
            'visit' => ['status' => ['value' => 'planifiee', 'label' => 'Planifiée'], 'date' => '2026-11-22', 'slot' => '10:00 – 12:00', 'duration' => '2 h', 'visited_at' => null, 'reschedule' => null],
            'payment' => ['reference' => 'PAY-2026-000051', 'status' => ['value' => 'reussie', 'label' => 'Réussie'], 'amount' => 49500, 'completed_at' => '2026-11-19T09:00:00+00:00', 'covers_trades' => 2],
            'versions' => [],
            'timeline' => [['id' => 'commandee', 'origin' => 'promoteur', 'author' => 'Awa Kouamé', 'what' => 'a payé la commande des devis', 'detail' => '49 500 FCFA', 'at' => '2026-11-19T09:00:00+00:00']],
            'actions' => ['extend' => false, 'cancel' => false, 'remind_promoter' => false, 'remind_contractor' => true],
            ...$overrides,
        ];
    }

    public function test_the_list_translates_the_tab_and_the_filters_for_the_api(): void
    {
        Http::fake([self::API.'/quote-requests*' => Http::response(['data' => [$this->row()], 'meta' => [
            'current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'total' => 1,
            'counts' => ['ouverte' => 2, 'visite_programmee' => 1, 'report_propose' => 1, 'devis_en_preparation' => 0, 'devis_envoye' => 1, 'devis_recu' => 3, 'expiree' => 1, 'annulee' => 0],
            'trades' => [['code' => 'electricien', 'label' => 'Électricien']],
            'cities' => [['id' => 'ci1', 'name' => 'Abidjan']],
        ]])]);

        $this->get('/demandes-devis?onglet=en-cours&q=riviera&metier=electricien&ville=ci1')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Quotes/Index')
                ->where('quotes.data.0.trade', 'Électricien')
                ->where('quotes.data.0.place', 'Abidjan · Cocody')
                ->where('quotes.data.0.contractor', 'BATI-CI Construction')
                ->where('quotes.data.0.amount', 27500)
                ->where('quotes.data.0.status', ['value' => 'visite_programmee', 'label' => 'Visite programmée', 'tone' => 'a'])
                ->where('quotes.data.0.deadline', null)
                ->where('quotes.data.0.visit', '22 nov. 2026 · 10:00 – 12:00')
                ->where('tabs.0.count', 9)
                ->where('tabs.2', ['key' => 'en-cours', 'label' => 'En cours', 'icon' => 'autorenew', 'count' => 3])
                ->where('tabs.3.count', 4)
                ->where('kpis', ['open' => 2, 'inProgress' => 3, 'toNudge' => 2, 'received' => 3]));

        Http::assertSent(function (ClientRequest $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $query === ['search' => 'riviera', 'trade' => 'electricien', 'city_id' => 'ci1', 'status' => 'visite_programmee,report_propose,devis_en_preparation,devis_envoye', 'per_page' => '20'];
        });
    }

    public function test_the_fiche_shows_the_visit_the_finances_and_the_timeline(): void
    {
        Http::fake([self::API.'/quote-requests/'.self::ID => Http::response(['data' => $this->detail()])]);

        $this->get('/demandes-devis/'.self::ID)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Quotes/Show')
                ->where('quote.reference', 'DV-2026-0012')
                ->where('quote.otherLines.0.trade', 'Plombier')
                ->where('quote.bids.0.status', ['label' => 'Choisie', 'tone' => 'g'])
                ->where('quote.bids.0.distance', '4,3 km')
                ->where('quote.finance.1', ['Frais de service O’CHANTIER (10 %)', '2 500 FCFA'])
                ->where('quote.payment.shared', 'Commande groupée de 2 métiers')
                ->where('quote.visitInfo.date', '22 nov. 2026 · 10:00 – 12:00')
                ->where('quote.timeline.0.category.label', 'Promoteur')
                ->where('quote.can.remindContractor', true)
                ->where('can', ['update' => true, 'validate' => true])
                ->where('header.crumbs.1.label', 'DV-2026-0012 · Électricien'));
    }

    public function test_decisions_are_sent_to_the_api_and_a_refusal_becomes_an_error_notification(): void
    {
        Http::fake([
            self::API.'/quote-requests/'.self::ID.'/extension' => Http::response(['message' => 'Date limite prolongée.', 'data' => []]),
            self::API.'/quote-requests/'.self::ID.'/cancellation' => Http::response(['message' => 'Une demande déjà commandée (visite payée) ne peut plus être annulée.', 'errors' => ['quote_request' => ['Une demande déjà commandée (visite payée) ne peut plus être annulée.']]], 422),
            self::API.'/quote-requests/'.self::ID.'/reminders' => Http::response(['message' => 'Relance envoyée au professionnel.', 'data' => []]),
        ]);
        $page = '/demandes-devis/'.self::ID;

        $this->from($page)->post($page.'/prolongation', ['deadline' => '2026-12-15'])->assertSessionHas('inertia.flash_data.toast', 'Date limite prolongée.');
        $this->from($page)->post($page.'/annulation', ['category' => 'Demande en double', 'reason' => 'Autre demande publiée.'])
            ->assertRedirect($page)
            ->assertSessionHas('inertia.flash_data.toastError', 'Une demande déjà commandée (visite payée) ne peut plus être annulée.');
        $this->from($page)->post($page.'/relance', ['target' => 'contractor', 'message' => ''])->assertSessionHas('inertia.flash_data.toast', 'Relance envoyée au professionnel.');

        Http::assertSent(fn (ClientRequest $r) => str_ends_with($r->url(), '/cancellation') && $r['reason'] === 'Demande en double · Autre demande publiée.');
        Http::assertSent(fn (ClientRequest $r) => str_ends_with($r->url(), '/reminders') && $r->data() === ['target' => 'contractor']);
    }

    public function test_the_buttons_follow_the_admin_permissions(): void
    {
        $this->signIn(['offers.read']);
        Http::fake([self::API.'/quote-requests/'.self::ID => Http::response(['data' => $this->detail()])]);

        $this->get('/demandes-devis/'.self::ID)->assertInertia(fn (Assert $page) => $page->where('can', ['update' => false, 'validate' => false]));
    }
}
