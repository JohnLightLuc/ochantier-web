<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Modules du back-office
    |--------------------------------------------------------------------------
    |
    | Ordre de la sidebar, repris de la maquette O’CHANTIER ADMIN. Le titre et
    | le sous-titre alimentent l’en-tête de page de chaque module.
    |
    */

    'modules' => [
        ['key' => 'dashboard', 'label' => 'Vue d’ensemble', 'icon' => 'home', 'path' => '/', 'title' => 'Bonjour, Administrateur', 'subtitle' => 'Voici la situation opérationnelle de la plateforme aujourd’hui'],
        ['key' => 'promoteurs', 'label' => 'Promoteurs', 'icon' => 'apartment', 'path' => '/promoteurs', 'title' => 'Gestion des promoteurs', 'subtitle' => 'Consultez, vérifiez et administrez les comptes promoteurs'],
        ['key' => 'ouvriers', 'label' => 'Ouvriers', 'icon' => 'group', 'path' => '/ouvriers', 'title' => 'Ouvriers', 'subtitle' => 'Gérez les profils, les vérifications et les disponibilités'],
        ['key' => 'commerciaux', 'label' => 'Commerciaux', 'icon' => 'trending_up', 'path' => '/commerciaux', 'title' => 'Commerciaux', 'subtitle' => 'Suivez les commerciaux terrain et leurs recrutements'],
        ['key' => 'chantiers', 'label' => 'Chantiers', 'icon' => 'construction', 'path' => '/chantiers', 'title' => 'Chantiers', 'subtitle' => 'Suivez l’avancement et les effectifs des chantiers'],
        ['key' => 'besoins', 'label' => 'Besoins', 'icon' => 'assignment', 'path' => '/besoins', 'title' => 'Besoins & offres', 'subtitle' => 'Pilotez les besoins en main-d’œuvre et les offres publiées'],
        ['key' => 'matching', 'label' => 'Matching', 'icon' => 'join_inner', 'path' => '/matching', 'title' => 'Matching intelligent', 'subtitle' => 'Identifiez les ouvriers les plus adaptés à chaque besoin'],
        ['key' => 'affectations', 'label' => 'Affectations & Offres', 'icon' => 'event_available', 'path' => '/affectations', 'title' => 'Affectations & offres', 'subtitle' => 'Validez les affectations et suivez les remplacements'],
        ['key' => 'pointages', 'label' => 'Pointages', 'icon' => 'schedule', 'path' => '/pointages', 'title' => 'Pointages', 'subtitle' => 'Contrôlez les présences et les anomalies'],
        ['key' => 'paiements', 'label' => 'Paiements', 'icon' => 'credit_card', 'path' => '/paiements', 'title' => 'Paiements', 'subtitle' => 'Autorisez et suivez les paiements et reversements'],
        ['key' => 'abonnements', 'label' => 'Abonnements', 'icon' => 'workspace_premium', 'path' => '/abonnements', 'title' => 'Abonnements', 'subtitle' => 'Gérez les formules et les abonnés des promoteurs'],
        ['key' => 'finance', 'label' => 'Finance & Reporting', 'icon' => 'monitoring', 'path' => '/finance-reporting', 'title' => 'Finance & Reporting', 'subtitle' => 'Centre financier O’CHANTIER'],
        ['key' => 'avis', 'label' => 'Avis & litiges', 'icon' => 'forum', 'path' => '/avis-litiges', 'title' => 'Avis & litiges', 'subtitle' => 'Modérez les avis et traitez les litiges'],
        ['key' => 'rapports', 'label' => 'Rapports', 'icon' => 'bar_chart', 'path' => '/rapports', 'title' => 'Rapports & analyses', 'subtitle' => 'Pilotez les performances opérationnelles et financières de la plateforme'],
        ['key' => 'parametres', 'label' => 'Paramètres', 'icon' => 'settings', 'path' => '/parametres', 'title' => 'Paramètres de la plateforme', 'subtitle' => 'Configurez les règles métier, les accès et les services O’CHANTIER'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Compteurs de la sidebar (données de démonstration)
    |--------------------------------------------------------------------------
    |
    | À remplacer par les vrais compteurs quand les modules seront branchés.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Commission O’CHANTIER sur la rémunération des ouvriers (Z, en %)
    |--------------------------------------------------------------------------
    |
    | Valeur par défaut du module Paramètres de la maquette.
    |
    */

    'worker_commission_rate' => 10,

    /*
    |--------------------------------------------------------------------------
    | Chantiers de démonstration proposés aux affectations
    |--------------------------------------------------------------------------
    |
    | Nom, promoteur, localisation. Remplacé par la table des chantiers avec le module Chantiers.
    |
    */

    'demo_sites' => [
        ['Résidence Les Oliviers', 'SICOGI', 'Cocody'], ['Tour Horizon', 'Ivoire Properties', 'Plateau'], ['Cité Belle Rive', 'SAPH', 'Bingerville'], ['Complexe scolaire SA', 'Fonds Éducation CI', 'Yamoussoukro'],
        ['Les Jardins d’Angré', 'SICOGI', 'Abidjan, Cocody Angré'], ['Cité Administrative Plateau', 'SICOGI', 'Abidjan, Plateau'], ['Logements sociaux Yopougon', 'SICOGI', 'Abidjan, Yopougon'],
        ['Résidence Bingerville Lot B', 'SICOGI', 'Bingerville'], ['Extension Cité Verte', 'SICOGI', 'Songon'], ['Tour Ivoire Business', 'SICOGI', 'Abidjan, Plateau'], ['Villa Duplex Riviera 3', 'SICOGI', 'Abidjan, Riviera'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Classement des commerciaux
    |--------------------------------------------------------------------------
    |
    | Mois en cours du classement, date de référence des données de
    | démonstration (inactivité, objectifs en cours) et pondérations du
    | score de performance sur 100.
    |
    */

    'sales_current_month' => '2026-09',

    'sales_reference_date' => '2026-09-23',

    'sales_score_weights' => [
        'validated' => ['Profils ouvriers validés', 30],
        'validation_rate' => ['Taux de validation des dossiers', 20],
        'objective' => ['Atteinte des objectifs', 20],
        'quality' => ['Qualité et complétude des dossiers', 15],
        'activity' => ['Régularité de l’activité terrain', 10],
        'delays' => ['Respect des délais de traitement', 5],
    ],

    /*
    |--------------------------------------------------------------------------
    | Chantiers
    |--------------------------------------------------------------------------
    |
    | Date de référence des données de démonstration : jours exécutés,
    | retards sur planning, jalons et calendrier des échéances.
    |
    */

    'sites_reference_date' => '2026-09-23',

    /*
    |--------------------------------------------------------------------------
    | Besoins & offres
    |--------------------------------------------------------------------------
    |
    | Date de référence des données de démonstration (expirations, délais
    | avant démarrage) et règles du module Paramètres de la maquette :
    | alertes de démarrage et d’expiration, couverture minimale, seuils
    | de paiement (R), acompte promoteur (X) et commission promoteur (Y).
    |
    */

    'needs_reference_date' => '2026-09-23',

    'need_rules' => [
        'start_alert_days' => 7,
        'minimum_coverage' => 80,
        'processing_hours' => 48,
        'reminder_days' => 3,
        'expiry_alert_days' => 3,
        'payment_threshold' => 50,
        'deposit_rate' => 30,
        'promoter_commission' => 8,
    ],

    /*
    |--------------------------------------------------------------------------
    | Moteur de Matching
    |--------------------------------------------------------------------------
    |
    | Paramètres du module Paramètres de la maquette : version de l’algorithme
    | et des pondérations (total 100 %), seuils de niveau, rayon et tolérance
    | budgétaire (prioritaire / bloquant), nombre de profils affichés,
    | ancienneté d’un résultat avant relance et période du taux de réussite.
    |
    */

    'matching' => [
        'algorithm' => 'MX-2.3',
        'weights_version' => 'PM-2026-09 · v4',
        'weights_date' => '2026-09-01',
        'weights' => [
            'metier' => ['Correspondance métier et spécialité', 25],
            'dispo' => ['Disponibilité et planning', 20],
            'exp' => ['Expérience', 15],
            'prox' => ['Proximité du chantier', 15],
            'cout' => ['Compatibilité du coût', 10],
            'qual' => ['Qualité et complétude du profil', 5],
            'note' => ['Note et fiabilité', 5],
            'hist' => ['Historique et engagements', 5],
        ],
        'thresholds' => ['Excellent match' => 85, 'Très bon match' => 75, 'Bon match' => 65, 'Match acceptable' => 55, 'Match faible' => 40],
        'radius' => 20,
        'radius_max' => 40,
        'tolerance' => 10,
        'tolerance_max' => 25,
        'limit' => 8,
        'obsolete_days' => 3,
        'identity_check' => true,
        'success_days' => 90,
    ],

    /*
    |--------------------------------------------------------------------------
    | Pointages
    |--------------------------------------------------------------------------
    |
    | Date de référence des données de démonstration et règles de pointage du
    | module Paramètres de la maquette (durées en minutes) : tolérances de
    | retard et de départ anticipé, pause exclue, arrondi, journée normale,
    | seuil et majoration des heures supplémentaires, durées validables,
    | rayon autorisé autour du chantier et preuves obligatoires.
    |
    */

    'attendance_reference_date' => '2026-09-23',

    'attendance' => [
        'late_tolerance' => 10,
        'pause' => 60,
        'pause_excluded' => true,
        'rounding' => 5,
        'normal' => 480,
        'overtime_threshold' => 30,
        'early_tolerance' => 15,
        'absence_after' => 120,
        'min_duration' => 240,
        'max_duration' => 720,
        'radius' => 150,
        'zone_tolerance' => 300,
        'precision' => 50,
        'photo' => true,
        'location' => true,
        'signature' => true,
        'night_rule' => 'Date de début du service',
        'auto_close' => '23:59',
        'transmission' => 'Après clôture de la journée',
        'overtime_rate' => 25,
        'clock_gap' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Paiements
    |--------------------------------------------------------------------------
    |
    | Règle de commission O’CHANTIER (taux Y promoteur et Z ouvrier repris de
    | need_rules et worker_commission_rate), frais des prestataires par moyen
    | de paiement (pourcentage, montant fixe), taxe, règle d’impact d’un
    | remboursement sur la commission et seuil de double validation.
    |
    */

    'payments' => [
        'rule' => [
            'name' => 'Commission standard partagée',
            'description' => 'Pourcentage du montant brut de la prestation, réparti entre promoteur (Y) et ouvrier (Z)',
            'type' => 'Pourcentage du montant brut',
            'base' => 'Montant brut (hors bonus et indemnités)',
            'min' => 0,
            'max' => 0,
            'scope' => 'Toutes prestations',
            'trade' => 'Tous métiers',
            'target' => 'Tous promoteurs',
            'from' => '2026-01-01',
            'to' => null,
            'status' => 'Active',
            'version' => 'COM-2026-v3',
        ],
        'previous_rules' => [
            ['name' => 'Commission standard partagée', 'version' => 'COM-2025-v2', 'from' => '2025-01-01', 'to' => '2025-12-31', 'status' => 'Archivée', 'type' => 'Pourcentage du montant brut'],
        ],
        'fees' => [
            'Orange Money' => [1, 0], 'MTN Mobile Money' => [1, 0], 'Moov Money' => [1, 0], 'Wave' => [1, 0],
            'Carte bancaire' => [2.5, 0], 'Virement bancaire' => [0, 500], 'Dépôt manuel rapproché' => [0, 0],
        ],
        'providers' => [
            'Orange Money' => 'Orange CI', 'MTN Mobile Money' => 'MTN CI', 'Moov Money' => 'Moov Africa', 'Wave' => 'Wave CI',
            'Carte bancaire' => 'CinetPay', 'Virement bancaire' => 'SGBCI', 'Dépôt manuel rapproché' => 'Caisse O’CHANTIER',
        ],
        'tax' => 0,
        'refund_rule' => 'Remboursement proportionnel',
        'double_validation' => 1000000,
        'fee_payer' => 'Promoteur',
    ],

    /*
    |--------------------------------------------------------------------------
    | Finance & Reporting
    |--------------------------------------------------------------------------
    |
    | Rôle financier de l’utilisateur connecté et autorisations par rôle
    | (« all » ou liste de droits), tranches de la balance âgée (créances
    | promoteurs) et de l’ancienneté des reversements, seuils d’alerte,
    | coûts variables de la marge contributive, objectifs mensuels et
    | rattachement des communes aux zones et agences.
    |
    */

    'finance' => [
        'role' => 'Direction financière',
        'roles' => [
            'Direction financière' => 'all',
            'Contrôleur de gestion' => ['vue', 'com', 'cre', 'rev', 'pro', 'ouv', 'marge', 'cout', 'rap', 'export', 'anom', 'audit', 'close', 'param'],
            'Analyste reporting' => ['vue', 'com', 'pro', 'rap', 'export', 'anom'],
            'Lecture seule' => ['vue', 'com'],
        ],
        'months' => 12,
        'last_sync' => '2026-09-23 08:45',
        'aging' => ['receivables' => [7, 15, 30, 60, 90], 'payouts' => [1, 3, 7, 15]],
        'thresholds' => ['top1' => 25, 'top5' => 60, 'coverage' => 100, 'refunds' => 3, 'payment_failures' => 8, 'payout_failures' => 5, 'fees' => 1.5, 'commission_drop' => 20],
        'sales_cost_rate' => 12,
        'direct_cost' => 150,
        'objectives' => ['volume' => 9000000, 'commissions' => 1500000, 'margin' => 1000000, 'fees' => 160000, 'refunds' => 60000, 'recovery' => 95, 'payout_delay' => 2, 'capture' => 16],
        'zones' => [
            'Cocody' => ['Abidjan Est', 'Agence Cocody'], 'Bingerville' => ['Abidjan Est', 'Agence Cocody'],
            'Plateau' => ['Abidjan Centre', 'Agence Plateau'], 'Adjamé' => ['Abidjan Centre', 'Agence Plateau'],
            'Treichville' => ['Abidjan Sud', 'Agence Marcory'], 'Marcory' => ['Abidjan Sud', 'Agence Marcory'], 'Koumassi' => ['Abidjan Sud', 'Agence Marcory'], 'Port-Bouët' => ['Abidjan Sud', 'Agence Marcory'], 'Vridi' => ['Abidjan Sud', 'Agence Marcory'],
            'Yopougon' => ['Abidjan Ouest', 'Agence Yopougon'], 'Songon' => ['Abidjan Ouest', 'Agence Yopougon'],
            'Abobo' => ['Abidjan Nord', 'Agence Abobo'], 'Anyama' => ['Abidjan Nord', 'Agence Abobo'],
            'Grand-Bassam' => ['Intérieur', 'Agence Intérieur'], 'Yamoussoukro' => ['Intérieur', 'Agence Intérieur'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Avis & litiges
    |--------------------------------------------------------------------------
    |
    | Rôle de l’utilisateur et droits par rôle, critères d’évaluation pondérés
    | (ouvrier, équipe ; total 100 %), note ajustée de classement (m, Matching),
    | seuils de satisfaction et d’avis négatif, délais d’évaluation, de
    | réclamation et de recours, SLA de chaque phase d’un litige (jours),
    | catégories, mesures financières, issues de décision et règles de
    | modération.
    |
    */

    'quality' => [
        'role' => 'Responsable qualité & litiges',
        'roles' => [
            'Responsable qualité & litiges' => 'all',
            'Gestionnaire de litiges' => ['av', 'sig', 'lit', 'create', 'qualif', 'assign', 'proof', 'notes', 'med', 'dec', 'fin', 'rempl', 'ptc', 'rec', 'close', 'export'],
            'Modérateur des avis' => ['av', 'mod', 'hide', 'restore', 'sig', 'lit', 'export'],
            'Lecture seule' => ['av', 'lit'],
        ],
        'criteria' => [
            'Ouvrier' => [['qualite', 'Qualité du travail', 20], ['ponct', 'Ponctualité', 10], ['consignes', 'Respect des consignes', 10], ['pro', 'Professionnalisme', 10], ['comm', 'Communication', 5], ['comport', 'Comportement', 10], ['secu', 'Respect des règles de sécurité', 10], ['delais', 'Respect des délais', 10], ['proprete', 'Propreté du travail', 5], ['satisf', 'Satisfaction globale', 10]],
            'Équipe' => [['qualite', 'Qualité globale', 15], ['coord', 'Coordination de l’équipe', 10], ['planning', 'Respect du planning', 10], ['livrables', 'Respect des livrables', 10], ['budget', 'Respect du budget convenu', 10], ['orga', 'Organisation', 10], ['comm', 'Communication avec le promoteur', 10], ['secu', 'Respect des règles de sécurité', 10], ['resol', 'Capacité de résolution des problèmes', 5], ['satisf', 'Satisfaction globale', 10]],
        ],
        'weights_version' => 'POND-2026-v2',
        'bayes_m' => 5,
        'bayes_matching' => true,
        'satisfaction' => 4,
        'negative' => 2.5,
        'review_window' => 30,
        'claim_window' => 60,
        'appeal_window' => 15,
        'double_approval' => 200000,
        'sla' => ['ack' => 1, 'qualif' => 2, 'rep' => 5, 'trait' => 15, 'med' => 10, 'dec' => 5, 'exec' => 7, 'rec' => 10],
        'owners' => ['Awa Bamba', 'N. Yao', 'M. Diabaté', 'A. Koné'],
        'approver' => 'Awa Bamba · Responsable qualité & litiges',
        'mediators' => ['Clarisse Ahoua · médiatrice agréée', 'Me Konan Brou · médiateur', 'Service médiation O’CHANTIER'],
        'categories' => ['Qualité du travail', 'Travail incomplet', 'Retard d’exécution', 'Abandon de chantier', 'Absence', 'Pointage contesté', 'Heures contestées', 'Comportement', 'Non-respect des consignes', 'Sécurité', 'Dommage matériel', 'Matériel perdu', 'Paiement non reçu', 'Montant incorrect', 'Retenue contestée', 'Commission contestée', 'Remboursement', 'Remplacement', 'Affectation contestée', 'Avis contesté', 'Usurpation', 'Fraude présumée', 'Document falsifié', 'Conflit contractuel', 'Autre'],
        'no_mediation' => ['Sécurité', 'Fraude présumée', 'Document falsifié', 'Usurpation'],
        'financial_types' => ['Suspension d’un paiement', 'Suspension d’un reversement', 'Libération d’un paiement', 'Remboursement total', 'Remboursement partiel', 'Retenue justifiée', 'Ajustement créditeur', 'Ajustement débiteur', 'Annulation d’une commission', 'Remboursement d’une commission'],
        'results' => ['Demande acceptée', 'Demande partiellement acceptée', 'Demande rejetée', 'Accord amiable', 'Absence de responsabilité établie', 'Nouvelle instruction nécessaire', 'Remplacement ordonné', 'Correction de pointage demandée', 'Ajustement financier demandé', 'Remboursement demandé', 'Paiement libéré', 'Paiement maintenu en suspension', 'Avis maintenu', 'Avis masqué', 'Avis annulé', 'Avertissement', 'Restriction de compte demandée'],
        'moderation_rules' => [
            ['MOD-01 · Conformité', 'Publication si l’avis est éligible et sans contenu interdit'], ['MOD-02 · Insultes, menaces, discrimination', 'Masquage puis rejet motivé'],
            ['MOD-03 · Données personnelles ou bancaires', 'Masquage et demande de correction à l’auteur'], ['MOD-04 · Pertinence', 'Rejet si sans rapport avec la prestation'],
            ['MOD-05 · Spam et duplication', 'Rejet'], ['MOD-06 · Conflit d’intérêts', 'Enquête puis décision'], ['MOD-07 · Manipulation de note, avis coordonnés', 'Masquage temporaire et enquête'],
            ['MOD-08 · Preuve falsifiée ou réutilisée', 'Enquête, rattachement possible à un litige'], ['MOD-09 · Décision humaine', 'Masquage définitif, rejet, fraude et sanction exigent une décision humaine motivée'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Couleur des statuts
    |--------------------------------------------------------------------------
    |
    | Ton de badge (g, a, o, r, b, p, n) associé aux libellés de statut des
    | données de démonstration (chantiers, offres, paiements, avis, litiges).
    |
    */

    'status_tones' => [
        'Actif' => 'g', 'À jour' => 'g', 'Vérifié' => 'g', 'Réussi' => 'g', 'Validée' => 'g', 'Ouverte' => 'g', 'Terminé' => 'g', 'Publié' => 'g', 'Résolu' => 'g', 'En cours' => 'g', 'Présent' => 'g',
        'À valider' => 'a', 'En attente' => 'a', 'À vérifier' => 'a', 'À démarrer' => 'a', 'À autoriser' => 'a', 'À modérer' => 'a', 'Moyenne' => 'a', 'Plusieurs jours' => 'a',
        'Expiré' => 'o', 'Haute' => 'r', 'Suspendu' => 'r', 'Retard' => 'r', 'Rejeté' => 'r', 'Échoué' => 'r', 'En retard' => 'r', 'Expirée' => 'r', 'Absent' => 'n',
        'Candidatures reçues' => 'b', 'En traitement' => 'b', 'En analyse' => 'b', 'Ponctuelle' => 'b', 'Contractuelle' => 'p', 'Remboursé' => 'p', 'Médiation' => 'p',
        'Manquant' => 'n', 'Brouillon' => 'n', 'Annulée' => 'n', 'Masqué' => 'n', 'Normale' => 'n',
    ],

];
