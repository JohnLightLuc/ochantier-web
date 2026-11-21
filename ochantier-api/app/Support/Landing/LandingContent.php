<?php

namespace App\Support\Landing;

use App\Enums\BillingPeriod;
use App\Models\Admin;
use App\Models\LandingContentDraft;
use App\Models\LandingContentVersion;
use App\Models\MediaFile;
use App\Models\SubscriptionPlan;
use App\Support\Media\MediaStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Contenu du site public : brouillon modifié depuis le Back Office, publications versionnées et restauration.
 */
class LandingContent
{
    public function __construct(private MediaStore $media) {}

    /**
     * État de l’éditeur : schéma, brouillon, version en ligne, modifications, contrôles et fichiers cités.
     *
     * @return array<string, mixed>
     */
    public function editor(): array
    {
        $draft = LandingContentDraft::query()->with('updatedBy')->findOrFail(1);
        $live = LandingContentVersion::query()->with('publishedBy')->orderByDesc('number')->firstOrFail();
        $changes = LandingContentSchema::changes($draft->content, $live->content);

        return [
            'schema' => LandingContentSchema::describe(),
            'draft' => $draft->content,
            'draft_updated_at' => $draft->updated_at?->toIso8601String(),
            'draft_updated_by' => $draft->updatedBy?->fullName(),
            'restored_from' => $draft->restored_from,
            'live' => ['number' => $live->number, 'published_at' => $live->published_at->toIso8601String(), 'published_by' => $live->publishedBy?->fullName(), 'content' => $live->content],
            'changes' => array_map(fn (string $key) => ['key' => $key, 'label' => LandingContentSchema::label($key)], $changes),
            'checks' => LandingContentSchema::checks($draft->content),
            'files' => $this->files([...LandingContentSchema::fileIds($draft->content), ...LandingContentSchema::fileIds($live->content)]),
        ];
    }

    /**
     * Enregistre les valeurs d’une section dans le brouillon.
     *
     * @param  array<string, mixed>  $values
     */
    public function saveSection(Admin $admin, string $section, array $values): void
    {
        $values = LandingContentSchema::clean($section, $values);

        foreach (LandingContentSchema::fields($section) as $field) {
            if ($field['type'] === 'file' && $values[$field['key']] !== null && ! MediaFile::query()->whereKey($values[$field['key']])->where('purpose', 'landing/contenu')->exists()) {
                throw ValidationException::withMessages([$field['key'] => 'Ce fichier est introuvable : envoyez-le à nouveau.']);
            }
        }

        $this->update($admin, function (array $content) use ($section, $values) {
            $content['sections'][$section] = $values;

            return $content;
        });
    }

    /**
     * Ordre des sections du corps de page et sections masquées.
     *
     * @param  list<string>  $order
     * @param  list<string>  $hidden
     */
    public function saveLayout(Admin $admin, array $order, array $hidden): void
    {
        $this->update($admin, fn (array $content) => [...$content, 'order' => array_values($order), 'hidden' => array_values(array_unique($hidden))]);
    }

    /**
     * Remet une section du brouillon (ou l’ordre et l’affichage, clé « layout ») à sa valeur en ligne.
     */
    public function resetSection(Admin $admin, string $section): void
    {
        $live = LandingContentVersion::current()->content;

        $this->update($admin, function (array $content) use ($section, $live) {
            if ($section === 'layout') {
                return [...$content, 'order' => $live['order'], 'hidden' => $live['hidden']];
            }

            $content['sections'][$section] = $live['sections'][$section];

            return $content;
        });
    }

    /**
     * Annule toutes les modifications : le brouillon revient à la version en ligne.
     */
    public function discard(Admin $admin): void
    {
        LandingContentDraft::current()->update(['content' => LandingContentVersion::current()->content, 'restored_from' => null, 'updated_by_admin_id' => $admin->getKey()]);
    }

    /**
     * Remplace le brouillon par le contenu d’une version antérieure (le site ne change qu’à la publication).
     */
    public function restore(Admin $admin, LandingContentVersion $version): void
    {
        LandingContentDraft::current()->update(['content' => $version->content, 'restored_from' => $version->number, 'updated_by_admin_id' => $admin->getKey()]);
    }

