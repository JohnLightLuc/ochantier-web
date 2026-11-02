<?php

namespace App\Support\Promoter;

use App\Enums\DocumentAudience;
use App\Enums\DocumentVerificationStatus;
use App\Enums\OrganizationMemberStatus;
use App\Enums\OrganizationRole;
use App\Enums\PromoterProfileKind;
use App\Enums\PromoterVerificationStatus;
use App\Enums\SiteStatus;
use App\Enums\VerificationReviewStatus;
use App\Models\Admin;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Organization;
use App\Models\PromoterProfile;
use App\Models\User;
use App\Support\Documents\DocumentStore;
use App\Support\Media\MediaStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Compte promoteur (« Profil », « Modifier le profil ») : identité, entreprise, pièces justificatives et vérification.
 *
 * Un particulier fournit une pièce d’identité et un certificat de résidence. Une entreprise fournit en plus son
 * RCCM, son NCC, son siège social, l’extrait RCCM et l’attestation NCC. Dès que tout est fourni, le profil est
 * transmis au Back office ; une modification d’un élément vérifié le renvoie en vérification.
 */
class PromoterAccount
{
    /**
     * Pièces d’identité acceptées (une au choix).
     */
    public const IDENTITY_DOCUMENTS = ['promoter_cni', 'promoter_passport', 'promoter_driving_license'];

    public const RESIDENCE_DOCUMENT = 'promoter_residence_certificate';

    /**
     * Documents complémentaires, sans remplacement : on peut en ajouter plusieurs.
     */
    public const OTHER_DOCUMENT = 'promoter_other';

    /**
     * Pièces de l’entreprise affichées dans le profil, dans l’ordre de la maquette.
     */
    public const COMPANY_DOCUMENTS = ['organization_rccm', 'organization_dfe', 'organization_statutes'];

    /**
     * Précisions affichées sous chaque pièce.
     *
     * @var array<string, string>
     */
    public const HINTS = [
        'identity' => 'CNI, passeport ou permis · recto verso',
        self::RESIDENCE_DOCUMENT => 'Daté de moins de 3 mois',
        'organization_rccm' => 'Registre du commerce · moins de 3 mois',
        'organization_dfe' => 'Déclaration fiscale d’existence',
        'organization_statutes' => 'Version signée',
    ];

    /**
     * Le type de compte (particulier ou entreprise) ne change plus une fois le profil transmis ou vérifié.
     */
    private const KIND_EDITABLE = [PromoterVerificationStatus::Brouillon, PromoterVerificationStatus::ACorriger, PromoterVerificationStatus::Refuse];

    public function __construct(private DocumentStore $documents, private MediaStore $media) {}

    public function organization(PromoterProfile $profile): ?Organization
    {
        return $profile->user->ownedOrganizations()->with(['commune', 'neighborhood'])->first();
    }

    public function isCompany(PromoterProfile $profile): bool
    {
        return $profile->profile_kind === PromoterProfileKind::Entreprise;
    }

    public function canChangeKind(PromoterProfile $profile): bool
    {
        return in_array($profile->verification_status, self::KIND_EDITABLE, true);
    }

    /**
     * Enregistre les champs envoyés (mise à jour partielle).
     *
     * @param  array<string, mixed>  $data  Champs validés (UpdatePromoterProfileRequest).
     */
    public function update(PromoterProfile $profile, User|Admin $author, array $data, bool $resubmitValidated = true): PromoterProfile
    {
        return DB::transaction(function () use ($profile, $author, $data, $resubmitValidated): PromoterProfile {
            $user = $profile->user;
            $user->fill(Arr::only($data, ['first_name', 'last_name']));
            $verifiedChange = $user->isDirty(['first_name', 'last_name']);
            $user->save();

            $profile->fill(Arr::only($data, ['job_title', 'address', 'commune_id', 'neighborhood_id']));

            if (isset($data['profile_kind']) && $data['profile_kind'] !== $profile->profile_kind->value) {
                $this->changeKind($profile, PromoterProfileKind::from($data['profile_kind']), $data['company']['legal_name'] ?? null);
                $verifiedChange = true;
            }

            $profile->save();

            if ($this->isCompany($profile) && isset($data['company'])) {
                $verifiedChange = $this->updateCompany($profile, $data['company']) || $verifiedChange;
            }

            $this->refreshVerification($profile, $author, $verifiedChange && $resubmitValidated);

            return $profile->refresh();
        });
    }

