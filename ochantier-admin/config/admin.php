<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Modules du back-office
    |--------------------------------------------------------------------------
    |
    | Ordre de la barre latérale. Le titre et le sous-titre alimentent l’en-tête
    | de page de chaque module.
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

];
