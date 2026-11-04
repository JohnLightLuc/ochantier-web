<?php

namespace App\Support\Admin;

use App\Enums\SkillLevel;
use App\Enums\VerificationReviewStatus;
use App\Enums\WorkerVerificationStatus;
use App\Models\Admin;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Worker;
use App\Support\Commercial\CommercialNotifier;
use App\Support\Commercial\ProspectDossier;
use App\Support\Documents\DocumentStore;
use App\Support\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Création et modification d’un ouvrier par le Back Office, et ajout de pièces à son dossier.
 *
 * Un ouvrier créé par un administrateur est rattaché à un commercial référent, sans commission (canal
 * « back_office »). Son dossier reste en brouillon jusqu’à ce que les informations et pièces obligatoires soient
 * réunies, puis passe « Soumis » pour le contrôle habituel. Une modification ne remet pas un dossier validé en
 * contrôle ; elle est inscrite au journal d’audit et signalée au commercial référent.
 */
class WorkerAdministration
{
    /**
     * Champs suivis dans le journal d’audit => libellé signalé au commercial.
     */
    private const TRACKED = [
        'first_name' => 'prénom', 'last_name' => 'nom', 'dial_code' => 'téléphone', 'phone_number' => 'téléphone', 'secondary_phone' => 'téléphone secondaire',
        'gender' => 'sexe', 'birth_date' => 'date de naissance', 'birth_place' => 'lieu de naissance',
        'residence_commune_id' => 'commune', 'residence_neighborhood_id' => 'quartier', 'residence_address' => 'adresse', 'residence_landmark' => 'repère',
        'emergency_contact_name' => 'contact d’urgence', 'emergency_contact_relation' => 'contact d’urgence', 'emergency_contact_dial_code' => 'contact d’urgence', 'emergency_contact_phone_number' => 'contact d’urgence',
        'estimated_daily_rate' => 'coût journalier', 'mobility_radius_km' => 'mobilité', 'accepts_long_travel' => 'mobilité', 'needs_accommodation' => 'hébergement',
        'transport_mode' => 'transport', 'works_solo' => 'mode de travail', 'works_in_team' => 'mode de travail',
        'accepted_offer_types' => 'types de mission', 'accepted_payment_frequencies' => 'rythmes de paiement', 'current_commercial_id' => 'commercial référent',
        'trades' => 'métiers', 'specialties' => 'spécialités', 'skills' => 'compétences', 'references' => 'références',
    ];

    public function __construct(private DocumentStore $documents, private CommercialNotifier $notifier) {}

    /**
     * @param  array<string, mixed>  $data  Champs validés (WorkerFormRequest).
     */
    public function create(array $data, Admin $admin, Request $request): Worker
    {
        $worker = DB::transaction(function () use ($data, $admin): Worker {
            $worker = Worker::query()->create([
                'reference' => sprintf('OUV-%06d', DB::scalar("SELECT nextval('worker_reference_seq')")),
                'origin_commercial_id' => $data['commercial_id'],
                'current_commercial_id' => $data['commercial_id'],
                'acquisition_channel' => Worker::BACK_OFFICE_CHANNEL,
                'verification_status' => WorkerVerificationStatus::Brouillon,
                ...$this->attributes($data),
            ]);

            $worker->statusHistories()->create(['from_status' => null, 'to_status' => WorkerVerificationStatus::Brouillon->value, 'reason' => 'Créé par le Back Office', 'actor_type' => $admin->getMorphClass(), 'actor_id' => $admin->getKey()]);
            $this->syncRelations($worker, $data);
            $this->submitIfComplete($worker, $admin);

            return $worker;
        });

        AdminAudit::record($admin, 'workers', 'worker.created', $request, $worker, null, $this->state($worker));

        return $worker;
    }

    /**
     * @param  array<string, mixed>  $data  Champs validés (WorkerFormRequest).
     */
    public function update(Worker $worker, array $data, Admin $admin, Request $request): Worker
    {
        $before = $this->state($worker);

        DB::transaction(function () use ($worker, $data, $admin): void {
            $worker->forceFill([...$this->attributes($data), 'current_commercial_id' => $data['commercial_id'], 'lock_version' => $worker->lock_version + 1])->save();
            $this->syncRelations($worker, $data);
            $this->submitIfComplete($worker, $admin);
        });

        $worker->refresh();
        $after = $this->state($worker);
        $changed = array_keys(array_filter($after, fn (mixed $value, string $key) => ($before[$key] ?? null) != $value, ARRAY_FILTER_USE_BOTH));

        if ($changed !== []) {
            AdminAudit::record($admin, 'workers', 'worker.updated', $request, $worker, Arr::only($before, $changed), Arr::only($after, $changed));

            if ($worker->verification_status !== WorkerVerificationStatus::Brouillon) {
                $this->notifier->updatedByBackOffice($worker, array_values(array_unique(array_map(fn (string $key) => self::TRACKED[$key], $changed))));
            }
        }

        return $worker;
    }

