<?php

namespace App\Enums;

/**
 * Valeurs de `source` pour la table `payment_transaction_status_histories`.
 */
enum TransactionStatusSource: string
{
    case Api = 'api';
    case Webhook = 'webhook';
    case Rapprochement = 'rapprochement';
    case Admin = 'admin';
    case Systeme = 'systeme';

    public function label(): string
    {
        return match ($this) {
            self::Api => 'API',
            self::Webhook => 'Webhook',
            self::Rapprochement => 'Rapprochement',
            self::Admin => 'Administrateur',
            self::Systeme => 'Système',
        };
    }
}
