<?php

namespace App\Support\Admin;

use App\Enums\CommercialProfileStatus;
use App\Enums\CommercialProfileVerificationStatus;
use App\Enums\DocumentAudience;
use App\Enums\DocumentVerificationStatus;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Models\Admin;
use App\Models\CommercialProfile;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\User;
use App\Support\Auth\AuthSessions;
use App\Support\Documents\DocumentStore;
use App\Support\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Création et modification d’un commercial par le Back Office, et contrôle de ses pièces. Le statut de vérification
 * suit les pièces obligatoires ; il informe le Back Office sans bloquer l’accès à l’application.
 */
class CommercialAdministration
{
    private const USER_FIELDS = ['first_name', 'last_name', 'dial_code', 'phone_number', 'email'];

    private const PROFILE_FIELDS = ['secondary_phone', 'address', 'started_at', 'supervisor_id', 'is_supervisor'];

    public function __construct(private AuthSessions $sessions, private DocumentStore $documents) {}

    /**
     * Compte en attente d’activation, sans mot de passe : le commercial le choisit par le lien reçu.
     *
     * @param  array<string, mixed>  $data  Champs validés (CommercialFormRequest).
     */
    public function create(array $data, PhoneNumber $phone, Admin $admin, Request $request): CommercialProfile
    {
        $commercial = DB::transaction(function () use ($data, $phone, $admin): CommercialProfile {
            $user = User::query()->create([
                'user_type' => UserType::Commercial,
                'status' => UserStatus::EnAttenteActivation,
                'first_name' => trim($data['first_name']),
                'last_name' => trim($data['last_name']),
                ...PhoneNumber::attributes($phone),
                'email' => $this->email($data['email'] ?? null),
                'created_by_admin_id' => $admin->getKey(),
            ]);

            /** @var CommercialProfile $commercial */
            $commercial = $user->commercialProfile()->create([
                ...$this->profileData($data),
                'employee_number' => $this->nextEmployeeNumber(),
                'verification_status' => CommercialProfileVerificationStatus::Incomplet,
                'status' => CommercialProfileStatus::Actif,
                'created_by_admin_id' => $admin->getKey(),
            ]);

            $zones = [[$data['primary_commune_id'], true], ...array_map(fn (string $id) => [$id, false], array_values(array_diff($data['secondary_commune_ids'] ?? [], [$data['primary_commune_id']])))];

            foreach ($zones as [$commune, $primary]) {
                $commercial->commercialZones()->create(['commune_id' => $commune, 'is_primary' => $primary, 'assigned_by_admin_id' => $admin->getKey()]);
            }

            return $commercial->setRelation('user', $user);
        });

        AdminAudit::record($admin, 'commercials', 'commercial.created', $request, $commercial, null, $this->state($commercial));

        return $commercial;
    }

    /**
     * Un nouveau numéro ou e-mail ferme les sessions : le commercial le confirme à sa prochaine connexion.
     *
     * @param  array<string, mixed>  $data  Champs validés (CommercialFormRequest).
     */
    public function update(CommercialProfile $commercial, array $data, PhoneNumber $phone, Admin $admin, Request $request): void
    {
        $before = $this->state($commercial);
        $user = $commercial->user;

        DB::transaction(function () use ($commercial, $user, $data, $phone): void {
            $user->fill(['first_name' => trim($data['first_name']), 'last_name' => trim($data['last_name']), ...PhoneNumber::attributes($phone), 'email' => $this->email($data['email'] ?? null)]);
            $contactChanged = $user->isDirty(['dial_code', 'phone_number', 'email']);

            if ($user->isDirty(['dial_code', 'phone_number'])) {
                $user->phone_verified_at = null;
            }

            if ($user->isDirty('email')) {
                $user->email_verified_at = null;
            }

            $user->save();
            $commercial->update($this->profileData($data));

            if ($contactChanged) {
                $this->sessions->revokeAll($user, 'contact_changed');
            }
        });

        $after = $this->state($commercial);
        $changed = array_keys(array_diff_assoc(array_map('strval', $after), array_map('strval', $before)));

        if ($changed !== []) {
            AdminAudit::record($admin, 'commercials', 'commercial.updated', $request, $commercial, Arr::only($before, $changed), Arr::only($after, $changed));
        }
    }

