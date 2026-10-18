<?php

namespace Database\Seeders;

use App\Enums\DocumentAudience;
use App\Models\DocumentType;
use App\Models\ReasonCode;
use App\Models\Trade;
use Illuminate\Database\Seeder;

/**
 * Types de pièces attendues par profil (KYC, entreprise, chantier…) et motifs normalisés.
 */
class DocumentTypeSeeder extends Seeder
{
    /**
     * Code => [libellé, destinataire, options]. Options : required, identity, back, expiry, validity (mois),
     * trade (code métier), images (images seules), sensitive.
     *
     * Une pièce d’identité (identity) est exigée parmi celles du destinataire : aucune n’est obligatoire seule.
     *
     * @var array<string, array{0: string, 1: DocumentAudience, 2: array<string, mixed>}>
     */
    private const TYPES = [
        'worker_cni' => ['Carte nationale d’identité', DocumentAudience::Worker, ['identity' => true, 'back' => true, 'expiry' => true]],
        'worker_passport' => ['Passeport', DocumentAudience::Worker, ['identity' => true, 'expiry' => true]],
        'worker_consular_card' => ['Carte consulaire', DocumentAudience::Worker, ['identity' => true, 'back' => true, 'expiry' => true]],
        'worker_driving_license' => ['Permis de conduire', DocumentAudience::Worker, ['identity' => true, 'back' => true, 'expiry' => true]],
        'worker_residence_card' => ['Carte de résident', DocumentAudience::Worker, ['identity' => true, 'back' => true, 'expiry' => true]],
        'worker_photo' => ['Photo d’identité', DocumentAudience::Worker, ['required' => true, 'images' => true]],
        'worker_diploma' => ['Certificat ou diplôme', DocumentAudience::Worker, ['sensitive' => false]],
        'worker_work_certificate' => ['Attestation professionnelle', DocumentAudience::Worker, []],
        'worker_proof_of_address' => ['Justificatif de domicile', DocumentAudience::Worker, []],
        'worker_cv' => ['CV', DocumentAudience::Worker, []],
        'worker_electrical_clearance' => ['Habilitation électrique', DocumentAudience::Worker, ['expiry' => true, 'validity' => 36, 'trade' => 'electricien', 'sensitive' => false]],
        'worker_safety_training' => ['Attestation sécurité sur chantier (SST)', DocumentAudience::Worker, ['expiry' => true, 'validity' => 24, 'sensitive' => false]],
        'promoter_cni' => ['Pièce d’identité du promoteur', DocumentAudience::Promoter, ['required' => true, 'identity' => true, 'back' => true, 'expiry' => true]],
        'organization_rccm' => ['Registre du commerce (RCCM)', DocumentAudience::Organization, ['required' => true]],
        'organization_dfe' => ['Déclaration fiscale d’existence (DFE)', DocumentAudience::Organization, []],
        'organization_mandate' => ['Pouvoir du représentant légal', DocumentAudience::Organization, []],
        'commercial_cni' => ['Pièce d’identité du commercial', DocumentAudience::Commercial, ['required' => true, 'identity' => true, 'back' => true, 'expiry' => true]],
        'commercial_contract' => ['Contrat ou charte commerciale signée', DocumentAudience::Commercial, ['required' => true]],
        'site_building_permit' => ['Permis de construire', DocumentAudience::Site, ['sensitive' => false]],
        'site_plan' => ['Plan du chantier', DocumentAudience::Site, ['sensitive' => false]],
        'site_photo' => ['Photo du chantier', DocumentAudience::Site, ['images' => true, 'sensitive' => false]],
        'offer_quote' => ['Devis ou cahier des charges', DocumentAudience::Offer, ['sensitive' => false]],
        'mission_contract' => ['Contrat de mission', DocumentAudience::Mission, []],
        'dispute_evidence' => ['Preuve (litige)', DocumentAudience::Dispute, []],
        'incident_report' => ['Rapport d’incident', DocumentAudience::Incident, []],
    ];

