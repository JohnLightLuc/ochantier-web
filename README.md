# Mise à jour — Modules O’CHANTIER PRO

Ajoute au Back Office O’CHANTIER trois modules issus de la « Documentation O’CHANTIER PRO » : **Entrepreneurs / Techniciens**, **Demandes de devis** et **Offres contractuelles**, avec la commission de service O’CHANTIER propre à chaque type d’opportunité, collectée puis reversée à O’CHANTIER.

Prérequis : le dépôt contient la version **avec le module Landing Page** (mise à jour précédente `landing-page-update`).

## Contenu
- `Back Office OChantier.dc.html` — fichier principal complet, à jour (remplace celui du dépôt).
- `ProSection.dc.html` — **nouveau** composant partagé (sections des fiches PRO : clés/valeurs, listes, tableaux, détail financier, historique).
- `CLAUDE.md` — notes projet mises à jour (section O’CHANTIER PRO).
- `o-chantier-pro.patch` — les mêmes modifications en diff unifié (alternative au remplacement).

Fichiers inchangés : Avatar, Button, StatCard, StateBlock, StatusBadge, ochantier-tokens.css, image-slot.js, support.js.

## Appliquer
Option A — remplacer / ajouter les fichiers :
```bash
cp "Back Office OChantier.dc.html" ProSection.dc.html CLAUDE.md /chemin/du/depot/
cd /chemin/du/depot
git add "Back Office OChantier.dc.html" ProSection.dc.html CLAUDE.md
git commit -m "feat(pro): Entrepreneurs/Techniciens, Demandes de devis, Offres contractuelles + commission de service"
git push
```

Option B — appliquer le patch (dépôt à la version Landing Page) :
```bash
cd /chemin/du/depot
git apply --check /chemin/o-chantier-pro.patch && git apply /chemin/o-chantier-pro.patch
```

## Changements

### Navigation
- Menu latéral : « Entrepreneurs / Techniciens », « Demandes de devis », « Offres contractuelles » après Chantiers, avec badges (dossiers à vérifier, visites à payer / devis acceptés, acomptes en attente).
- Routes : `#/entrepreneurs[/:id]`, `#/demandes-devis[/:id]`, `#/offres-contractuelles[/:id]` (`prRoute` / `prOpen`, retour navigateur dans `syncHash`).

### Commission de service
- Chaque offre émise par un professionnel est majorée d’une commission : total promoteur = montant du professionnel + commission ; le professionnel perçoit son montant net.
- **Un taux par type d’opportunité** : demandes de devis (frais de visite, devis) et offres contractuelles (réponses). 10 % par défaut chacun.
- Taux figé sur chaque offre au moment de son émission.
- Collecte à chaque encaissement (visite, acompte), statut À collecter → Collectée → Reversée ; onglet « Commissions » avec reversement unitaire ou groupé vers O’CHANTIER.
- Règles (taux devis, taux offres, nombre d’offres comparées, validité d’une demande) modifiables **uniquement** depuis la page Entrepreneurs / Techniciens (« Règles & commissions »).
- L’acompte d’attribution n’est plus un réglage PRO : il reprend le taux d’acompte X de Paramètres.
- Aucune lettre « R » affichée dans l’interface (libellé « commission de service »).

### Entrepreneurs / Techniciens
- Liste filtrable (type, métier, zone, formule) et onglets (À vérifier, Vérifiés, Rejetés & suspendus, Brouillons).
- Fiche : parcours d’inscription KYB (entreprise) / KYC (indépendant), identité, formule, réputation, réponses aux opportunités, commissions générées, historique.
- **Visionneuse de pièces** : aperçu, zoom, rotation, navigation entre pièces, téléchargement ; actions **Confirmer**, **Demander un complément** (SMS, statut « Complément demandé »), **Rejeter** (motif).
- Actions dossier : valider (toutes pièces confirmées), rejeter, suspendre, réactiver, rouvrir, relancer.

### Demandes de devis
- Propositions de visite (comparaison), paiement de la visite (Mobile Money, frais opérateur), mission de visite (report, empêchement, reprogrammation), devis versionnés (correction = nouvelle version).
- **Devis consultable et téléchargeable en PDF** (aperçu A4, impression « Enregistrer au format PDF »), pour chaque version.
- **Un devis accepté ne vaut pas attribution** : le promoteur publie ensuite une offre contractuelle liée, ouverte à tous ; l’auteur du devis y est prioritaire. Plus d’acompte côté demande de devis.

### Offres contractuelles
- Réponses conformes ou contre-propositions, contrôle d’échéancier, comparatif côte à côte, réponse retenue, acompte puis chantier attribué.
- Offre issue d’un devis : auteur du devis **prioritaire** (notifié en premier, en tête des réponses, badge dans le comparatif, relance dédiée) ; le promoteur reste libre de retenir un autre professionnel (avertissement à la sélection).
- Actions du promoteur simulées (« Simulation · … »).

### Vue d’ensemble (tableau de bord)
- Nouveau bloc « O’CHANTIER PRO » : professionnels vérifiés, demandes de devis et offres contractuelles en cours, commissions de service (à collecter / à reverser / reversées).
- Priorités : dossiers PRO à vérifier, paiements PRO en attente, commissions à reverser ; total « à traiter » calculé.

## Notes
- Données de démonstration seedées par `prData` (13 professionnels, 10 demandes, 8 offres).
- Le fichier principal est volumineux (~2,4 Mo) : le premier chargement peut prendre 30 à 45 s ; recharger si la page reste sur les gabarits bruts.
