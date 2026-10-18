<?php

namespace App\Http\Requests\V1;

use App\Enums\UserType;
use App\Models\User;
use App\Support\PhoneNumber;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Numéro national (phone_number) de la longueur attendue pour l’indicatif saisi à côté, éventuellement non encore
 * utilisé par un compte du type donné. L’indicatif lui-même est contrôlé par DialCodeRule.
 */
class PhoneRule implements DataAwareRule, ValidationRule
{
    /**
     * @var array<string, mixed>
     */
    private array $data = [];

    public function __construct(public ?UserType $uniqueFor = null, public ?string $ignoreId = null, public string $prefix = '') {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match('/^[\d\s.\-]+$/', $value)) {
            $fail('Saisissez uniquement les chiffres du numéro.');

            return;
        }

        $phone = PhoneNumber::from($this->data["{$this->prefix}dial_code"] ?? null, $value);

        if (! $phone) {
            return;
        }

        if (! $phone->isValid()) {
            $length = $phone->expectedLength();
            $fail($length ? "Saisissez un numéro à {$length} chiffres." : 'Saisissez un numéro valide.');

            return;
        }

        $existing = $this->uniqueFor ? User::findByContact($this->uniqueFor, $phone->e164()) : null;

        if ($existing && $existing->getKey() !== $this->ignoreId) {
            $fail('Ce numéro est déjà utilisé par un autre compte.');
        }
    }
}
