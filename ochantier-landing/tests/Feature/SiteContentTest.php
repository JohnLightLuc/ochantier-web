<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Contenu du site : version publiée dans le Back Office (lue dans l’API, gardée en cache), puis dernière version
 * reçue et textes d’origine si l’API ne répond pas ; fichiers publiés recopiés localement.
 */
class SiteContentTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'http://api.test/api/v1';

    private const FILE = '01a2004e-89b8-717f-a7be-59712dd71b75';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.ochantier_api.url' => self::API, 'services.ochantier_api.key' => 'cle-du-site']);
        Storage::fake('local');
    }

    /**
     * @param  array<string, mixed>  $sections
     * @return array<string, mixed>
     */
    private function published(int $version, array $sections = [], array $hidden = []): array
    {
        $defaults = json_decode((string) file_get_contents(resource_path('content/landing.json')), true);
        $content = $defaults['content'];

        foreach ($sections as $key => $values) {
            $content['sections'][$key] = [...$content['sections'][$key], ...$values];
        }

        return ['data' => [
            'version' => $version,
            'published_at' => '2026-11-21T10:00:00+00:00',
            'content' => [...$content, 'hidden' => $hidden],
            'plans' => [['code' => 'equipe', 'name' => 'Équipe', 'subtitle' => null, 'description' => null, 'price_amount' => 15000, 'billing_period' => 'mois', 'max_users' => 5, 'features' => ['Jusqu’à 5 utilisateurs'], 'is_featured' => true]],
            'files' => [self::FILE => ['name' => 'partage.png', 'mime_type' => 'image/png']],
        ]];
    }

    /**
     * @return array<string, mixed>
     */
    private function site(TestResponse $response): array
    {
        preg_match('/window\.OCH_SITE = (\{.*?\});<\/script>/s', (string) $response->getContent(), $m);

        return json_decode($m[1], true);
    }

    public function test_la_page_affiche_la_version_publiee_et_son_referencement(): void
    {
        Http::fake([self::API.'/landing/content' => Http::response($this->published(4, ['seo' => ['title' => 'O’CHANTIER · Titre publié', 'indexable' => false, 'og_image' => self::FILE], 'cover' => ['title' => 'Nouveau titre']], ['tarifs']))]);

        $response = $this->get('/')
            ->assertOk()
            ->assertSee('<title>O’CHANTIER · Titre publié</title>', false)
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false)
            ->assertSee('/fichiers/'.self::FILE, false)
            ->assertSee('{{ C.cover.title }}', false);
        $site = $this->site($response);

        $this->assertSame(4, $site['version']);
        $this->assertSame('Nouveau titre', $site['sections']['cover']['title']);
        $this->assertSame(['tarifs'], $site['hidden']);
        $this->assertSame('Équipe', $site['plans'][0]['name']);
        $this->assertStringEndsWith('/fichiers/'.self::FILE, $site['files'][self::FILE]);

        $this->get('/');
        Http::assertSentCount(1);
    }

    public function test_sans_api_le_site_garde_la_derniere_version_recue_puis_les_textes_d_origine(): void
    {
        $api = 'injoignable';
        Http::fake([self::API.'/landing/content' => function () use (&$api) {
            return match ($api) {
                'injoignable' => Http::failedConnection(),
                'en_erreur' => Http::response(['message' => 'Erreur'], 500),
                default => Http::response($this->published(5, ['cover' => ['title' => 'Version 5']])),
            };
        }]);

        $site = $this->site($this->get('/')->assertOk()->assertSee('<title>O’CHANTIER · Recrutez des ouvriers du BTP qualifiés, ici et maintenant</title>', false));
        $this->assertNull($site['version']);
        $this->assertSame('Trouvez vos prochains ouvriers.', $site['sections']['cover']['title']);
        $this->assertSame('Solo', $site['plans'][0]['name']);

        $api = 'en_ligne';
        $this->assertNull($this->site($this->get('/'))['version'], 'Après un échec, l’API n’est rappelée qu’au bout d’une minute.');
        $this->travel(2)->minutes();
        $this->assertSame(5, $this->site($this->get('/'))['version']);

        $api = 'en_erreur';
        $this->travel(10)->minutes();
        $site = $this->site($this->get('/')->assertOk());
        $this->assertSame(5, $site['version']);
        $this->assertSame('Version 5', $site['sections']['cover']['title']);
    }

    public function test_le_formulaire_de_contact_lit_les_profils_sans_appeler_l_api(): void
    {
        Http::fake([self::API.'/landing/contact-messages' => Http::response(['data' => ['reference' => 'MSG-000009']], 201)]);
        Storage::disk('local')->put('landing/contenu.json', json_encode($this->published(3, ['contact' => ['profiles' => ['Architecte']]])['data']));

        $this->postJson('/contact', ['profil' => 'Architecte', 'nom' => 'Awa Koné', 'tel' => '+225 07 00 00 00 00', 'email' => 'awa@exemple.ci', 'objet' => 'Partenariat', 'message' => 'Parlons d’un partenariat.', 'consent' => '1'])->assertCreated();
        Http::assertSentCount(1);
    }

    public function test_un_contenu_incomplet_est_complete_par_les_textes_d_origine(): void
    {
        $published = $this->published(6);
        unset($published['data']['content']['sections']['footer'], $published['data']['content']['sections']['cover']['live']);
        $published['data']['content']['order'] = ['contact', 'demo', 'inconnue'];
        $published['data']['plans'] = [];
        Http::fake([self::API.'/landing/content' => Http::response($published)]);

        $site = $this->site($this->get('/'));

        $this->assertSame('Ouvriers disponibles maintenant', $site['sections']['cover']['live']);
        $this->assertSame('© 2026 O’CHANTIER · Mentions légales · CGU', $site['sections']['footer']['legal']);
        $this->assertSame(['contact', 'demo', 'solution', 'paiement', 'tarifs', 'carte', 'app'], $site['order']);
        $this->assertSame('Solo', $site['plans'][0]['name']);
    }

    public function test_un_fichier_publie_est_recopie_puis_servi_localement(): void
    {
        Http::fake([
            self::API.'/landing/content' => Http::response($this->published(4)),
            self::API.'/landing/files/'.self::FILE => Http::response('PNG-BYTES', 200, ['Content-Type' => 'image/png']),
        ]);

        $this->get('/fichiers/'.self::FILE)->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get('/fichiers/'.self::FILE)->assertOk();
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/landing/files/'.self::FILE) && $r->hasHeader('X-Landing-Key', 'cle-du-site'));
        $this->assertSame(1, collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), '/landing/files/'))->count());

        $this->get('/fichiers/01a2004e-0000-7000-8000-000000000000')->assertNotFound();
    }

    public function test_le_formulaire_de_contact_accepte_les_profils_publies(): void
    {
        Http::fake([
            self::API.'/landing/content' => Http::response($this->published(4, ['contact' => ['profiles' => ['Architecte', 'Autre']]])),
            self::API.'/landing/contact-messages' => Http::response(['data' => ['reference' => 'MSG-000009']], 201),
        ]);
        $payload = ['nom' => 'Awa Koné', 'tel' => '+225 07 00 00 00 00', 'email' => 'awa@exemple.ci', 'objet' => 'Partenariat', 'message' => 'Parlons d’un partenariat.', 'consent' => '1'];

        $this->get('/')->assertOk();
        $this->postJson('/contact', [...$payload, 'profil' => 'Architecte'])->assertCreated();
        $this->postJson('/contact', [...$payload, 'profil' => 'Promoteur immobilier'])->assertCreated();
        $this->postJson('/contact', [...$payload, 'profil' => 'Inconnu'])->assertUnprocessable()->assertJsonValidationErrors('profil');
    }
}
