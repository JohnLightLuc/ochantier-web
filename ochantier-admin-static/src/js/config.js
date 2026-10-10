'use strict';
// Propriétés de démonstration de l’application (valeurs par défaut = « Normal »).
// Modifiez `default` pour simuler un état : chargement, erreur, aucune donnée…
OCH.rootProps = {
  "etatDemo": {
    "editor": "enum",
    "options": [
      "Normal",
      "Chargement initial",
      "Aucune donnée",
      "Erreur partielle",
      "Erreur globale",
      "Accès limité",
      "Connexion perdue"
    ],
    "default": "Normal",
    "tsType": "string",
    "section": "Démonstration · Tableau de bord"
  },
  "erreurEnregistrement": {
    "editor": "boolean",
    "default": false,
    "tsType": "boolean",
    "section": "Démonstration · Paramètres"
  },
  "etatOuvriers": {
    "editor": "enum",
    "options": [
      "Normal",
      "Aucune donnée",
      "Erreur de chargement"
    ],
    "default": "Normal",
    "tsType": "string",
    "section": "Démonstration · Ouvriers"
  },
  "erreurActionsOuvriers": {
    "editor": "boolean",
    "default": false,
    "tsType": "boolean",
    "section": "Démonstration · Ouvriers"
  },
  "etatCommerciaux": {
    "editor": "enum",
    "options": [
      "Normal",
      "Aucune donnée",
      "Erreur de chargement"
    ],
    "default": "Normal",
    "tsType": "string",
    "section": "Démonstration · Commerciaux"
  },
  "erreurActionsCommerciaux": {
    "editor": "boolean",
    "default": false,
    "tsType": "boolean",
    "section": "Démonstration · Commerciaux"
  },
  "etatChantiers": {
    "editor": "enum",
    "options": [
      "Normal",
      "Aucune donnée",
      "Erreur de chargement"
    ],
    "default": "Normal",
    "tsType": "string",
    "section": "Démonstration · Chantiers"
  },
  "etatBesoins": {
    "editor": "enum",
    "options": [
      "Normal",
      "Aucune donnée",
      "Erreur de chargement"
    ],
    "default": "Normal",
    "tsType": "string",
    "section": "Démonstration · Besoins & offres"
  },
  "erreurActionsBesoins": {
    "editor": "boolean",
    "default": false,
    "tsType": "boolean",
    "section": "Démonstration · Besoins & offres"
  },
  "etatPaiements": {
    "editor": "enum",
    "options": [
      "Normal",
      "Chargement",
      "Aucune opération",
      "Erreur de chargement",
      "Accès non autorisé"
    ],
    "default": "Normal",
    "tsType": "string",
    "section": "Démonstration · Paiements"
  },
  "rolePaiements": {
    "editor": "enum",
    "options": [
      "Administrateur finance",
      "Agent paiements",
      "Lecture seule"
    ],
    "default": "Administrateur finance",
    "tsType": "string",
    "section": "Démonstration · Paiements"
  },
  "etatPointages": {
    "editor": "enum",
    "options": [
      "Normal",
      "Chargement",
      "Aucune donnée",
      "Erreur de chargement",
      "Accès non autorisé"
    ],
    "default": "Normal",
    "tsType": "string",
    "section": "Démonstration · Pointages"
  },
  "etatMatching": {
    "editor": "enum",
    "options": [
      "Normal",
      "Chargement",
      "Aucun besoin",
      "Erreur de chargement",
      "Configuration manquante",
      "Accès non autorisé"
    ],
    "default": "Normal",
    "tsType": "string",
    "section": "Démonstration · Matching"
  },
  "roleMatching": {
    "editor": "enum",
    "options": [
      "Administrateur",
      "Chargé de matching",
      "Lecture seule"
    ],
    "default": "Administrateur",
    "tsType": "string",
    "section": "Démonstration · Matching"
  },
  "erreurMatching": {
    "editor": "boolean",
    "default": false,
    "tsType": "boolean",
    "section": "Démonstration · Matching"
  },
  "etatFinance": {
    "editor": "enum",
    "options": [
      "Normal",
      "Chargement",
      "Synchronisation en cours",
      "Retard de synchronisation",
      "Données partielles",
      "Échec de synchronisation",
      "Données incohérentes",
      "Aucune donnée",
      "Erreur de chargement",
      "Accès non autorisé"
    ],
    "default": "Normal",
    "tsType": "string",
    "section": "Finance & Reporting"
  },
  "roleFinance": {
    "editor": "enum",
    "options": [
      "Direction financière",
      "Contrôleur de gestion",
      "Analyste reporting",
      "Lecture seule"
    ],
    "default": "Direction financière",
    "tsType": "string",
    "section": "Finance & Reporting"
  },
  "etatAvis": {
    "editor": "enum",
    "options": [
      "Normal",
      "Chargement",
      "Aucune donnée",
      "Erreur de chargement",
      "Erreur d’action",
      "Accès non autorisé"
    ],
    "default": "Normal",
    "tsType": "string",
    "section": "Avis & Litiges"
  },
  "roleAvis": {
    "editor": "enum",
    "options": [
      "Responsable qualité & litiges",
      "Gestionnaire de litiges",
      "Modérateur des avis",
      "Lecture seule"
    ],
    "default": "Responsable qualité & litiges",
    "tsType": "string",
    "section": "Avis & Litiges"
  }
};
