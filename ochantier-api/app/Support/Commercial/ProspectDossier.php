<?php

namespace App\Support\Commercial;

use App\Enums\ConsentType;
use App\Enums\DocumentAudience;
use App\Enums\DocumentVerificationStatus;
use App\Enums\ProspectStatus;
use App\Enums\SkillLevel;
use App\Enums\VerificationReviewStatus;
use App\Enums\WorkerVerificationStatus;
use App\Models\CommercialProfile;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Prospect;
use App\Models\ReasonCode;
use App\Models\User;
use App\Models\Worker;
use App\Support\Media\MediaStore;
use App\Support\Phone;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Prospect d’un commercial et son dossier ouvrier.
 *
 * Le prospect porte le contact (nom, téléphone, commune, métier pressenti) et le suivi. Dès qu’une information du
 * dossier (naissance, coût, contact d’urgence…) ou une pièce est saisie, un ouvrier « brouillon » lié au prospect est
 * créé : il porte le dossier complet. La soumission passe l’ouvrier en « soumis » pour le contrôle du Back office et
 * le prospect en « converti ».
 */
class ProspectDossier
{
    /**
     * Champs propres au dossier : leur saisie crée l’ouvrier brouillon.
     */
    public const DOSSIER_FIELDS = [
        'gender', 'birth_date', 'birth_place', 'daily_rate', 'specialty_ids', 'skill_ids', 'secondary_trade_id',
        'secondary_experience_years', 'experiences', 'emergency_contact_name', 'emergency_contact_phone',
    ];

    /**
     * Pièces facultatives comptées dans l’avancement du dossier (en plus de la pièce d’identité et de la photo).
     */
    public const OPTIONAL_DOCUMENTS = ['worker_diploma', 'worker_work_certificate', 'worker_proof_of_address'];

    public const PHOTO_DOCUMENT = 'worker_photo';

    /**
     * Pièces affichées dans la fiche du prospect, après la pièce d’identité.
     */
    public const DOCUMENT_SLOTS = [self::PHOTO_DOCUMENT, ...self::OPTIONAL_DOCUMENTS, 'worker_cv'];

    /**
     * @var array<string, string>|null
     */
    private ?array $documentLabels = null;

