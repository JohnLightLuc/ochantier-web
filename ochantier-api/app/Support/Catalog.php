<?php

namespace App\Support;

use App\Models\Site;

/**
 * Listes de référence partagées avec le Back-office (formulaires Ouvrier, Chantier et Besoin) : à garder
 * synchronisées avec App\Http\Requests\WorkerRequest, SiteRequest et NeedRequest côté admin.
 */
class Catalog
{
    public const TRADES = ['Maçon', 'Électricien', 'Plombier', 'Peintre', 'Menuisier', 'Carreleur', 'Manœuvre', 'Coffreur', 'Ferrailleur', 'Soudeur'];

    public const COMMUNES = ['Abobo', 'Adjamé', 'Attécoubé', 'Cocody', 'Koumassi', 'Marcory', 'Plateau', 'Port-Bouët', 'Treichville', 'Yopougon', 'Bingerville', 'Songon', 'Anyama', 'Grand-Bassam', 'Bouaké'];

    public const NATIONALITIES = ['Ivoirienne', 'Burkinabè', 'Malienne', 'Guinéenne', 'Ghanéenne', 'Togolaise', 'Béninoise', 'Autre'];

    public const QUALIFICATIONS = ['Ouvrier spécialisé', 'Ouvrier qualifié', 'Ouvrier hautement qualifié', 'Chef d’équipe'];

    public const MOBILITIES = ['Commune et communes limitrophes', 'Abidjan et environs (50 km)', 'Tout le district d’Abidjan', 'Toute la Côte d’Ivoire'];

    public const ID_TYPES = ['Carte nationale d’identité', 'Passeport', 'Carte consulaire', 'Attestation d’identité'];

    public const CHANNELS = ['Terrain', 'Recommandation', 'Application mobile', 'Agence', 'Réseaux sociaux'];

    public const SITE_TYPES = ['Maison individuelle', 'Immeuble', 'Résidence', 'Bureau', 'Commerce', 'Entrepôt', 'Usine', 'Infrastructure publique', 'Réhabilitation', 'Rénovation', 'Autre'];

    public const NEED_TYPES = ['Besoin journalier', 'Besoin hebdomadaire', 'Besoin contractuel', 'Besoin ponctuel', 'Besoin récurrent', 'Besoin urgent', 'Besoin de remplacement', 'Renfort d’équipe'];

    public const EXPERIENCES = ['Débutant', 'Junior', 'Intermédiaire', 'Expérimenté', 'Expert', 'Chef d’équipe'];

    public const PAY_TYPES = ['Journalier', 'Hebdomadaire', 'Forfaitaire', 'Contractuel', 'Par étape', 'Par livrable', 'À négocier'];

    public const TRADE_GROUPS = ['Gros œuvre', 'Structure', 'Second œuvre', 'Réseaux', 'Électricité', 'Menuiserie', 'Métallerie', 'Finitions'];

    public const TRADE_DETAILS = [
        'Maçon' => ['specialties' => ['Maçonnerie traditionnelle', 'Élévation de murs', 'Chapes et enduits', 'Maçonnerie générale', 'Chaînages'], 'skills' => ['Lecture de plans', 'Dosage béton', 'Pose de parpaings', 'Coffrage simple'], 'certifications' => ['CAP Maçonnerie', 'Sécurité sur chantier SST']],
        'Électricien' => ['specialties' => ['Courant faible', 'Courant fort', 'Tableaux électriques'], 'skills' => ['Câblage', 'Mise à la terre', 'Lecture de schémas', 'Norme NF C 15-100'], 'certifications' => ['BT Électrotechnique', 'Habilitation électrique B1V']],
        'Plombier' => ['specialties' => ['Sanitaires', 'Réseaux PVC'], 'skills' => ['Soudure cuivre', 'Pose de sanitaires', 'Évacuations', 'Recherche de fuites'], 'certifications' => ['CAP Plomberie']],
        'Peintre' => ['specialties' => ['Peinture intérieure', 'Peinture extérieure', 'Peinture décorative', 'Façades'], 'skills' => ['Préparation des supports', 'Enduits de lissage', 'Peinture au pistolet'], 'certifications' => ['Attestation de formation peinture']],
        'Menuisier' => ['specialties' => ['Menuiserie bois', 'Pose de portes'], 'skills' => ['Découpe', 'Assemblage', 'Pose de menuiseries', 'Finitions'], 'certifications' => ['CAP Menuiserie']],
        'Carreleur' => ['specialties' => ['Carrelage sol', 'Faïence murale'], 'skills' => ['Calepinage', 'Pose collée', 'Joints', 'Découpe à la meuleuse'], 'certifications' => ['Attestation carrelage']],
        'Manœuvre' => ['specialties' => ['Manutention', 'Préparation', 'Préparation de chantier'], 'skills' => ['Gâchage', 'Nettoyage de chantier', 'Aide au coffrage'], 'certifications' => []],
        'Coffreur' => ['specialties' => ['Coffrage bois', 'Coffrage métallique'], 'skills' => ['Lecture de plans', 'Étaiement', 'Décoffrage', 'Traçage'], 'certifications' => ['CQP Coffreur-bancheur']],
        'Ferrailleur' => ['specialties' => ['Armatures', 'Façonnage'], 'skills' => ['Lecture de plans de ferraillage', 'Ligature', 'Découpe et pliage'], 'certifications' => ['Attestation ferraillage']],
        'Soudeur' => ['specialties' => ['Soudure à l’arc', 'Charpente métallique'], 'skills' => ['Soudure MIG', 'Meulage', 'Lecture de plans'], 'certifications' => []],
    ];

    /**
     * Métiers actifs : catalogue publié dans Paramètres › Métiers, sinon catalogue par défaut.
     *
     * @return array<int, string>
     */
    public static function trades(): array
    {
        return Settings::activeTrades();
    }

    /**
     * Communes où un chantier peut être localisé.
     *
     * @return array<int, string>
     */
    public static function siteCommunes(): array
    {
        return array_keys(Site::COORDINATES);
    }
}