    public function setPhoto(PromoterProfile $profile, User|Admin $author, UploadedFile $photo): PromoterProfile
    {
        $media = $this->media->store($photo, 'photo_profil', $author, false);
        $profile->forceFill(['photo_media_id' => $media->getKey()])->save();

        return $profile->refresh();
    }

    public function removePhoto(PromoterProfile $profile): PromoterProfile
    {
        $profile->forceFill(['photo_media_id' => null])->save();

        return $profile->refresh();
    }

    /**
     * Ajoute ou remplace une pièce : celles de l’entreprise sont rattachées à l’organisation.
     *
     * @param  array<string, UploadedFile>  $files
     */
    public function attachDocument(PromoterProfile $profile, User|Admin $author, DocumentType $type, array $files, ?string $number = null, ?string $expiresAt = null, bool $resubmitValidated = true): Document
    {
        $owner = $type->audience === DocumentAudience::Organization ? $this->organization($profile) : $profile;

        if (! $owner) {
            throw ValidationException::withMessages(['document_type' => 'Ce document concerne une entreprise : passez d’abord votre compte en « Entreprise ».']);
        }

        return DB::transaction(function () use ($profile, $author, $type, $files, $number, $expiresAt, $owner, $resubmitValidated): Document {
            $replaced = $type->code === self::OTHER_DOCUMENT ? collect() : $this->documents->active($owner)->filter(
                fn (Document $document) => $document->document_type_id === $type->getKey() || ($type->is_identity && $document->documentType->is_identity),
            );

            $document = $this->documents->attach($owner, $author, $type, $files, $replaced, $number, $expiresAt, $type->is_identity ? 'piece_identite' : 'document');
            $this->refreshVerification($profile, $author, $resubmitValidated && $type->code !== self::OTHER_DOCUMENT);

            return $document;
        });
    }

    /**
     * Retire une pièce. Une pièce obligatoire déjà vérifiée ne peut qu’être remplacée.
     */
    public function removeDocument(PromoterProfile $profile, Document $document): void
    {
        $required = $document->documentType->is_required || $document->documentType->is_identity;

        if ($required && $document->verification_status === DocumentVerificationStatus::Valide) {
            throw ValidationException::withMessages(['document' => 'Ce document a été vérifié : remplacez-le plutôt que de le retirer.']);
        }

        DB::transaction(function () use ($profile, $document): void {
            $document->delete();
            $profile->unsetRelation('documents');
        });
    }

    /**
     * Pièces en vigueur du promoteur et de son entreprise.
     *
     * @return Collection<int, Document>
     */
    public function activeDocuments(PromoterProfile $profile): Collection
    {
        $organization = $this->organization($profile);

        return $this->documents->active($profile)->concat($organization ? $this->documents->active($organization) : []);
    }

    /**
     * Pièce de la liste du promoteur (y compris celles de son entreprise).
     */
    public function findDocument(PromoterProfile $profile, string $id): ?Document
    {
        return $this->activeDocuments($profile)->first(fn (Document $document) => $document->getKey() === $id);
    }

    public function isCompleteIdentity(Document $document): bool
    {
        return $this->documents->isCompleteIdentity($document, requiresNumber: false);
    }