    /**
     * Ajoute une pièce au dossier : elle arrive « En attente » ; un dossier validé n’est pas remis en contrôle.
     *
     * @param  array<string, UploadedFile>  $files
     */
    public function attachDocument(Worker $worker, DocumentType $type, array $files, ?string $number, ?string $expiresAt, Admin $admin, Request $request): Document
    {
        $document = DB::transaction(function () use ($worker, $type, $files, $number, $expiresAt, $admin): Document {
            $previous = $this->documents->active($worker)
                ->filter(fn (Document $document) => $document->document_type_id === $type->getKey() || ($type->is_identity && $document->documentType->is_identity));

            $purpose = match (true) {
                $type->is_identity => 'piece_identite',
                $type->code === ProspectDossier::PHOTO_DOCUMENT => 'photo_profil',
                default => 'document',
            };

            $document = $this->documents->attach($worker, $admin, $type, $files, $previous, $number, $expiresAt, $purpose);

            if ($type->code === ProspectDossier::PHOTO_DOCUMENT) {
                $worker->forceFill(['photo_media_id' => $document->files->first()->getKey()])->save();
            }

            $worker->unsetRelation('documents');
            $this->submitIfComplete($worker, $admin);

            return $document;
        });

        AdminAudit::record($admin, 'documents', 'document.uploaded', $request, $document, null, ['document_type' => $type->code, 'worker_id' => $worker->getKey()]);

        return $document;
    }

    /**
     * Informations et pièces obligatoires encore manquantes avant la transmission au contrôle.
     *
     * @return list<string>
     */
    public function missing(Worker $worker): array
    {
        $documents = $this->documents->active($worker);
        $primary = $worker->workerTrades()->where('is_primary', true)->first();

        return array_keys(array_filter([
            'Nom et prénom' => blank($worker->first_name) || blank($worker->last_name),
            'Téléphone' => blank($worker->phone_number),
            'Commune' => blank($worker->residence_commune_id),
            'Métier principal' => $primary === null,
            'Coût journalier' => blank($worker->estimated_daily_rate),
            'Contact d’urgence' => blank($worker->emergency_contact_phone_number),
            'Pièce d’identité' => ! $documents->contains(fn (Document $document) => $this->documents->isCompleteIdentity($document)),
            'Photo d’identité' => ! $documents->contains(fn (Document $document) => $document->documentType->code === ProspectDossier::PHOTO_DOCUMENT && $document->files->isNotEmpty()),
        ]));
    }

