<?php

namespace App\Enums;

/**
 * Valeurs de `status` pour un moyen de paiement.
 */
enum PaymentMethodStatus: string
{
    case Actif = 'actif';
    case Desactive = 'desactive';

    public function label(): string
    {
        return match ($this) {
            self::Actif => 'Actif',
            self::Desactive => 'Désactivé',
        };
    }
}
