<?php

namespace App\Enums;

/**
 * Valeurs de `status` pour la table `worker_duplicate_candidates`.
 */
enum DuplicateCandidateStatus: string
{
    case AVerifier = 'a_verifier';
    case Confirme = 'confirme';
    case FauxPositif = 'faux_positif';
    case Fusionne = 'fusionne';

    public function label(): string
    {
        return match ($this) {
            self::AVerifier => 'À vérifier',
            self::Confirme => 'Confirmé',
            self::FauxPositif => 'Faux positif',
            self::Fusionne => 'Fusionné',
        };
    }
}
