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

    'demo_badges' => [
        'affectations' => 18,
        'pointages' => 4,
        'paiements' => 7,
        'abonnements' => 2,
        'avis' => 5,
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