    public function __construct(private MediaStore $media) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(CommercialProfile $commercial, User $author, array $data, ?string $ipAddress = null, ?string $deviceId = null): Prospect
    {
        $this->ensurePhoneAvailable($commercial, $data['phone']);

        return DB::transaction(function () use ($commercial, $author, $data, $ipAddress, $deviceId): Prospect {
            $prospect = $commercial->prospects()->create([
                ...$this->contactAttributes($data),
                'client_uuid' => $data['client_uuid'] ?? null,
                'status' => $data['status'] ?? ProspectStatus::Nouveau,
                'contact_consent_at' => now(),
                'lock_version' => 1,
            ]);

            $prospect->consents()->create([
                'consent_type' => ConsentType::Contact,
                'granted' => true,
                'granted_at' => now(),
                'recorded_by_type' => $author->getMorphClass(),
                'recorded_by_id' => $author->getKey(),
                'channel' => 'terrain',
                'ip_address' => $ipAddress,
                'device_id' => $deviceId,
            ]);

            $this->fillDossier($prospect, $data);

            return $prospect->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Prospect $prospect, array $data): Prospect
    {
        $this->ensureEditable($prospect);

        if (isset($data['lock_version']) && (int) $data['lock_version'] !== $prospect->lock_version) {
            throw new ConflictHttpException('Ce prospect a été modifié sur un autre appareil. Rechargez-le avant de continuer.');
        }

        if (isset($data['phone'])) {
            $this->ensurePhoneAvailable($prospect->commercial, $data['phone'], $prospect);
        }

        return DB::transaction(function () use ($prospect, $data): Prospect {
            $prospect->forceFill([...$this->contactAttributes($data), 'lock_version' => $prospect->lock_version + 1])->save();
            $this->fillDossier($prospect, $data);

            return $prospect->refresh();
        });
    }

    /**
     * Abandon du prospect (« Archiver ») ; son dossier brouillon est retiré.
     */
    public function abandon(Prospect $prospect, ReasonCode $reason, ?string $comment): Prospect
    {
        $this->ensureEditable($prospect);

        DB::transaction(function () use ($prospect, $reason, $comment): void {
            $prospect->forceFill([
                'status' => ProspectStatus::Abandonne,
                'abandon_reason_id' => $reason->getKey(),
                'abandon_comment' => $comment,
                'lock_version' => $prospect->lock_version + 1,
            ])->save();

            $this->draft($prospect)?->delete();
        });

        return $prospect->refresh();
    }

    /**
     * Ajoute une pièce au dossier. Une pièce du même type (ou une autre pièce d’identité) est remplacée.
     *
     * @param  array<string, UploadedFile>  $files  côté (recto, verso, page) => fichier
     */
    public function attachDocument(Prospect $prospect, User $author, DocumentType $type, array $files, ?string $number = null, ?string $expiresAt = null): Document
    {
        $this->ensureEditable($prospect);

        return DB::transaction(function () use ($prospect, $author, $type, $files, $number, $expiresAt): Document {
            $worker = $this->ensureDraft($prospect);
            $previous = $this->activeDocuments($worker)
                ->filter(fn (Document $document) => $document->document_type_id === $type->getKey() || ($type->is_identity && $document->documentType->is_identity));

            $document = $worker->documents()->create([
                'document_type_id' => $type->getKey(),
                'label' => $type->label,
                'number_encrypted' => $number,
                'number_last4' => $number ? substr(preg_replace('/\s+/', '', $number), -4) : null,
                'expires_at' => $expiresAt,
                'version' => ($previous->max('version') ?? 0) + 1,
                'replaces_document_id' => $previous->sortByDesc('created_at')->first()?->getKey(),
                'verification_status' => DocumentVerificationStatus::EnAttente,
                'uploaded_by_type' => $author->getMorphClass(),
                'uploaded_by_id' => $author->getKey(),
            ]);

            $purpose = match (true) {
                $type->is_identity => 'piece_identite',
                $type->code === self::PHOTO_DOCUMENT => 'photo_profil',
                default => 'document',
            };

            foreach ($files as $side => $file) {
                $media = $this->media->store($file, $purpose, $author, $type->is_sensitive);
                $document->files()->attach($media->getKey(), ['side' => $side, 'page_number' => 1]);

                if ($type->code === self::PHOTO_DOCUMENT) {
                    $worker->forceFill(['photo_media_id' => $media->getKey()])->save();
                }
            }

            foreach ($previous as $replaced) {
                $replaced->forceFill(['verification_status' => DocumentVerificationStatus::Remplace])->save();
            }

            $worker->unsetRelation('documents');
            $this->touch($prospect);

            return $document->load(['documentType', 'files']);
        });
    }

    public function removeDocument(Prospect $prospect, Document $document): void
    {
        $this->ensureEditable($prospect);

        DB::transaction(function () use ($prospect, $document): void {
            if ($document->documentType->code === self::PHOTO_DOCUMENT) {
                $document->owner->forceFill(['photo_media_id' => null])->save();
            }

            $document->delete();
            $this->draft($prospect)?->unsetRelation('documents');
            $this->touch($prospect);
        });
    }

    /**
     * Transmet le dossier complet au Back office.
     */
    public function submit(Prospect $prospect, User $author): Worker
    {
        $this->ensureEditable($prospect);
        $completion = $this->completion($prospect);

        if (! $completion['ready']) {
            throw ValidationException::withMessages([
                'dossier' => 'Dossier incomplet : '.implode(', ', array_map(fn (array $item) => mb_strtolower(mb_substr($item['label'], 0, 1)).mb_substr($item['label'], 1), $completion['blocking'])).'.',
            ]);
        }

        return DB::transaction(function () use ($prospect, $author): Worker {
            $worker = $this->draft($prospect);
            $worker->forceFill([
                'verification_status' => WorkerVerificationStatus::Soumis,
                'submitted_at' => now(),
                'lock_version' => $worker->lock_version + 1,
            ])->save();

            $worker->statusHistories()->create([
                'from_status' => WorkerVerificationStatus::Brouillon,
                'to_status' => WorkerVerificationStatus::Soumis,
                'actor_type' => $author->getMorphClass(),
                'actor_id' => $author->getKey(),
            ]);

            $worker->verificationReviews()->create([
                'submission_number' => $worker->verificationReviews()->count() + 1,
                'submitted_by_type' => $author->getMorphClass(),
                'submitted_by_id' => $author->getKey(),
                'submitted_at' => now(),
                'status' => VerificationReviewStatus::Soumis,
                'snapshot' => $this->snapshot($prospect),
            ]);

            $prospect->forceFill([
                'status' => ProspectStatus::Converti,
                'converted_worker_id' => $worker->getKey(),
                'converted_at' => now(),
                'lock_version' => $prospect->lock_version + 1,
            ])->save();

            return $worker;
        });
    }

    /**
     * Ouvrier brouillon du prospect, s’il a été créé.
     */
    public function draft(Prospect $prospect): ?Worker
    {
        return $prospect->draftWorker;
    }

    /**
     * Avancement du dossier (12 éléments, comme la maquette) et éléments bloquant la soumission.
     *
     * @return array{percent: int, filled: int, total: int, ready: bool, items: list<array{key: string, label: string, done: bool, required: bool}>, blocking: list<array{key: string, label: string, done: bool, required: bool}>}
     */
    public function completion(Prospect $prospect): array
    {
        $worker = $this->draft($prospect);
        $documents = $worker ? $this->activeDocuments($worker) : collect();
        $has = fn (string $code) => $documents->contains(fn (Document $document) => $document->documentType->code === $code && $document->files->isNotEmpty());

        $item = fn (string $key, string $label, bool $done, bool $required = true, bool $counted = true) => compact('key', 'label', 'done', 'required', 'counted');
        $items = [
            $item('name', 'Nom et prénom', filled($prospect->first_name) && filled($prospect->last_name)),
            $item('phone', 'Téléphone', filled($prospect->phone)),
            $item('commune', 'Commune', filled($prospect->commune_id)),
            $item('trade', 'Métier principal', filled($prospect->presumed_trade_id)),
            $item('experience', 'Années d’expérience', $prospect->estimated_experience_years !== null),
            $item('daily_rate', 'Coût journalier', filled($worker?->estimated_daily_rate)),
            $item('emergency_contact', 'Contact d’urgence', filled($worker?->emergency_contact_phone)),
            $item('identity', 'Pièce d’identité', $documents->contains(fn (Document $document) => $this->isCompleteIdentity($document))),
            $item('photo', 'Photo d’identité', $has(self::PHOTO_DOCUMENT)),
            ...array_map(fn (string $code) => $item($code, $this->documentLabel($code), $has($code), false), self::OPTIONAL_DOCUMENTS),
            $item('gender', 'Sexe', filled($worker?->gender), counted: false),
            $item('birth', 'Date et lieu de naissance', filled($worker?->birth_date) && filled($worker?->birth_place), counted: false),
        ];

        $counted = array_values(array_filter($items, fn (array $i) => $i['counted']));
        $filled = count(array_filter($counted, fn (array $i) => $i['done']));
        $blocking = array_values(array_filter($items, fn (array $i) => $i['required'] && ! $i['done']));
        $strip = fn (array $i) => array_diff_key($i, ['counted' => true]);

        return [
            'percent' => (int) (round($filled / count($counted) * 20) * 5),
            'filled' => $filled,
            'total' => count($counted),
            'ready' => $blocking === [] && $worker !== null,
            'items' => array_map($strip, $counted),
            'blocking' => array_map($strip, $blocking),
        ];
    }

    /**
     * Pièces actives du dossier (hors pièces remplacées), avec leur type et leurs fichiers.
     *
     * @return Collection<int, Document>
     */
    public function activeDocuments(Worker $worker): Collection
    {
        $worker->loadMissing(['documents.documentType', 'documents.files']);

        return $worker->documents
            ->reject(fn (Document $document) => $document->verification_status === DocumentVerificationStatus::Remplace)
            ->sortByDesc('created_at')
            ->values();
    }

    public function isCompleteIdentity(Document $document): bool
    {
        $type = $document->documentType;
        $sides = $document->files->pluck('pivot.side')->all();

        return $type->is_identity
            && filled($document->number_last4)
            && ($type->has_back_side ? ! array_diff(['recto', 'verso'], $sides) : $sides !== []);
    }

    /**
     * Refuse un numéro déjà suivi par ce commercial ou déjà inscrit comme ouvrier.
     */
    public function ensurePhoneAvailable(CommercialProfile $commercial, string $phone, ?Prospect $ignore = null): void
    {
        if ($message = $this->phoneConflict($commercial, $phone, $ignore)) {
            throw ValidationException::withMessages(['phone' => $message]);
        }
    }

    public function phoneConflict(CommercialProfile $commercial, string $phone, ?Prospect $ignore = null): ?string
    {
        $phone = Phone::e164($phone);

        $inProspects = $commercial->prospects()
            ->where('phone', $phone)
            ->whereNotIn('status', [ProspectStatus::Abandonne, ProspectStatus::Converti])
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->getKey()))
            ->exists();

        if ($inProspects) {
            return 'Ce numéro existe déjà dans vos prospects.';
        }

        $draft = $ignore ? $this->draft($ignore) : null;
        $isWorker = Worker::query()
            ->where('phone', $phone)
            ->whereNull('merged_into_worker_id')
            ->when($draft, fn ($query) => $query->whereKeyNot($draft->getKey()))
            ->exists();

        return $isWorker ? 'Ce numéro est déjà inscrit sur O’CHANTIER.' : null;
    }

