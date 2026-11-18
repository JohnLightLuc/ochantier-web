<?php

namespace App\Support\Contractor;

use App\Enums\ContractorKind;
use App\Enums\ContractorStatus;
use App\Enums\ContractorVerificationStatus;
use App\Enums\DocumentVerificationStatus;
use App\Enums\VerificationReviewStatus;
use App\Models\Contractor;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\User;
use App\Support\Documents\DocumentStore;
use App\Support\Media\MediaStore;
use App\Support\PhoneNumber;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Dossier de vérification d’un entrepreneur (« Quel est votre profil ? », « Dossier entreprise (KYB) »,
 * « Dossier indépendant (KYC) », « Modifier mon dossier »).
 *
 * Le dossier est enregistré au fil de la saisie (brouillon), puis soumis avec la certification de l’exactitude des
 * informations. Tant qu’il est en vérification, il ne peut plus être modifié. Après la validation, la modification
 * d’une donnée sensible (identité, entreprise, métiers, pièce d’identité ou d’entreprise) remet le dossier en
 * brouillon : il doit être soumis de nouveau, et les réponses aux opportunités sont suspendues jusqu’à la décision.
 */
class ContractorAccount
{
    /**
     * Formes juridiques proposées pour une entreprise.
     */
    public const LEGAL_FORMS = ['SARL', 'SA', 'SAS', 'SASU', 'SUARL', 'Entreprise individuelle', 'GIE', 'Société coopérative'];

    /**
     * Pièces d’identité acceptées (une au choix) : de l’indépendant, ou du représentant légal de l’entreprise.
     */
    public const IDENTITY_DOCUMENTS = ['contractor_cni', 'contractor_passport', 'contractor_consular_card', 'contractor_identity_certificate'];

    /**
     * Autres pièces proposées selon le profil (« Selon votre statut », « Facultatif »).
     *
     * @var array<string, list<string>>
     */
    public const OTHER_DOCUMENTS = [
        'entreprise' => ['contractor_rccm', 'contractor_tax_certificate', 'contractor_site_references'],
        'independant' => ['contractor_trade_certificate'],
    ];

    /**
     * Pièces dont le remplacement relance la vérification d’un dossier validé.
     */
    private const SENSITIVE_DOCUMENTS = [...self::IDENTITY_DOCUMENTS, 'contractor_rccm', 'contractor_tax_certificate'];

    /**
     * Statuts dans lesquels le dossier peut être modifié puis soumis.
     */
    private const EDITABLE = [ContractorVerificationStatus::Brouillon, ContractorVerificationStatus::ACorriger, ContractorVerificationStatus::Refuse, ContractorVerificationStatus::Valide];

    /**
     * Le type de profil ne change plus une fois le dossier validé.
     */
    private const KIND_EDITABLE = [ContractorVerificationStatus::Brouillon, ContractorVerificationStatus::ACorriger, ContractorVerificationStatus::Refuse];

    public const WITH = ['user', 'city', 'zones', 'trades', 'logoMedia', 'documents.documentType', 'documents.files'];

    public function __construct(private DocumentStore $documents, private MediaStore $media, private ContractorNotifier $notifier) {}

    /**
     * Profil choisi après l’inscription (« Entreprise ou professionnel constitué », « Particulier ou indépendant ») :
     * crée le dossier en brouillon, prérempli avec le téléphone et l’e-mail du compte.
     *
     * @param  array<string, mixed>  $data  Champs validés (ChooseContractorProfileRequest).
     */
    public function choose(User $user, ContractorKind $kind, array $data): Contractor
    {
        if ($user->contractor()->exists()) {
            throw ValidationException::withMessages(['kind' => 'Votre profil est déjà choisi : modifiez votre dossier.']);
        }

        return DB::transaction(function () use ($user, $kind, $data): Contractor {
            $contractor = $user->contractor()->create([
                'reference' => sprintf('ENT-%05d', DB::scalar("SELECT nextval('contractor_reference_seq')")),
                'kind' => $kind,
                'name' => '',
                ...PhoneNumber::attributes(PhoneNumber::from($user->dial_code, $user->phone_number)),
                'email' => $user->email,
                'status' => ContractorStatus::Actif,
                'verification_status' => ContractorVerificationStatus::Brouillon,
            ]);

            return $this->update($contractor->setRelation('user', $user), $data);
        });
    }

