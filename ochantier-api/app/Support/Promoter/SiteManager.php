<?php

namespace App\Support\Promoter;

use App\Enums\OrganizationMemberStatus;
use App\Enums\SiteStatus;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\PromoterProfile;
use App\Models\Site;
use App\Models\User;
use App\Support\Documents\DocumentStore;
use App\Support\Media\MediaStore;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Chantiers d’un promoteur (« Mes chantiers », « Nouveau chantier ») : brouillon, photo, localisation, calendrier,
 * responsable et documents. Les besoins sont gérés par OfferDraft et la publication par SitePublisher.
 */
class SiteManager
{
    /**
     * Champs du chantier enregistrés tels quels.
     */
    private const FIELDS = [
        'description', 'work_type', 'construction_type_id', 'address', 'commune_id', 'neighborhood_id',
        'access_instructions', 'location', 'planned_start_date', 'planned_end_date', 'responsible_user_id', 'internal_reference',
    ];

    /**
     * État affiché sur la carte du chantier (badge de la maquette), par statut enregistré.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const DISPLAY = [
        'brouillon' => ['brouillon', 'Brouillon'],
        'soumis' => ['recherche', 'Recherche d’ouvriers en cours'],
        'recherche' => ['recherche', 'Recherche d’ouvriers en cours'],
        'paiement' => ['propose', 'Ouvriers trouvés'],
        'confirme' => ['confirme', 'Équipe confirmée'],
        'en_cours' => ['en_cours', 'En cours'],
        'suspendu' => ['suspendu', 'Suspendu'],
        'termine' => ['termine', 'Terminé'],
        'annule' => ['annule', 'Annulé'],
    ];

    /**
     * Onglets de « Mes chantiers » : filtre => statuts.
     *
     * @var array<string, list<SiteStatus>>
     */
    public const FILTERS = [
        'brouillons' => [SiteStatus::Brouillon],
        'recherche' => [SiteStatus::Soumis, SiteStatus::Recherche, SiteStatus::Paiement],
        'en_cours' => [SiteStatus::Confirme, SiteStatus::EnCours, SiteStatus::Suspendu],
        'termines' => [SiteStatus::Termine],
    ];

    /**
     * Collaborateurs pouvant être désignés responsables d’un chantier.
     */
    private const TEAM_STATUSES = [OrganizationMemberStatus::Actif, OrganizationMemberStatus::Invite];

    public function __construct(private DocumentStore $documents, private MediaStore $media) {}

    /**
     * Enregistre un brouillon de chantier (« Enregistrer le brouillon »).
     *
     * @param  array<string, mixed>  $data  Champs validés (SiteRequest).
     */
    public function create(PromoterProfile $profile, User $author, array $data): Site
    {
        return DB::transaction(function () use ($profile, $author, $data): Site {
            $site = $profile->sites()->make([
                ...$this->attributes($data),
                'reference' => sprintf('CH-%s-%04d', now()->format('Y'), DB::scalar("SELECT nextval('site_reference_seq')")),
                'organization_id' => $profile->user->ownedOrganizations()->value('id'),
                'created_by_user_id' => $author->getKey(),
                'responsible_user_id' => $data['responsible_user_id'] ?? $author->getKey(),
                'status' => SiteStatus::Brouillon,
                'client_uuid' => $data['client_uuid'] ?? null,
                'lock_version' => 1,
            ]);
            $this->computeDuration($site)->save();

            $site->statusHistories()->create(['from_status' => null, 'to_status' => SiteStatus::Brouillon, 'actor_type' => $author->getMorphClass(), 'actor_id' => $author->getKey()]);

            return $site->refresh();
        });
    }

