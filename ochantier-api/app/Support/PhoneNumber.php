<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Numéro de téléphone saisi en deux champs : indicatif pays (dial_code, « 225 ») et numéro national
 * (phone_number, « 0708091011 »). En base, la colonne E.164 (« +2250708091011 ») en est déduite.
 */
final class PhoneNumber
{
    /**
     * Longueur du numéro national par indicatif ; à défaut, de 4 chiffres jusqu’aux 15 chiffres du format E.164.
     *
     * @var array<string, int>
     */
    public const LENGTHS = ['225' => 10];

    public function __construct(public readonly string $dialCode, public readonly string $number) {}

    /**
     * Numéro saisi, sans « + » ni « 00 » devant l’indicatif ni séparateurs ; null si l’un des deux champs manque.
     */
    public static function from(mixed $dialCode, mixed $number): ?self
    {
        $dialCode = self::digits($dialCode);
        $number = self::digits($number);

        return $dialCode !== '' && $number !== '' ? new self(ltrim($dialCode, '0') ?: '0', $number) : null;
    }

    /**
     * Numéro enregistré sur un modèle (colonnes <prefix>dial_code et <prefix>phone_number).
     */
    public static function of(Model $model, string $prefix = ''): ?self
    {
        return self::from($model->getAttribute("{$prefix}dial_code"), $model->getAttribute("{$prefix}phone_number"));
    }

    /**
     * Colonnes à enregistrer, vides si le numéro est retiré.
     *
     * @return array<string, string|null>
     */
    public static function attributes(?self $phone, string $prefix = ''): array
    {
        return ["{$prefix}dial_code" => $phone?->dialCode, "{$prefix}phone_number" => $phone?->number];
    }

    /**
     * Chiffres seuls (espaces, points, tirets et « + » retirés).
     */
    public static function digits(mixed $value): string
    {
        return is_scalar($value) ? preg_replace('/\D/', '', (string) $value) : '';
    }

    /**
     * Longueur attendue du numéro national pour cet indicatif, ou null si elle n’est pas connue.
     */
    public function expectedLength(): ?int
    {
        return self::LENGTHS[$this->dialCode] ?? null;
    }

    public function isValid(): bool
    {
        $length = strlen($this->number);

        return strlen($this->dialCode) <= 4 && ($this->expectedLength() ?? $length) === $length
            && $length >= 4 && strlen($this->dialCode) + $length <= 15;
    }

    /**
     * Format E.164 enregistré en base et utilisé pour l’envoi des SMS : « +2250708091011 ».
     */
    public function e164(): string
    {
        return "+{$this->dialCode}{$this->number}";
    }

    /**
     * Format affiché : « +225 07 08 09 10 11 ».
     */
    public function formatted(): string
    {
        return "+{$this->dialCode} ".implode(' ', str_split($this->number, 2));
    }

    /**
     * Numéro masqué pour les messages : « +225 07 •• •• •• 11 ».
     */
    public function masked(): string
    {
        $hidden = implode(' ', array_fill(0, max(1, intdiv(strlen($this->number) - 3, 2)), '••'));

        return "+{$this->dialCode} ".substr($this->number, 0, 2)." {$hidden} ".substr($this->number, -2);
    }

    public function equals(?self $other): bool
    {
        return $other !== null && $other->e164() === $this->e164();
    }

    /**
     * Représentation renvoyée par l’API.
     *
     * @return array{dial_code: string, phone_number: string, phone: string}
     */
    public function toArray(): array
    {
        return ['dial_code' => $this->dialCode, 'phone_number' => $this->number, 'phone' => $this->formatted()];
    }
}