    /**
     * Éléments attendus pour la vérification et avancement (« Profil à compléter · 67 % »).
     *
     * @return array{percent: int, done: int, total: int, complete: bool, items: list<array{key: string, label: string, done: bool}>, missing: list<array{key: string, label: string}>}
     */
    public function completion(PromoterProfile $profile): array
    {
        $documents = $this->activeDocuments($profile);
        $has = fn (string $code) => $documents->contains(fn (Document $document) => $document->documentType->code === $code && $document->files->isNotEmpty());
        $item = fn (string $key, string $label, bool $done) => compact('key', 'label', 'done');
        $user = $profile->user;

        $items = [
            $item('name', 'Nom et prénom', filled($user->first_name) && filled($user->last_name)),
            $item('identity', $this->isCompany($profile) ? 'Pièce d’identité du dirigeant' : 'Pièce d’identité', $documents->contains(fn (Document $document) => $this->isCompleteIdentity($document))),
            $item('residence', $this->isCompany($profile) ? 'Certificat de résidence du dirigeant' : 'Certificat de résidence', $has(self::RESIDENCE_DOCUMENT)),
        ];

        if ($this->isCompany($profile)) {
            $organization = $this->organization($profile);
            $items = [
                ...$items,
                $item('company_name', 'Raison sociale', filled($organization?->legal_name)),
                $item('rccm', 'N° RCCM', filled($organization?->rccm)),
                $item('ncc', 'N° NCC', filled($organization?->tax_identifier)),
                $item('headquarters', 'Siège social', filled($organization?->address)),
                $item('organization_rccm', 'Extrait RCCM', $has('organization_rccm')),
                $item('organization_dfe', 'Attestation NCC / DFE', $has('organization_dfe')),
            ];
        }

        $done = count(array_filter($items, fn (array $i) => $i['done']));
        $missing = array_values(array_map(fn (array $i) => ['key' => $i['key'], 'label' => $i['label']], array_filter($items, fn (array $i) => ! $i['done'])));

        return [
            'percent' => (int) round($done / count($items) * 100),
            'done' => $done,
            'total' => count($items),
            'complete' => $missing === [],
            'items' => $items,
            'missing' => $missing,
        ];
    }

    /**
     * État affiché (« À compléter », « En vérification », « Vérifié »…) à partir du statut et des éléments fournis.
     *
     * @return array{value: string, label: string}
     */
    public function verificationState(PromoterProfile $profile, bool $complete): array
    {
        return match (true) {
            $profile->verification_status === PromoterVerificationStatus::Suspendu => ['value' => 'suspendu', 'label' => 'Suspendu'],
            $profile->verification_status === PromoterVerificationStatus::Refuse => ['value' => 'refuse', 'label' => 'Refusé'],
            $profile->verification_status === PromoterVerificationStatus::ACorriger => ['value' => 'a_corriger', 'label' => 'À corriger'],
            ! $complete => ['value' => 'a_completer', 'label' => 'À compléter'],
            $profile->verification_status === PromoterVerificationStatus::Valide => ['value' => 'verifie', 'label' => 'Vérifié'],
            default => ['value' => 'en_verification', 'label' => 'En vérification'],
        };
    }

    /**
     * Transmet le profil au Back office dès qu’il est complet : à la première complétion, après une correction
     * demandée, ou quand un élément déjà vérifié est modifié.
     */
    public function refreshVerification(PromoterProfile $profile, User|Admin $author, bool $verifiedChange = false): void
    {
        $status = $profile->verification_status;
        $resubmit = in_array($status, [PromoterVerificationStatus::Brouillon, PromoterVerificationStatus::ACorriger], true)
            || ($status === PromoterVerificationStatus::Valide && $verifiedChange);

        if (! $resubmit || ! $this->completion($profile)['complete']) {
            return;
        }

        $organization = $this->organization($profile);

        $profile->forceFill(['verification_status' => PromoterVerificationStatus::Soumis, 'validated_at' => null])->save();
        $organization?->forceFill(['verification_status' => PromoterVerificationStatus::Soumis, 'validated_at' => null])->save();

        $profile->verificationReviews()->create([
            'submission_number' => $profile->verificationReviews()->count() + 1,
            'submitted_by_type' => $author->getMorphClass(),
            'submitted_by_id' => $author->getKey(),
            'submitted_at' => now(),
            'status' => VerificationReviewStatus::Soumis,
            'snapshot' => $this->snapshot($profile, $organization),
        ]);
    }