    /**
     * Enregistre les champs envoyés (mise à jour partielle).
     *
     * @param  array<string, mixed>  $data  Champs validés (UpdateContractorProfileRequest).
     */
    public function update(Contractor $contractor, array $data): Contractor
    {
        $this->ensureEditable($contractor);

        return DB::transaction(function () use ($contractor, $data): Contractor {
            $user = $contractor->user;
            $sensitive = false;

            if (isset($data['kind']) && $data['kind'] !== $contractor->kind->value) {
                if (! in_array($contractor->verification_status, self::KIND_EDITABLE, true)) {
                    throw ValidationException::withMessages(['kind' => 'Votre dossier est vérifié : le type de profil ne peut plus être modifié. Contactez l’assistance.']);
                }

                $contractor->kind = ContractorKind::from($data['kind']);
                $sensitive = true;
            }

            $user->fill(Arr::only($data, ['first_name', 'last_name']));
            $sensitive = $user->isDirty() || $sensitive;
            $user->save();

            $contractor->fill([
                ...Arr::only($data, ['legal_form', 'rccm', 'birth_date', 'city_id', 'address', 'experience_years', 'description', 'email']),
                ...(array_key_exists('legal_name', $data) ? ['name' => $data['legal_name']] : []),
                ...(array_key_exists('ncc', $data) ? ['tax_identifier' => $data['ncc']] : []),
                ...(array_key_exists('representative_name', $data) ? ['contact_name' => $data['representative_name']] : []),
                ...(array_key_exists('phone', $data) ? PhoneNumber::attributes($data['phone']) : []),
            ]);

            if (! $contractor->isCompany()) {
                $contractor->name = trim($user->first_name.' '.$user->last_name);
                $contractor->contact_name = $contractor->name ?: null;
            }

            $sensitive = $contractor->isDirty(['name', 'legal_form', 'rccm', 'tax_identifier', 'contact_name', 'birth_date']) || $sensitive;
            $contractor->save();

            if (array_key_exists('trade_ids', $data)) {
                $sensitive = $contractor->trades()->sync($data['trade_ids']) !== ['attached' => [], 'detached' => [], 'updated' => []] || $sensitive;
            }

            if (array_key_exists('zone_city_ids', $data)) {
                $contractor->zones()->sync(array_values(array_diff($data['zone_city_ids'], [$contractor->city_id])));
            } elseif ($contractor->wasChanged('city_id')) {
                $contractor->zones()->detach($contractor->city_id);
            }

            if ($sensitive) {
                $this->reopen($contractor);
            }

            return $this->fresh($contractor);
        });
    }

    public function setPhoto(Contractor $contractor, User $author, UploadedFile $photo): Contractor
    {
        $media = $this->media->store($photo, $contractor->isCompany() ? 'logo' : 'photo_profil', $author, false);
        $contractor->forceFill(['logo_media_id' => $media->getKey()])->save();

        return $this->fresh($contractor);
    }

    public function removePhoto(Contractor $contractor): Contractor
    {
        $contractor->forceFill(['logo_media_id' => null])->save();

        return $this->fresh($contractor);
    }

    /**
     * Types de pièces proposés pour ce profil, dans l’ordre de la maquette.
     *
     * @return list<string>
     */
    public function documentCodes(Contractor $contractor): array
    {
        return [...self::IDENTITY_DOCUMENTS, ...self::OTHER_DOCUMENTS[$contractor->kind->value]];
    }

    /**
     * Ajoute ou remplace une pièce ; une nouvelle pièce d’identité remplace la précédente, quel que soit son type.
     *
     * @param  array<string, UploadedFile>  $files
     */
    public function attachDocument(Contractor $contractor, User $author, DocumentType $type, array $files, ?string $number = null, ?string $expiresAt = null): Document
    {
        $this->ensureEditable($contractor);

        if (! in_array($type->code, $this->documentCodes($contractor), true)) {
            throw ValidationException::withMessages(['document_type' => 'Cette pièce n’est pas demandée pour votre profil.']);
        }

        return DB::transaction(function () use ($contractor, $author, $type, $files, $number, $expiresAt): Document {
            $replaced = $this->documents->active($contractor)->filter(
                fn (Document $document) => $document->document_type_id === $type->getKey() || ($type->is_identity && $document->documentType->is_identity),
            );

            $document = $this->documents->attach($contractor, $author, $type, $files, $replaced, $number, $expiresAt, $type->is_identity ? 'piece_identite' : 'document');

            if (in_array($type->code, self::SENSITIVE_DOCUMENTS, true)) {
                $this->reopen($contractor);
            }

            return $document;
        });
    }

