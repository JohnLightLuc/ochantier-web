<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * Contenu affiché par le site : la version publiée dans le Back Office, lue dans l’API et gardée en cache quelques
 * minutes. Si l’API ne répond pas, le site affiche la dernière version reçue, et à défaut les textes d’origine
 * (resources/content/landing.json). Les fichiers publiés sont recopiés localement à leur première demande.
 */
class SiteContent
{
    private const CACHE_KEY = 'landing.content';

    /**
     * Dernière version reçue de l’API, sur le disque local.
     */
    private const LAST_KNOWN = 'landing/contenu.json';

    private const FILES_DIR = 'landing/fichiers';

    public function __construct(private OchantierApi $api) {}

    /**
     * @return array{version: int|null, published_at: string|null, content: array<string, mixed>, plans: list<array<string, mixed>>, files: array<string, array{name: string, mime_type: string}>, source: 'api'|'copie'|'origine'}
     */
    public function current(): array
    {
        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached)) {
            return $cached;
        }

        $fresh = $this->api->content();

        if ($fresh !== null) {
            $site = [...$this->normalize($fresh), 'source' => 'api'];
            Storage::disk('local')->put(self::LAST_KNOWN, json_encode($fresh, JSON_UNESCAPED_UNICODE));
            Cache::put(self::CACHE_KEY, $site, max(30, (int) config('services.ochantier_api.content_ttl', 300)));

            return $site;
        }

        $copy = Storage::disk('local')->exists(self::LAST_KNOWN) ? json_decode((string) Storage::disk('local')->get(self::LAST_KNOWN), true) : null;
        $site = is_array($copy) ? [...$this->normalize($copy), 'source' => 'copie'] : [...$this->normalize($this->defaults()), 'source' => 'origine'];

        // L’API ne répond pas : nouvel essai dans une minute, sans ralentir chaque visite d’ici là.
        Cache::put(self::CACHE_KEY, $site, 60);

        return $site;
    }

    /**
     * Profils proposés par le formulaire de contact affiché, sans appeler l’API : version en cache, sinon dernière
     * version reçue, sinon textes d’origine. Les profils d’origine restent acceptés.
     *
     * @return list<string>
     */
    public function contactProfiles(): array
    {
        $cached = Cache::get(self::CACHE_KEY);
        $copy = Storage::disk('local')->exists(self::LAST_KNOWN) ? json_decode((string) Storage::disk('local')->get(self::LAST_KNOWN), true) : null;
        $site = is_array($cached) ? $cached : $this->normalize(is_array($copy) ? $copy : $this->defaults());

        return array_values(array_unique([...$this->defaults()['content']['sections']['contact']['profiles'], ...$site['content']['sections']['contact']['profiles']]));
    }

    /**
     * Fichier publié recopié localement : [chemin absolu, type MIME], ou null s’il n’est pas cité par la version
     * affichée ou pas disponible.
     *
     * @return array{0: string, 1: string}|null
     */
    public function file(string $id): ?array
    {
        $file = $this->current()['files'][$id] ?? null;

        if ($file === null) {
            return null;
        }

        $path = self::FILES_DIR.'/'.$id;
        $disk = Storage::disk('local');

        if (! $disk->exists($path)) {
            $response = $this->api->file($id);

            if ($response === null) {
                return null;
            }

            $disk->put($path, $response->body());
        }

        return [$disk->path($path), $file['mime_type']];
    }

    /**
     * Vide le cache : la prochaine visite relit l’API.
     */
    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Contenu complété par les textes d’origine (une section ou un champ absent garde sa valeur d’origine).
     *
     * @param  array<string, mixed>  $data
     * @return array{version: int|null, published_at: string|null, content: array<string, mixed>, plans: list<array<string, mixed>>, files: array<string, array{name: string, mime_type: string}>}
     */
    private function normalize(array $data): array
    {
        $defaults = $this->defaults();
        $content = $data['content'] ?? [];
        $sections = $defaults['content']['sections'];

        foreach ($sections as $key => $values) {
            $sections[$key] = [...$values, ...array_intersect_key((array) ($content['sections'][$key] ?? []), $values)];
        }

        $order = array_values(array_intersect((array) ($content['order'] ?? []), $defaults['content']['order']));

        return [
            'version' => $data['version'] ?? null,
            'published_at' => $data['published_at'] ?? null,
            'content' => [
                'order' => [...$order, ...array_values(array_diff($defaults['content']['order'], $order))],
                'hidden' => array_values((array) ($content['hidden'] ?? [])),
                'sections' => $sections,
            ],
            'plans' => is_array($data['plans'] ?? null) && $data['plans'] !== [] ? array_values($data['plans']) : $defaults['plans'],
            'files' => (array) ($data['files'] ?? []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function defaults(): array
    {
        return json_decode(File::get(resource_path('content/landing.json')), true);
    }
}