    /**
     * Un dossier en brouillon complet est transmis au contrôle (« Soumis »), comme un dossier envoyé par un
     * commercial.
     */
    private function submitIfComplete(Worker $worker, Admin $admin): void
    {
        if ($worker->verification_status !== WorkerVerificationStatus::Brouillon || $this->missing($worker) !== []) {
            return;
        }

        $worker->statusHistories()->create(['from_status' => WorkerVerificationStatus::Brouillon->value, 'to_status' => WorkerVerificationStatus::Soumis->value, 'reason' => 'Dossier complété par le Back Office', 'actor_type' => $admin->getMorphClass(), 'actor_id' => $admin->getKey()]);
        $worker->forceFill(['verification_status' => WorkerVerificationStatus::Soumis, 'submitted_at' => now(), 'lock_version' => $worker->lock_version + 1])->save();
        $worker->verificationReviews()->create([
            'submission_number' => $worker->verificationReviews()->count() + 1,
            'submitted_by_type' => $admin->getMorphClass(),
            'submitted_by_id' => $admin->getKey(),
            'submitted_at' => now(),
            'status' => VerificationReviewStatus::Soumis,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $text = fn (string $key) => ($value = trim((string) ($data[$key] ?? ''))) === '' ? null : $value;

        return [
            'first_name' => trim($data['first_name']),
            'last_name' => trim($data['last_name']),
            ...PhoneNumber::attributes(PhoneNumber::from($data['dial_code'] ?? null, $data['phone_number'] ?? null)),
            'secondary_phone' => $text('secondary_phone'),
            'gender' => $data['gender'] ?? null,
            'birth_date' => $data['birth_date'] ?? null,
            'birth_place' => $text('birth_place'),
            'residence_commune_id' => $data['residence_commune_id'],
            'residence_neighborhood_id' => $data['residence_neighborhood_id'] ?? null,
            'residence_address' => $text('residence_address'),
            'residence_landmark' => $text('residence_landmark'),
            'emergency_contact_name' => $text('emergency_contact_name'),
            'emergency_contact_relation' => $text('emergency_contact_relation'),
            ...PhoneNumber::attributes(PhoneNumber::from($data['emergency_contact_dial_code'] ?? null, $data['emergency_contact_phone_number'] ?? null), 'emergency_contact_'),
            'estimated_daily_rate' => $data['daily_rate'] ?? null,
            'estimated_daily_rate_at' => isset($data['daily_rate']) ? now()->toDateString() : null,
            'mobility_radius_km' => $data['mobility_radius_km'] ?? null,
            'accepts_long_travel' => (bool) ($data['accepts_long_travel'] ?? false),
            'needs_accommodation' => (bool) ($data['needs_accommodation'] ?? false),
            'transport_mode' => $text('transport_mode'),
            'works_solo' => (bool) ($data['works_solo'] ?? true),
            'works_in_team' => (bool) ($data['works_in_team'] ?? true),
            'accepted_offer_types' => $data['accepted_offer_types'] ?? ['ponctuelle', 'temporaire', 'contractuelle'],
            'accepted_payment_frequencies' => $data['accepted_payment_frequencies'] ?? ['journalier', 'hebdomadaire', 'mensuel', 'jalon'],
        ];
    }

    /**
     * Métier principal et secondaire, spécialités et compétences des métiers retenus, références.
     *
     * @param  array<string, mixed>  $data
     */
    private function syncRelations(Worker $worker, array $data): void
    {
        $trades = [$data['trade_id'] => ['is_primary' => true, 'years' => (int) $data['experience_years']]];

        if (! empty($data['secondary_trade_id'])) {
            $trades[$data['secondary_trade_id']] = ['is_primary' => false, 'years' => (int) ($data['secondary_experience_years'] ?? 0)];
        }

        $worker->workerTrades()->whereNotIn('trade_id', array_keys($trades))->delete();

        foreach ($trades as $tradeId => $trade) {
            $worker->workerTrades()->updateOrCreate(['trade_id' => $tradeId], ['is_primary' => $trade['is_primary'], 'years_experience' => $trade['years'], 'level' => $this->level($trade['years'])]);
        }

        $worker->specialties()->sync($data['specialty_ids'] ?? []);
        $worker->skills()->sync($data['skill_ids'] ?? []);

        $worker->references()->delete();

        foreach ($data['references'] ?? [] as $reference) {
            $worker->references()->create([
                'project_name' => trim($reference['project']),
                'role' => $reference['role'] ?? null,
                'employer_name' => $reference['employer'] ?? null,
                'start_date' => $reference['start_date'] ?? null,
                'end_date' => $reference['end_date'] ?? null,
            ]);
        }
    }

    /**
     * Valeurs suivies (champs de l’ouvrier, puis métiers, spécialités, compétences et références résumés).
     *
     * @return array<string, mixed>
     */
    private function state(Worker $worker): array
    {
        $worker = $worker->fresh(['workerTrades', 'specialties', 'skills', 'references']);
        $value = fn (mixed $v) => $v instanceof \BackedEnum ? $v->value : ($v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v);
        $fields = array_filter(array_keys(self::TRACKED), fn (string $key) => ! in_array($key, ['trades', 'specialties', 'skills', 'references'], true));

        return [
            ...collect($fields)->mapWithKeys(fn (string $field) => [$field => $value($worker->{$field})])->all(),
            'trades' => $worker->workerTrades->sortByDesc('is_primary')->map(fn ($t) => $t->trade_id.':'.$t->years_experience)->values()->all(),
            'specialties' => $worker->specialties->pluck('id')->sort()->values()->all(),
            'skills' => $worker->skills->pluck('id')->sort()->values()->all(),
            'references' => $worker->references->pluck('project_name')->values()->all(),
        ];
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
}
