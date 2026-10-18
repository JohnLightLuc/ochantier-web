<?php

namespace App\Enums;

/**
 * Valeurs de `consent_type` pour un consentement.
 */
enum ConsentType: string
{
    case Cgu = 'cgu';
    case Privacy = 'privacy';
    case DataProcessing = 'data_processing';
    case PhotoUsage = 'photo_usage';
    case FinancialTerms = 'financial_terms';
    case Contact = 'contact';
    case Geolocation = 'geolocation';
    case ReferenceContact = 'reference_contact';

    public function label(): string
    {
        return match ($this) {
            self::Cgu => 'CGU',
            self::Privacy => 'Confidentialité',
            self::DataProcessing => 'Traitement des données',
            self::PhotoUsage => 'Utilisation des photos',
            self::FinancialTerms => 'Conditions financières',
            self::Contact => 'Contact',
            self::Geolocation => 'Géolocalisation',
            self::ReferenceContact => 'Contact des références',
        };
    }
}