    /**
     * Ajoute une pièce ; elle remplace celle du même type.
     *
     * @param  array<string, UploadedFile>  $files
     */
    public function attachDocument(CommercialProfile $commercial, DocumentType $type, array $files, ?string $number, ?string $expiresAt, Admin $admin, Request $request): Document
    {
        $document = DB::transaction(function () use ($commercial, $type, $files, $number, $expiresAt, $admin): Document {
            $previous = $this->documents->active($commercial)->filter(fn (Document $document) => $document->document_type_id === $type->getKey());
            $document = $this->documents->attach($commercial, $admin, $type, $files, $previous, $number, $expiresAt, $type->is_identity ? 'piece_identite' : 'document');
            $commercial->unsetRelation('documents');
            $this->refreshVerification($commercial);

            return $document;
        });

        AdminAudit::record($admin, 'documents', 'document.uploaded', $request, $document, null, ['document_type' => $type->code, 'commercial_id' => $commercial->getKey()]);

        return $document;
    }

    public function decideDocument(CommercialProfile $commercial, Document $document, Admin $admin, DocumentVerificationStatus $status, ?string $reason, Request $request): void
    {
        $before = $document->verification_status->value;

        DB::transaction(function () use ($commercial, $document, $admin, $status, $reason): void {
            $document->forceFill(['verification_status' => $status, 'rejection_reason' => $reason, 'verified_by_admin_id' => $admin->getKey(), 'verified_at' => now()])->save();
            $commercial->unsetRelation('documents');
            $this->refreshVerification($commercial);
        });

        AdminAudit::record($admin, 'documents', $status === DocumentVerificationStatus::Valide ? 'document.approved' : 'document.rejected', $request, $document,
            ['verification_status' => $before], array_filter(['verification_status' => $status->value, 'reason' => $reason, 'commercial_id' => $commercial->getKey()]));
    }

    /**
     * Pièces obligatoires : une pièce rejetée demande une correction ; toutes vérifiées, le dossier est validé.
     */
    private function refreshVerification(CommercialProfile $commercial): void
    {
        $active = $this->documents->active($commercial);
        $statuses = DocumentType::query()->where('audience', DocumentAudience::Commercial)->where('is_active', true)->where('is_required', true)->get()
            ->map(fn (DocumentType $type) => $active->firstWhere('document_type_id', $type->getKey())?->verification_status);

        $status = match (true) {
            $statuses->contains(DocumentVerificationStatus::Rejete) => CommercialProfileVerificationStatus::ACorriger,
            $statuses->contains(null) => CommercialProfileVerificationStatus::Incomplet,
            $statuses->every(fn (?DocumentVerificationStatus $status) => $status === DocumentVerificationStatus::Valide) => CommercialProfileVerificationStatus::Valide,
            default => CommercialProfileVerificationStatus::Soumis,
        };

        $commercial->forceFill(['verification_status' => $status])->save();
    }

    /**
     * Matricule suivant (COM-00003), sans doublon entre requêtes concurrentes.
     */
    private function nextEmployeeNumber(): string
    {
        DB::statement("select pg_advisory_xact_lock(hashtext('commercial_employee_number'))");

        return sprintf('COM-%05d', 1 + (int) DB::scalar("select coalesce(max(substring(employee_number from '[0-9]+$')::bigint), 0) from commercial_profiles"));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function profileData(array $data): array
    {
        return [
            'secondary_phone' => $data['secondary_phone'] ?? null,
            'address' => $data['address'] ?? null,
            'started_at' => $data['started_at'],
            'supervisor_id' => $data['supervisor_id'] ?? null,
            'is_supervisor' => (bool) ($data['is_supervisor'] ?? false),
        ];
    }

    private function email(mixed $email): ?string
    {
        $email = mb_strtolower(trim((string) $email));

        return $email === '' ? null : $email;
    }

    /**
     * @return array<string, mixed>
     */
    private function state(CommercialProfile $commercial): array
    {
        return [
            ...$commercial->user->only(self::USER_FIELDS),
            ...collect($commercial->only(self::PROFILE_FIELDS))->map(fn (mixed $value) => $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value)->all(),
        ];
    }
}