    /**
     * Retire une pièce. Une pièce d’identité déjà vérifiée ne peut qu’être remplacée.
     */
    public function removeDocument(Contractor $contractor, Document $document): void
    {
        $this->ensureEditable($contractor);

        if ($document->documentType->is_identity && $document->verification_status === DocumentVerificationStatus::Valide) {
            throw ValidationException::withMessages(['document' => 'Cette pièce a été vérifiée : remplacez-la plutôt que de la retirer.']);
        }

        DB::transaction(function () use ($contractor, $document): void {
            $document->delete();

            if (in_array($document->documentType->code, self::SENSITIVE_DOCUMENTS, true)) {
                $this->reopen($contractor);
            }

            $contractor->unsetRelation('documents');
        });
    }

    /**
     * Pièces en vigueur du dossier.
     *
     * @return Collection<int, Document>
     */
    public function activeDocuments(Contractor $contractor): Collection
    {
        return $this->documents->active($contractor);
    }

    public function findDocument(Contractor $contractor, string $id): ?Document
    {
        return $this->activeDocuments($contractor)->first(fn (Document $document) => $document->getKey() === $id);
    }

    /**
     * Éléments attendus pour soumettre le dossier, dans l’ordre des étapes, et avancement.
     *
     * @return array{percent: int, done: int, total: int, complete: bool, items: list<array{key: string, label: string, step: string, done: bool}>, missing: list<array{key: string, label: string, step: string}>}
     */
    public function completion(Contractor $contractor): array
    {
        $user = $contractor->user;
        $phone = PhoneNumber::of($contractor);
        $identity = $this->activeDocuments($contractor)->contains(
            fn (Document $document) => $this->documents->isCompleteIdentity($document, requiresNumber: ! $contractor->isCompany()),
        );
        $item = fn (string $key, string $label, string $step, bool $done) => compact('key', 'label', 'step', 'done');

        $items = $contractor->isCompany() ? [
            $item('legal_name', 'Raison sociale', 'entreprise', filled($contractor->name)),
            $item('legal_form', 'Forme juridique', 'entreprise', filled($contractor->legal_form)),
            $item('representative_name', 'Représentant légal', 'representant', filled($contractor->contact_name)),
            $item('phone', 'Téléphone', 'representant', (bool) $phone?->isValid()),
            $item('email', 'Email', 'representant', filled($contractor->email)),
            $item('city', 'Ville', 'representant', filled($contractor->city_id)),
            $item('address', 'Adresse du siège', 'representant', filled($contractor->address)),
        ] : [
            $item('last_name', 'Nom', 'identite', filled($user->last_name)),
            $item('first_name', 'Prénoms', 'identite', filled($user->first_name)),
            $item('birth_date', 'Date de naissance', 'identite', $contractor->birth_date !== null),
            $item('phone', 'Téléphone', 'identite', (bool) $phone?->isValid()),
            $item('city', 'Ville', 'coordonnees', filled($contractor->city_id)),
            $item('address', 'Adresse', 'coordonnees', filled($contractor->address)),
        ];

        $items = [
            ...$items,
            $item('trades', 'Métiers exercés', 'activite', $contractor->trades->isNotEmpty()),
            $item('experience_years', 'Années d’expérience', 'activite', $contractor->experience_years !== null),
            $item('identity', $contractor->isCompany() ? 'Pièce d’identité du représentant' : 'Pièce d’identité (numéro, recto et verso)', 'pieces', $identity),
        ];

        $done = count(array_filter($items, fn (array $i) => $i['done']));
        $missing = array_values(array_map(fn (array $i) => Arr::only($i, ['key', 'label', 'step']), array_filter($items, fn (array $i) => ! $i['done'])));

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
     * Soumet le dossier complet au Back Office (« Soumettre le dossier ? »).
     */
    public function submit(Contractor $contractor, User $author): Contractor
    {
        $status = $contractor->verification_status;

        if ($status->isPending()) {
            throw ValidationException::withMessages(['profile' => 'Votre dossier est déjà en cours de vérification.']);
        }

        if ($status === ContractorVerificationStatus::Valide) {
            throw ValidationException::withMessages(['profile' => 'Votre dossier est déjà vérifié.']);
        }

        $completion = $this->completion($contractor);

        if (! $completion['complete']) {
            throw ValidationException::withMessages(collect($completion['missing'])->mapWithKeys(fn (array $item) => [$item['key'] => $item['label'].' : information manquante.'])->all());
        }

        $resubmission = $contractor->verificationReviews()->exists();

        DB::transaction(function () use ($contractor, $author): void {
            $contractor->forceFill([
                'verification_status' => ContractorVerificationStatus::Soumis,
                'submitted_at' => now(),
                'validated_at' => null,
                'verification_reason' => null,
            ])->save();

            $contractor->verificationReviews()->create([
                'submission_number' => (int) $contractor->verificationReviews()->max('submission_number') + 1,
                'submitted_by_type' => $author->getMorphClass(),
                'submitted_by_id' => $author->getKey(),
                'submitted_at' => now(),
                'status' => VerificationReviewStatus::Soumis,
                'snapshot' => $this->snapshot($contractor),
            ]);
        });

        $this->notifier->notify($contractor, 'profile.submitted', $resubmission ? 'Modification soumise' : 'Dossier soumis', 'Votre dossier est en vérification. Vous serez notifié de la décision.');

        return $this->fresh($contractor);
    }

    /**
     * État affiché (« Brouillon », « En vérification », « Vérifié », « À corriger », « Rejeté », « Suspendu »).
     *
     * @return array{value: string, label: string}
     */
    public function state(Contractor $contractor): array
    {
        return match (true) {
            $contractor->status !== ContractorStatus::Actif => ['value' => 'suspendu', 'label' => 'Suspendu'],
            $contractor->verification_status === ContractorVerificationStatus::Valide => ['value' => 'verifie', 'label' => 'Vérifié'],
            $contractor->verification_status->isPending() => ['value' => 'en_verification', 'label' => 'En vérification'],
            $contractor->verification_status === ContractorVerificationStatus::ACorriger => ['value' => 'a_corriger', 'label' => 'À corriger'],
            $contractor->verification_status === ContractorVerificationStatus::Refuse => ['value' => 'rejete', 'label' => 'Rejeté'],
            default => ['value' => 'brouillon', 'label' => 'Brouillon'],
        };
    }

    public function canEdit(Contractor $contractor): bool
    {
        return in_array($contractor->verification_status, self::EDITABLE, true) && $contractor->status === ContractorStatus::Actif;
    }

    public function canChangeKind(Contractor $contractor): bool
    {
        return $this->canEdit($contractor) && in_array($contractor->verification_status, self::KIND_EDITABLE, true);
    }

    public function canSubmit(Contractor $contractor): bool
    {
        return $this->canEdit($contractor) && $contractor->verification_status !== ContractorVerificationStatus::Valide;
    }

    /**
     * Dossier relu en base avec ses relations.
     */
    public function fresh(Contractor $contractor): Contractor
    {
        return $contractor->refresh()->load(self::WITH);
    }

    /**
     * État du dossier enregistré avec la demande de vérification.
     *
     * @return array<string, mixed>
     */
    public function snapshot(Contractor $contractor): array
    {
        $contractor->loadMissing(['trades', 'zones', 'city']);

        return [
            'contractor_id' => $contractor->getKey(),
            'kind' => $contractor->kind->value,
            'user' => $contractor->user->only(['first_name', 'last_name', 'dial_code', 'phone_number', 'email']),
            ...$contractor->only(['name', 'legal_form', 'rccm', 'tax_identifier', 'contact_name', 'dial_code', 'phone_number', 'email', 'city_id', 'address', 'experience_years', 'description']),
            'birth_date' => $contractor->birth_date?->format('Y-m-d'),
            'trades' => $contractor->trades->pluck('code')->all(),
            'zones' => $contractor->zones->pluck('id')->all(),
            'documents' => $this->activeDocuments($contractor)->map(fn (Document $document) => [
                'id' => $document->getKey(),
                'type' => $document->documentType->code,
                'files' => $document->files->map(fn ($file) => ['media_file_id' => $file->getKey(), 'side' => $file->pivot->side])->all(),
            ])->values()->all(),
            'certified_at' => now()->toIso8601String(),
        ];
    }

    private function ensureEditable(Contractor $contractor): void
    {
        if ($contractor->status !== ContractorStatus::Actif) {
            throw ValidationException::withMessages(['profile' => 'Votre compte est suspendu : contactez l’assistance O’CHANTIER.']);
        }

        if ($contractor->verification_status->isPending()) {
            throw ValidationException::withMessages(['profile' => 'Votre dossier est en cours de vérification : vous pourrez le modifier après la décision.']);
        }
    }

    /**
     * Une donnée sensible d’un dossier validé a changé : il repasse en brouillon jusqu’à sa nouvelle soumission.
     */
    private function reopen(Contractor $contractor): void
    {
        if ($contractor->verification_status === ContractorVerificationStatus::Valide) {
            $contractor->forceFill(['verification_status' => ContractorVerificationStatus::Brouillon, 'validated_at' => null])->save();
        }
    }
}
