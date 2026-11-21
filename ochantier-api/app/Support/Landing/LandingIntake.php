<?php

namespace App\Support\Landing;

use App\Enums\LandingApplicationStatus;
use App\Enums\LandingMessageStatus;
use App\Models\Commune;
use App\Models\LandingContactMessage;
use App\Models\LandingWorkerApplication;
use App\Models\NewsletterSubscriber;
use App\Models\Trade;
use App\Support\Media\MediaStore;
use App\Support\PhoneNumber;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Formulaires du site public transmis à l’API : candidatures « Devenir ouvrier » (pièce d’identité et photo
 * gardées en fichiers sensibles), messages de contact et inscriptions à la lettre d’information.
 */
class LandingIntake
{
    public function __construct(private MediaStore $media) {}

    /**
     * @param  array<string, mixed>  $data  Champs validés (WorkerApplicationRequest).
     */
    public function application(array $data, UploadedFile $identity, ?UploadedFile $photo): LandingWorkerApplication
    {
        return DB::transaction(function () use ($data, $identity, $photo): LandingWorkerApplication {
            $application = new LandingWorkerApplication([
                'reference' => sprintf('CAND-%06d', DB::scalar("SELECT nextval('landing_application_reference_seq')")),
                'first_name' => trim($data['first_name']),
                'last_name' => trim($data['last_name']),
                'phone' => trim($data['phone']),
                'phone_digits' => substr(PhoneNumber::digits($data['phone']), -10),
                'email' => $data['email'] ?? null,
                'birth_date' => $data['birth_date'] ?? null,
                'gender' => $data['gender'] ?? null,
                'trade_label' => trim($data['trade']),
                'trade_id' => Trade::query()->where('is_active', true)->where(fn ($query) => $query->whereRaw('lower(label) = lower(?)', [trim($data['trade'])])->orWhere('code', mb_strtolower(trim($data['trade']))))->value('id'),
                'daily_rate' => $data['daily_rate'] ?? null,
                'experience_years' => $data['experience_years'],
                'level' => $data['level'],
                'skills' => array_values($data['skills'] ?? []),
                'certifications' => array_values($data['certifications'] ?? []),
                'bio' => $data['bio'] ?? null,
                'commune_label' => trim($data['commune']),
                'commune_id' => Commune::query()->where('is_active', true)->whereRaw('lower(name) = lower(?)', [trim($data['commune'])])->value('id'),
                'neighborhood' => $data['neighborhood'] ?? null,
                'mobility' => $data['mobility'],
                'availability' => $data['availability'],
                'available_days' => array_values($data['available_days'] ?? []),
                'payout_method' => $data['payout_method'],
                'payout_number' => $data['payout_number'] ?? null,
                'target_site' => $data['target_site'] ?? null,
                'target_site_commune' => $data['target_site_commune'] ?? null,
                'status' => LandingApplicationStatus::Nouvelle,
                'consented_at' => now(),
            ]);
            // Les fichiers sont rattachés à la candidature : elle existe avant eux, sans pièce, le temps de la transaction.
            $application->id = (string) Str::uuid7();
            $application->identity_media_id = $this->media->store($identity, 'landing/identite', $application)->getKey();
            $application->photo_media_id = $photo ? $this->media->store($photo, 'landing/photo', $application)->getKey() : null;
            $application->save();

            return $application;
        });
    }

    /**
     * @param  array<string, mixed>  $data  Champs validés (ContactMessageRequest).
     */
    public function message(array $data): LandingContactMessage
    {
        return LandingContactMessage::query()->create([
            'reference' => sprintf('MSG-%06d', DB::scalar("SELECT nextval('landing_message_reference_seq')")),
            'profile' => $data['profile'],
            'name' => trim($data['name']),
            'company' => $data['company'] ?? null,
            'phone' => trim($data['phone']),
            'email' => mb_strtolower(trim($data['email'])),
            'subject' => trim($data['subject']),
            'message' => trim($data['message']),
            'status' => LandingMessageStatus::Nouveau,
            'consented_at' => now(),
        ]);
    }

    /**
     * Inscription (ou réinscription) à la lettre d’information.
     */
    public function subscribe(string $email): NewsletterSubscriber
    {
        $subscriber = NewsletterSubscriber::query()->firstOrNew(['email' => mb_strtolower(trim($email))]);

        if (! $subscriber->exists || $subscriber->unsubscribed_at !== null) {
            $subscriber->fill(['subscribed_at' => now(), 'unsubscribed_at' => null])->save();
        }

        return $subscriber;
    }
}