    /**
     * État du dossier enregistré avec la demande de contrôle.
     *
     * @return array<string, mixed>
     */
    public function snapshot(Prospect $prospect): array
    {
        $worker = $this->draft($prospect)->load(['workerTrades', 'specialties', 'skills', 'references']);

        return [
            'prospect_id' => $prospect->getKey(),
            'worker' => $worker->only([
                'reference', 'first_name', 'last_name', 'gender', 'birth_place', 'phone', 'emergency_contact_name',
                'emergency_contact_phone', 'residence_commune_id', 'residence_neighborhood_id', 'estimated_daily_rate',
            ]) + ['birth_date' => $worker->birth_date?->toDateString()],
            'trades' => $worker->workerTrades->map->only(['trade_id', 'is_primary', 'years_experience', 'level'])->all(),
            'specialty_ids' => $worker->specialties->modelKeys(),
            'skill_ids' => $worker->skills->modelKeys(),
            'experiences' => $worker->references->pluck('project_name')->all(),
            'documents' => $this->activeDocuments($worker)->map(fn (Document $document) => [
                'id' => $document->getKey(),
                'type' => $document->documentType->code,
                'number_last4' => $document->number_last4,
                'files' => $document->files->map(fn ($file) => ['media_file_id' => $file->getKey(), 'side' => $file->pivot->side])->all(),
            ])->all(),
        ];
    }

