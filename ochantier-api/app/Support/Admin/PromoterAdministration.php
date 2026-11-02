<?php

namespace App\Support\Admin;

use App\Enums\PromoterProfileKind;
use App\Enums\PromoterVerificationStatus;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Models\Admin;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\PromoterProfile;
use App\Models\User;
use App\Support\Auth\AuthSessions;
use App\Support\PhoneNumber;
use App\Support\Promoter\PromoterAccount;
use App\Support\Sms\SmsGateway;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Création et modification d’un promoteur par le Back Office, et ajout de pièces à son dossier.
 *
 * Un compte créé par un administrateur reste « en attente d’activation » : le promoteur l’active dans l’application
 * (« Créer votre compte » avec le même numéro ou e-mail), ce qui enregistre son acceptation des CGU. Une modification
 * faite par un administrateur ne remet pas un dossier validé en contrôle ; elle est tracée dans le journal d’audit.
 */
class PromoterAdministration
{
    /**
     * Champs suivis dans le journal d’audit.
     */
    private const USER_FIELDS = ['first_name', 'last_name', 'dial_code', 'phone_number', 'email'];

    private const PROFILE_FIELDS = ['profile_kind', 'job_title', 'address', 'commune_id', 'neighborhood_id'];

    private const COMPANY_FIELDS = ['legal_name', 'trade_name', 'legal_form', 'rccm', 'tax_identifier', 'address', 'commune_id', 'neighborhood_id'];

    public function __construct(private PromoterAccount $account, private AuthSessions $sessions, private SmsGateway $sms) {}

    /**
     * @param  array<string, mixed>  $data  Champs validés (PromoterFormRequest).
     */
    public function create(array $data, ?PhoneNumber $phone, bool $invite, Admin $admin, Request $request): PromoterProfile
    {
        $email = $this->email($data['email'] ?? null);

        $profile = DB::transaction(function () use ($data, $phone, $email, $admin): PromoterProfile {
            $user = User::query()->create([
                'user_type' => UserType::Promoter,
                'status' => UserStatus::EnAttenteActivation,
                'first_name' => trim($data['first_name']),
                'last_name' => trim($data['last_name']),
                ...PhoneNumber::attributes($phone),
                'email' => $email,
            ]);

            /** @var PromoterProfile $profile */
            $profile = $user->promoterProfile()->updateOrCreate([], ['profile_kind' => PromoterProfileKind::Particulier, 'verification_status' => PromoterVerificationStatus::Brouillon]);

            return $this->account->update($profile->setRelation('user', $user), $admin, $this->profileData($data), resubmitValidated: false);
        });

        if ($invite) {
            $this->invite($profile->user, $phone);
        }

        AdminAudit::record($admin, 'promoters', 'promoter.created', $request, $profile, null, [...$this->state($profile), 'invitation_sent' => $invite]);

        return $profile;
    }

    /**
     * Met à jour l’identité, les coordonnées, le profil et l’entreprise. Un nouveau numéro ou e-mail ferme les
     * sessions du promoteur, qui le confirmera à sa prochaine connexion.
     *
     * @param  array<string, mixed>  $data  Champs validés (PromoterFormRequest).
     */
    public function update(PromoterProfile $profile, array $data, ?PhoneNumber $phone, Admin $admin, Request $request): PromoterProfile
    {
        $kind = PromoterProfileKind::from($data['profile_kind']);

        if ($kind !== $profile->profile_kind && ! $this->account->canChangeKind($profile)) {
            throw ValidationException::withMessages(['profile_kind' => 'Le dossier a déjà été transmis pour vérification : le type de compte ne peut plus être modifié.']);
        }

        $before = $this->state($profile);
        $user = $profile->user;

        DB::transaction(function () use ($profile, $data, $phone, $user, $admin): void {
            $user->fill([...PhoneNumber::attributes($phone), 'email' => $this->email($data['email'] ?? null)]);
            $contactChanged = $user->isDirty(['dial_code', 'phone_number', 'email']);

            if ($user->isDirty(['dial_code', 'phone_number'])) {
                $user->phone_verified_at = null;
            }

            if ($user->isDirty('email')) {
                $user->email_verified_at = null;
            }

            $user->save();

            if ($contactChanged) {
                $this->sessions->revokeAll($user, 'contact_changed');
            }

            $this->account->update($profile, $admin, $this->profileData($data), resubmitValidated: false);
        });

        $profile->refresh();
        $after = $this->state($profile);
        $changed = array_keys(array_filter($after, fn (mixed $value, string $key) => ($before[$key] ?? null) !== $value, ARRAY_FILTER_USE_BOTH));

        if ($changed !== []) {
            AdminAudit::record($admin, 'promoters', 'promoter.updated', $request, $profile, Arr::only($before, $changed), Arr::only($after, $changed));
        }

        return $profile;
    }

    /**
     * Ajoute une pièce au nom du promoteur : elle arrive « À vérifier ». Un dossier validé n’est pas remis en contrôle.
     *
     * @param  array<string, UploadedFile>  $files
     */
    public function attachDocument(PromoterProfile $profile, DocumentType $type, array $files, ?string $number, ?string $expiresAt, Admin $admin, Request $request): Document
    {
        $document = $this->account->attachDocument($profile, $admin, $type, $files, $number, $expiresAt, resubmitValidated: false);

        AdminAudit::record($admin, 'documents', 'document.uploaded', $request, $document, null, ['document_type' => $type->code, 'promoter_profile_id' => $profile->getKey()]);

        return $document;
    }

    /**
     * Champs du profil au format attendu par PromoterAccount::update.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function profileData(array $data): array
    {
        $profile = Arr::only($data, ['first_name', 'last_name', 'profile_kind', 'job_title', 'address', 'commune_id', 'neighborhood_id']);

        if ($data['profile_kind'] === PromoterProfileKind::Entreprise->value) {
            $profile['company'] = Arr::only($data['company'], ['legal_name', 'trade_name', 'legal_form', 'rccm', 'ncc', 'headquarters']);
        }

        return $profile;
    }

    private function invite(User $user, ?PhoneNumber $phone): void
    {
        $message = 'O’CHANTIER : un compte promoteur a été préparé pour vous. Installez l’application O’CHANTIER Promoteur, '
            .'choisissez « Créer votre compte » et saisissez ce '.($phone ? 'numéro' : 'e-mail').' pour l’activer.';

        if ($phone) {
            $this->sms->send($phone->e164(), $message);

            return;
        }

        Mail::raw($message, fn ($mail) => $mail->to($user->email)->subject('Votre compte promoteur O’CHANTIER'));
    }

    private function email(mixed $email): ?string
    {
        $email = mb_strtolower(trim((string) $email));

        return $email === '' ? null : $email;
    }

    /**
     * Valeurs suivies du compte, du profil et de l’entreprise (clés préfixées : user.*, profile.*, company.*).
     *
     * @return array<string, mixed>
     */
    private function state(PromoterProfile $profile): array
    {
        $user = $profile->user->fresh();
        $organization = $this->account->organization($profile);
        $value = fn (mixed $v) => $v instanceof \BackedEnum ? $v->value : $v;

        return [
            ...collect(self::USER_FIELDS)->mapWithKeys(fn (string $field) => ["user.{$field}" => $user->{$field}])->all(),
            ...collect(self::PROFILE_FIELDS)->mapWithKeys(fn (string $field) => ["profile.{$field}" => $value($profile->{$field})])->all(),
            ...($organization ? collect(self::COMPANY_FIELDS)->mapWithKeys(fn (string $field) => ["company.{$field}" => $organization->{$field}])->all() : []),
        ];
    }
}