    /**
     * Catégorie => [code => libellé].
     *
     * @var array<string, array<string, string>>
     */
    private const REASONS = [
        'refus_ouvrier' => [
            'tarif_insuffisant' => 'Rémunération jugée insuffisante',
            'distance' => 'Chantier trop éloigné',
            'deja_engage' => 'Déjà engagé sur un autre chantier',
            'dates_incompatibles' => 'Dates incompatibles',
            'autre' => 'Autre motif',
        ],
        'indisponibilite' => [
            'maladie' => 'Maladie',
            'raison_familiale' => 'Raison familiale',
            'deplacement' => 'Déplacement hors de la zone',
            'autre_chantier' => 'Engagé sur un autre chantier',
            'autre' => 'Autre motif',
        ],
        'suspension' => [
            'documents_invalides' => 'Documents invalides ou expirés',
            'fraude' => 'Suspicion de fraude',
            'absences_repetees' => 'Absences répétées',
            'comportement' => 'Comportement inapproprié sur chantier',
            'impaye' => 'Impayé',
            'autre' => 'Autre motif',
        ],
        'annulation' => [
            'chantier_reporte' => 'Chantier reporté',
            'budget' => 'Budget insuffisant',
            'besoin_couvert' => 'Besoin déjà couvert',
            'erreur_saisie' => 'Erreur de saisie',
            'autre' => 'Autre motif',
        ],
        'abandon_prospect' => [
            'injoignable' => 'Prospect injoignable',
            'pas_interesse' => 'Pas intéressé',
            'hors_zone' => 'Hors de la zone couverte',
            'doublon' => 'Doublon d’un ouvrier existant',
            'autre' => 'Autre motif',
        ],
        'rejet_document' => [
            'illisible' => 'Document illisible',
            'expire' => 'Document expiré',
            'non_conforme' => 'Document non conforme au type demandé',
            'incoherent' => 'Informations incohérentes avec le profil',
            'incomplet' => 'Recto ou verso manquant',
        ],
        'remplacement' => [
            'absence' => 'Absence de l’ouvrier',
            'abandon' => 'Abandon de chantier',
            'qualite' => 'Qualité du travail insuffisante',
            'demande_promoteur' => 'Demande du promoteur',
            'indisponibilite' => 'Indisponibilité déclarée',
        ],
        'litige' => [
            'paiement' => 'Désaccord sur un paiement',
            'presence' => 'Désaccord sur les jours de présence',
            'qualite' => 'Qualité des travaux',
            'materiel' => 'Matériel endommagé ou perdu',
            'comportement' => 'Comportement',
            'autre' => 'Autre motif',
        ],
    ];

    public function run(): void
    {
        $trades = Trade::query()->pluck('id', 'code');

        foreach (self::TYPES as $code => [$label, $audience, $options]) {
            DocumentType::query()->updateOrCreate(['code' => $code], [
                'label' => $label,
                'audience' => $audience,
                'trade_id' => isset($options['trade']) ? $trades[$options['trade']] : null,
                'is_required' => $options['required'] ?? false,
                'is_identity' => $options['identity'] ?? false,
                'has_back_side' => $options['back'] ?? false,
                'requires_expiry' => $options['expiry'] ?? false,
                'validity_months' => $options['validity'] ?? null,
                'allowed_mime' => ($options['images'] ?? false) ? ['image/jpeg', 'image/png'] : ['application/pdf', 'image/jpeg', 'image/png'],
                'is_sensitive' => $options['sensitive'] ?? true,
                'is_active' => true,
            ]);
        }

        foreach (self::REASONS as $category => $reasons) {
            foreach ($reasons as $code => $label) {
                ReasonCode::query()->updateOrCreate(['category' => $category, 'code' => $code], ['label' => $label, 'is_active' => true]);
            }
        }
    }
}
