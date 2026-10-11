<?php

namespace App\Enums;

enum NeedUrgency: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Urgent = 'urgent';
    case Critical = 'critical';

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Faible',
            self::Normal => 'Normal',
            self::High => 'Élevé',
            self::Urgent => 'Urgent',
            self::Critical => 'Critique',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Low => 'n',
            self::Normal => 'b',
            self::High => 'a',
            self::Urgent => 'o',
            self::Critical => 'r',
        };
    }

    /**
     * Urgent ou critique : une justification est exigée.
     */
    public function isPressing(): bool
    {
        return in_array($this, [self::Urgent, self::Critical], true);
    }

    public static function fromLabel(string $label): self
    {
        return collect(self::cases())->first(fn (self $u): bool => $u->label() === $label) ?? self::Normal;
    }
}