    /**
     * Chiffres du profil entreprise : chantiers publiés, chantiers en cours, utilisateurs du compte.
     *
     * @return array{sites: int, active_sites: int, users: int}
     */
    public function stats(PromoterProfile $profile): array
    {
        $organization = $this->organization($profile);

        return [
            'sites' => $profile->sites()->where('status', '!=', SiteStatus::Brouillon)->count(),
            'active_sites' => $profile->sites()->where('status', SiteStatus::EnCours)->count(),
            'users' => $organization ? max(1, $organization->members()->where('status', OrganizationMemberStatus::Actif)->count()) : 1,
        ];
    }

    private function changeKind(PromoterProfile $profile, PromoterProfileKind $kind, ?string $companyName): void
    {
        if (! $this->canChangeKind($profile)) {
            throw ValidationException::withMessages(['profile_kind' => 'Votre profil a été transmis pour vérification : le type de compte ne peut plus être modifié. Contactez le support.']);
        }

        $organization = $this->organization($profile);

        if ($kind === PromoterProfileKind::Entreprise && ! $organization) {
            $organization = $profile->user->ownedOrganizations()->create(['legal_name' => $companyName]);
            $organization->members()->create([
                'user_id' => $profile->user_id,
                'role' => OrganizationRole::Administrateur,
                'status' => OrganizationMemberStatus::Actif,
                'joined_at' => now(),
            ]);
        }

        if ($kind !== PromoterProfileKind::Entreprise && $organization) {
            if ($organization->sites()->exists() || $organization->members()->where('user_id', '!=', $profile->user_id)->exists()) {
                throw ValidationException::withMessages(['profile_kind' => 'Votre entreprise a déjà des chantiers ou des utilisateurs : le compte ne peut plus redevenir particulier.']);
            }

            $organization->delete();
        }

        $profile->profile_kind = $kind;
    }

    /**
     * @param  array<string, mixed>  $company
     * @return bool Un élément vérifié (raison sociale, RCCM, NCC) a changé.
     */
    private function updateCompany(PromoterProfile $profile, array $company): bool
    {
        $organization = $this->organization($profile);
        $organization->fill([
            ...Arr::only($company, ['legal_name', 'trade_name', 'legal_form', 'rccm']),
            ...(array_key_exists('ncc', $company) ? ['tax_identifier' => $company['ncc']] : []),
            ...(array_key_exists('headquarters', $company) ? $this->headquarters($company['headquarters']) : []),
        ]);

        $changed = $organization->isDirty(['legal_name', 'rccm', 'tax_identifier']);
        $organization->save();

        return $changed;
    }

    /**
     * @param  array<string, mixed>|null  $headquarters
     * @return array<string, mixed>
     */
    private function headquarters(?array $headquarters): array
    {
        return [
            'address' => $headquarters['address'] ?? null,
            'commune_id' => $headquarters['commune_id'] ?? null,
            'neighborhood_id' => $headquarters['neighborhood_id'] ?? null,
            'location' => isset($headquarters['location']) ? Arr::only($headquarters['location'], ['latitude', 'longitude']) : null,
        ];
    }

    /**
     * État du dossier enregistré avec la demande de vérification.
     *
     * @return array<string, mixed>
     */
    private function snapshot(PromoterProfile $profile, ?Organization $organization): array
    {
        return [
            'promoter_profile_id' => $profile->getKey(),
            'profile_kind' => $profile->profile_kind->value,
            'user' => $profile->user->only(['first_name', 'last_name', 'dial_code', 'phone_number', 'email']),
            'job_title' => $profile->job_title,
            'organization' => $organization?->only(['id', 'legal_name', 'trade_name', 'legal_form', 'rccm', 'tax_identifier', 'address', 'commune_id']),
            'documents' => $this->activeDocuments($profile)->map(fn (Document $document) => [
                'id' => $document->getKey(),
                'type' => $document->documentType->code,
                'files' => $document->files->map(fn ($file) => ['media_file_id' => $file->getKey(), 'side' => $file->pivot->side])->all(),
            ])->values()->all(),
        ];
    }
}
