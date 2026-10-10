'use strict';
// Module Promoteurs : données, liste, fiche, KYC, litiges
// Couche de la classe Component : voir src/js/och.js (OCH.extend).
OCH.extend(Base => class extends Base {
  kpi(k) {
    return { label: k.label, value: k.value, unit: k.unit || '', icon: k.icon, tn: k.tn || 'g', delta: k.delta || '', dtn: k.dtn || 'g', cmp: k.cmp || 'vs mois dernier', hint: k.hint || '', highlight: !!k.warn };
  }

  ST = { 'Actif': 'g', 'À jour': 'g', 'Vérifié': 'g', 'Réussi': 'g', 'Validée': 'g', 'Ouverte': 'g', 'Terminé': 'g', 'Publié': 'g', 'Résolu': 'g', 'En cours': 'g', 'Présent': 'g',
    'À valider': 'a', 'En attente': 'a', 'À vérifier': 'a', 'À démarrer': 'a', 'À autoriser': 'a', 'À modérer': 'a', 'Moyenne': 'a', 'Plusieurs jours': 'a',
    'Expiré': 'o', 'Haute': 'r', 'Suspendu': 'r', 'Retard': 'r', 'Rejeté': 'r', 'Échoué': 'r', 'En retard': 'r', 'Expirée': 'r', 'Absent': 'n',
    'Candidatures reçues': 'b', 'En traitement': 'b', 'En analyse': 'b', 'Ponctuelle': 'b', 'Contractuelle': 'p', 'Remboursé': 'p', 'Médiation': 'p',
    'Manquant': 'n', 'Brouillon': 'n', 'Annulée': 'n', 'Masqué': 'n', 'Normale': 'n' };
  KYCI = { 'Vérifié': 'verified', 'À vérifier': 'schedule', 'Expiré': 'event_busy', 'Rejeté': 'cancel', 'Manquant': 'remove_circle' };
  DOCTYPES = ['Registre de commerce', 'Pièce d’identité du représentant', 'Attestation fiscale', 'Justificatif d’adresse', 'Document complémentaire'];
  LOCS = ['Abidjan, Cocody', 'Abidjan, Plateau', 'Abidjan, Marcory', 'Abidjan, Yopougon', 'Bingerville', 'Yamoussoukro', 'Bouaké', 'Grand-Bassam', 'San-Pédro'];
  FF = [
    { sec: 'ent', key: 'name', label: 'Raison sociale', req: 1, ph: 'Ex. SICOGI' },
    { sec: 'ent', key: 'forme', label: 'Forme juridique', req: 1, type: 'select', options: ['', 'SA', 'SARL', 'SAS', 'Société d’État', 'Entreprise individuelle'] },
    { sec: 'ent', key: 'rccm', label: 'N° d’immatriculation (RCCM)', req: 1, ph: 'CI-ABJ-2026-B-00000' },
    { sec: 'ent', key: 'ncc', label: 'N° de contribuable (NCC)', ph: '0000000 A' },
    { sec: 'ent', key: 'secteur', label: 'Secteur d’activité', type: 'select', options: ['', 'Promotion immobilière', 'BTP · Gros œuvre', 'BTP · Second œuvre', 'Aménagement'] },
    { sec: 'ent', key: 'siege', label: 'Adresse du siège', req: 1, ph: 'Rue, quartier, commune', span: 2 },
    { sec: 'rep', key: 'resp', label: 'Nom du représentant', req: 1, ph: 'Prénom Nom' },
    { sec: 'rep', key: 'fonction', label: 'Fonction', ph: 'Ex. Gérant' },
    { sec: 'rep', key: 'phone', label: 'Téléphone', req: 1, type: 'tel', ph: '+225 07 00 00 00 00' },
    { sec: 'rep', key: 'email', label: 'Adresse e-mail', req: 1, type: 'email', ph: 'nom@entreprise.ci' },
    { sec: 'acc', key: 'type', label: 'Type de compte', req: 1, type: 'select', options: ['', 'Entreprise', 'Institution', 'Particulier'] },
    { sec: 'acc', key: 'loc', label: 'Localisation', req: 1, type: 'select', options: ['', 'Abidjan, Cocody', 'Abidjan, Plateau', 'Abidjan, Marcory', 'Abidjan, Yopougon', 'Bingerville', 'Yamoussoukro', 'Bouaké', 'Grand-Bassam', 'San-Pédro'] },
    { sec: 'acc', key: 'note', label: 'Note interne', type: 'textarea', span: 2, ph: 'Visible uniquement par les administrateurs' }
  ];
  P = this.buildPromos();

  tone(l) { return this.ST[l] || 'n'; }
  fmt(n) { return Math.round(n).toLocaleString('fr-FR').replace(/\u202f|\u00a0/g, ' '); }
  bump() { this.setState({ rev: (this.state.rev || 0) + 1 }); }
  kycOf(p) { const req = p.docs.slice(0, 4).map(d => d.st); for (const k of ['Manquant', 'Rejeté', 'Expiré', 'À vérifier']) if (req.includes(k)) return k; return 'Vérifié'; }
  dkey(d) { const M = { 'janv.': 1, 'févr.': 2, 'mars': 3, 'avr.': 4, 'mai': 5, 'juin': 6, 'juil.': 7, 'août': 8, 'sept.': 9, 'oct.': 10, 'nov.': 11, 'déc.': 12 }; const m = String(d || '').match(/(\d{1,2}) ([^\s]+) (\d{4})/); return m ? (+m[3]) * 10000 + (M[m[2]] || 0) * 100 + (+m[1]) : 0; }
  logEv(p, action, target, icon, tn, link) { const d = new Date(); p.extra.unshift({ d: '23 sept. 2026', t: String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0'), author: 'N. Yao', kind: 'admin', action, target: target || '', icon, tn, link: link || '' }); p.last = 'À l’instant'; }
  download(name, content, type) { try { const b = new Blob([content], { type: type || 'text/html;charset=utf-8' }); const u = URL.createObjectURL(b); const a = document.createElement('a'); a.href = u; a.download = name; document.body.appendChild(a); a.click(); a.remove(); setTimeout(() => URL.revokeObjectURL(u), 1500); } catch (e) {} }

  buildPromos() {
    const CH = [
      ['Résidence Les Oliviers', 'Abidjan, Cocody', 'Résidentiel collectif', 'Janv. 2024 – Déc. 2026', 28, 75, 'En cours'],
      ['Les Jardins d’Angré', 'Abidjan, Cocody Angré', 'Résidentiel', 'Mars 2025 – Juin 2027', 36, 40, 'En cours'],
      ['Cité Administrative Plateau', 'Abidjan, Plateau', 'Bureaux', 'Nov. 2026 – Déc. 2027', 0, 0, 'À démarrer'],
      ['Logements sociaux Yopougon', 'Abidjan, Yopougon', 'Logement social', 'Févr. 2025 – Nov. 2026', 44, 62, 'En retard'],
      ['Résidence Bingerville Lot B', 'Bingerville', 'Résidentiel', 'Mai 2023 – Juil. 2025', 0, 100, 'Terminé'],
      ['Extension Cité Verte', 'Songon', 'Lotissement', 'À planifier', 0, 0, 'Brouillon'],
      ['Tour Ivoire Business', 'Abidjan, Plateau', 'Bureaux', 'Avr. 2024 – Oct. 2026', 12, 30, 'Suspendu'],
      ['Villa Duplex Riviera 3', 'Abidjan, Riviera', 'Villa', 'Juin 2026 – Mars 2027', 9, 15, 'En cours']
    ].map(([name, loc, type, period, ouv, pct, st], i) => ({ id: 'ch' + (i + 1), name, loc, type, period, ouv, pct, st }));
    const OF = [
      ['Renfort maçonnerie', 0, 'Maçon', 'Ponctuelle', 12, '25 000 FCFA/j', '15 oct. 2026', 'Ouverte'],
      ['Coffrage niveau R+4', 1, 'Coffreur', 'Plusieurs jours', 8, '22 000 FCFA/j', '28 oct. 2026', 'Candidatures reçues'],
      ['Électricité bâtiment C', 3, 'Électricien', 'Contractuelle', 5, '1 800 000 FCFA', '10 nov. 2026', 'À valider'],
      ['Pose de carrelage hall', 0, 'Carreleur', 'Plusieurs jours', 9, '20 000 FCFA/j', '5 nov. 2026', 'Validée'],
      ['Étanchéité toiture', 1, 'Étancheur', 'Ponctuelle', 0, '30 000 FCFA/j', '30 sept. 2026', 'Expirée'],
      ['Plomberie sanitaires', 2, 'Plombier', 'Plusieurs jours', 0, '20 000 FCFA/j', '—', 'Brouillon'],
      ['Peinture façades', 3, 'Peintre', 'Ponctuelle', 3, '18 000 FCFA/j', '12 sept. 2026', 'Annulée'],
      ['Chef d’équipe gros œuvre', 0, 'Chef d’équipe', 'Contractuelle', 4, '2 400 000 FCFA', '20 nov. 2026', 'Ouverte']
    ].map(([name, ci, metier, type, cand, budget, due, st], i) => ({ id: 'of' + (i + 1), name, ch: CH[ci].name, metier, type, cand, budget, due, st }));
    const PAY = [
      ['PAY-260922-1842', 0, 'Acompte', 'Mobile Money', 3750000, '22 sept. 2026 · 10:42', 'Réussi', 'Koffi Adjoumani'],
      ['PAY-260919-1730', 1, 'Paiement hebdomadaire', 'Virement', 1540000, '19 sept. 2026 · 16:05', 'Réussi', 'Équipe Bâtir Pro'],
      ['PAY-260918-1702', 3, 'Paiement contractuel', 'Virement', 2400000, '18 sept. 2026 · 11:20', 'À autoriser', 'Équipe Menuiserie CI'],
      ['PAY-260917-1688', 0, 'Paiement journalier', 'Wave', 450000, '17 sept. 2026 · 18:40', 'En traitement', 'Koffi Adjoumani'],
      ['PAY-260915-1654', 0, 'Commission', 'Interne', 280000, '15 sept. 2026 · 09:00', 'Réussi', 'O’CHANTIER'],
      ['PAY-260912-1611', 6, 'Remboursement', 'Virement', 600000, '12 sept. 2026 · 14:22', 'Remboursé', 'Promoteur'],
      ['PAY-260910-1590', 1, 'Paiement hebdomadaire', 'Orange Money', 980000, '10 sept. 2026 · 17:15', 'Échoué', 'Mamadou Koné'],
      ['PAY-260909-1575', 3, 'Acompte', 'Mobile Money', 1200000, '9 sept. 2026 · 08:30', 'En attente', 'Issa Bamba']
    ].map(([ref, ci, type, moyen, amount, date, st, benef]) => ({ id: ref, ref, ch: CH[ci].name, type, moyen, amount, com: (type === 'Commission' || type === 'Remboursement') ? 0 : Math.round(amount * 0.08), date, st, benef }));
    const AV = [
      ['Koffi Adjoumani', 'Maçon', 0, '4,8', 'Travail soigné, ponctuel sur toute la durée du lot.', '21 sept. 2026', 'Publié'],
      ['Équipe Bâtir Pro', '6 membres', 1, '4,5', 'Bonne coordination avec le chef de chantier.', '16 sept. 2026', 'Publié'],
      ['Mamadou Koné', 'Plombier', 3, '3,2', 'Finitions à reprendre sur deux logements.', '9 sept. 2026', 'À modérer'],
      ['Issa Bamba', 'Carreleur', 0, '4,6', 'Très bon rendement, pose régulière.', '2 sept. 2026', 'Masqué']
    ].map(([who, sub, ci, note, comment, date, st], i) => ({ id: 'av' + i, who, sub, ch: CH[ci].name, note, comment, date, st }));
    const LIT = [
      ['LIT-0102', 'Équipe Bâtir Pro', 3, 'Retard de livraison du lot électricité', 'Haute', '16 sept. 2026', 'Awa Bamba', 'En analyse'],
      ['LIT-0087', 'Koffi Adjoumani', 0, 'Paiement journalier contesté', 'Moyenne', '2 août 2026', 'N. Yao', 'Résolu']
    ].map(([ref, other, ci, motif, prio, date, owner, st]) => ({ id: ref, ref, other, ch: CH[ci].name, motif, prio, date, owner, st }));
    this.POOL = { CH, OF, PAY, AV, LIT };
    const L = [
      { id: 'sicogi', name: 'SICOGI', resp: 'Mireille Konan', fonction: 'Directrice des opérations', type: 'Institution', forme: 'Société d’État', rccm: 'CI-ABJ-1962-B-2104', ncc: '6200154 K', secteur: 'Promotion immobilière', siege: 'Avenue Terrasson de Fougères, Abidjan-Plateau', loc: 'Abidjan, Plateau', phone: '+225 27 20 25 10 00', email: 'm.konan@sicogi.ci', status: 'Actif', payst: 'À jour', inscrit: '12 janv. 2024', last: 'Aujourd’hui · 09:42', chi: [0, 1, 2, 3, 4, 5, 6], ofi: [0, 1, 2, 3, 4, 5, 6, 7], payi: [0, 1, 2, 3, 4, 5, 6, 7], avi: [0, 1, 2, 3], liti: [0, 1], dc: 'vvaem' },
      { id: 'ivoire-construction', name: 'Ivoire Construction', resp: 'Koffi N’Guessan', fonction: 'Gérant', type: 'Entreprise', forme: 'SARL', rccm: 'CI-ABJ-2015-B-11872', ncc: '1512987 A', secteur: 'BTP · Gros œuvre', siege: 'Rue des Jardins, Abidjan-Cocody', loc: 'Abidjan, Cocody', phone: '+225 07 08 25 14 63', email: 'k.nguessan@ivoireconstruction.ci', status: 'Actif', payst: 'À jour', inscrit: '03 mars 2024', last: 'Hier · 17:20', chi: [0, 1, 2, 3, 4, 5, 6, 7], ofi: [0, 1, 3, 7], payi: [0, 1, 4], avi: [0, 1], liti: [], dc: 'vvvvv' },
      { id: 'mk-immobilier', name: 'MK Immobilier', resp: 'Mariam Koné', fonction: 'Directrice générale', type: 'Entreprise', forme: 'SA', rccm: 'CI-ABJ-2018-B-20431', ncc: '1820554 C', secteur: 'Promotion immobilière', siege: 'Boulevard de la République, Abidjan-Plateau', loc: 'Abidjan, Plateau', phone: '+225 05 66 42 18 90', email: 'mariam.kone@mkimmo.ci', status: 'Actif', payst: 'À jour', inscrit: '21 mai 2024', last: 'Aujourd’hui · 08:05', chi: [1, 2, 3, 4, 7], ofi: [1, 2], payi: [1, 4, 6], avi: [1], liti: [0], dc: 'vvvvm' },
      { id: 'horizon-btp', name: 'Horizon BTP', resp: 'Yao Kouassi', fonction: 'Fondateur', type: 'Entreprise', forme: 'SARL', rccm: 'CI-ABJ-2026-B-03318', ncc: '2604412 F', secteur: 'BTP · Second œuvre', siege: 'Route de Bingerville, Bingerville', loc: 'Bingerville', phone: '+225 01 74 28 10 55', email: 'y.kouassi@horizonbtp.ci', status: 'À valider', payst: 'En attente', inscrit: '15 sept. 2026', last: 'Il y a 3 jours', chi: [2, 5, 7], ofi: [5], payi: [7], avi: [], liti: [], dc: 'aaamm' },
      { id: 'residences-plus', name: 'Résidences Plus', resp: 'Fatou Diarra', fonction: 'Responsable projets', type: 'Entreprise', forme: 'SAS', rccm: 'CI-YAM-2019-B-00562', ncc: '1904771 B', secteur: 'Promotion immobilière', siege: 'Quartier Habitat, Yamoussoukro', loc: 'Yamoussoukro', phone: '+225 07 57 91 33 20', email: 'f.diarra@residencesplus.ci', status: 'Actif', payst: 'À jour', inscrit: '08 août 2024', last: 'Il y a 2 jours', chi: [0, 1, 3, 4, 6, 7], ofi: [0, 3, 4, 6], payi: [0, 2, 4], avi: [0, 3], liti: [], dc: 'vvvvv' },
      { id: 'batir-afrique', name: 'Bâtir Afrique', resp: 'Serge Aka', fonction: 'Gérant', type: 'Entreprise', forme: 'SARL', rccm: 'CI-BKE-2020-B-01104', ncc: '2011053 D', secteur: 'BTP · Gros œuvre', siege: 'Quartier Commerce, Bouaké', loc: 'Bouaké', phone: '+225 05 04 88 71 19', email: 's.aka@batirafrique.ci', status: 'Suspendu', payst: 'Retard', inscrit: '11 févr. 2025', last: 'Il y a 12 jours', chi: [3, 6], ofi: [6], payi: [6], avi: [], liti: [0], dc: 'vveev', suspReason: 'Retards de paiement — 3 échéances impayées sur Logements sociaux Yopougon.', suspDate: '11 sept. 2026', actError: true },
      { id: 'at-developpement', name: 'AT Développement', resp: 'Aïcha Traoré', fonction: 'Directrice', type: 'Entreprise', forme: 'SARL', rccm: 'CI-GBA-2021-B-00219', ncc: '2107318 E', secteur: 'Aménagement', siege: 'Boulevard de France, Grand-Bassam', loc: 'Grand-Bassam', phone: '+225 07 78 40 12 33', email: 'a.traore@atdev.ci', status: 'Actif', payst: 'À jour', inscrit: '19 oct. 2025', last: 'Aujourd’hui · 07:50', chi: [0, 2, 4, 7], ofi: [3, 7], payi: [0, 1], avi: [0], liti: [], dc: 'vvvva' }
    ];
    const P = {};
    L.forEach(o => { P[o.id] = this.mkPromo(o); });
    return P;
  }
  mkPromo(o) {
    const { CH, OF, PAY, AV, LIT } = this.POOL;
    const pick = (arr, idx) => (idx || []).map(i => ({ ...arr[i] }));
    const SC = { v: 'Vérifié', a: 'À vérifier', e: 'Expiré', r: 'Rejeté', m: 'Manquant' };
    const DOCT = [['Registre de commerce', 'rccm', '12 janv. 2024', 'Sans expiration'], ['Pièce d’identité du représentant', 'CNI C0045 8812', '12 janv. 2024', '04 mars 2029'], ['Attestation fiscale', 'AF-2026-00871', '03 mars 2026', '31 déc. 2026'], ['Justificatif d’adresse', 'Facture CIE · nov. 2025', '14 nov. 2025', '14 mai 2027'], ['Document complémentaire', 'Statuts de la société', '12 janv. 2024', 'Sans expiration']];
    const p = { note: '', ...o, ch: pick(CH, o.chi), of: pick(OF, o.ofi), pay: pick(PAY, o.payi), av: pick(AV, o.avi), lit: pick(LIT, o.liti), extra: [] };
    p.pay.forEach(x => { if (x.benef === 'Promoteur') x.benef = p.name; });
    const added = this.dkey(p.inscrit) > this.dkey('12 janv. 2024') ? p.inscrit : null;
    p.docs = DOCT.map(([type, ref, add, exp], i) => { const c = (o.dc || 'mmmmm')[i]; const st = SC[c]; return { id: 'doc' + (i + 1), type, ref: c === 'm' ? '—' : (ref === 'rccm' ? p.rccm : ref), added: c === 'm' ? '—' : (added || add), exp: c === 'm' ? '—' : (c === 'e' ? '14 mai 2026' : exp), st, motif: '' }; });
    return p;
  }
  actsFor(p) {
    const ev = (d, t, author, kind, action, target, icon, tn, link) => ({ d, t, author, kind, action, target, icon, tn, link });
    const E = [...p.extra];
    E.push(ev('23 sept. 2026', '09:42', p.resp, 'promoter', 's’est connecté à son espace', 'Espace promoteur', 'login', 'neutral', ''));
    const ok = p.pay.find(x => x.st === 'Réussi' && x.type !== 'Commission');
    if (ok) E.push(ev(ok.date.split(' · ')[0], ok.date.split(' · ')[1] || '10:00', 'Système', 'system', 'a confirmé un paiement', ok.ref + ' · ' + this.fmt(ok.amount) + ' FCFA', 'payments', 'success', 'paiements/' + ok.ref));
    if (p.lit[0]) E.push(ev(p.lit[0].date, '14:10', p.resp, 'promoter', 'a ouvert un litige', p.lit[0].ref + ' · ' + p.lit[0].motif, 'gavel', 'danger', 'litiges'));
    if (p.of[0]) E.push(ev('14 sept. 2026', '11:05', p.resp, 'promoter', 'a publié un besoin', p.of[0].name + ' · ' + p.of[0].ch, 'campaign', 'info', 'offres/' + p.of[0].id));
    if (p.ch[0]) E.push(ev('02 sept. 2026', '15:30', p.resp, 'promoter', 'a créé un chantier', p.ch[0].name, 'construction', 'success', 'chantiers/' + p.ch[0].id));
    if (p.suspDate) E.push(ev(p.suspDate, '16:20', 'N. Yao', 'admin', 'a suspendu le compte', 'Retards de paiement', 'block', 'danger', ''));
    if (p.dc && p.dc[0] === 'v') {
      E.push(ev('28 août 2026', '10:12', 'N. Yao', 'admin', 'a modifié le profil', 'Téléphone du représentant', 'edit', 'purple', ''));
      E.push(ev(p.inscrit, '16:48', 'N. Yao', 'admin', 'a validé le dossier KYC', 'Registre de commerce, pièce d’identité', 'verified', 'success', 'kyc'));
    }
    if (p.dc) E.push(ev(p.inscrit, '09:15', p.resp, 'promoter', 'a créé son compte', p.name, 'person_add', 'neutral', ''));
    return E.sort((a, b) => (this.dkey(b.d) - this.dkey(a.d)) || b.t.localeCompare(a.t));
  }


  suspPromo(p) { this.openModal({ kind: 'reason', tone: 'danger', icon: 'block', title: `Suspendre ${p.name} ?`, body: 'Le promoteur ne pourra plus publier de besoin ni initier de paiement. Les chantiers en cours restent visibles par les ouvriers affectés.', ok: 'Suspendre le compte', reasonLabel: 'Motif de la suspension *', motifs: ['Retards de paiement', 'Documents KYC non conformes', 'Signalements répétés', 'Demande du promoteur', 'Autre'], after: v => { p.status = 'Suspendu'; p.suspReason = v.motif + ' — ' + v.reason.trim(); p.suspDate = '23 sept. 2026'; this.logEv(p, 'a suspendu le compte', v.motif, 'block', 'danger', ''); this.bump(); this.toast(`Compte ${p.name} suspendu.`); } }); }
  reactPromo(p) { this.openModal({ tone: 'success', icon: 'settings_backup_restore', title: `Réactiver ${p.name} ?`, body: 'Le promoteur retrouvera l’accès complet à la plateforme et sera notifié par e-mail.', ok: 'Réactiver le compte', after: () => { p.status = 'Actif'; p.suspReason = ''; p.suspDate = ''; this.logEv(p, 'a réactivé le compte', '', 'settings_backup_restore', 'success', ''); this.bump(); this.toast(`Compte ${p.name} réactivé.`); } }); }
  validPromo(p) { const n = p.docs.slice(0, 4).filter(d => d.st !== 'Vérifié').length; this.openModal({ tone: 'success', icon: 'verified', title: 'Valider le compte ?', body: (n ? `Attention : ${n} document${n > 1 ? 's' : ''} KYC obligatoire${n > 1 ? 's ne sont' : ' n’est'} pas encore vérifié${n > 1 ? 's' : ''}. ` : '') + `Le compte ${p.name} passera au statut « Actif ».`, ok: 'Valider le compte', after: () => { p.status = 'Actif'; p.payst = p.payst === 'En attente' ? 'À jour' : p.payst; this.logEv(p, 'a validé le compte', '', 'verified', 'success', ''); this.bump(); this.toast(`Compte ${p.name} validé.`); } }); }
  notifPromo(p, objet) { this.openModal({ kind: 'notif', icon: 'send', title: 'Envoyer une notification', recipient: `${p.resp} · ${p.name}`, objet: objet || '', ok: 'Envoyer', okIcon: 'send', after: v => { this.logEv(p, 'a envoyé une notification', v.objet, 'send', 'info', ''); this.bump(); this.toast(`Notification envoyée à ${p.resp} (${v.canal.join(', ')}).`); } }); }
  exportCsv() { const rows = [['Promoteur', 'Entreprise', 'Téléphone', 'E-mail', 'Localisation', 'Chantiers', 'Paiements', 'KYC', 'Statut']].concat(Object.values(this.P).map(p => [p.resp, p.name, p.phone, p.email, p.loc, p.ch.length, p.payst, this.kycOf(p), p.status])); this.download('promoteurs.csv', '\ufeff' + rows.map(r => r.map(c => '"' + String(c).replace(/"/g, '""') + '"').join(';')).join('\n'), 'text/csv;charset=utf-8'); this.toast('Export CSV généré (' + (rows.length - 1) + ' promoteurs).'); }
  ficheHtml(p) { const li = (k, v) => `<tr><td style="padding:6px 12px;color:#555">${k}</td><td style="padding:6px 12px"><b>${v || '—'}</b></td></tr>`; return `<!doctype html><meta charset="utf-8"><title>Fiche ${p.name}</title><body style="font-family:sans-serif;padding:32px"><h1>${p.name}</h1><p>${p.resp} · ${p.fonction || ''}</p><table>${li('Statut', p.status)}${li('KYC', this.kycOf(p))}${li('Type de compte', p.type)}${li('Forme juridique', p.forme)}${li('RCCM', p.rccm)}${li('NCC', p.ncc)}${li('Siège', p.siege)}${li('Téléphone', p.phone)}${li('E-mail', p.email)}${li('Chantiers', p.ch.length)}${li('Inscrit le', p.inscrit)}</table><p style="color:#888">Exporté depuis O’CHANTIER Admin le 23 sept. 2026</p></body>`; }
  receiptHtml(p, x, com, fee, net) { const f = n => this.fmt(n) + ' FCFA'; return `<!doctype html><meta charset="utf-8"><title>Reçu ${x.ref}</title><body style="font-family:sans-serif;padding:32px;max-width:640px"><h1>O’CHANTIER — Reçu de paiement</h1><p><b>${x.ref}</b> · ${x.date}</p><p>Payeur : ${p.name}<br>Bénéficiaire : ${x.benef}<br>Chantier : ${x.ch}<br>Type : ${x.type} · ${x.moyen}</p><table style="width:100%;border-collapse:collapse"><tr><td>Montant brut</td><td align="right">${f(x.amount)}</td></tr><tr><td>Commission O’CHANTIER</td><td align="right">${com ? '− ' + f(com) : '—'}</td></tr><tr><td>Frais opérateur</td><td align="right">${fee ? '− ' + f(fee) : '—'}</td></tr><tr><td><b>Net versé</b></td><td align="right"><b>${f(net)}</b></td></tr></table><p>Statut : ${x.st}</p></body>`; }

  promoRows() {
    return Object.values(this.P).map(p => {
      const kyc = this.kycOf(p), n = p.ch.length, open = () => this.route('/promoteurs/' + p.id);
      const r = [{ k: 'per', name: p.resp, open }, { k: 'txt', v: p.name, open }, { k: 'stk', v: p.phone, sub: p.email }, { k: 'loc', v: p.loc }, { k: 'txt', v: n + (n > 1 ? ' chantiers' : ' chantier'), link: 1, open: () => this.route('/promoteurs/' + p.id + '/chantiers') }, { k: 'bdg', v: p.payst, tn: this.tone(p.payst) }, { k: 'bdg', v: kyc, tn: this.tone(kyc), icon: this.KYCI[kyc] }, { k: 'bdg', v: p.status, tn: this.tone(p.status) }];
      r.p = p; return r;
    });
  }
  promoFilterSort(vis) {
    const f = this.state.pfil || {}, so = this.state.psort;
    let v = vis.filter(x => { const p = x.r.p; if (f.status && p.status !== f.status) return false; if (f.loc && p.loc !== f.loc) return false; if (f.type && p.type !== f.type) return false; if (f.kyc && this.kycOf(p) !== f.kyc) return false; if (f.period) { const k = this.dkey(p.inscrit); if (f.period === '30 derniers jours' ? k < 20260824 : String(Math.floor(k / 10000)) !== f.period) return false; } return true; });
    const qk = this.state.pquick;
    if (qk) v = v.filter(x => { const m = this.promoFlags(x.r.p); return qk === 'all' ? (m.inc || m.exp || m.sig) : m[{ incomplets: 'inc', expirees: 'exp', signales: 'sig' }[qk]]; });
    if (so) { const val = x => { const c = x.r[so.col] || {}; return String(c.v || c.name || ''); }; v = [...v].sort((a, b) => val(a).localeCompare(val(b), 'fr', { numeric: true }) * so.dir); }
    return v;
  }
  promoFlags(p) { return { inc: p.docs.slice(0, 4).some(d => d.st === 'Manquant'), exp: p.docs.some(d => d.st === 'Expiré'), sig: p.lit.some(l => l.st !== 'Résolu') }; }
  promoBottom() {
    const P = Object.values(this.P), q = this.state.pquick, TN = this.TN;
    const pl = (n, a, b) => n > 1 ? b : a;
    const inc = P.filter(p => this.promoFlags(p).inc).length, sig = P.filter(p => this.promoFlags(p).sig).length;
    const expP = P.filter(p => this.promoFlags(p).exp).length, expD = P.reduce((a, p) => a + p.docs.filter(d => d.st === 'Expiré').length, 0);
    const LBL = { incomplets: 'Dossiers incomplets', expirees: 'Pièces expirées', signales: 'Comptes signalés', all: 'Tous les dossiers à traiter' };
    const reveal = () => requestAnimationFrame(() => { const el = document.querySelector('[data-component="ListPanel"]'); if (el) window.scrollTo({ top: el.getBoundingClientRect().top + window.scrollY - 16, behavior: 'smooth' }); });
    const apply = k => { this.setState({ pquick: k, sel: { ...this.state.sel, promoteurs: [] }, rowMenu: null }); if (k) reveal(); };
    const items = [['incomplets', inc, pl(inc, 'dossier incomplet', 'dossiers incomplets') + ' (pièce KYC obligatoire manquante)', 'description', 'a'], ['expirees', expD, pl(expD, 'pièce expirée', 'pièces expirées') + ' · ' + expP + ' ' + pl(expP, 'promoteur', 'promoteurs'), 'event_busy', 'r'], ['signales', sig, pl(sig, 'compte signalé', 'comptes signalés') + ' (litige ouvert)', 'flag', 'r']];
    const total = P.filter(p => { const m = this.promoFlags(p); return m.inc || m.exp || m.sig; }).length;
    return {
      title: 'À traiter aujourd’hui', icon: 'schedule', iconC: 'var(--c-primary)',
      hasActive: !!q, activeLabel: LBL[q] || '', clear: () => apply(null),
      cta: q === 'all' ? 'Afficher tous les promoteurs' : 'Voir les dossiers (' + total + ')', ctaGo: () => apply(q === 'all' ? null : 'all'),
      items: items.map(([k, n, label, icon, tn]) => { const on = q === k, off = n === 0; return { n: String(n), label, icon, bg: TN[tn][0], fg: TN[tn][1], on, off, go: () => { if (!off) apply(on ? null : k); }, border: on ? 'var(--c-primary-400)' : 'var(--c-line)', cardBg: on ? 'var(--c-primary-50)' : 'var(--c-surface)', cursor: off ? 'default' : 'pointer', op: off ? 0.55 : 1, arrow: on ? 'close' : 'arrow_forward', arrowC: on ? 'var(--c-primary)' : 'var(--c-ink-2)' }; })
    };
  }
  promoFilters() {
    const f = this.state.pfil || {}, P = Object.values(this.P);
    const mk = (key, label, opts) => ({ label, isBtn: false, isSel: true, value: f[key] || '', options: [{ v: '', l: label }, ...opts.map(o => ({ v: o, l: o }))], onChange: e => this.setState({ pfil: { ...(this.state.pfil || {}), [key]: e.target.value } }), border: f[key] ? 'var(--c-primary-400)' : 'var(--c-line-strong)', bg: f[key] ? 'var(--c-primary-50)' : 'var(--c-surface)' });
    return [mk('status', 'Statut', ['Actif', 'À valider', 'Suspendu']), mk('loc', 'Localisation', [...new Set(P.map(p => p.loc))]), mk('type', 'Type de compte', ['Entreprise', 'Institution', 'Particulier']), mk('kyc', 'État KYC', ['Vérifié', 'À vérifier', 'Expiré', 'Rejeté', 'Manquant']), mk('period', 'Inscription', ['30 derniers jours', '2026', '2025', '2024'])];
  }
  listCols(scr, cfg) {
    if (scr === 'besoins') return this.boCols();
    const isW = scr === 'ouvriers', isC = scr === 'commerciaux', isCh = scr === 'chantiers', isAl = scr === 'avis', isP = scr === 'promoteurs' || isW || isC || isCh || isAl, key = isW ? 'wsort' : isC ? 'csort' : isCh ? 'chsort' : isAl ? 'alsort' : 'psort', so = this.state[key];
    const cols = scr === 'promoteurs' ? ['Promoteur', 'Entreprise', 'Contact', 'Localisation', 'Chantiers', 'Paiements', 'KYC', 'Statut'] : isCh ? ['Chantier', 'Référence', 'Promoteur', 'Localisation', 'Type', 'Début', 'Fin prévue', 'Jours exécutés / prévus', 'Progression', 'Effectif', 'Pointages', 'Paiements', 'Alerte', 'Statut'] : isC ? ['Commercial', 'Contact', 'Zone', 'Ouvriers', 'Taux de validation', 'Objectif', 'Gain hebdomadaire', 'Performance', 'Disponibilité', 'Statut du compte', 'Dernière activité'] : cfg.cols;
    return cols.map((label, i) => { const on = isP && so && so.col === i; return { label, sort: isP ? () => this.setState({ [key]: on ? (so.dir === 1 ? { col: i, dir: -1 } : null) : { col: i, dir: 1 } }) : null, cursor: isP ? 'pointer' : 'default', icon: on ? (so.dir === 1 ? 'arrow_upward' : 'arrow_downward') : 'unfold_more', iconC: on ? 'var(--c-primary)' : 'var(--c-subtle)', aria: on ? (so.dir === 1 ? 'ascending' : 'descending') : 'none' }; });
  }
  rowActs(scr, x, cfg) {
    if (scr === 'ouvriers' && x.r.w) return this.wRowActs(x);
    if (scr === 'commerciaux' && x.r.c) return this.cRowActs(x);
    if (scr === 'chantiers' && x.r.ch) return this.chRowActs(x);
    if (scr === 'besoins' && x.r.bo) return this.boRowActs(x);
    if (scr === 'avis' && x.r.al) return this.alRowActs(x);
    if (scr !== 'promoteurs') return { acts: (cfg.act || ['visibility', 'edit', 'more_vert']).map(icon => ({ icon, label: '', go: null })), menuOpen: false, menu: [] };
    const p = x.r.p, key = 'l' + p.id, open = this.state.rowMenu === key;
    const M = [['construction', 'Consulter les chantiers', () => this.route(`/promoteurs/${p.id}/chantiers`)], ['payments', 'Consulter les paiements', () => this.route(`/promoteurs/${p.id}/paiements`)], ['send', 'Envoyer une notification', () => this.notifPromo(p)], p.status === 'Suspendu' ? ['settings_backup_restore', 'Réactiver', () => this.reactPromo(p)] : ['block', 'Suspendre', () => this.suspPromo(p), 1]];
    return { acts: [{ icon: 'visibility', label: 'Voir le profil', go: () => this.route('/promoteurs/' + p.id) }, { icon: 'edit', label: 'Modifier', go: () => this.route(`/promoteurs/${p.id}/modifier`) }, { icon: 'more_vert', label: 'Plus d’actions', go: () => this.setState({ rowMenu: open ? null : key }) }], menuOpen: open, menu: M.map(([icon, label, fn, d]) => ({ icon, label, color: d ? 'var(--c-danger-fg)' : 'var(--c-ink)', go: () => { this.setState({ rowMenu: null }); fn(); } })) };
  }

  promoVals(s) {
    const parts = (s.route || '/promoteurs').split('/').filter(Boolean);
    const id = parts[1], sub = parts[2] || '', subId = parts[3];
    const R = r => () => this.route(r), fmt = n => this.fmt(n), tn = l => this.tone(l);
    const res = { isPromoModule: false, drawer: { open: false }, hasCrumbs: false, crumbs: [] };
    if (!id) return res;
    const crumbs = list => list.map(([label, go], i) => ({ label, go: go || null, isLink: !!go, isCur: !go, hasSep: i > 0 }));
    const pm = { isDetail: false, isChantier: false, isPaiement: false, isForm: false, isMissing: false };
    Object.assign(res, { isPromoModule: true, isList: false, hasKpis: false, kpis: [], actions: [], hasActions: false, hasPeriod: false, pm, hasCrumbs: true });
    if (id === 'nouveau') { pm.isForm = true; pm.form = this.formVals(s); res.title = 'Ajouter un promoteur'; res.subtitle = 'Créez un compte promoteur. Les champs marqués * sont obligatoires.'; res.crumbs = crumbs([['Promoteurs', R('/promoteurs')], ['Nouveau promoteur']]); return res; }
    const p = this.P[id];
    if (!p) { pm.isMissing = true; pm.back = R('/promoteurs'); res.title = 'Promoteur introuvable'; res.subtitle = ''; res.crumbs = crumbs([['Promoteurs', R('/promoteurs')], ['Introuvable']]); return res; }
    const base = '/promoteurs/' + id;
    const kv = (icon, k, v) => ({ icon, k, v: v || '—' });
    if (sub === 'modifier') {
      pm.isForm = true; pm.form = this.formVals(s);
      const ent = subId === 'entreprise';
      res.title = ent ? 'Modifier les informations de l’entreprise' : 'Modifier un promoteur'; res.subtitle = p.name + ' · les champs marqués * sont obligatoires.';
      res.crumbs = crumbs([['Promoteurs', R('/promoteurs')], [p.name, R(base + (ent ? '/kyc' : ''))], ['Modifier']]); return res;
    }
    if (sub === 'chantiers' && subId) {
      const c = p.ch.find(x => x.id === subId);
      if (!c) { pm.isMissing = true; pm.back = R(base + '/chantiers'); res.title = 'Chantier introuvable'; res.subtitle = ''; res.crumbs = crumbs([['Promoteurs', R('/promoteurs')], [p.name, R(base)], ['Chantiers', R(base + '/chantiers')]]); return res; }
      const cpay = p.pay.filter(x => x.ch === c.name), cof = p.of.filter(o => o.ch === c.name);
      const paid = cpay.filter(x => x.st === 'Réussi').reduce((a, x) => a + x.amount, 0);
      const TEAM = [['Koffi Adjoumani', 'Maçon', 'Présent'], ['Issa Traoré', 'Électricien', 'Présent'], ['Mamadou Koné', 'Plombier', 'En retard'], ['Aïcha Traoré', 'Peintre', 'Absent']];
      const J = [['Études & permis', 10], ['Terrassement & fondations', 25], ['Gros œuvre', 60], ['Second œuvre', 85], ['Finitions & réception', 100]];
      let cur = false;
      pm.isChantier = true;
      pm.cd = {
        name: c.name, st: c.st, tn: tn(c.st), promo: p.name, back: R(base + '/chantiers'), backLabel: 'Retour à la fiche ' + p.name,
        meta: [{ icon: 'location_on', l: 'Localisation', v: c.loc }, { icon: 'domain', l: 'Type', v: c.type }, { icon: 'date_range', l: 'Période', v: c.period }],
        kpis: [this.kpi({ label: 'Ouvriers affectés', value: String(c.ouv), icon: 'groups', tn: 'g' }), this.kpi({ label: 'Progression', value: c.pct + ' %', icon: 'trending_up', tn: c.pct < 50 ? 'a' : 'g' }), this.kpi({ label: 'Montant payé', value: fmt(paid), unit: 'FCFA', icon: 'payments', tn: 'a' }), this.kpi({ label: 'Besoins liés', value: String(cof.length), icon: 'assignment', tn: 'b' })],
        pL: c.pct + ' %', w: c.pct + '%', barC: c.pct < 50 ? 'var(--c-accent-600)' : 'var(--c-primary-500)',
        jalons: J.map(([l, th]) => { const done = c.pct >= th; const now = !done && !cur && c.st !== 'Brouillon' && c.st !== 'À démarrer'; if (now) cur = true; return { l, icon: done ? 'check_circle' : (now ? 'pending' : 'radio_button_unchecked'), c: done ? 'var(--c-success)' : (now ? 'var(--c-accent-600)' : 'var(--c-subtle)'), fw: now ? 700 : 400, state: done ? 'Terminé' : (now ? 'En cours' : 'À venir') }; }),
        team: c.ouv ? TEAM.slice(0, Math.min(4, c.ouv)).map(([name, job, st]) => ({ name, job, st, tn: tn(st) })) : [], noTeam: !c.ouv, hasMore: c.ouv > 4, more: `+ ${c.ouv - 4} autres ouvriers affectés`,
        of: cof.map(o => ({ name: o.name, sub: o.metier + ' · ' + o.cand + ' candidats', st: o.st, tn: tn(o.st), go: R(`${base}/offres/${o.id}`) })), noOf: !cof.length,
        pay: cpay.map(x => ({ amount: fmt(x.amount) + ' FCFA', ref: x.ref, type: x.type, st: x.st, tn: tn(x.st), go: R(`${base}/paiements/${x.id}`) })), noPay: !cpay.length,
        edit: () => this.toast('L’édition du chantier se fait depuis le module Chantiers.'), goPointages: () => this.go('pointages'),
        goOffres: () => { this.route(base + '/offres'); this.setState({ pfCh: c.name }); }
      };
      res.title = 'Détail du chantier'; res.subtitle = '';
      res.crumbs = crumbs([['Promoteurs', R('/promoteurs')], [p.name, R(base)], ['Chantiers', R(base + '/chantiers')], [c.name]]); return res;
    }
    if (sub === 'paiements' && subId) {
      const x = p.pay.find(y => y.id === subId);
      if (!x) { pm.isMissing = true; pm.back = R(base + '/paiements'); res.title = 'Paiement introuvable'; res.subtitle = ''; res.crumbs = crumbs([['Promoteurs', R('/promoteurs')], [p.name, R(base)], ['Paiements', R(base + '/paiements')]]); return res; }
      const com = x.com, fee = (x.type === 'Commission' || x.type === 'Remboursement') ? 0 : Math.round(x.amount * 0.01), net = x.amount - com - fee;
      const [day, time] = x.date.split(' · ');
      const H = [['Paiement initié par ' + p.resp, day + ' · 08:02', 'add_circle', 'var(--c-primary-500)']];
      if (x.st !== 'À autoriser' && x.st !== 'En attente') H.push(['Autorisé par N. Yao', day + ' · 09:15', 'verified', 'var(--c-primary-500)'], ['Transmis à l’opérateur ' + x.moyen, day + ' · ' + (time || '10:00'), 'send', 'var(--c-primary-500)']);
      H.push({ 'Réussi': ['Paiement réussi', x.date, 'check_circle', 'var(--c-success)'], 'Échoué': ['Échec : fonds insuffisants chez l’opérateur', x.date, 'cancel', 'var(--c-danger)'], 'En traitement': ['En cours de traitement', 'Mise à jour il y a 12 min', 'pending', 'var(--c-info)'], 'À autoriser': ['En attente d’autorisation', 'Depuis le ' + day, 'pending', 'var(--c-accent-600)'], 'En attente': ['En attente des fonds du promoteur', 'Depuis le ' + day, 'pending', 'var(--c-accent-600)'], 'Remboursé': ['Remboursement effectué', x.date, 'undo', 'var(--c-purple)'] }[x.st] || ['Statut : ' + x.st, x.date, 'info', 'var(--c-subtle)']);
      pm.isPaiement = true;
      pm.px = {
        ref: x.ref, amount: fmt(x.amount), st: x.st, tn: tn(x.st), type: x.type, moyen: x.moyen, date: x.date, ch: x.ch,
        back: R(base + '/paiements'), backLabel: 'Retour aux paiements de ' + p.name,
        payer: [kv('domain', 'Payeur', p.name), kv('person', 'Ordonnateur', p.resp), kv('call', 'Téléphone', p.phone), kv('account_balance_wallet', 'Compte débité', x.moyen === 'Virement' ? 'SGBCI •••• 4471' : x.moyen + ' •••• 1063')],
        benef: [kv('person', 'Bénéficiaire', x.benef), kv('construction', 'Chantier', x.ch), kv('category', 'Nature', x.type), kv('account_balance_wallet', 'Compte crédité', x.benef === 'O’CHANTIER' ? 'Compte séquestre O’CHANTIER' : (x.moyen === 'Virement' ? 'Ecobank •••• 2290' : x.moyen + ' •••• 5518'))],
        forbidden: x.type === 'Remboursement', canSplit: x.type !== 'Remboursement',
        split: [{ l: 'Montant brut', v: fmt(x.amount) + ' FCFA', c: 'transparent', fw: 400 }, { l: 'Commission O’CHANTIER (8 %)', v: com ? '− ' + fmt(com) + ' FCFA' : '—', c: 'var(--c-accent-600)', fw: 400 }, { l: 'Frais opérateur (1 %)', v: fee ? '− ' + fmt(fee) + ' FCFA' : '—', c: 'var(--c-info)', fw: 400 }, { l: 'Net versé au bénéficiaire', v: fmt(net) + ' FCFA', c: 'var(--c-primary-500)', fw: 700 }],
        bars: [{ w: (net / x.amount * 100) + '%', c: 'var(--c-primary-500)' }, { w: (com / x.amount * 100) + '%', c: 'var(--c-accent-600)' }, { w: (fee / x.amount * 100) + '%', c: 'var(--c-info)' }],
        history: H.map(([l, when, icon, c]) => ({ l, when, icon, c })),
        receipt: () => { this.download(`recu-${x.ref}.html`, this.receiptHtml(p, x, com, fee, net)); this.logEv(p, 'a téléchargé un reçu', x.ref, 'download', 'neutral', 'paiements/' + x.ref); this.toast(`Reçu ${x.ref} téléchargé.`); },
        canAuth: x.st === 'À autoriser', authorize: () => this.openModal({ tone: 'success', icon: 'verified', title: 'Autoriser ce paiement ?', body: `${fmt(x.amount)} FCFA seront versés à ${x.benef} via ${x.moyen}.`, ok: 'Autoriser', after: () => { x.st = 'Réussi'; this.logEv(p, 'a autorisé un paiement', x.ref, 'payments', 'success', 'paiements/' + x.ref); this.bump(); this.toast('Paiement autorisé.'); } }),
        canRetry: x.st === 'Échoué', retry: () => { x.st = 'En traitement'; this.logEv(p, 'a relancé un paiement', x.ref, 'refresh', 'info', 'paiements/' + x.ref); this.bump(); this.toast('Paiement relancé auprès de l’opérateur.'); }
      };
      res.title = 'Détail du paiement'; res.subtitle = '';
      res.crumbs = crumbs([['Promoteurs', R('/promoteurs')], [p.name, R(base)], ['Paiements', R(base + '/paiements')], [x.ref]]); return res;
    }

    // ---- fiche détaillée ----
    const TABS = [['', 'Vue générale', 'vue'], ['kyc', 'Entreprise & KYC', 'kyc'], ['chantiers', 'Chantiers', 'ch'], ['offres', 'Besoins & offres', 'of'], ['paiements', 'Paiements', 'pay'], ['avis', 'Avis & litiges', 'av'], ['activite', 'Activité', 'act']];
    const tabKey = sub === 'litiges' ? 'avis' : sub;
    const TB = TABS.find(t => t[0] === tabKey) || TABS[0], tab = TB[2];
    const kyc = this.kycOf(p);
    const paid = p.pay.filter(x => x.st === 'Réussi' && x.type !== 'Commission').reduce((a, x) => a + x.amount, 0);
    const openLit = p.lit.filter(x => x.st !== 'Résolu').length;
    pm.isDetail = true;
    pm.p = { name: p.name, resp: p.resp, fonction: p.fonction || 'Représentant', st: p.status, stTn: tn(p.status), kyc: 'KYC · ' + kyc, kycTn: tn(kyc), kycIcon: this.KYCI[kyc],
      meta: [{ icon: 'category', l: 'Type de compte', v: p.type }, { icon: 'location_on', l: 'Localisation', v: p.loc }, { icon: 'event', l: 'Inscrit le', v: p.inscrit }, { icon: 'history', l: 'Dernière activité', v: p.last }],
      canSuspend: p.status === 'Actif', canReactivate: p.status === 'Suspendu', canValidate: p.status === 'À valider', isSuspended: p.status === 'Suspendu', suspendText: `Depuis le ${p.suspDate || '—'} · Motif : ${p.suspReason || 'non précisé'}` };
    const addDoc = type => this.openModal({ kind: 'doc', icon: 'upload_file', title: 'Ajouter un document KYC', body: `Le document sera ajouté au dossier de ${p.name} avec le statut « À vérifier ».`, ok: 'Ajouter le document', docType: type, after: v => { const d = p.docs.find(y => y.type === v.docType); let exp = 'Sans expiration'; if (v.exp) { try { exp = new Date(v.exp).toLocaleDateString('fr-FR', { day: '2-digit', month: 'short', year: 'numeric' }); } catch (e) {} } Object.assign(d, { ref: v.ref.trim(), added: '23 sept. 2026', exp, st: 'À vérifier', motif: '' }); this.logEv(p, 'a ajouté un document KYC', v.docType, 'upload_file', 'info', 'kyc/' + d.id); this.bump(); this.toast('Document ajouté · en attente de vérification.'); } });
    Object.assign(pm, {
      edit: R(base + '/modifier'), message: () => this.notifPromo(p), suspend: () => this.suspPromo(p), reactivate: () => this.reactPromo(p), validateAccount: () => this.validPromo(p),
      exportFiche: () => { this.download(`fiche-${id}.html`, this.ficheHtml(p)); this.logEv(p, 'a exporté la fiche', '', 'download', 'neutral', ''); this.bump(); this.toast('Fiche exportée.'); },
      moreOpen: !!s.more, toggleMore: () => this.setState({ more: !s.more }),
      moreItems: [['upload_file', 'Ajouter un document KYC', () => addDoc('Attestation fiscale')], ['domain', 'Modifier les informations de l’entreprise', R(base + '/modifier/entreprise')], ['history', 'Voir l’activité', R(base + '/activite')], ['content_copy', 'Copier l’identifiant', () => { try { navigator.clipboard.writeText(id); } catch (e) {} this.toast('Identifiant « ' + id + ' » copié.'); }]].map(([icon, label, fn]) => ({ icon, label, color: 'var(--c-ink)', go: () => { this.setState({ more: false }); fn(); } })),
      kpis: [this.kpi({ label: 'Nombre de chantiers', value: String(p.ch.length), icon: 'construction', tn: 'g', hint: p.ch.filter(c => c.st === 'En cours').length + ' en cours' }), this.kpi({ label: 'Besoins publiés', value: String(p.of.filter(o => o.st !== 'Brouillon').length), icon: 'assignment', tn: 'b', hint: p.of.filter(o => o.st === 'Ouverte' || o.st === 'Candidatures reçues').length + ' ouverts aux candidatures' }), this.kpi({ label: 'Montant total payé', value: fmt(paid), unit: 'FCFA', icon: 'payments', tn: 'a', hint: p.pay.filter(x => x.st === 'Réussi').length + ' transactions réussies' }), this.kpi({ label: 'Litiges ouverts', value: String(openLit), icon: 'gavel', tn: openLit ? 'r' : 'g', hint: p.lit.length + ' au total', warn: openLit > 0 })],
      goKyc: R(base + '/kyc'), editCompany: R(base + '/modifier/entreprise'), addDoc: () => addDoc('Attestation fiscale'),
      tabLoad: !!s.tabLoad, skel: [1, 2, 3, 4]
    });
    const needKyc = p.docs.filter(d => d.st !== 'Vérifié').length;
    const counts = { '': '', kyc: needKyc || '', chantiers: p.ch.length, offres: p.of.length, paiements: p.pay.length, avis: p.av.length + p.lit.length, activite: '' };
    pm.tabs = TABS.map(([k, l]) => { const on = k === TB[0], warn = k === 'kyc'; return { label: l, on, count: String(counts[k]), hasCount: counts[k] !== '', go: R(base + (k ? '/' + k : '')), line: on ? 'var(--c-primary)' : 'transparent', fg: on ? 'var(--c-primary)' : 'var(--c-muted)', cBg: on ? 'var(--c-primary)' : (warn ? 'var(--c-warning-bg)' : 'var(--c-neutral-bg)'), cFg: on ? 'var(--c-on-dark)' : (warn ? 'var(--c-warning-fg)' : 'var(--c-neutral-fg)') }; });
    const vis = !s.tabLoad;
    pm.showVue = vis && tab === 'vue'; pm.showKyc = vis && tab === 'kyc'; pm.showAct = vis && tab === 'act'; pm.showTable = vis && ['ch', 'of', 'pay', 'av'].includes(tab);
    res.title = 'Détail du promoteur'; res.subtitle = '';
    res.crumbs = crumbs([['Promoteurs', R('/promoteurs')], [p.name, tab === 'vue' ? null : R(base)], ...(tab === 'vue' ? [['Vue générale']] : [[TB[1]]])]);

    const dlDoc = d => { this.download(`${d.type.normalize('NFD').replace(/[^a-zA-Z0-9]+/g, '-')}-${id}.txt`, `${d.type}\nRéférence : ${d.ref}\nPromoteur : ${p.name}\nStatut : ${d.st}`, 'text/plain;charset=utf-8'); this.toast('Téléchargement lancé.'); };
    const valDoc = d => this.openModal({ tone: 'success', icon: 'verified', title: 'Valider ce document ?', body: `« ${d.type} » (${d.ref}) sera marqué comme vérifié.`, ok: 'Valider le document', after: () => { d.st = 'Vérifié'; d.motif = ''; this.logEv(p, 'a validé un document KYC', d.type, 'verified', 'success', 'kyc/' + d.id); this.setState({ docOk: d.id }); this.bump(); this.toast('Document validé.'); } });
    const rejDoc = d => this.openModal({ kind: 'reason', tone: 'danger', icon: 'cancel', title: 'Rejeter ce document ?', body: `Le promoteur sera invité à déposer une nouvelle version de « ${d.type} ».`, ok: 'Rejeter le document', reasonLabel: 'Motif du rejet *', motifs: ['Document illisible', 'Document expiré', 'Informations incohérentes', 'Mauvais document', 'Autre'], after: v => { d.st = 'Rejeté'; d.motif = v.motif + ' — ' + v.reason.trim(); this.logEv(p, 'a rejeté un document KYC', d.type, 'cancel', 'danger', 'kyc/' + d.id); this.setState({ docOk: null }); this.bump(); this.toast('Document rejeté · le promoteur a été notifié.'); } });
    const canV = d => d.st === 'À vérifier', canR = d => d.st === 'À vérifier' || d.st === 'Vérifié';
    const docs = p.docs.map((d, i) => ({ ...d, req: i < 4 ? 'Obligatoire' : 'Facultatif', tn: tn(d.st), icon: this.KYCI[d.st], isMissing: d.st === 'Manquant', hasFile: d.st !== 'Manquant', expC: d.st === 'Expiré' ? 'var(--c-danger-fg)' : 'var(--c-ink-2)', expW: d.st === 'Expiré' ? 700 : 400,
      preview: R(`${base}/kyc/${d.id}`), download: () => dlDoc(d), validate: () => { if (canV(d)) valDoc(d); }, reject: () => { if (canR(d)) rejDoc(d); }, noV: !canV(d), noR: !canR(d), vOp: canV(d) ? 1 : 0.3, rOp: canR(d) ? 1 : 0.3, vCur: canV(d) ? 'pointer' : 'not-allowed', rCur: canR(d) ? 'pointer' : 'not-allowed', add: () => addDoc(d.type) }));
    const acts = this.actsFor(p);
    const EV = { admin: ['Administrateur', 'p'], promoter: ['Promoteur', 'b'], system: ['Système', 'n'] };
    const evVm = e => ({ ...e, iBg: `var(--c-${e.tn}-bg)`, iFg: `var(--c-${e.tn}-fg)`, when: e.d + ' · ' + e.t, kindL: EV[e.kind][0], kindTn: EV[e.kind][1], hasTarget: !!e.target, hasLink: !!e.link, go: e.link ? R(base + '/' + e.link) : null });

    if (tab === 'vue') {
      const alerts = [];
      p.docs.forEach(d => { if (d.st === 'Expiré') alerts.push(['danger', 'event_busy', `${d.type} expiré`, `depuis le ${d.exp}`, 'Voir le document', R(`${base}/kyc/${d.id}`)]); });
      p.docs.slice(0, 4).forEach(d => { if (d.st === 'Manquant') alerts.push(['warning', 'upload_file', 'Document KYC manquant', d.type, 'Ajouter', () => addDoc(d.type)]); });
      if (openLit) alerts.push(['warning', 'gavel', `${openLit} litige${openLit > 1 ? 's' : ''} ouvert${openLit > 1 ? 's' : ''}`, p.lit.filter(x => x.st !== 'Résolu').map(x => x.ref).join(', '), 'Voir les litiges', R(base + '/litiges')]);
      const auth = p.pay.filter(x => x.st === 'À autoriser');
      if (auth.length) alerts.push(['info', 'payments', `${auth.length} paiement${auth.length > 1 ? 's' : ''} à autoriser`, auth.map(x => x.ref + ' · ' + fmt(x.amount) + ' FCFA').join(', '), 'Traiter', R(`${base}/paiements/${auth[0].id}`)]);
      const openOf = p.of.filter(o => ['Ouverte', 'Candidatures reçues', 'À valider'].includes(o.st));
      const nv = p.docs.filter(d => d.st === 'Vérifié').length;
      pm.vue = {
        alerts: alerts.map(([t, icon, title, d, cta, go]) => ({ bg: `var(--c-${t}-bg)`, fg: `var(--c-${t}-fg)`, icon, t: title, d, cta, go })), hasAlerts: alerts.length > 0,
        contact: [kv('person', 'Représentant', p.resp + (p.fonction ? ' · ' + p.fonction : '')), kv('call', 'Téléphone', p.phone), kv('mail', 'E-mail', p.email), kv('location_on', 'Siège', p.siege)],
        company: [kv('domain', 'Raison sociale', p.name), kv('gavel', 'Forme juridique', p.forme), kv('tag', 'RCCM', p.rccm), kv('category', 'Secteur', p.secteur)],
        kycLabel: nv + ' / 5', kycW: (nv / 5 * 100) + '%', kycItems: docs.map(d => ({ type: d.type, st: d.st, tn: d.tn, icon: d.icon })),
        ch: p.ch.slice(0, 3).map(c => ({ name: c.name, loc: c.loc, st: c.st, tn: tn(c.st), pL: c.pct + ' %', w: c.pct + '%', barC: c.pct < 50 ? 'var(--c-accent-600)' : 'var(--c-primary-500)', go: R(`${base}/chantiers/${c.id}`) })), noCh: !p.ch.length, goCh: R(base + '/chantiers'),
        pay: p.pay.slice(0, 3).map(x => ({ ref: x.ref, date: x.date, amount: fmt(x.amount) + ' FCFA', st: x.st, tn: tn(x.st), go: R(`${base}/paiements/${x.id}`) })), noPay: !p.pay.length, goPay: R(base + '/paiements'),
        of: openOf.slice(0, 3).map(o => ({ name: o.name, sub: o.ch + ' · ' + o.metier, st: o.st, tn: tn(o.st), go: R(`${base}/offres/${o.id}`) })), noOf: !openOf.length, goOf: R(base + '/offres'),
        feed: acts.slice(0, 4).map(evVm), goAct: R(base + '/activite')
      };
    }
    if (tab === 'kyc') {
      const nv = p.docs.filter(d => d.st === 'Vérifié').length;
      pm.kyc = {
        info: [['Raison sociale', p.name], ['Forme juridique', p.forme], ['N° d’immatriculation', p.rccm], ['N° de contribuable', p.ncc], ['Adresse du siège', p.siege], ['Secteur d’activité', p.secteur], ['Représentant', p.resp], ['Fonction', p.fonction], ['Téléphone', p.phone], ['Adresse e-mail', p.email]].map(([k, v]) => ({ k, v: v || '—' })),
        summary: `${nv} / 5 vérifiés · ${needKyc} à traiter`, docs
      };
    }
    if (tab === 'act') {
      const F = s.actF || 'Tous', MAP = { 'Administrateur': 'admin', 'Promoteur': 'promoter', 'Système': 'system' };
      const list = acts.filter(e => F === 'Tous' || e.kind === MAP[F]);
      const groups = []; list.forEach(e => { let g = groups.find(x => x.day === e.d); if (!g) groups.push(g = { day: e.d === '23 sept. 2026' ? 'Aujourd’hui · 23 sept. 2026' : e.d, key: e.d, items: [] }); g.items.push(evVm(e)); });
      const err = p.actError && !(s.actOk || {})[id];
      pm.act = { isError: err, isOk: !err, retry: () => { this.setState({ actOk: { ...(s.actOk || {}), [id]: true }, tabLoad: true }); clearTimeout(this._tl); this._tl = setTimeout(() => this.setState({ tabLoad: false }), 600); },
        filters: ['Tous', 'Administrateur', 'Promoteur', 'Système'].map(l => ({ label: l, go: () => this.setState({ actF: l }), bg: l === F ? 'var(--c-primary)' : 'var(--c-surface)', fg: l === F ? 'var(--c-on-dark)' : 'var(--c-ink-2)', border: l === F ? 'var(--c-primary)' : 'var(--c-line-strong)' })),
        groups, empty: !groups.length };
    }
    if (pm.showTable || ['ch', 'of', 'pay', 'av'].includes(tab)) {
      const stop = fn => e => { e && e.stopPropagation && e.stopPropagation(); fn(); };
      const chip = s.pfCh;
      const mkRow = (cells, open, acts, menuKey, menu) => { const mo = menuKey && s.rowMenu === menuKey; return { cells: cells.map(c => this.norm(c)), open: open || null, cursor: open ? 'pointer' : 'default', acts: acts.map(a => ({ ...a, go: stop(a.go) })).concat(menu ? [{ icon: 'more_vert', label: 'Plus d’actions', go: stop(() => this.setState({ rowMenu: mo ? null : menuKey })) }] : []), menuOpen: !!mo, menu: (menu || []).map(([icon, label, fn]) => ({ icon, label, color: 'var(--c-ink)', go: stop(() => { this.setState({ rowMenu: null }); fn(); }) })) }; };
      let t = { cols: [], rows: [], hasSub: false, hasChip: false, partial: false };
      if (tab === 'ch') {
        t.cols = ['Chantier', 'Localisation', 'Type de construction', 'Période', 'Ouvriers', 'Progression', 'Statut'];
        t.rows = p.ch.map(c => { const go = R(`${base}/chantiers/${c.id}`); return mkRow([{ k: 'thumb', v: c.name }, { k: 'loc', v: c.loc }, { k: 'txt', v: c.type }, { k: 'txt', v: c.period }, { k: 'txt', v: String(c.ouv) }, { k: 'prog', v: c.pct }, { k: 'bdg', v: c.st, tn: tn(c.st) }], go, [{ icon: 'visibility', label: 'Voir le chantier', go }, { icon: 'edit', label: 'Modifier', go: () => this.toast('L’édition du chantier se fait depuis le module Chantiers.') }], 'c' + c.id, [['assignment', 'Consulter les besoins', () => { this.route(base + '/offres'); this.setState({ pfCh: c.name }); }], ['schedule', 'Consulter les pointages', () => this.go('pointages')], ['payments', 'Consulter les paiements', () => { this.route(base + '/paiements'); this.setState({ pfCh: c.name }); }]]); });
        Object.assign(t, { countLabel: `${p.ch.length} chantier${p.ch.length > 1 ? 's' : ''}`, emptyIcon: 'construction', emptyTitle: 'Aucun chantier', emptyText: 'Ce promoteur n’a encore créé aucun chantier.' });
      }
      if (tab === 'of') {
        const list = p.of.filter(o => !chip || o.ch === chip);
        t.cols = ['Besoin / Offre', 'Chantier', 'Métier', 'Type', 'Candidatures', 'Budget', 'Échéance', 'Statut'];
        t.rows = list.map(o => { const go = R(`${base}/offres/${o.id}`); return mkRow([{ k: 'txt', v: o.name, b: 1 }, { k: 'txt', v: o.ch }, { k: 'txt', v: o.metier }, { k: 'bdg', v: o.type, tn: tn(o.type) }, { k: 'txt', v: o.cand + ' candidat' + (o.cand > 1 ? 's' : '') }, { k: 'txt', v: o.budget }, { k: 'txt', v: o.due }, { k: 'bdg', v: o.st, tn: tn(o.st) }], go, [{ icon: 'visibility', label: 'Voir l’offre', go }]); });
        Object.assign(t, { hasChip: !!chip, chip, clearChip: () => this.setState({ pfCh: null }), countLabel: `${list.length} besoin${list.length > 1 ? 's' : ''} et offres`, emptyIcon: 'assignment', emptyTitle: chip ? 'Aucun besoin pour ce chantier' : 'Aucun besoin', emptyText: chip ? 'Retirez le filtre pour voir tous les besoins du promoteur.' : 'Ce promoteur n’a encore publié aucun besoin.' });
      }
      if (tab === 'pay') {
        const list = p.pay.filter(x => !chip || x.ch === chip);
        const proc = list.filter(x => x.st === 'En traitement');
        t.cols = ['Référence', 'Chantier', 'Type de paiement', 'Moyen', 'Montant', 'Commission', 'Date', 'Statut'];
        t.rows = list.map(x => { const go = R(`${base}/paiements/${x.id}`); return mkRow([{ k: 'txt', v: x.ref, m: 1 }, { k: 'txt', v: x.ch }, { k: 'txt', v: x.type }, { k: 'txt', v: x.moyen }, { k: 'txt', v: fmt(x.amount) + ' FCFA', b: 1 }, { k: 'txt', v: x.com ? fmt(x.com) + ' FCFA' : '—', m: !x.com }, { k: 'txt', v: x.date }, { k: 'bdg', v: x.st, tn: tn(x.st) }], go, [{ icon: 'visibility', label: 'Voir le détail', go }, { icon: 'receipt', label: 'Télécharger le reçu', go: () => { const com = x.com, fee = (x.type === 'Commission' || x.type === 'Remboursement') ? 0 : Math.round(x.amount * 0.01); this.download(`recu-${x.ref}.html`, this.receiptHtml(p, x, com, fee, x.amount - com - fee)); this.toast(`Reçu ${x.ref} téléchargé.`); } }]); });
        Object.assign(t, { hasChip: !!chip, chip, clearChip: () => this.setState({ pfCh: null }), partial: proc.length > 0, partialText: `${proc.length} transaction${proc.length > 1 ? 's' : ''} en cours de synchronisation avec l’opérateur (${proc.map(x => x.moyen).join(', ')}). Montants et statuts susceptibles d’évoluer.`, countLabel: `${list.length} transaction${list.length > 1 ? 's' : ''}`, emptyIcon: 'receipt_long', emptyTitle: chip ? 'Aucun paiement pour ce chantier' : 'Aucun paiement', emptyText: chip ? 'Retirez le filtre pour voir toutes les transactions.' : 'Aucune transaction n’a encore été enregistrée pour ce promoteur.' });
      }
      if (tab === 'av') {
        const isLit = sub === 'litiges';
        t.hasSub = true;
        t.subs = [['Avis', p.av.length, base + '/avis', !isLit], ['Litiges', p.lit.length, base + '/litiges', isLit]].map(([label, count, r, on]) => ({ label, count: String(count), go: R(r), bg: on ? 'var(--c-surface)' : 'transparent', fg: on ? 'var(--c-primary)' : 'var(--c-muted)', sh: on ? 'var(--shadow-xs)' : 'none' }));
        if (!isLit) {
          t.cols = ['Ouvrier / équipe évalué(e)', 'Chantier', 'Note', 'Commentaire', 'Date', 'Modération'];
          t.rows = p.av.map(a => mkRow([{ k: 'per', name: a.who, sub: a.sub, kind: /^Équipe/.test(a.who) ? 't' : '' }, { k: 'txt', v: a.ch }, { k: 'rate', v: a.note + ' / 5' }, { k: 'txt', v: a.comment }, { k: 'txt', v: a.date }, { k: 'bdg', v: a.st, tn: tn(a.st) }], null, [{ icon: 'check', label: 'Publier', go: () => { a.st = 'Publié'; this.bump(); this.toast('Avis publié.'); } }, { icon: 'visibility_off', label: 'Masquer', go: () => { a.st = 'Masqué'; this.bump(); this.toast('Avis masqué.'); } }]));
          Object.assign(t, { countLabel: `${p.av.length} avis`, emptyIcon: 'reviews', emptyTitle: 'Aucun avis', emptyText: 'Ce promoteur n’a encore évalué aucun ouvrier ni aucune équipe.' });
        } else {
          t.cols = ['Référence', 'Parties concernées', 'Chantier', 'Motif', 'Priorité', 'Ouverture', 'Responsable', 'Statut'];
          t.rows = p.lit.map(l => { const go = () => this.go('avis'); return mkRow([{ k: 'txt', v: l.ref, link: 1 }, { k: 'stk', v: p.name, sub: '↔ ' + l.other }, { k: 'txt', v: l.ch }, { k: 'txt', v: l.motif }, { k: 'bdg', v: l.prio, tn: tn(l.prio) }, { k: 'txt', v: l.date }, { k: 'txt', v: l.owner }, { k: 'bdg', v: l.st, tn: tn(l.st) }], go, [{ icon: 'open_in_new', label: 'Ouvrir dans Avis & litiges', go }]); });
          Object.assign(t, { countLabel: `${p.lit.length} litige${p.lit.length > 1 ? 's' : ''}`, emptyIcon: 'gavel', emptyTitle: 'Aucun litige', emptyText: 'Aucun litige n’a été ouvert avec ce promoteur.' });
        }
      }
      t.hasRows = t.rows.length > 0; t.empty = t.rows.length === 0;
      pm.t = t;
    }
    // drawers
    if (tab === 'kyc' && subId) {
      const d = docs.find(x => x.id === subId);
      if (d) {
        const dd = { ...d, showSuccess: s.docOk === d.id && d.st === 'Vérifié', isExpired: d.st === 'Expiré', isRejected: d.st === 'Rejeté', expText: `Expiré depuis le ${d.exp}. Le promoteur doit transmettre une version à jour.`, askUpdate: () => this.notifPromo(p, `Mise à jour requise : ${d.type}`),
          meta: [['N° / référence', d.ref], ['Ajouté le', d.added], ['Expiration', d.exp], ['Statut', d.st], ['Exigence', d.req]].concat(d.motif ? [['Motif du rejet', d.motif]] : []).map(([k, v]) => ({ k, v })) };
        const A = [];
        if (d.hasFile) A.push({ label: 'Télécharger', icon: 'download', variant: 'secondary', go: d.download });
        if (canR(d)) A.push({ label: 'Rejeter', icon: 'cancel', variant: 'danger-soft', go: d.reject });
        if (canV(d)) A.push({ label: 'Valider', icon: 'check', variant: 'primary', go: d.validate });
        if (d.isMissing || d.isExpired || d.isRejected) A.push({ label: d.isMissing ? 'Ajouter le document' : 'Remplacer', icon: 'upload_file', variant: 'primary', go: d.add });
        res.drawer = { open: true, isDoc: true, isOffre: false, kicker: 'Aperçu du document · ' + p.name, title: d.type, close: R(base + '/kyc'), doc: dd, actions: A };
      }
    }
    if (tab === 'of' && subId) {
      const o = p.of.find(x => x.id === subId);
      if (o) {
        const CN = [['Koffi Adjoumani', 'Maçon · 8 ans', '96 %'], ['Mamadou Koné', 'Plombier · 6 ans', '92 %'], ['Issa Traoré', 'Électricien · 5 ans', '89 %']];
        const A = [{ label: 'Voir dans Besoins & offres', icon: 'open_in_new', variant: 'secondary', go: () => this.go('besoins') }];
        if (o.st === 'À valider') A.push({ label: 'Valider l’offre', icon: 'check', variant: 'primary', go: () => this.openModal({ tone: 'success', icon: 'verified', title: 'Valider cette offre ?', body: `« ${o.name} » sera publiée et visible par les ouvriers correspondant au métier ${o.metier}.`, ok: 'Valider l’offre', after: () => { o.st = 'Validée'; this.logEv(p, 'a validé une offre', o.name, 'verified', 'success', 'offres/' + o.id); this.bump(); this.toast('Offre validée.'); } }) });
        res.drawer = { open: true, isDoc: false, isOffre: true, kicker: 'Fiche de l’offre · ' + p.name, title: o.name, close: R(base + '/offres'), actions: A,
          of: { st: o.st, tn: tn(o.st), type: o.type, typeTn: tn(o.type), meta: [['Chantier', o.ch], ['Métier', o.metier], ['Budget', o.budget], ['Échéance', o.due], ['Candidatures', String(o.cand)]].map(([k, v]) => ({ k, v })), cands: CN.slice(0, Math.min(3, o.cand)).map(([name, sub, score]) => ({ name, sub, score })), noCand: !o.cand, hasMore: o.cand > 3, more: `+ ${o.cand - 3} autres candidatures` } };
      }
    }
    return res;
  }

});