    /**
     * Publie le brouillon : nouvelle version, aussitôt en ligne.
     */
    public function publish(Admin $admin, ?string $note): LandingContentVersion
    {
        return DB::transaction(function () use ($admin, $note) {
            $draft = LandingContentDraft::query()->lockForUpdate()->findOrFail(1);
            $live = LandingContentVersion::current();
            $changes = LandingContentSchema::changes($draft->content, $live->content);

            if ($changes === []) {
                throw ValidationException::withMessages(['content' => 'Aucune modification à publier : le brouillon est identique à la version en ligne.']);
            }

            $errors = array_filter(LandingContentSchema::checks($draft->content), fn (array $check) => $check['level'] === 'error');

            if ($errors !== []) {
                throw ValidationException::withMessages(['content' => 'Publication impossible : '.implode(' ', array_map(fn (array $e) => LandingContentSchema::label($e['section']).' · '.$e['message'], $errors))]);
            }

            $labels = array_map(LandingContentSchema::label(...), $changes);
            $version = LandingContentVersion::query()->create([
                'number' => $live->number + 1,
                'content' => $draft->content,
                'changed_sections' => $changes,
                'note' => $note ?: 'Mise à jour : '.implode(', ', $labels).'.',
                'restored_from' => $draft->restored_from,
                'published_by_admin_id' => $admin->getKey(),
                'published_at' => now(),
            ]);
            $draft->update(['restored_from' => null]);

            return $version;
        });
    }

    /**
     * Fichier envoyé depuis l’éditeur (vidéo, logo, QR code, image de partage), à citer ensuite dans une section.
     *
     * @return array{id: string, name: string, mime_type: string, url: string}
     */
    public function upload(Admin $admin, UploadedFile $file): array
    {
        $media = $this->media->store($file, 'landing/contenu', $admin, false);

        return $this->file($media);
    }

    /**
     * Contenu en ligne pour le site : sections, formules d’abonnement promoteur actives et chemins des fichiers.
     *
     * @return array<string, mixed>
     */
    public function published(): array
    {
        $live = LandingContentVersion::current();

        return [
            'version' => $live->number,
            'published_at' => $live->published_at->toIso8601String(),
            'content' => $live->content,
            'plans' => SubscriptionPlan::query()->where('audience', 'promoter')->where('is_active', true)->orderBy('sort_order')->orderBy('price_amount')->get()->map(fn (SubscriptionPlan $p) => [
                'code' => $p->code,
                'name' => $p->name,
                'subtitle' => $p->subtitle,
                'description' => $p->description,
                'price_amount' => (int) $p->price_amount,
                'billing_period' => $p->billing_period instanceof BillingPeriod ? $p->billing_period->value : $p->billing_period,
                'max_users' => $p->max_users,
                'features' => $p->features ?? [],
                'is_featured' => (bool) $p->is_featured,
            ])->values()->all(),
            'files' => array_map(fn (array $f) => ['name' => $f['name'], 'mime_type' => $f['mime_type']], $this->files(LandingContentSchema::fileIds($live->content))),
        ];
    }

    /**
     * Fichier cité par la version en ligne, ou rien.
     */
    public function publishedFile(string $id): ?MediaFile
    {
        return in_array($id, LandingContentSchema::fileIds(LandingContentVersion::current()->content), true) ? MediaFile::query()->find($id) : null;
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $change
     */
    private function update(Admin $admin, callable $change): void
    {
        DB::transaction(function () use ($admin, $change) {
            $draft = LandingContentDraft::query()->lockForUpdate()->findOrFail(1);
            $draft->update(['content' => $change($draft->content), 'updated_by_admin_id' => $admin->getKey()]);
        });
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, array{id: string, name: string, mime_type: string, url: string}>
     */
    private function files(array $ids): array
    {
        return MediaFile::query()->whereIn('id', array_unique($ids))->get()->mapWithKeys(fn (MediaFile $m) => [$m->getKey() => $this->file($m)])->all();
    }

    /**
     * @return array{id: string, name: string, mime_type: string, url: string}
     */
    private function file(MediaFile $media): array
    {
        return ['id' => $media->getKey(), 'name' => $media->original_name, 'mime_type' => $media->mime_type, 'url' => $this->media->temporaryUrl($media)];
    }
}
