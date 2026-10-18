<?php

namespace App\Enums;

/**
 * Valeurs de `status` pour un besoin.
 */
enum NeedStatus: string
{
    case Brouillon = 'brouillon';
    case Soumis = 'soumis';
    case EnControle = 'en_controle';
    case ACorriger = 'a_corriger';
    case Matching = 'matching';
    case ContactOuvriers = 'contact_ouvriers';
    case PropositionPromoteur = 'proposition_promoteur';
    case Selection = 'selection';
    case PaiementEnAttente = 'paiement_en_attente';
    case Confirme = 'confirme';
    case EnCours = 'en_cours';
    case Suspendu = 'suspendu';
    case Termine = 'termine';
    case Annule = 'annule';

    public function label(): string
    {
        return match ($this) {
            self::Brouillon => 'Brouillon',
            self::Soumis => 'Soumis',
            self::EnControle => 'En contrôle',
            self::ACorriger => 'À corriger',
            self::Matching => 'Matching',
            self::ContactOuvriers => 'Contact des ouvriers',
            self::PropositionPromoteur => 'Proposition promoteur',
            self::Selection => 'Sélection',
            self::PaiementEnAttente => 'Paiement en attente',
            self::Confirme => 'Confirmé',
            self::EnCours => 'En cours',
            self::Suspendu => 'Suspendu',
            self::Termine => 'Terminé',
            self::Annule => 'Annulé',
        };
    }
}
