<?php

namespace App\Support\Promoter;

use App\Enums\ReferralStatus;
use App\Enums\UserType;
use App\Models\PromoterProfile;
use App\Models\Referral;
use App\Models\User;
use App\Support\PhoneNumber;
use App\Support\Sms\SmsGateway;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * « Inviter un ami » : code parrain et lien du compte, invitations par SMS, et suivi des proches inscrits (par le code
 * saisi à l’inscription, ou parce que leur numéro avait été invité). Pas de récompense.
 */
class PromoterReferrals
{
    public function __construct(private SmsGateway $sms) {}

    /**
     * Code parrain du compte, créé au premier besoin : 4 lettres du prénom (ou de l’entreprise) et l’année.
     */
    public function code(PromoterProfile $profile): string
    {
        if ($profile->referral_code) {
            return $profile->referral_code;
        }

        $owner = $profile->user;
        $letters = Str::of($owner->first_name ?: ($owner->ownedOrganizations()->value('legal_name') ?: 'OC'))->ascii()->upper()->replaceMatches('/[^A-Z]/', '')->substr(0, 4)->toString() ?: 'OC';
        $code = $letters.now()->format('Y');

        while (PromoterProfile::query()->where('referral_code', $code)->exists()) {
            $code = $letters.now()->format('Y').random_int(10, 99);
        }

        $profile->update(['referral_code' => $code]);

        return $code;
    }

    public function link(PromoterProfile $profile): string
    {
        return rtrim((string) config('services.referrals.url'), '/').'/'.$this->code($profile);
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(PromoterProfile $profile): array
    {
        $referrals = $profile->referrals()->with('referredUser')->latest('created_at')->get();
        $registered = $referrals->where('status', ReferralStatus::Inscrite)->count();
        $link = $this->link($profile);

        return [
            'code' => $this->code($profile),
            'link' => $link,
            'share_message' => 'Rejoins-moi sur O’CHANTIER pour trouver des ouvriers vérifiés pour tes chantiers : '.$link,
            'stats' => [
                'sent' => $referrals->count(),
                'registered' => $registered,
                'label' => $referrals->count().' envoyée'.($referrals->count() > 1 ? 's' : '').' · '.$registered.' inscrit(e)'.($registered > 1 ? 's' : ''),
            ],
            'invitations' => $referrals->map(fn (Referral $referral) => $this->row($referral))->values()->all(),
        ];
    }

    /**
     * « Inviter par SMS » ; une invitation déjà envoyée au même numéro est renvoyée.
     */
    public function invite(PromoterProfile $profile, PhoneNumber $phone, ?string $name = null): Referral
    {
        if (User::query()->where('user_type', UserType::Promoter)->where('phone', $phone->e164())->exists()) {
            throw ValidationException::withMessages(['phone_number' => 'Ce numéro a déjà un compte O’CHANTIER.']);
        }

        $referral = $profile->referrals()->updateOrCreate(['invited_phone' => $phone->e164()], [
            'channel' => 'sms',
            'invited_name' => $name ? trim($name) : null,
            'status' => ReferralStatus::Envoyee,
            'sent_at' => now(),
        ]);

        $owner = $profile->user;
        $from = trim($owner->first_name.' '.$owner->last_name) ?: 'Un proche';
        $this->sms->send($phone->e164(), 'O’CHANTIER : '.$from.' vous invite à trouver des ouvriers vérifiés pour vos chantiers. Téléchargez l’application : '.$this->link($profile));

        return $referral;
    }

    /**
     * Inscription d’un nouveau promoteur : rattache son parrain (code saisi, sinon numéro invité) et passe
     * l’invitation à « Inscrit ».
     */
    public function registered(User $user): ?Referral
    {
        $profile = $user->promoterProfile()->first();
        $phone = PhoneNumber::of($user)?->e164();
        $referrer = $profile?->referred_by_profile_id
            ? PromoterProfile::query()->find($profile->referred_by_profile_id)
            : ($phone ? Referral::query()->where('invited_phone', $phone)->where('status', ReferralStatus::Envoyee)->latest('sent_at')->first()?->referrerProfile : null);

        if (! $profile || ! $referrer || $referrer->is($profile)) {
            return null;
        }

        $profile->update(['referred_by_profile_id' => $referrer->getKey()]);
        $referral = ($phone ? $referrer->referrals()->where('invited_phone', $phone)->first() : null)
            ?? $referrer->referrals()->make(['channel' => 'lien', 'invited_phone' => $phone]);
        $referral->fill([
            'invited_name' => trim($user->first_name.' '.$user->last_name) ?: $referral->invited_name,
            'referred_user_id' => $user->getKey(),
            'status' => ReferralStatus::Inscrite,
            'registered_at' => now(),
        ])->save();

        app(PromoterNotifier::class)->referralRegistered($referral->load(['referrerProfile', 'referredUser']));

        return $referral;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Referral $referral): array
    {
        $user = $referral->referredUser;
        $name = $user ? trim($user->first_name.' '.$user->last_name) : null;
        $name = $name ?: $referral->invited_name ?: $referral->invited_phone ?: 'Invité';
        $at = $referral->registered_at ?? $referral->sent_at ?? $referral->created_at;

        return [
            'id' => $referral->id,
            'name' => $name,
            'initials' => mb_strtoupper(collect(explode(' ', (string) preg_replace('/[^\p{L} ]/u', '', $name)))->filter()->take(2)->map(fn (string $part) => mb_substr($part, 0, 1))->implode('')) ?: '+',
            'via' => ($referral->channel === 'sms' ? 'SMS' : 'Lien').' · '.$at->locale('fr')->translatedFormat('j M Y'),
            'status' => ['value' => $referral->status->value, 'label' => $referral->status->label()],
        ];
    }
}