    /**
     * Modifie le brouillon ; seuls les champs envoyés sont enregistrés. Avec lock_version, une modification faite
     * entre-temps sur un autre appareil est refusée (409).
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Site $site, array $data): Site
    {
        $this->ensureEditable($site);

        if (isset($data['lock_version']) && (int) $data['lock_version'] !== $site->lock_version) {
            throw new ConflictHttpException('Ce chantier a été modifié sur un autre appareil. Rechargez-le avant de continuer.');
        }

        $this->computeDuration($site->forceFill([...$this->attributes($data), 'lock_version' => $site->lock_version + 1]))->save();

        return $site->refresh();
    }

    /**
     * Supprime un brouillon qui n’a jamais été publié.
     */
    public function delete(Site $site): void
    {
        if ($site->status !== SiteStatus::Brouillon) {
            throw ValidationException::withMessages(['site' => 'Seul un brouillon peut être supprimé.']);
        }

        $site->delete();
    }

    public function setPhoto(Site $site, User $author, UploadedFile $photo): Site
    {
        $this->ensureEditable($site);

        $media = $this->media->store($photo, 'photo_chantier', $author, false);
        $site->forceFill(['main_image_media_id' => $media->getKey(), 'lock_version' => $site->lock_version + 1])->save();

        return $site->refresh();
    }

    public function removePhoto(Site $site): Site
    {
        $this->ensureEditable($site);

        $site->forceFill(['main_image_media_id' => null, 'lock_version' => $site->lock_version + 1])->save();

        return $site->refresh();
    }

    /**
     * Joint un document au chantier (plans, devis, permis…) ; plusieurs documents d’un même type sont possibles.
     *
     * @param  array<string, UploadedFile>  $files
     */
    public function attachDocument(Site $site, User $author, DocumentType $type, array $files): Document
    {
        $this->ensureEditable($site);

        return DB::transaction(function () use ($site, $author, $type, $files): Document {
            $document = $this->documents->attach($site, $author, $type, $files, collect());
            $site->forceFill(['lock_version' => $site->lock_version + 1])->save();

            return $document;
        });
    }

    public function removeDocument(Site $site, Document $document): void
    {
        $this->ensureEditable($site);

        DB::transaction(function () use ($site, $document): void {
            $document->delete();
            $site->unsetRelation('documents');
            $site->forceFill(['lock_version' => $site->lock_version + 1])->save();
        });
    }

    /**
     * @return Collection<int, Document>
     */
    public function activeDocuments(Site $site): Collection
    {
        return $this->documents->active($site);
    }

    /**
     * Éléments manquants avant la publication (chantier et besoins).
     *
     * @return list<array{key: string, label: string}>
     */
    public function missingForPublication(Site $site): array
    {
        return app(OfferDraft::class)->publicationErrors($site);
    }

    /**
     * Badge de la carte : statut de la maquette et libellé.
     *
     * @return array{value: string, label: string}
     */
    public function displayStatus(Site $site): array
    {
        [$value, $label] = self::DISPLAY[$site->status->value];

        return compact('value', 'label');
    }

    /**
     * Ligne de calendrier de la carte (« Démarrage dans 3 jours · 30 jours prévus », « Jour 12 / 90 · fin le … »,
     * « Livré le … »).
     *
     * @return array{label: string, tag: string|null}|null
     */
    public function schedule(Site $site, ?CarbonImmutable $today = null): ?array
    {
        $today ??= CarbonImmutable::today();
        $start = $site->planned_start_date ? CarbonImmutable::parse($site->planned_start_date) : null;
        $end = $site->planned_end_date ? CarbonImmutable::parse($site->planned_end_date) : null;
        $duration = $site->planned_duration_days;
        $plural = fn (int $n, string $word) => $n.' '.$word.($n > 1 ? 's' : '');

        return match (true) {
            $site->status === SiteStatus::Termine && $end => ['label' => 'Livré le '.$this->date($end), 'tag' => $duration ? $plural($duration, 'jour') : null],
            in_array($site->status, [SiteStatus::Confirme, SiteStatus::EnCours], true) && $start && $start->greaterThan($today) => [
                'label' => 'Démarrage dans '.$plural((int) $today->diffInDays($start), 'jour').($duration ? ' · '.$duration.' jours prévus' : ''),
                'tag' => 'Planifié',
            ],
            $site->status === SiteStatus::Confirme && $start => ['label' => 'Démarrage aujourd’hui', 'tag' => $duration ? $duration.' jours prévus' : null],
            $site->status === SiteStatus::EnCours && $start && $duration => [
                'label' => 'Jour '.min($duration, (int) $start->diffInDays($today) + 1).' / '.$duration.($end ? ' · fin le '.$this->date($end) : ''),
                'tag' => null,
            ],
            default => null,
        };
    }

