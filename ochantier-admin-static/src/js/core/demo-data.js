'use strict';
// Jeux de données de démonstration (tableau de bord, listes génériques)
// Couche de la classe Component : voir src/js/och.js (OCH.extend).
OCH.extend(Base => class extends Base {
  build() {
    const t = (v, o = {}) => ({ k: 'txt', v, ...o });
    const per = (name, sub, kind) => ({ k: 'per', name, sub, kind });
    const bd = (v, tn, icon) => ({ k: 'bdg', v, tn, icon });
    const loc = v => ({ k: 'loc', v });
    const pr = v => ({ k: 'prog', v });
    const rt = (v, text) => ({ k: 'rate', v, text });
    const st = (v, sub, o = {}) => ({ k: 'stk', v, sub, ...o });
    const th = v => ({ k: 'thumb', v });
    const tm = (v, ok, note) => ({ k: 'time', v, ok, note });
    const pf = (a, b) => ({ k: 'proof', a, b });
    const K = (label, value, icon, tn, delta, dtn, x = {}) => ({ label, value, icon, tn, delta, dtn, ...x });
    const B = (n, label, icon, tn) => ({ n, label, icon, tn });
    const ex = { label: 'Exporter', icon: 'download' };
    const V = 'check_circle', W = 'schedule', HG = 'hourglass_top', PL = 'play_circle';
    const S = {
      promoteurs: {
        t: 'Gestion des promoteurs', s: 'Consultez, vérifiez et administrez les comptes promoteurs',
        a: [{ label: 'Exporter', icon: 'download', go: () => this.exportCsv() }, { label: 'Ajouter un promoteur', icon: 'add', primary: 1, go: () => this.route('/promoteurs/nouveau') }],
        k: [K('Total promoteurs', '248', 'apartment', 'g', '+12 %'), K('Comptes actifs', '219', 'groups', 'g', '+8 %'), K('À valider', '17', 'warning', 'a', '+21 %', 'a', { warn: 1 }), K('Suspendus', '12', 'person_off', 'r', '+9 %', 'r')],
        lt: 'Liste des promoteurs', li: 'apartment', total: '248', unit: 'promoteurs', ph: 'Rechercher par nom, entreprise, téléphone ou e-mail',
        f: ['Statut', 'Localisation', 'Type de compte'],
        cols: ['Promoteur', 'Entreprise', 'Contact', 'Localisation', 'Chantiers', 'Paiements', 'Statut'],
        rows: [
          [per("Koffi N'Guessan"), t('Ivoire Construction'), t('+225 07 08 25 14 63'), loc('Abidjan, Cocody'), t('8 chantiers', { link: 1 }), bd('À jour', 'g'), bd('Actif', 'g')],
          [per('Mariam Koné'), t('MK Immobilier'), t('+225 05 66 42 18 90'), loc('Abidjan, Plateau'), t('5 chantiers', { link: 1 }), bd('À jour', 'g'), bd('Actif', 'g')],
          [per('Yao Kouassi'), t('Horizon BTP'), t('+225 01 74 28 10 55'), loc('Bingerville'), t('3 chantiers', { link: 1 }), bd('En attente', 'a'), bd('À valider', 'a')],
          [per('Fatou Diarra'), t('Résidences Plus'), t('+225 07 57 91 33 20'), loc('Yamoussoukro'), t('6 chantiers', { link: 1 }), bd('À jour', 'g'), bd('Actif', 'g')],
          [per('Serge Aka'), t('Bâtir Afrique'), t('+225 05 04 88 71 19'), loc('Bouaké'), t('2 chantiers', { link: 1 }), bd('Retard', 'r'), bd('Suspendu', 'r')],
          [per('Aïcha Traoré'), t('AT Développement'), t('+225 07 78 40 12 33'), loc('Grand-Bassam'), t('4 chantiers', { link: 1 }), bd('À jour', 'g'), bd('Actif', 'g')]
        ], pages: [1, 2, 3, 4, 5],
        b: { title: 'À traiter aujourd’hui', icon: 'schedule', items: [B('7', 'dossiers incomplets', 'description', 'a'), B('5', 'pièces expirées', 'event_busy', 'r'), B('3', 'comptes signalés', 'flag', 'r')], cta: 'Voir les dossiers' }
      },
      ouvriers: {
        t: 'Gestion des ouvriers', s: 'Consultez, vérifiez et administrez les profils des ouvriers',
        a: [ex, { label: 'Ajouter un ouvrier', icon: 'add', primary: 1 }],
        k: [K('Total ouvriers', '1 248', 'groups', 'g', '+12 %'), K('Disponibles', '786', 'person', 'g', '+8 %'), K('En mission', '391', 'engineering', 'a', '+15 %', 'a'), K('À valider', '71', 'warning', 'a', '+21 %', 'r', { warn: 1 })],
        lt: 'Liste des ouvriers', li: 'group', total: '1 248', unit: 'ouvriers', ph: 'Rechercher par nom, téléphone ou métier',
        f: ['Métier', 'Disponibilité', 'Expérience', 'Localisation'],
        cols: ['Ouvrier', 'Métier', 'Expérience', 'Coût journalier', 'Disponibilité', 'Note', 'Statut'],
        rows: [
          [per('Koffi Adjoumani'), t('Maçon'), t('8 ans'), t('12 000 FCFA/jour'), bd('Disponible', 'g'), rt('4,8'), bd('Vérifié', 'g', 'verified')],
          [per('Issa Traoré'), t('Électricien'), t('5 ans'), t('10 000 FCFA/jour'), bd('Disponible', 'g'), rt('4,6'), bd('Vérifié', 'g', 'verified')],
          [per('Mamadou Koné'), t('Plombier'), t('7 ans'), t('11 000 FCFA/jour'), bd('En mission', 'a'), rt('4,7'), bd('Vérifié', 'g', 'verified')],
          [per('Yao Kouamé'), t('Peintre'), t('4 ans'), t('9 000 FCFA/jour'), bd('Disponible', 'g'), rt('4,4'), bd('À valider', 'a')],
          [per('Franck Dohi'), t('Menuisier'), t('6 ans'), t('11 500 FCFA/jour'), bd('En mission', 'a'), rt('4,5'), bd('Vérifié', 'g', 'verified')],
          [per('Siaka Bamba'), t('Carreleur'), t('3 ans'), t('9 500 FCFA/jour'), bd('Indisponible', 'r'), rt('4,2'), bd('Suspendu', 'r')]
        ], pages: [1, 2, 3, 4, 5],
        b: { title: 'À traiter aujourd’hui', icon: 'schedule', items: [B('12', 'profils à valider', 'description', 'a'), B('5', 'remplacements urgents', 'person_alert', 'r'), B('8', 'pièces expirées', 'event_busy', 'r')], cta: 'Voir les dossiers' }
      },
      commerciaux: {
        t: 'Gestion des commerciaux', s: 'Suivez les équipes terrain, leurs performances et leurs commissions',
        a: [ex, { label: 'Ajouter un commercial', icon: 'add', primary: 1 }],
        k: [K('Total commerciaux', '84', 'groups', 'g', '+12 %'), K('Actifs aujourd’hui', '62', 'person', 'g', '+8 %'), K('Ouvriers enregistrés', '1 248', 'engineering', 'a', '+15 %'), K('Commissions à payer', '4 850 000', 'paid', 'a', '+21 %', 'r', { unit: 'FCFA' })],
        lt: 'Liste des commerciaux', li: 'trending_up', total: '84', unit: 'commerciaux', ph: 'Rechercher par nom, téléphone ou zone',
        f: ['Zone', 'Disponibilité', 'Performance', 'Statut'],
        cols: ['Commercial', 'Zone', 'Ouvriers créés', 'Disponibilité', 'Gain hebdomadaire', 'Performance', 'Statut'],
        rows: [
          [per('Adama Coulibaly'), t('Abidjan Nord'), t('286'), bd('Disponible', 'g'), t('850 000 FCFA'), pr(92), bd('Actif', 'g')],
          [per('Mariam Koné'), t('Cocody'), t('241'), bd('Disponible', 'g'), t('720 000 FCFA'), pr(88), bd('Actif', 'g')],
          [per('Jean-Marc Yao'), t('Yopougon'), t('198'), bd('En mission', 'a'), t('610 000 FCFA'), pr(76), bd('Actif', 'g')],
          [per('Aïcha Traoré'), t('Bingerville'), t('167'), bd('Disponible', 'g'), t('480 000 FCFA'), pr(68), bd('Actif', 'g')],
          [per('Serge Koffi'), t('Bouaké'), t('143'), bd('Indisponible', 'r'), t('320 000 FCFA'), pr(54), bd('Suspendu', 'r')],
          [per('Fatou Diarra'), t('Grand-Bassam'), t('213'), bd('Disponible', 'g'), t('670 000 FCFA'), pr(81), bd('Actif', 'g')]
        ], pages: [1, 2, 3, 4, 5],
        b: { title: 'Suivi des performances', icon: 'schedule', items: [B('18', 'objectifs atteints', 'emoji_events', 'a'), B('7', 'commerciaux à relancer', 'person_alert', 'r'), B('2 340 000 FCFA', 'à valider', 'paid', 'a')], cta: 'Voir les performances' }
      },
      chantiers: {
        t: 'Gestion des chantiers', s: 'Supervisez l’activité, l’avancement et les équipes de chaque chantier',
        a: [ex, { label: 'Créer un chantier', icon: 'add', primary: 1 }],
        k: [K('Total chantiers', '156', 'construction', 'g', '+12 %'), K('En cours', '68', 'play_arrow', 'g', '+8 %'), K('À démarrer', '24', 'calendar_month', 'a', '+20 %', 'a'), K('En retard', '11', 'schedule', 'r', '+37 %', 'r')],
        lt: 'Liste des chantiers', li: 'construction', total: '156', unit: 'chantiers', ph: 'Rechercher par chantier, promoteur ou localisation',
        f: ['Statut', 'Localisation', 'Type de construction', 'Progression'],
        cols: ['Chantier', 'Promoteur', 'Localisation', 'Période', 'Ouvriers', 'Progression', 'Statut'],
        rows: [
          [th('Résidence Les Oliviers'), t('SICOGI'), t('Abidjan Cocody'), t('Jan 2024 – Déc 2025'), t('28'), pr(75), bd('En cours', 'g')],
          [th('Tour Horizon'), t('Ivoire Properties'), t('Abidjan Plateau'), t('Mar 2024 – Fév 2026'), t('42'), pr(45), bd('En cours', 'g')],
          [th('Cité Belle Rive'), t('SAPH'), t('Bingerville'), t('Sep 2024 – Aoû 2026'), t('18'), pr(20), bd('À démarrer', 'a')],
          [th('Complexe scolaire SA'), t('Fonds Éducation CI'), t('Yamoussoukro'), t('Nov 2023 – Oct 2025'), t('36'), pr(90), bd('Presque terminé', 'g')],
          [th('Villa Riviera'), t('Lagune Immobilier'), t('Abidjan Marcory'), t('Fév 2024 – Jan 2025'), t('16'), pr(60), bd('En cours', 'g')],
          [th('Centre commercial Akouédo'), t('Groupement ADO'), t('Grand-Bassam'), t('Mai 2024 – Avr 2026'), t('24'), pr(35), bd('En retard', 'r')]
        ], pages: [1, 2, 3, 4, 5],
        b: { title: 'Alertes et échéances', icon: 'schedule', items: [B('11', 'chantiers en retard', 'schedule', 'r'), B('8', 'paiements à déclencher', 'credit_card', 'a'), B('5', 'remplacements urgents', 'person_alert', 'r')], cta: 'Voir les alertes' }
      },
      besoins: {
        t: 'Besoins', s: 'Commandes de besoins en main-d’œuvre exprimées par les promoteurs pour leurs chantiers',
        a: [ex, { label: 'Créer une offre', icon: 'add', primary: 1 }],
        k: [K('Total des besoins', '324', 'construction', 'g', '+12 %'), K('Ouverts', '86', 'description', 'g', '+8 %'), K('En validation', '27', 'hourglass_top', 'a', '+20 %', 'a'), K('Contractuels', '42', 'handshake', 'r', '+15 %')],
        lt: 'Liste des besoins et offres', li: 'assignment', total: '324', unit: 'besoins', ph: 'Rechercher par chantier, métier ou promoteur',
        tabs: [['Tous'], ['Ponctuels', 'Ponctuelle'], ['Plusieurs jours', 'Plusieurs jours'], ['Contractuels', 'Contractuelle']],
        f: ['Type d’offre', 'Métier', 'Statut', 'Localisation'],
        cols: ['Besoin / Offre', 'Chantier', 'Métier', 'Type', 'Candidatures', 'Budget', 'Échéance', 'Statut'],
        rows: [
          [th('Renfort maçonnerie'), st('Résidence Les Oliviers', 'SICOGI'), t('Maçon'), bd('Ponctuelle', 'b'), t('12 candidats'), t('25 000 FCFA/j'), t('15 oct. 2026'), bd('Ouverte', 'g')],
          [th('Installation électrique'), st('Tour Horizon', 'Ivoire Properties'), t('Électricien'), bd('Plusieurs jours', 'a'), t('8 candidats'), t('22 000 FCFA/j'), t('28 oct. 2026'), bd('Candidatures reçues', 'b')],
          [th('Équipe de plomberie'), st('Cité Belle Rive', 'SAPH'), t('Plombier'), bd('Plusieurs jours', 'a'), t('15 candidats'), t('20 000 FCFA/j'), t('10 nov. 2026'), bd('À valider', 'a')],
          [th('Travaux de peinture'), st('Complexe scolaire SA', 'Fonds Éducation CI'), t('Peintre'), bd('Ponctuelle', 'b'), t('6 candidats'), t('18 000 FCFA/j'), t('22 oct. 2026'), bd('Validée', 'g')],
          [th('Pose de carrelage'), st('Villa Riviera', 'Lagune Immobilier'), t('Carreleur'), bd('Plusieurs jours', 'a'), t('9 candidats'), t('20 000 FCFA/j'), t('5 nov. 2026'), bd('Candidatures reçues', 'b')],
          [th('Menuiserie intérieure'), st('Centre commercial Akouédo', 'Groupement ADO'), t('Menuisier'), bd('Contractuelle', 'p'), t('4 candidats'), t('1 200 000 FCFA'), t('30 sept. 2026'), bd('Expirée', 'r')]
        ], pages: [1, 2, 3, 4, 5],
        b: { title: 'Actions prioritaires', icon: 'schedule', items: [B('14', 'offres à valider', 'description', 'r'), B('9', 'offres sans candidature', 'group', 'a'), B('6', 'acomptes en attente', 'credit_card', 'r')], cta: 'Voir les priorités' }
      },
      affectations: {
        t: 'Affectations & Offres', s: 'Contactez et validez les ouvriers trouvés pour chaque besoin, et traitez les offres des promoteurs',
        a: [ex, { label: 'Créer une affectation', icon: 'add_circle', primary: 1 }],
        k: [K('Total affectations', '286', 'event_note', 'g'), K('À valider', '18', 'schedule', 'a', null, null, { warn: 1 }), K('En cours', '142', 'engineering', 'b'), K('Remplacements', '7', 'swap_horiz', 'r')],
        lt: 'Liste des affectations', li: 'event_available', total: '286', unit: 'affectations', ph: 'Rechercher un ouvrier, une équipe ou un chantier',
        tabs: [['Toutes'], ['À valider', 'À valider'], ['Confirmées', 'Confirmée'], ['En cours', 'En cours'], ['Terminées', 'Terminée']],
        f: ['Chantier', 'Métier', 'Période', 'Statut'],
        cols: ['Ouvrier / Équipe', 'Chantier', 'Mission', 'Période', 'Paiement', 'Validation', 'Statut'],
        rows: [
          [per('Koffi Adjoumani', 'Maçon'), st('Résidence Les Oliviers', 'Cocody', { hasLoc: 1, b: 1 }), t('Maçonnerie'), t('18 mars au 2 avril'), t('Hebdomadaire'), bd('Validée', 'g', V), bd('En cours', 'b', PL)],
          [per('Équipe Bâtir Pro', '6 membres', 't'), st('Tour Horizon', 'Plateau', { hasLoc: 1, b: 1 }), t('Installation électrique'), t('25 mars au 30 avril'), t('Contractuel'), bd('À valider', 'a', W), bd('En attente', 'a', HG)],
          [per('Mamadou Koné', 'Plombier'), st('Cité Belle Rive', 'Marcory', { hasLoc: 1, b: 1 }), t('Plomberie'), t('20 au 28 mars'), t('Journalier'), bd('Validée', 'g', V), bd('Confirmée', 'g', V)],
          [per('Aïcha Traoré', 'Peintre'), st('Villa Riviera', 'Bingerville', { hasLoc: 1, b: 1 }), t('Peinture'), t('22 mars au 5 avril'), t('Hebdomadaire'), bd('À valider', 'a', W), bd('En attente', 'a', HG)],
          [per('Issa Bamba', 'Carreleur'), st('Complexe scolaire SA', 'Yopougon', { hasLoc: 1, b: 1 }), t('Carrelage'), t('12 au 26 mars'), t('Journalier'), bd('Validée', 'g', V), bd('En cours', 'b', PL)],
          [per('Équipe Menuiserie CI', '4 membres', 't'), st('Centre commercial Akouédo', 'Akouédo', { hasLoc: 1, b: 1 }), t('Menuiserie'), t('1er au 20 avril'), t('Contractuel'), bd('Validée', 'g', V), bd('Confirmée', 'g', V)]
        ], pages: [1, 2, 3, '…', 48],
        b: { title: 'Situations à traiter', icon: 'warning', warn: 1, items: [B('7', 'remplacements urgents', 'swap_horiz', 'r'), B('5', 'confirmations promoteur', 'group', 'a'), B('3', 'affectations sans date', 'event_busy', 'b')], cta: 'Voir les situations' }
      },
      pointages: {
        t: 'Gestion des pointages', s: 'Contrôlez les présences, les heures travaillées et les justificatifs',
        p: 'Aujourd’hui · 22 septembre 2026',
        a: [ex, { label: 'Ajouter un pointage', icon: 'add_circle', primary: 1 }],
        k: [K('Ouvriers attendus', '186', 'groups', 'g'), K('Présents', '164', 'check_circle', 'g'), K('Retards', '14', 'schedule', 'a'), K('Anomalies', '8', 'warning', 'r')],
        lt: 'Pointages du jour', li: 'event_note', total: '186', unit: 'pointages', ph: 'Rechercher un ouvrier ou un chantier',
        tabs: [['Tous'], ['À valider', 'À valider'], ['Validés', 'Validé'], ['Anomalies', 'Anomalie'], ['Absents', 'Absent']],
        f: ['Chantier', 'Métier', 'Statut', 'Horaire'],
        cols: ['Ouvrier', 'Chantier', 'Arrivée', 'Départ', 'Durée', 'Justificatifs', 'Statut'],
        act: ['visibility', 'check', 'edit', 'more_horiz'],
        rows: [
          [per('Koffi Adjoumani', 'Maçon'), st('Résidence Les Oliviers', 'Cocody', { hasLoc: 1, b: 1 }), tm('07:42', true), tm('17:08', true), t('8 h 26'), pf('ok', 'ok'), bd('Validé', 'g', V)],
          [per('Équipe Bâtir Pro', '6 membres', 't'), st('Tour Horizon', 'Plateau', { hasLoc: 1, b: 1 }), tm('07:15', true), tm('16:52', true), t('8 h 37'), pf('ok', 'ok'), bd('Validé', 'g', V)],
          [per('Mamadou Koné', 'Plombier'), st('Cité Belle Rive', 'Marcory', { hasLoc: 1, b: 1 }), tm('08:31', true, 'Retard 31 min'), tm('17:05', true), t('8 h 34'), pf('ok', 'ok'), bd('À valider', 'a', W)],
          [per('Aïcha Traoré', 'Peintre'), st('Villa Riviera', 'Bingerville', { hasLoc: 1, b: 1 }), tm('07:56', false), tm('En attente'), t('—', { m: 1 }), pf('ok', 'ko'), bd('En cours', 'b', PL)],
          [per('Issa Bamba', 'Carreleur'), st('Complexe scolaire SA', 'Yopougon', { hasLoc: 1, b: 1 }), tm('07:28', false), tm('16:11', true), t('8 h 43'), pf('ko', 'ko'), bd('Anomalie', 'r', 'warning')],
          [per('Équipe Menuiserie CI', '4 membres', 't'), st('Centre commercial Akouédo', 'Akouédo', { hasLoc: 1, b: 1 }), tm('—'), tm('—'), t('—', { m: 1 }), pf('none', 'none'), bd('Absent', 'n', 'remove_circle')]
        ], pages: [1, 2, 3, '…', 31],
        b: { title: 'Contrôles à effectuer', icon: 'warning', warn: 1, items: [B('8', 'anomalies à vérifier', 'warning', 'r'), B('5', 'départs non enregistrés', 'schedule', 'a'), B('3', 'signatures manquantes', 'signature', 'b')], cta: 'Traiter les pointages' }
      },
      paiements: {
        t: 'Gestion des paiements', s: 'Supervisez les encaissements, reversements et commissions O’CHANTIER',
        p: 'Ce mois · Septembre 2026',
        a: [ex, { label: 'Effectuer un paiement', icon: 'add_circle', primary: 1 }],
        k: [K('Encaissements promoteurs', '48 500 000', 'account_balance_wallet', 'g', null, null, { unit: 'FCFA' }), K('À reverser aux ouvriers', '21 850 000', 'groups', 'b', null, null, { unit: 'FCFA' }), K('Commissions O’CHANTIER', '4 350 000', 'paid', 'a', null, null, { unit: 'FCFA' }), K('Opérations en attente', '12', 'schedule', 'r')],
        lt: 'Historique des transactions', li: 'receipt_long', total: '438', unit: 'transactions', ph: 'Rechercher une référence, un bénéficiaire ou un chantier',
        tabs: [['Toutes'], ['Encaissements', 'Acompte'], ['Paiements ouvriers', 'Paiement ouvrier'], ['Commissions', 'Commission'], ['Remboursements', 'Remboursement']],
        f: ['Type', 'Méthode', 'Statut', 'Période'],
        cols: ['Référence', 'Payeur / Bénéficiaire', 'Chantier', 'Type', 'Méthode', 'Montant', 'Date', 'Statut'],
        act: ['visibility', 'receipt', 'check', 'more_horiz'],
        rows: [
          [t('PAY-260922-1842', { m: 1 }), per('SICOGI', null, 'o'), st('Résidence Les Oliviers', 'Cocody', { hasLoc: 1 }), bd('Acompte promoteur', 'g'), t('Mobile Money'), t('3 750 000 FCFA', { b: 1 }), t('22 sept. 2026 · 10:42'), bd('Réussi', 'g', V)],
          [t('PAY-260922-1816', { m: 1 }), per('Koffi Adjoumani'), st('Résidence Les Oliviers', 'Cocody', { hasLoc: 1 }), bd('Paiement ouvrier', 'b'), t('Wave'), t('450 000 FCFA', { b: 1 }), t('22 sept. 2026 · 09:18'), bd('À autoriser', 'a', W)],
          [t('PAY-260921-1784', { m: 1 }), per('Équipe Bâtir Pro', '6 membres', 't'), st('Tour Horizon', 'Plateau', { hasLoc: 1 }), bd('Acompte contractuel', 'g'), t('Virement'), t('2 400 000 FCFA', { b: 1 }), t('21 sept. 2026 · 16:05'), bd('Réussi', 'g', V)],
          [t('PAY-260921-1759', { m: 1 }), per('O’CHANTIER', null, 'x'), st('Cité Belle Rive', 'Marcory', { hasLoc: 1 }), bd('Commission', 'a'), t('Interne'), t('280 000 FCFA', { b: 1 }), t('21 sept. 2026 · 14:22'), bd('Comptabilisée', 'g', V)],
          [t('PAY-260920-1698', { m: 1 }), per('Mamadou Koné'), st('Villa Riviera', 'Bingerville', { hasLoc: 1 }), bd('Paiement ouvrier', 'b'), t('Orange Money'), t('175 000 FCFA', { b: 1 }), t('20 sept. 2026 · 18:40'), bd('En traitement', 'b', 'autorenew')],
          [t('PAY-260920-1662', { m: 1 }), per('Ivoire Properties', null, 'o'), st('Tour Horizon', 'Plateau', { hasLoc: 1 }), bd('Remboursement', 'p'), t('Virement'), t('600 000 FCFA', { b: 1 }), t('20 sept. 2026 · 11:10'), bd('Échoué', 'r', 'cancel')]
        ], pages: [1, 2, 3, '…', 73],
        b: { title: 'Opérations à traiter', icon: 'warning', warn: 1, items: [B('7', 'paiements ouvriers à autoriser', 'schedule', 'a'), B('3', 'acomptes en attente', 'receipt_long', 'b'), B('2', 'transactions échouées', 'cancel', 'r')], cta: 'Traiter les opérations' }
      },
      avis: {
        t: 'Gestion des avis & litiges', s: 'Modérez les évaluations et assurez le suivi des réclamations',
        p: 'Ce mois · Septembre 2026',
        a: [ex, { label: 'Créer un dossier', icon: 'add_circle', primary: 1 }],
        k: [K('Note moyenne', '4,6 / 5', 'star', 'a'), K('Avis ce mois', '328', 'chat_bubble', 'b'), K('Litiges ouverts', '18', 'feed', 'r'), K('Dossiers urgents', '5', 'warning', 'r')],
        lt: 'Avis et dossiers', li: 'forum', total: '412', unit: 'dossiers', ph: 'Rechercher un ouvrier, une équipe, un promoteur ou un dossier',
        tabs: [['Tous'], ['Avis ouvriers', 'Avis ouvrier'], ['Avis équipes', 'Avis équipe'], ['Litiges', 'Litige'], ['Signalements', 'Signalement']],
        f: ['Type', 'Priorité', 'Statut', 'Période'],
        cols: ['Référence / Type', 'Parties concernées', 'Chantier', 'Motif / Évaluation', 'Date', 'Priorité', 'Statut'],
        act: ['visibility', 'chat', 'person', 'more_horiz'],
        rows: [
          [st('AV-1842', 'Avis ouvrier', { link: 1 }), st('SICOGI', '→ Koffi Adjoumani'), st('Résidence Les Oliviers', 'Cocody', { hasLoc: 1 }), rt('4,8 / 5', 'Travail soigné'), t('22 sept. 2026'), bd('Normale', 'n'), bd('Publié', 'g', V)],
          [st('AV-1816', 'Avis équipe', { link: 1 }), st('Ivoire Properties', '→ Équipe Bâtir Pro'), st('Tour Horizon', 'Plateau', { hasLoc: 1 }), rt('4,5 / 5', 'Bonne coordination'), t('21 sept. 2026'), bd('Normale', 'n'), bd('À modérer', 'a', W)],
          [st('LIT-0098', 'Litige paiement', { link: 1 }), st('Mamadou Koné', '↔ Lagune Immobilier'), st('Villa Riviera', 'Bingerville', { hasLoc: 1 }), t('Paiement journalier contesté'), t('20 sept. 2026'), bd('Haute', 'r'), bd('En analyse', 'b', 'manage_search')],
          [st('LIT-0094', 'Litige absence', { link: 1 }), st('Aïcha Traoré', '↔ Fonds Éducation CI'), st('Complexe scolaire SA', 'Yopougon', { hasLoc: 1 }), t('Absence non justifiée'), t('19 sept. 2026'), bd('Moyenne', 'a'), bd('Médiation', 'p', 'groups')],
          [st('SIG-0067', 'Signalement', { link: 1 }), st('SAPH', '→ Issa Bamba'), st('Cité Belle Rive', 'Marcory', { hasLoc: 1 }), t('Comportement sur chantier'), t('18 sept. 2026'), bd('Urgente', 'R'), bd('À traiter', 'a', W)],
          [st('LIT-0089', 'Litige pointage', { link: 1 }), st('Équipe Menuiserie CI', '↔ Groupement ADO'), st('Centre commercial Akouédo', 'Akouédo', { hasLoc: 1 }), t('Signature de départ contestée'), t('17 sept. 2026'), bd('Haute', 'r'), bd('Résolu', 'g', V)]
        ], pages: [1, 2, 3, '…', 69],
        b: { title: 'Actions prioritaires', icon: 'warning', warn: 1, items: [B('5', 'dossiers urgents', 'feed', 'r'), B('7', 'avis à modérer', 'schedule', 'a'), B('4', 'médiations en retard', 'groups', 'p')], cta: 'Traiter les dossiers' }
      }
    };
    return { S };
  }

});