    /**
     * Champs du contact (prospect) présents dans la requête : mise à jour partielle.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function contactAttributes(array $data): array
    {
        $attributes = array_intersect_key($data, array_flip(['first_name', 'last_name', 'commune_id', 'neighborhood_id', 'status']));

        if (isset($data['phone'])) {
            $attributes['phone'] = Phone::e164($data['phone']);
        }

        if (array_key_exists('trade_id', $data)) {
            $attributes['presumed_trade_id'] = $data['trade_id'];
        }

        if (array_key_exists('experience_years', $data)) {
            $attributes['estimated_experience_years'] = $data['experience_years'];
        }

        return $attributes;
    }

    /**
     * Crée l’ouvrier brouillon si un champ du dossier est saisi, puis le tient à jour avec le prospect.
     *
     * @param  array<string, mixed>  $data
     */
    private function fillDossier(Prospect $prospect, array $data): void
    {
        $startsDossier = array_intersect_key($data, array_flip(self::DOSSIER_FIELDS)) !== [];

        if ($worker = $startsDossier ? $this->ensureDraft($prospect) : $this->draft($prospect)) {
            $this->fillDraft($prospect, $worker, $data);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function fillDraft(Prospect $prospect, Worker $worker, array $data): void
    {
        $attributes = [
            'first_name' => $prospect->first_name,
            'last_name' => $prospect->last_name,
            'phone' => $prospect->phone,
            'residence_commune_id' => $prospect->commune_id,
            'residence_neighborhood_id' => $prospect->neighborhood_id,
            ...array_intersect_key($data, array_flip(['gender', 'birth_date', 'birth_place', 'emergency_contact_name'])),
            'lock_version' => $worker->lock_version + 1,
        ];

        if (array_key_exists('emergency_contact_phone', $data)) {
            $attributes['emergency_contact_phone'] = $data['emergency_contact_phone'] ? Phone::e164($data['emergency_contact_phone']) : null;
        }

        if (array_key_exists('daily_rate', $data)) {
            $attributes['estimated_daily_rate'] = $data['daily_rate'];
            $attributes['estimated_daily_rate_at'] = $data['daily_rate'] ? now()->toDateString() : null;
        }

        $worker->forceFill($attributes)->save();
        $this->syncTrades($prospect, $worker, $data);

        if (array_key_exists('experiences', $data)) {
            $worker->references()->delete();

            foreach ($data['experiences'] ?? [] as $experience) {
                $worker->references()->create(['project_name' => $experience]);
            }
        }
    }

    /**
     * Métier principal (repris du prospect), métier secondaire, spécialités et compétences des métiers retenus.
     *
     * @param  array<string, mixed>  $data
     */
    private function syncTrades(Prospect $prospect, Worker $worker, array $data): void
    {
        $trades = $worker->workerTrades()->get();
        $secondary = $trades->firstWhere('is_primary', false);

        if (array_key_exists('secondary_trade_id', $data) && ! $data['secondary_trade_id']) {
            $secondary?->delete();
            $secondary = null;
        } elseif (! empty($data['secondary_trade_id'])) {
            $years = $data['secondary_experience_years'] ?? $secondary?->years_experience ?? 0;

            if ($secondary && $secondary->trade_id !== $data['secondary_trade_id']) {
                $secondary->delete();
            }

            $secondary = $worker->workerTrades()->updateOrCreate(
                ['trade_id' => $data['secondary_trade_id']],
                ['is_primary' => false, 'years_experience' => $years, 'level' => $this->level($years)],
            );
        } elseif ($secondary && array_key_exists('secondary_experience_years', $data)) {
            $years = $data['secondary_experience_years'] ?? 0;
            $secondary->update(['years_experience' => $years, 'level' => $this->level($years)]);
        }

        $primary = $trades->firstWhere('is_primary', true);

        if ($prospect->presumed_trade_id && $prospect->estimated_experience_years !== null) {
            if ($primary && $primary->trade_id !== $prospect->presumed_trade_id) {
                $primary->delete();
            }

            $worker->workerTrades()->where('trade_id', $prospect->presumed_trade_id)->where('is_primary', false)->delete();
            $worker->workerTrades()->updateOrCreate(['trade_id' => $prospect->presumed_trade_id], [
                'is_primary' => true,
                'years_experience' => $prospect->estimated_experience_years,
                'level' => $this->level($prospect->estimated_experience_years),
            ]);
        } else {
            $primary?->delete();
        }

        $tradeIds = $worker->workerTrades()->pluck('trade_id');

        if (array_key_exists('specialty_ids', $data)) {
            $worker->specialties()->sync($data['specialty_ids'] ?? []);
        }

        if (array_key_exists('skill_ids', $data)) {
            $worker->skills()->sync($data['skill_ids'] ?? []);
        }

        // Les spécialités et compétences d’un métier retiré ne s’appliquent plus.
        $worker->specialties()->detach($worker->specialties()->whereNotIn('specialties.trade_id', $tradeIds)->pluck('specialties.id'));
        $worker->skills()->detach($worker->skills()->whereNotIn('skills.trade_id', $tradeIds)->pluck('skills.id'));
    }

    private function ensureDraft(Prospect $prospect): Worker
    {
        if ($worker = $this->draft($prospect)) {
            return $worker;
        }

        if ($this->phoneConflict($prospect->commercial, $prospect->phone, $prospect)) {
            throw ValidationException::withMessages(['phone' => 'Ce numéro est déjà inscrit sur O’CHANTIER.']);
        }

        $worker = $prospect->workers()->create([
            'reference' => sprintf('OUV-%06d', DB::scalar("SELECT nextval('worker_reference_seq')")),
            'origin_commercial_id' => $prospect->commercial_id,
            'current_commercial_id' => $prospect->commercial_id,
            'first_name' => $prospect->first_name,
            'last_name' => $prospect->last_name,
            'phone' => $prospect->phone,
            'residence_commune_id' => $prospect->commune_id,
            'residence_neighborhood_id' => $prospect->neighborhood_id,
            'acquisition_channel' => 'terrain',
            'verification_status' => WorkerVerificationStatus::Brouillon,
        ]);

        if ($prospect->status === ProspectStatus::Nouveau) {
            $prospect->forceFill(['status' => ProspectStatus::ACompleter])->save();
        }

        $prospect->setRelation('draftWorker', $worker);

        return $worker;
    }

    private function ensureEditable(Prospect $prospect): void
    {
        if (in_array($prospect->status, [ProspectStatus::Converti, ProspectStatus::Abandonne], true)) {
            throw ValidationException::withMessages([
                'prospect' => $prospect->status === ProspectStatus::Converti
                    ? 'Dossier transmis : la modification se fait via le Back office.'
                    : 'Ce prospect est archivé.',
            ]);
        }
    }

    private function touch(Prospect $prospect): void
    {
        $prospect->forceFill(['lock_version' => $prospect->lock_version + 1])->save();
    }

    private function level(int $years): SkillLevel
    {
        return match (true) {
            $years >= 10 => SkillLevel::Expert,
            $years >= 5 => SkillLevel::Confirme,
            $years >= 2 => SkillLevel::Intermediaire,
            default => SkillLevel::Debutant,
        };
    }

    private function documentLabel(string $code): string
    {
        $this->documentLabels ??= DocumentType::query()->where('audience', DocumentAudience::Worker)->pluck('label', 'code')->all();

        return $this->documentLabels[$code] ?? $code;
    }
}
