<?php

namespace App\Support\Admin;

use App\Enums\LandingApplicationStatus;
use App\Enums\LandingMessageStatus;
use App\Models\Admin;
use App\Models\DocumentType;
use App\Models\LandingContactMessage;
use App\Models\LandingWorkerApplication;
use App\Models\Setting;
use App\Models\Worker;
use App\Support\Documents\DocumentStore;
use App\Support\Media\MediaStore;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Demandes du site public dans le Back Office : candidatures « Devenir ouvrier » (vérifier, refuser, convertir en
 * profil ouvrier avec la pièce d’identité et la photo transmises, doublons repérés par le téléphone) et messages de
 * contact (prise en charge, traitement, délai de réponse annoncé). Chaque décision est tracée dans le journal d’audit.
 */
class LandingLeads
{
    /**
     * Délai de réponse annoncé par défaut (heures), si le paramètre landing.contact_response_hours manque.
     */
    public const DEFAULT_RESPONSE_HOURS = 24;

    public function __construct(private MediaStore $media, private DocumentStore $documents) {}

    /**
     * @param  array<string, mixed>  $filters  status, search, trade
     * @return Builder<LandingWorkerApplication>
     */
    public function applications(array $filters): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $digits = PhoneNumber::digits($search);

        return LandingWorkerApplication::query()
            ->with(['trade', 'commune', 'worker'])
            ->selectRaw('landing_worker_applications.*, (SELECT count(*) FROM landing_worker_applications o WHERE o.phone_digits = landing_worker_applications.phone_digits AND o.id <> landing_worker_applications.id) AS duplicates_count')
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['trade'] ?? null, fn (Builder $query, string $trade) => $query->whereRaw('lower(trade_label) = lower(?)', [$trade]))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('reference', 'ilike', '%'.$search.'%')
                ->orWhereRaw("concat_ws(' ', first_name, last_name) ilike ?", ['%'.$search.'%'])
                ->when(strlen($digits) >= 4, fn (Builder $query) => $query->orWhere('phone_digits', 'like', '%'.$digits.'%'))))
            ->latest();
    }

    /**
     * @return array<string, int>
     */
    public function applicationCounts(): array
    {
        $counts = LandingWorkerApplication::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return collect(LandingApplicationStatus::cases())->mapWithKeys(fn (LandingApplicationStatus $status) => [$status->value => (int) ($counts[$status->value] ?? 0)])->all();
    }

    /**
     * Autres candidatures et ouvriers déjà inscrits avec le même téléphone.
     *
     * @return array{applications: list<array<string, mixed>>, workers: list<array<string, mixed>>}
     */
    public function duplicates(LandingWorkerApplication $application): array
    {
        $digits = $application->phone_digits;

        return [
            'applications' => LandingWorkerApplication::query()->where('phone_digits', $digits)->whereKeyNot($application->getKey())->latest()->get()
                ->map(fn (LandingWorkerApplication $other) => ['id' => $other->id, 'reference' => $other->reference, 'status' => ['value' => $other->status->value, 'label' => $other->status->label()], 'created_at' => $other->created_at?->toIso8601String()])->all(),
            'workers' => Worker::query()->whereRaw("right(regexp_replace(coalesce(phone_number, ''), '\\D', '', 'g'), 10) = ?", [$digits])->get(['id', 'reference', 'first_name', 'last_name', 'verification_status'])
                ->map(fn (Worker $worker) => ['id' => $worker->id, 'reference' => $worker->reference, 'full_name' => trim($worker->first_name.' '.$worker->last_name), 'status' => ['value' => $worker->verification_status->value, 'label' => $worker->verification_status->label()]])->all(),
        ];
    }

    /**
     * Champs du formulaire de création d’ouvrier préremplis d’après la candidature (le commercial référent et les
     * champs non reconnus restent à choisir).
     *
     * @return array<string, mixed>
     */
    public function prefill(LandingWorkerApplication $application): array
    {
        $digits = PhoneNumber::digits($application->phone);
        $local = strlen($digits) > 10 && str_starts_with($digits, '225') ? substr($digits, 3) : $digits;

        return array_filter([
            'first_name' => $application->first_name,
            'last_name' => $application->last_name,
            'dial_code' => '225',
            'phone_number' => $local,
            'gender' => match ($application->gender) {
                'Homme' => 'M',
                'Femme' => 'F',
                default => null,
            },
            'birth_date' => $application->birth_date?->toDateString(),
            'residence_commune_id' => $application->commune_id,
            'trade_id' => $application->trade_id,
            'experience_years' => $application->experience_years,
            'daily_rate' => $application->daily_rate,
        ], fn ($value) => $value !== null);
    }

    public function review(LandingWorkerApplication $application, Admin $admin, Request $request): void
    {
        $this->ensureApplication($application, [LandingApplicationStatus::Nouvelle], 'Cette candidature est déjà prise en charge.');
        $application->update(['status' => LandingApplicationStatus::EnVerification, 'handled_by_admin_id' => $admin->getKey()]);
        AdminAudit::record($admin, 'workers', 'landing.application_reviewed', $request, $application, ['status' => 'nouvelle'], ['status' => 'en_verification']);
    }

    public function refuse(LandingWorkerApplication $application, string $reason, Admin $admin, Request $request): void
    {
        $before = $application->status->value;
        $this->ensureApplication($application, [LandingApplicationStatus::Nouvelle, LandingApplicationStatus::EnVerification], 'Cette candidature est déjà traitée.');
        $application->update(['status' => LandingApplicationStatus::Refusee, 'refusal_reason' => $reason, 'handled_by_admin_id' => $admin->getKey()]);
        AdminAudit::record($admin, 'workers', 'landing.application_refused', $request, $application, ['status' => $before], ['status' => 'refusee', 'reason' => $reason]);
    }

    /**
     * Rattache la candidature au profil ouvrier créé depuis le Back Office : la pièce d’identité et la photo
     * transmises sont ajoutées au dossier de l’ouvrier, la candidature passe « Convertie ».
     */
    public function convert(LandingWorkerApplication $application, Worker $worker, Admin $admin, Request $request): void
    {
        $this->ensureApplication($application, [LandingApplicationStatus::Nouvelle, LandingApplicationStatus::EnVerification], 'Cette candidature est déjà traitée.');

        DB::transaction(function () use ($application, $worker, $admin): void {
            $identity = DocumentType::query()->where('code', 'worker_cni')->first();
            $photo = DocumentType::query()->where('code', 'worker_photo')->first();

            if ($identity && $application->identityMedia) {
                $this->documents->attachGenerated($worker, $admin, $identity, $application->identityMedia, 'Pièce d’identité (candidature '.$application->reference.')');
            }

            if ($photo && $application->photoMedia) {
                $this->documents->attachGenerated($worker, $admin, $photo, $application->photoMedia);
            }

            $application->update(['status' => LandingApplicationStatus::Convertie, 'worker_id' => $worker->getKey(), 'handled_by_admin_id' => $admin->getKey()]);
        });

        AdminAudit::record($admin, 'workers', 'landing.application_converted', $request, $application, null, ['status' => 'convertie', 'worker_id' => $worker->getKey()]);
    }

    /**
     * Pièces de la candidature, consultables par un lien temporaire.
     *
     * @return array{identity: array<string, mixed>|null, photo: array<string, mixed>|null}
     */
    public function files(LandingWorkerApplication $application): array
    {
        $file = fn ($media) => $media ? ['name' => $media->original_name, 'mime_type' => $media->mime_type, 'url' => $this->media->temporaryUrl($media)] : null;

        return ['identity' => $file($application->identityMedia), 'photo' => $file($application->photoMedia)];
    }

    /**
     * @param  array<string, mixed>  $filters  status, search, late
     * @return Builder<LandingContactMessage>
     */
    public function messages(array $filters): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));

        return LandingContactMessage::query()
            ->with('handledByAdmin')
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['late'] ?? false, fn (Builder $query) => $query->where('status', '!=', LandingMessageStatus::Traite->value)->where('created_at', '<', now()->subHours($this->responseHours())))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('reference', 'ilike', '%'.$search.'%')->orWhere('name', 'ilike', '%'.$search.'%')->orWhere('company', 'ilike', '%'.$search.'%')
                ->orWhere('email', 'ilike', '%'.$search.'%')->orWhere('subject', 'ilike', '%'.$search.'%')))
            ->latest();
    }

    /**
     * @return array<string, int>
     */
    public function messageCounts(): array
    {
        $counts = LandingContactMessage::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return [
            ...collect(LandingMessageStatus::cases())->mapWithKeys(fn (LandingMessageStatus $status) => [$status->value => (int) ($counts[$status->value] ?? 0)])->all(),
            'late' => $this->messages(['late' => true])->count(),
        ];
    }

    public function responseHours(): int
    {
        return (int) (Setting::query()->whereKey('landing.contact_response_hours')->value('value') ?? self::DEFAULT_RESPONSE_HOURS);
    }

    public function isLate(LandingContactMessage $message): bool
    {
        return $message->status !== LandingMessageStatus::Traite && $message->created_at->lt(now()->subHours($this->responseHours()));
    }

    /**
     * Prise en charge (« en cours ») ou traitement (« traité »), avec une note interne.
     */
    public function handle(LandingContactMessage $message, LandingMessageStatus $status, ?string $note, Admin $admin, Request $request): void
    {
        if ($message->status === LandingMessageStatus::Traite) {
            throw ValidationException::withMessages(['message' => 'Ce message est déjà traité.']);
        }

        if ($status === LandingMessageStatus::Nouveau || $status === $message->status) {
            throw ValidationException::withMessages(['status' => 'Ce message a déjà ce statut.']);
        }

        $before = $message->status->value;
        $message->update([
            'status' => $status,
            'handled_by_admin_id' => $admin->getKey(),
            'handling_note' => $note ?? $message->handling_note,
            'handled_at' => $status === LandingMessageStatus::Traite ? now() : $message->handled_at,
        ]);

        AdminAudit::record($admin, 'tickets', 'landing.message_'.$status->value, $request, $message, ['status' => $before], array_filter(['status' => $status->value, 'note' => $note]));
    }

    /**
     * @param  list<LandingApplicationStatus>  $allowed
     */
    private function ensureApplication(LandingWorkerApplication $application, array $allowed, string $refusal): void
    {
        if (! in_array($application->status, $allowed, true)) {
            throw ValidationException::withMessages(['application' => $refusal]);
        }
    }
}
