'use strict';
// Module Tableau de bord : KPI, activité, flux, notifications
// Couche de la classe Component : voir src/js/och.js (OCH.extend).
OCH.extend(Base => class extends Base {
  AL = { g: 'success', a: 'warning', o: 'orange', r: 'danger', b: 'info', p: 'purple', n: 'neutral' };
  DCH = [
    { id: 'd1', name: 'Résidence Les Oliviers', promo: 'SICOGI', loc: 'Cocody', pct: 75, ouv: '24 / 30', st: 'En cours', tn: 'g', period: 'Janv. 2024 – Déc. 2026', type: 'Résidentiel collectif', link: '/promoteurs/sicogi/chantiers/ch1', pid: 'sicogi' },
    { id: 'd2', name: 'Tour Horizon', promo: 'Ivoire Properties', loc: 'Plateau', pct: 45, ouv: '18 / 40', st: 'En cours', tn: 'g', period: 'Mars 2025 – Juin 2027', type: 'Bureaux' },
    { id: 'd3', name: 'Cité Belle Rive', promo: 'SAPH', loc: 'Bingerville', pct: 20, ouv: '12 / 50', st: 'En retard', tn: 'r', period: 'Févr. 2025 – Mars 2027', type: 'Logement social' },
    { id: 'd4', name: 'Complexe scolaire SA', promo: 'Fonds Éducation CI', loc: 'Yamoussoukro', pct: 90, ouv: '36 / 40', st: 'Presque terminé', tn: 'b', period: 'Sept. 2024 – Oct. 2026', type: 'Équipement public' }
  ];
  WK = {
    'Koffi Adjoumani': { job: 'Maçon', exp: '8 ans', rate: '12 000 FCFA/jour', avail: 'Disponible', note: '4,8 / 5', st: 'Vérifié', loc: 'Cocody' },
    'Diallo Fatoumata': { job: 'Manœuvre', exp: '3 ans', rate: '7 000 FCFA/jour', avail: 'Disponible', note: '4,6 / 5', st: 'Vérifié', loc: 'Yopougon' },
    'Traoré Ibrahima': { job: 'Électricien', exp: '6 ans', rate: '11 000 FCFA/jour', avail: 'Disponible', note: '4,5 / 5', st: 'Vérifié', loc: 'Abobo' },
    'Mamadou Koné': { job: 'Plombier', exp: '7 ans', rate: '11 000 FCFA/jour', avail: 'En mission', note: '4,7 / 5', st: 'Vérifié', loc: 'Abobo' },
    'Issa Traoré': { job: 'Électricien', exp: '5 ans', rate: '10 000 FCFA/jour', avail: 'Disponible', note: '4,6 / 5', st: 'Vérifié', loc: 'Yopougon' }
  };
  ACT = {
    '7j': { l: ['Lun 15', 'Mar 16', 'Mer 17', 'Jeu 18', 'Ven 19', 'Sam 20', 'Dim 21'], d: ['Lundi 15 sept. 2026', 'Mardi 16 sept. 2026', 'Mercredi 17 sept. 2026', 'Jeudi 18 sept. 2026', 'Vendredi 19 sept. 2026', 'Samedi 20 sept. 2026', 'Dimanche 21 sept. 2026'], a: [19, 20, 21, 22, 22, 23, 24], b: [3, 2, 4, 1, 3, 1, 2] },
    '30j': { l: ['23 août', '28 août', '2 sept.', '7 sept.', '12 sept.', '17 sept.', '21 sept.'], d: ['23 août 2026', '28 août 2026', '2 sept. 2026', '7 sept. 2026', '12 sept. 2026', '17 sept. 2026', '21 sept. 2026'], a: [16, 17, 19, 20, 21, 23, 24], b: [5, 4, 6, 5, 7, 6, 8] },
    '3m': { l: ['Juil.', 'Mi-juil.', 'Août', 'Mi-août', 'Sept.', 'Mi-sept.', 'Auj.'], d: ['1er juil. 2026', '15 juil. 2026', '1er août 2026', '15 août 2026', '1er sept. 2026', '15 sept. 2026', '23 sept. 2026'], a: [12, 14, 15, 18, 20, 22, 24], b: [9, 10, 8, 12, 11, 13, 14] }
  };
  ACT_CAP = { '7j': 'Du 15 au 21 septembre 2026 · valeurs journalières', '30j': 'Du 23 août au 21 septembre 2026 · valeurs tous les 5 jours', '3m': 'De juillet à septembre 2026 · valeurs bimensuelles' };
  FX = {
    auj: { l: ['08h', '10h', '12h', '14h', '16h', '18h', '20h'], enc: [0.8, 1.6, 2.2, 1.1, 2.9, 1.4, 0.6], rev: [0.4, 0.9, 1.3, 0.7, 1.6, 0.8, 0.3], pend: 3.2 },
    '7j': { l: ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'], enc: [4.2, 5.1, 3.8, 4.6, 6.2, 2.9, 1.7], rev: [2.3, 2.8, 2.1, 2.6, 3.4, 1.5, 0.9], pend: 12.45 },
    '30j': { l: ['25 août', '29 août', '2 sept.', '6 sept.', '10 sept.', '14 sept.', '18 sept.'], enc: [14.2, 16.8, 15.1, 18.4, 17.2, 19.6, 21.5], rev: [7.6, 9.1, 8.4, 10.2, 9.3, 10.8, 11.9], pend: 18.9 },
    '3m': { l: ['Juil.', 'Mi-juil.', 'Août', 'Mi-août', 'Sept.', 'Mi-sept.', 'Auj.'], enc: [38, 41, 36, 44, 47, 52, 28], rev: [21, 23, 20, 24, 26, 29, 15], pend: 24.6 }
  };
  NT = [
    { id: 'n1', icon: 'person_check', tn: 'warning', t: '8 profils ouvriers à valider', d: 'Pièces d’identité et certificats en attente', prio: 'Urgent', when: 'Il y a 10 min', go: () => this.goFilter('ouvriers', ['À valider']) },
    { id: 'n2', icon: 'payments', tn: 'info', t: 'Paiement PAY-260918-1702 à autoriser', d: '2 400 000 FCFA · Équipe Menuiserie CI', prio: 'Élevé', when: 'Il y a 45 min', go: () => this.route('/promoteurs/sicogi/paiements/PAY-260918-1702') },
    { id: 'n3', icon: 'gavel', tn: 'danger', t: 'Litige LIT-0102 escaladé', d: 'Priorité haute · Logements sociaux Yopougon', prio: 'Urgent', when: 'Il y a 2 h', go: () => this.route('/promoteurs/sicogi/litiges') },
    { id: 'n4', icon: 'swap_horiz', tn: 'orange', t: 'Remplacement urgent demandé', d: 'Tour Horizon · 2 électriciens absents', prio: 'Élevé', when: 'Il y a 3 h', go: () => this.goFilter('affectations', ['Remplacement', 'Remplacements']) },
    { id: 'n5', icon: 'event_busy', tn: 'danger', t: 'Attestation fiscale expirée', d: 'Bâtir Afrique · document KYC', prio: 'Moyen', when: 'Hier · 16:20', go: () => this.route('/promoteurs/batir-afrique/kyc') },
    { id: 'n6', icon: 'construction', tn: 'success', t: 'Nouveau chantier créé', d: 'Extension Cité Verte · SICOGI', prio: 'Moyen', when: 'Hier · 11:05', go: () => this.route('/promoteurs/sicogi/chantiers/ch6') },
    { id: 'n7', icon: 'task_alt', tn: 'purple', t: 'Affectation confirmée', d: 'Koffi Adjoumani · Résidence Les Oliviers', prio: 'Moyen', when: '21 sept. · 14:30', go: () => this.goFilter('affectations', ['Confirmées']) }
  ];
  FEED = [
    ['construction', 'success', 'Nouveau chantier créé', 'Extension Cité Verte', 'SICOGI · M. Konan', 'Il y a 28 min', () => this.route('/promoteurs/sicogi/chantiers/ch6')],
    ['verified', 'success', 'Ouvrier validé', 'Koffi Adjoumani · Maçon', 'N. Yao', 'Il y a 1 h', () => this.openWorker('Koffi Adjoumani')],
    ['payments', 'warning', 'Paiement autorisé', 'PAY-260922-1842 · 3 750 000 FCFA', 'N. Yao', 'Il y a 2 h', () => this.route('/promoteurs/sicogi/paiements/PAY-260922-1842')],
    ['gavel', 'danger', 'Litige ouvert', 'LIT-0102 · Logements sociaux Yopougon', 'Équipe Bâtir Pro', 'Il y a 3 h', () => this.route('/promoteurs/sicogi/litiges')],
    ['campaign', 'info', 'Offre publiée', 'Chef d’équipe gros œuvre', 'SICOGI · M. Konan', 'Il y a 5 h', () => this.route('/promoteurs/sicogi/offres/of8')],
    ['task_alt', 'purple', 'Affectation confirmée', 'Issa Traoré → Tour Horizon', 'A. Bamba', 'Hier · 17:40', () => this.goFilter('affectations', ['Confirmées'])],
    ['person_add', 'info', 'Nouveau promoteur inscrit', 'Horizon BTP', 'Système', 'Hier · 09:15', () => this.route('/promoteurs/horizon-btp')],
    ['block', 'danger', 'Compte suspendu', 'Bâtir Afrique', 'N. Yao', '11 sept. · 16:20', () => this.route('/promoteurs/batir-afrique')],
    ['receipt_long', 'warning', 'Paiement échoué', 'PAY-260910-1590 · 980 000 FCFA', 'Orange Money', '10 sept. · 17:15', () => this.route('/promoteurs/sicogi/paiements/PAY-260910-1590')],
    ['verified', 'success', 'Document KYC validé', 'Registre de commerce · Ivoire Construction', 'N. Yao', '9 sept. · 10:02', () => this.route('/promoteurs/ivoire-construction/kyc')]
  ];


  dashVals(s) {
    const TN = this.TN, demo = this.demo();
    if (this._lastDemo !== demo) { const first = this._lastDemo === undefined; this._lastDemo = demo; if (!first) setTimeout(() => this.setState({ recoOk: false, errFix: {}, reco: false }), 0); }
    const offline = this.isOffline(s), limited = demo === 'Accès limité', empty = demo === 'Aucune donnée', fix = s.errFix || {};
    const errAct = demo === 'Erreur partielle' && !fix.act, errFeed = demo === 'Erreur partielle' && !fix.feed;
    const globalError = demo === 'Erreur globale' && !fix.global;
    const initLoading = !globalError && (demo === 'Chargement initial' || !!s.dLoad);
    const sideW = s.side ? 76 : 252, cw = Math.min((s.vw || 1728) - sideW, 1560) - 64;
    const wide = cw >= 1060, sp = n => String(wide ? n : 12);
    const kpiCols = cw >= 980 ? 4 : cw >= 480 ? 2 : 1;
    const G = fn => e => { e && e.stopPropagation && e.stopPropagation(); fn(); };
    const seg = (on) => ({ bg: on ? 'var(--c-primary)' : 'var(--c-surface)', fg: on ? 'var(--c-on-dark)' : 'var(--c-ink-2)' });

    const K = [
      ['Chantiers actifs', '24', '', 'construction', 'g', '+8 %', 'g', 'north_east', 'vs semaine dernière', ['chantiers', ['En cours']]],
      ['Ouvriers disponibles', '386', '', 'groups', 'g', '+12 %', 'g', 'north_east', 'vs semaine dernière', ['ouvriers', ['Disponible']]],
      ['Affectations à valider', '18', '', 'pending_actions', 'a', '+50 %', 'r', 'north_east', 'vs semaine dernière', ['affectations', ['À valider']]],
      ['Paiements en attente', '12 450 000', 'FCFA', 'account_balance_wallet', 'b', '0 %', 'n', 'east', 'stable vs semaine dernière', ['paiements', ['En attente']]]
    ];
    const kpis = K.map(([label, value, unit, icon, tn, delta, dtn, di, cmp, [mod, f]], i) => {
      const lock = limited && i === 3;
      return { label, value: empty ? '0' : lock ? '•••' : value, unit: lock ? '' : unit, icon, tn, delta: empty || lock ? '' : delta, dtn, di, cmp, hint: lock ? 'Accès réservé au profil Finance' : (empty ? 'Aucune donnée pour le moment' : ''), highlight: i === 2 && !empty,
        go: lock ? () => this.toast('Accès restreint : données financières réservées au profil Finance.') : () => this.goFilter(mod, f), aria: `${label} : ${lock ? 'masqué' : value + (unit ? ' ' + unit : '')}. Ouvrir ${mod} filtré sur « ${f[0]} »` };
    });

    const rk = s.range || '7j', cu = s.custom || {}, cd = s.cDraft || cu;
    let R = rk === 'custom' ? this.genCustom(cu.from, cu.to) : this.ACT[rk];
    if (empty) R = { l: [], d: [], a: [], b: [] };
    const n = R.a.length, max = n ? Math.max(...R.a, ...R.b) : 10, yMax = Math.max(5, Math.ceil(max * 1.2 / 5) * 5);
    const X = i => n > 1 ? i / (n - 1) * 100 : 50, Y = v => 100 - v / yMax * 100;
    const SA = n > 1 ? this.series(R.a, yMax) : { line: '', area: '' }, SB = n > 1 ? this.series(R.b, yMax) : { line: '', area: '' };
    const hp = s.hp, dots = [];
    [['a', 'Chantiers actifs', 'var(--c-primary-500)'], ['b', 'Nouveaux chantiers', 'var(--c-accent-600)']].forEach(([k, name, c]) => R[k].forEach((v, i) => { const on = hp && hp.s === k && hp.i === i; dots.push({ left: X(i) + '%', top: Y(v) + '%', c, scale: on ? 1.35 : 1, label: `${R.d[i]} · ${name} : ${v}`, enter: () => this.setState({ hp: { s: k, i } }), click: () => this.openPoint(name, R.d[i], v, k) }); }));
    let tip = { left: '0', top: '0', tx: '0', date: '', name: '', v: '', c: 'transparent' }, hasTip = false;
    if (hp && R[hp.s] && R[hp.s][hp.i] !== undefined) { const x = X(hp.i), v = R[hp.s][hp.i]; hasTip = true; tip = { left: x + '%', top: Y(v) + '%', tx: x < 12 ? '-12px' : x > 88 ? 'calc(-100% + 12px)' : '-50%', date: R.d[hp.i], name: hp.s === 'a' ? 'Chantiers actifs' : 'Nouveaux chantiers', v: String(v), c: hp.s === 'a' ? 'var(--c-primary-500)' : 'var(--c-accent-600)' }; }
    const act = {
      ranges: [['7j', '7 jours'], ['30j', '30 jours'], ['3m', '3 mois'], ['custom', 'Personnalisée']].map(([k, label]) => ({ label, on: rk === k, isCustom: k === 'custom', ...seg(rk === k), go: k === 'custom' ? () => this.setState({ pickOpen: !s.pickOpen, cDraft: { ...(s.custom || {}), err: '' } }) : () => this.setRange(k) })),
      pickOpen: !!s.pickOpen, from: cd.from || '', to: cd.to || '', hasPickErr: !!(s.cDraft && s.cDraft.err), pickErr: (s.cDraft && s.cDraft.err) || '',
      onFrom: e => this.setState({ cDraft: { ...this.state.cDraft, from: e.target.value, err: '' } }), onTo: e => this.setState({ cDraft: { ...this.state.cDraft, to: e.target.value, err: '' } }),
      pickCancel: () => this.setState({ pickOpen: false }),
      pickApply: () => { const d = this.state.cDraft || {}; if (!d.from || !d.to) return this.setState({ cDraft: { ...d, err: 'Renseignez les deux dates.' } }); if (d.from > d.to) return this.setState({ cDraft: { ...d, err: 'La date de début doit précéder la date de fin.' } }); this.setState({ custom: { from: d.from, to: d.to } }); this.setRange('custom'); },
      caption: rk === 'custom' ? `Du ${this.fmtDate(cu.from)} au ${this.fmtDate(cu.to)}` : this.ACT_CAP[rk],
      isErr: errAct, isLoad: !errAct && !!s.cLoad, isEmpty: !errAct && !s.cLoad && !n, isOk: !errAct && !s.cLoad && n > 0,
      retry: () => { this.setState({ errFix: { ...(this.state.errFix || {}), act: true } }); this.setRange(rk); },
      yTicks: [1, 0.8, 0.6, 0.4, 0.2, 0].map(k => String(Math.round(yMax * k))), xl: R.l.map((t, i) => ({ t, left: X(i) + '%', tx: i === 0 ? '0' : i === n - 1 ? '-100%' : '-50%' })),
      aLine: SA.line, aArea: SA.area, bLine: SB.line, bArea: SB.area, dots, hasTip, tip, leave: () => this.setState({ hp: null }),
      report: () => { this.go('rapports'); this.toast('Rapports · filtre « Chantiers » appliqué'); }
    };

    const P = [
      [5, 'remplacements urgents', 'Absences signalées sur 3 chantiers actifs', 'Urgent', 'swap_horiz', ['affectations', ['Remplacement', 'Remplacements']]],
      [8, 'profils ouvriers à valider', 'Pièces d’identité et certificats à vérifier', 'Élevé', 'person_check', ['ouvriers', ['À valider']]],
      [7, 'paiements à autoriser', 'Montant total : 12 450 000 FCFA', 'Élevé', 'payments', ['paiements', ['À autoriser']]],
      [3, 'litiges ouverts', 'Dont 1 escaladé en priorité haute', 'Moyen', 'gavel', ['avis', ['Ouvert', 'Litiges']]]
    ];
    const PX = [
      [4, 'pointages en anomalie', 'Écarts d’horaires à contrôler', 'Élevé', 'schedule', ['pointages', ['Anomalies']]],
      [6, 'offres sans candidature', 'Publiées depuis plus de 5 jours', 'Moyen', 'assignment', ['besoins', ['Ouverte']]],
      [2, 'pièces KYC expirées', 'Dossiers promoteurs à mettre à jour', 'Moyen', 'event_busy', null]
    ];
    const PTN = { Urgent: 'r', 'Élevé': 'o', Moyen: 'a' };
    const pItems = empty ? [] : (s.prioAll ? [...P, ...PX] : P).map(([n2, t, sub, b, icon, dest]) => ({ n: String(n2), t, s: sub, b, tn: PTN[b], icon, bg: TN[PTN[b]][0], fg: TN[PTN[b]][1], go: dest ? () => this.goFilter(dest[0], dest[1]) : () => { this.go('promoteurs'); this.setState({ pquick: 'expirees' }); } }));
    const prio = { items: pItems, isEmpty: empty, hasTotal: !empty, total: (s.prioAll ? 35 : 23) + ' à traiter', hasToggle: !empty, toggle: () => this.setState({ prioAll: !s.prioAll }), toggleLabel: s.prioAll ? 'Afficher moins' : `Voir toutes les priorités (${P.length + PX.length})`, toggleIcon: s.prioAll ? 'expand_less' : 'expand_more' };

    const rows = empty ? [] : this.DCH.map(c => { const key = 'dc' + c.id, mo = s.rowMenu === key;
      const menu = [['visibility', 'Voir le détail', () => this.openChantier(c)], c.pid ? ['apartment', 'Voir le promoteur', () => this.route('/promoteurs/' + c.pid)] : null, ['schedule', 'Consulter les pointages', () => this.go('pointages')], ['open_in_new', 'Ouvrir dans Chantiers', () => this.goQ('chantiers', c.name)]].filter(Boolean);
      return { ...c, pctL: c.pct + ' %', w: c.pct + '%', barC: c.pct < 50 ? 'var(--c-accent-600)' : 'var(--c-primary-500)', open: () => this.openChantier(c), view: G(() => this.openChantier(c)), toggle: G(() => this.setState({ rowMenu: mo ? null : key })), menuOpen: mo, menu: menu.map(([icon, label, fn]) => ({ icon, label, go: G(() => { this.setState({ rowMenu: null }); fn(); }) })) }; });
    const DI = [['En cours', 17, 'var(--c-primary-500)', ['En cours']], ['En retard', 4, 'var(--c-danger)', ['En retard']], ['Presque terminés', 3, 'var(--c-info)', ['Presque terminé']]];
    const recent = { rows, hasRows: rows.length > 0, isEmpty: !rows.length, seeAll: () => this.go('chantiers'), dist: DI.map(([l, n2, c, f]) => ({ l, n: String(n2), c, w: (n2 / 24 * 100) + '%', go: () => this.goFilter('chantiers', f) })) };

    const MP = [['Koffi Adjoumani', 'Maçon', 'Résidence Les Oliviers', 96], ['Diallo Fatoumata', 'Manœuvre', 'Cité Belle Rive', 94], ['Traoré Ibrahima', 'Électricien', 'Tour Horizon', 91]];
    const MX = [...MP, ['Mamadou Koné', 'Plombier', 'Cité Belle Rive', 89], ['Issa Traoré', 'Électricien', 'Tour Horizon', 87]];
    const match = { isEmpty: empty, isOk: !empty, profiles: MP.map(([name, job, ch, sc]) => ({ name, job, sub: 'pour ' + ch, score: sc + ' %', w: sc + '%', go: () => this.openWorker(name, sc) })),
      seeAll: () => this.openGd({ kicker: 'Matching intelligent', title: 'Suggestions récentes', text: 'Profils proposés par le moteur de matching pour les besoins en attente. Taux moyen de pertinence : 92 %.', listTitle: '5 profils suggérés', list: MX.map(([name, job, ch, sc]) => ({ icon: 'person', tone: 'g', name, sub: job + ' · pour ' + ch, badge: sc + ' %', badgeTn: 'g', go: () => this.openWorker(name, sc) })), actions: [{ label: 'Ouvrir le module Matching', icon: 'join_inner', variant: 'primary', go: () => this.go('matching') }] }),
      launch: () => { this.go('matching'); this.toast('Matching lancé sur 38 besoins en attente.'); } };

    const fk = s.fRange || '7j', F = this.FX[fk], com = F.enc.map(v => v * 0.08);
    const step = this.niceStep(Math.max(...F.enc) * 1.1 / 4), fy = step * 4, sum = a => a.reduce((x, y) => x + y, 0), M = v => this.fmt(Math.round(v * 1000) * 1000);
    const fx = {
      ranges: [['auj', 'Aujourd’hui'], ['7j', '7 jours'], ['30j', '30 jours'], ['3m', '3 mois']].map(([k, label]) => ({ label, on: fk === k, ...seg(fk === k), go: () => this.setFRange(k) })),
      isForbidden: limited, isLoad: !limited && !!s.fLoad, isEmpty: !limited && !s.fLoad && empty, isOk: !limited && !s.fLoad && !empty,
      legend: [['Encaissements promoteurs', 'var(--c-primary-600)'], ['Reversements aux ouvriers', 'var(--c-accent-600)'], ['Commissions O’CHANTIER', 'var(--c-info)']].map(([l, c]) => ({ l, c })),
      yTicks: [4, 3, 2, 1, 0].map(k => this.fmtM(step * k)),
      leave: () => this.setState({ fxHp: null }),
      groups: F.l.map((l, i) => { const on = s.fxHp === i, last = F.l.length - 1; return { l, h1: (F.enc[i] / fy * 100) + '%', h2: (F.rev[i] / fy * 100) + '%', h3: (com[i] / fy * 100) + '%', on, enter: () => this.setState({ fxHp: i }), hoverBg: on ? 'var(--c-surface-3)' : 'transparent', tipBottom: `calc(${F.enc[i] / fy * 100}% + var(--space-2))`, tx: i === 0 ? '-20%' : i === last ? '-80%' : '-50%', period: ({ auj: 'Aujourd’hui · ' + l, '7j': l + ' · semaine en cours', '30j': 'Semaine du ' + l, '3m': l + ' 2026' })[fk] || l, aria: `${l} : encaissements ${M(F.enc[i])} FCFA, reversements ${M(F.rev[i])} FCFA, commissions ${M(com[i])} FCFA`, rows: [['Encaissements', F.enc[i], 'var(--c-primary-600)'], ['Reversements', F.rev[i], 'var(--c-accent-600)'], ['Commissions', com[i], 'var(--c-info)']].map(([tl, v, c]) => ({ l: tl, v: M(v), c })) }; }),
      totals: [['Total encaissé', sum(F.enc), 'var(--c-primary-600)'], ['Total reversé', sum(F.rev), 'var(--c-accent-600)'], ['Total des commissions', sum(com), 'var(--c-info)'], ['Montant en attente', F.pend, 'var(--c-orange)']].map(([l, v, c]) => ({ l, v: M(v), c })),
      seePay: () => limited ? this.toast('Accès restreint : données financières réservées au profil Finance.') : this.go('paiements')
    };

    const fi = f => ({ icon: f[0], iBg: `var(--c-${f[1]}-bg)`, iFg: `var(--c-${f[1]}-fg)`, t: f[2], target: f[3], user: f[4], when: f[5], aria: 'Ouvrir : ' + f[2] + ' · ' + f[3], go: f[6] });
    const feed = { isErr: errFeed, isLoad: false, isEmpty: !errFeed && empty, isOk: !errFeed && !empty, skel: [1, 2, 3, 4], items: this.FEED.slice(0, 6).map(fi),
      retry: () => this.setState({ errFix: { ...(this.state.errFix || {}), feed: true } }),
      seeAll: () => this.openGd({ kicker: 'Journal d’activité', title: 'Toute l’activité', text: 'Actions réalisées sur la plateforme au cours des 15 derniers jours.', list: this.FEED.map(f => ({ icon: f[0], tone: f[1], name: f[2] + ' · ' + f[3], sub: f[4] + ' · ' + f[5], go: f[6] })) }) };

    return {
      offline, reco: !!s.reco && !offline, limited, partialBanner: errAct || errFeed,
      partialText: [errAct ? 'le graphique d’activité' : '', errFeed ? 'l’activité récente' : ''].filter(Boolean).join(' et ').replace(/^./, c => c.toUpperCase()) + (errAct && errFeed ? ' n’ont pas pu être chargés.' : ' n’a pas pu être chargé' + (errFeed && !errAct ? 'e.' : '.')),
      updated: s.updated, liveLabel: offline ? 'Hors ligne · dernière mise à jour à ' + s.updated : 'Mis à jour à ' + s.updated, liveC: offline ? 'var(--c-danger)' : 'var(--c-success)',
      refresh: () => this.refreshDash(), reconnect: () => this.reconnect(),
      globalError, retryGlobal: () => { clearTimeout(this._dlt); this.setState({ errFix: { ...(this.state.errFix || {}), global: true }, dLoad: true }); this._dlt = setTimeout(() => this.setState({ dLoad: false }), 700); },
      initLoading, ready: !globalError && !initLoading, kpiCols: String(kpiCols), sk4: [1, 2, 3, 4],
      skGrid: [[8, '340px'], [4, '340px'], [8, '320px'], [4, '320px']].map(([n2, h]) => ({ span: sp(n2), h })),
      sp8: sp(8), sp4: sp(4), sp7: sp(7), sp5: sp(5), kpis, act, prio, recent, match, fx, feed
    };
  }

});