    /**
     * Personnes pouvant être désignées responsables : le propriétaire du compte, puis les collaborateurs actifs ou
     * invités de son équipe (un technicien invité depuis le formulaire peut être désigné aussitôt).
     *
     * @return list<array<string, mixed>>
     */
    public function responsibles(PromoterProfile $profile, User $me): array
    {
        $organization = $profile->user->ownedOrganizations()->first();
        $members = $organization
            ? $organization->members()->whereIn('status', self::TEAM_STATUSES)->with('user')->oldest()->get()
            : collect();

        $people = collect([['user' => $profile->user, 'role' => PromoterAccess::ROLE_LABELS[PromoterAccess::OWNER]]])
            ->concat($members->reject(fn ($member) => $member->user_id === $profile->user_id)->map(fn ($member) => ['user' => $member->user, 'role' => PromoterAccess::ROLE_LABELS[$member->role->value]]));

        return $people->map(fn (array $person) => $this->person($person['user'], $person['user']->is($me) ? 'Moi-même' : $person['role']))->values()->all();
    }

    /**
     * Le responsable doit être le propriétaire du compte ou un collaborateur actif ou invité de son équipe.
     */
    public function isAllowedResponsible(PromoterProfile $profile, string $userId): bool
    {
        if ($userId === $profile->user_id) {
            return true;
        }

        return (bool) $profile->user->ownedOrganizations()->first()?->members()
            ->where('user_id', $userId)
            ->whereIn('status', self::TEAM_STATUSES)
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    public function person(User $user, ?string $role = null): array
    {
        $name = trim($user->first_name.' '.$user->last_name);

        return [
            'id' => $user->id,
            'name' => $name !== '' ? $name : (PhoneNumber::of($user)?->formatted() ?? $user->email),
            'initials' => mb_strtoupper(mb_substr((string) $user->first_name, 0, 1).mb_substr((string) $user->last_name, 0, 1)) ?: null,
            'role' => $role,
            'phone' => PhoneNumber::of($user)?->formatted(),
        ];
    }

    public function ensureEditable(Site $site): void
    {
        if ($site->status !== SiteStatus::Brouillon) {
            throw ValidationException::withMessages(['site' => 'Ce chantier est publié : il ne peut plus être modifié depuis l’application.']);
        }
    }

    /**
     * Champs du chantier présents dans la requête, avec la durée prévue calculée à partir des dates.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $attributes = Arr::only($data, self::FIELDS);

        if (array_key_exists('title', $data)) {
            $attributes['label'] = $data['title'];
        }

        if (array_key_exists('location', $data) && is_array($data['location'])) {
            $attributes['location'] = Arr::only($data['location'], ['latitude', 'longitude']);
        }

        return $attributes;
    }

    /**
     * Durée prévue en jours calendaires, début et fin inclus.
     */
    private function computeDuration(Site $site): Site
    {
        $start = $site->planned_start_date;
        $end = $site->planned_end_date;
        $site->planned_duration_days = $start && $end ? (int) CarbonImmutable::parse($start)->diffInDays(CarbonImmutable::parse($end)) + 1 : null;

        return $site;
    }

    private function date(CarbonImmutable $date): string
    {
        return $date->locale('fr')->translatedFormat('j M Y');
    }
}
