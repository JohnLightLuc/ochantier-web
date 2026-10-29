<?php

namespace App\Support\Auth;

use App\Models\AdminIpAllowlist;

/**
 * Restriction des connexions au Back Office par adresse IP (config auth.admin.ip_restriction) :
 * off, alert (connexion hors liste blanche signalée) ou block (refusée).
 *
 * Tant que la liste blanche ne contient aucune plage active, aucune adresse n’est bloquée ni signalée, pour ne pas
 * fermer l’accès à tous les administrateurs.
 */
class AdminIpPolicy
{
    public function mode(): string
    {
        return in_array($mode = config('auth.admin.ip_restriction'), ['off', 'alert', 'block'], true) ? $mode : 'alert';
    }

    /**
     * Adresse comprise dans une plage active de la liste blanche (ou liste vide).
     */
    public function isKnown(?string $ip): bool
    {
        $ranges = AdminIpAllowlist::query()->where('is_active', true);

        if (! $ranges->exists()) {
            return true;
        }

        return $ip !== null && filter_var($ip, FILTER_VALIDATE_IP) !== false
            && $ranges->whereRaw('?::inet <<= cidr', [$ip])->exists();
    }

    public function blocks(?string $ip): bool
    {
        return $this->mode() === 'block' && ! $this->isKnown($ip);
    }

    /**
     * Connexion à signaler : adresse inconnue en mode alerte.
     */
    public function alerts(?string $ip): bool
    {
        return $this->mode() === 'alert' && ! $this->isKnown($ip);
    }
}
