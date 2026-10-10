'use strict';
// Module Abonnements : formules Solo / Équipe / Pro, abonnés, factures
// Couche de la classe Component : voir src/js/och.js (OCH.extend).
OCH.extend(Base => class extends Base {
  ABOPL = [
    { k: 'solo', n: 'Solo', max: 1, price: 0, per: 'pour toujours', sub: '1 utilisateur', desc: 'Compte à utilisateur unique · propriétaire', feats: ['Propriétaire uniquement', 'Chantiers, réservations et pointage', 'Paiement en une fois · sans rapport global'] },
    { k: 'equipe', n: 'Équipe', max: 5, price: 15000, per: 'par mois', sub: 'Jusqu’à 5 utilisateurs', desc: 'Compte équipe · rôles clés', feats: ['Jusqu’à 5 utilisateurs · rôles clés', 'Paiement flexible en 2 ou 3 fois', 'Rapport global de fin de chantier'], pop: 1 },
    { k: 'pro', n: 'Pro', max: 15, price: 35000, per: 'par mois', sub: 'Jusqu’à 15 utilisateurs', desc: 'Compte entreprise · équipes multiples', feats: ['Jusqu’à 15 utilisateurs', 'Paiement flexible et rapports de fin de chantier', 'Journal d’activité détaillé'] }
  ];
  ABOROLES = ['Chef de chantier', 'Technicien', 'Comptable', 'Observateur'];
  aboPl(k) { return this.ABOPL.find(p => p.k === k) || this.ABOPL[0]; }
  aboAdd(iso, n) { const d = new Date(iso + 'T12:00:00'); d.setDate(d.getDate() + n); return d.toISOString().slice(0, 10); }
  aboD(iso) { if (!iso) return '—'; const M = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.']; return +iso.slice(8, 10) + ' ' + M[+iso.slice(5, 7) - 1] + ' ' + iso.slice(0, 4); }
  aboIni(n) { return String(n || '?').split(/\s+/).filter(Boolean).slice(0, 2).map(w => w[0]).join('').toUpperCase(); }
  aboList() {
    if (!this.ABO) {
      const T = this.TODAY, S = {}, FN = ['Awa', 'Koffi', 'Mariam', 'Yao', 'Fatou', 'Ibrahim', 'Adjoua', 'Serge', 'Aminata', 'Didier', 'Nadia', 'Paul', 'Salimata', 'Hervé', 'Clarisse'], LN = ['Kouassi', 'Traoré', 'Koné', 'Bamba', 'Diallo', 'N’Guessan', 'Ouattara', 'Touré', 'Coulibaly', 'Yapi'];
      const PAT = ['equipe', 'solo', 'pro', 'equipe', 'solo', 'equipe', 'pro', 'solo', 'equipe', 'solo', 'equipe', 'pro'], STP = { equipe: ['Actif', 'Actif', 'Impayé', 'Essai', 'Actif', 'Suspendu'], pro: ['Actif', 'Actif', 'Résilié', 'Actif'] }, PM = ['Orange Money', 'MTN Mobile Money', 'Wave', 'Carte bancaire', 'Moov Money'];
      Object.values(this.P).forEach((p, i) => {
        const plan = PAT[i % PAT.length], pl = this.aboPl(plan), st = plan === 'solo' ? 'Actif' : STP[plan][i % STP[plan].length], since = this.aboAdd(T, -(40 + (i * 37) % 420));
        const nCol = plan === 'solo' ? 0 : Math.min(pl.max - 1, (plan === 'pro' ? 4 : 1) + (i * 3) % (pl.max - 1)), users = [{ id: 'u0', name: p.resp || p.name, role: 'Propriétaire', via: 'Compte principal', st: 'Actif', owner: 1 }];
        for (let j = 0; j < nCol; j++) users.push({ id: 'u' + (j + 1), name: FN[(i * 5 + j * 3) % FN.length] + ' ' + LN[(i + j * 7) % LN.length], role: this.ABOROLES[(i + j) % 4], via: (i + j) % 3 ? 'Invité par SMS' : 'Invité par e-mail', st: j === nCol - 1 && i % 2 ? 'Invitation envoyée' : 'Actif' });
        const next = plan === 'solo' || st === 'Résilié' ? null : st === 'Impayé' ? this.aboAdd(T, -(3 + i % 6)) : st === 'Essai' ? this.aboAdd(T, 3 + i % 9) : this.aboAdd(T, 1 + (i * 5) % 28);
        const inv = []; if (plan !== 'solo' && st !== 'Essai') for (let k = 0; k < 3; k++) { const d = this.aboAdd(next || this.aboAdd(T, -10), -30 * (k + (st === 'Impayé' ? 0 : 1))); inv.push({ ref: 'ABO-' + d.slice(0, 7).replace('-', '') + '-' + String(100 + i).padStart(4, '0'), date: d, amt: pl.price, label: 'Formule ' + pl.n, st: st === 'Impayé' && k === 0 ? 'Échouée' : 'Payée' }); }
        const log = [{ d: since, txt: plan === 'solo' ? 'Compte créé en formule Solo' : 'Souscription à la formule ' + pl.n + (st === 'Essai' ? ' · essai de 14 jours' : '') }];
        if (st === 'Impayé') log.unshift({ d: next, txt: 'Échec du prélèvement ' + PM[i % PM.length] });
        if (st === 'Suspendu') log.unshift({ d: this.aboAdd(T, -5), txt: 'Abonnement suspendu · impayé prolongé' });
        if (st === 'Résilié') log.unshift({ d: this.aboAdd(T, -12), txt: 'Abonnement résilié · retour à la formule Solo' });
        S[p.id] = { id: p.id, promo: p.name, resp: p.resp || p.name, phone: p.phone || '—', email: p.email || '—', plan: st === 'Résilié' ? 'solo' : plan, was: plan, st, since, next, pm: plan === 'solo' ? '—' : PM[i % PM.length], users: st === 'Résilié' ? users.slice(0, 1) : users, inv, log, pending: null };
      });
      this.ABO = S;
    }
    Object.values(this.P).forEach(p => { if (!this.ABO[p.id]) this.ABO[p.id] = { id: p.id, promo: p.name, resp: p.resp || p.name, phone: p.phone || '—', email: p.email || '—', plan: 'solo', st: 'Actif', since: this.TODAY, next: null, pm: '—', users: [{ id: 'u0', name: p.resp || p.name, role: 'Propriétaire', via: 'Compte principal', st: 'Actif', owner: 1 }], inv: [], log: [{ d: this.TODAY, txt: 'Compte créé en formule Solo' }], pending: null }; });
    return Object.values(this.ABO).sort((a, b) => a.promo.localeCompare(b.promo, 'fr'));
  }
  aboBadge() { try { return this.aboList().filter(x => x.st === 'Impayé').length || null; } catch (e) { return null; } }
  aboKpis() {
    const S = this.aboList(), paid = S.filter(x => x.plan !== 'solo' && ['Actif', 'Impayé'].includes(x.st)), act = paid.filter(x => x.st === 'Actif'), mrr = act.reduce((a, x) => a + this.aboPl(x.plan).price, 0), imp = S.filter(x => x.st === 'Impayé');
    return [{ label: 'Abonnés payants', value: String(paid.length), unit: 'sur ' + S.length, icon: 'workspace_premium', tn: 'g', cmp: ' ' }, { label: 'Revenu mensuel récurrent', value: this.fmt(mrr), unit: 'FCFA', icon: 'payments', tn: 'b', cmp: ' ' }, { label: 'Taux de conversion', value: Math.round(paid.length / (S.length || 1) * 100) + ' %', icon: 'trending_up', tn: 'a', cmp: ' ', hint: 'Promoteurs passés d’une formule Solo à une formule payante' }, { label: 'Impayés', value: String(imp.length), icon: 'error', tn: 'r', cmp: ' ', warn: imp.length > 0 }];
  }
  aboroute(r, noPush) { const id = (r || '').split('/')[2]; this.setState({ screen: 'abonnements', aboId: id ? decodeURIComponent(id) : null, aboPick: null, menu: false, notif: false, rowMenu: null, gd: null, loading: false }); try { localStorage.setItem('och_screen', 'abonnements'); if (!noPush && decodeURIComponent(location.hash.slice(1)) !== r) history.pushState(null, '', '#' + r); } catch (e) {} window.scrollTo(0, 0); }
  aboLog(x, txt) { x.log.unshift({ d: this.TODAY, txt }); this.bump(); }
  aboExport() { const n = this.aboList().length; this.toast('Liste des abonnements exportée (Excel, ' + n + ' lignes).'); }
  aboApply(x, k) {
    const pl = this.aboPl(x.plan), pp = this.aboPl(k), T = this.TODAY, up = pp.price > pl.price;
    const left = x.next ? Math.max(0, Math.round((new Date(x.next) - new Date(T)) / 864e5)) : 30, pro = pl.price ? Math.round((pp.price - pl.price) * Math.min(30, left) / 30 / 100) * 100 : pp.price;
    this.openModal({ tone: up ? 'success' : 'warning', icon: up ? 'upgrade' : 'south', title: 'Passer à la formule ' + pp.n + ' ?', body: up ? 'Activation immédiate pour ' + x.promo + '. ' + this.fmt(pro) + ' FCFA facturés aujourd’hui, puis ' + this.fmt(pp.price) + ' FCFA par mois. Le promoteur est notifié par SMS et e-mail.' : 'Le changement prendra effet le ' + this.aboD(x.next) + '. Les avantages de la formule ' + pl.n + ' restent actifs jusque-là.', ok: 'Confirmer', after: () => {
      if (up) { const wasSolo = x.plan === 'solo'; x.plan = k; x.pending = null; x.st = 'Actif'; if (wasSolo || !x.next) { x.next = this.aboAdd(T, 30); x.pm = x.pm === '—' ? 'Orange Money' : x.pm; } x.inv.unshift({ ref: 'ABO-' + T.slice(0, 7).replace('-', '') + '-' + String(x.inv.length + 500).padStart(4, '0'), date: T, amt: pro, label: wasSolo ? 'Formule ' + pp.n : 'Prorata ' + pl.n + ' → ' + pp.n, st: 'En attente' }); this.aboLog(x, 'Formule changée : ' + pl.n + ' → ' + pp.n + ' (activation immédiate)'); this.toast('Formule ' + pp.n + ' activée pour ' + x.promo + '.'); }
      else { x.pending = { plan: k, at: x.next }; this.aboLog(x, 'Passage à la formule ' + pp.n + ' programmé au ' + this.aboD(x.next)); this.toast('Changement programmé au ' + this.aboD(x.next) + '.'); }
      this.setState({ aboPick: null });
    } });
  }
  aboPlanForm(p) {
    const S = this.aboList(), isNew = !p, PL = this.ABOPL;
    this.openModal({ kind: 'fields', icon: isNew ? 'add' : 'edit', title: isNew ? 'Nouvelle formule' : 'Modifier la formule ' + p.n, body: isNew ? 'La formule devient disponible immédiatement pour les promoteurs, dans l’application et ici.' : 'Le nouveau prix s’applique aux prochaines échéances. Les abonnés sont informés 30 jours avant.', ok: isNew ? 'Créer la formule' : 'Enregistrer', fields: [
      { key: 'n', label: 'Nom de la formule', req: 1, value: isNew ? '' : p.n, validate: v => PL.some(x => x !== p && x.n.toLowerCase() === String(v).trim().toLowerCase()) ? 'Une formule porte déjà ce nom.' : '' },
      { key: 'price', label: 'Prix mensuel (FCFA)', type: 'number', req: 1, value: isNew ? '' : String(p.price), validate: v => { const n = +String(v).replace(/\s/g, ''); return !Number.isFinite(n) || n < 1000 ? 'Montant minimum : 1 000 FCFA.' : ''; } },
      { key: 'max', label: 'Utilisateurs maximum', type: 'number', req: 1, value: isNew ? '' : String(p.max), validate: v => { const n = +v; if (!Number.isInteger(n) || n < 2) return 'Nombre entier, 2 minimum.'; const over = isNew ? 0 : S.filter(x => x.plan === p.k && x.users.length > n).length; return over ? over + ' abonné(s) dépassent déjà cette limite.' : ''; } },
      { key: 'pop', label: 'Mise en avant', type: 'select', req: 1, options: ['Non', 'Oui · « Le plus choisi »'], value: !isNew && p.pop ? 'Oui · « Le plus choisi »' : 'Non' },
      { key: 'feats', label: 'Avantages', type: 'lines', req: 1, span: 2, value: isNew ? [''] : p.feats.slice(), hint: 'Affichés dans cet ordre sur la carte de la formule.', validate: v => (Array.isArray(v) ? v : []).filter(l => String(l).trim()).length ? '' : 'Ajoutez au moins un avantage.' }
    ], after: v => {
      const f = v.f, price = +String(f.price).replace(/\s/g, ''), max = +f.max, n = f.n.trim(), feats = (Array.isArray(f.feats) ? f.feats : String(f.feats).split('\n')).map(l => String(l).trim()).filter(Boolean), pop = /^Oui/.test(f.pop);
      if (pop) PL.forEach(x => { x.pop = 0; });
      if (isNew) { let k = n.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'formule'; while (PL.some(x => x.k === k)) k += '-2'; PL.push({ k, n, max, price, per: 'par mois', sub: 'Jusqu’à ' + max + ' utilisateurs', desc: 'Formule ' + n + ' · jusqu’à ' + max + ' utilisateurs', feats, pop: pop ? 1 : 0 }); this.bump(); this.toast('Formule « ' + n + ' » créée.'); }
      else { const old = p.n; Object.assign(p, { n, price, max, feats, pop: pop ? 1 : 0, sub: 'Jusqu’à ' + max + ' utilisateurs', desc: p.desc.replace(old, n) }); S.filter(x => x.plan === p.k).forEach(x => x.log.unshift({ d: this.TODAY, txt: 'Formule ' + n + ' mise à jour : ' + this.fmt(price) + ' FCFA · ' + max + ' utilisateurs' })); this.bump(); this.toast('Formule « ' + n + ' » mise à jour.'); }
    } });
  }
  aboEditPlan(p) { this.aboPlanForm(p); }
  aboDelPlan(p) {
    const S = this.aboList(), subs = S.filter(x => x.plan === p.k || (x.pending && x.pending.plan === p.k)), others = this.ABOPL.filter(x => x !== p);
    const done = to => { const tp = to && this.aboPl(to); subs.forEach(x => { if (x.pending && x.pending.plan === p.k) x.pending = null; if (x.plan === p.k) { x.plan = tp.k; if (!tp.price) { x.next = null; x.pm = '—'; } x.log.unshift({ d: this.TODAY, txt: 'Formule ' + p.n + ' supprimée · migration vers ' + tp.n }); } }); this.ABOPL = this.ABOPL.filter(x => x !== p); this.setState({ aboFp: this.state.aboFp === p.k ? '' : this.state.aboFp }); this.bump(); this.toast('Formule « ' + p.n + ' » supprimée' + (subs.length ? ' · ' + subs.length + ' abonné(s) migré(s) vers ' + tp.n : '') + '.'); };
    if (!subs.length) return this.openModal({ tone: 'danger', icon: 'delete', title: 'Supprimer la formule ' + p.n + ' ?', body: 'Aucun promoteur n’y est abonné. La formule ne sera plus proposée.', ok: 'Supprimer', after: () => done(null) });
    const need = Math.max(...subs.filter(x => x.plan === p.k).map(x => x.users.length), 1);
    this.openModal({ kind: 'fields', tone: 'danger', icon: 'delete', title: 'Supprimer la formule ' + p.n + ' ?', body: subs.length + ' promoteur(s) utilisent cette formule. Choisissez la formule vers laquelle les migrer : ils seront notifiés par SMS et e-mail.', ok: 'Migrer et supprimer', fields: [
      { key: 'to', label: 'Migrer les abonnés vers', type: 'select', req: 1, span: 2, options: others.map(x => x.n + ' · ' + (x.price ? this.fmt(x.price) + ' FCFA' : 'Gratuit') + ' · ' + x.max + ' util.'), validate: v => { const tp = others.find(x => String(v).startsWith(x.n + ' ·')); return tp && tp.max < need ? 'Un abonné compte ' + need + ' utilisateurs : cette formule en autorise ' + tp.max + '.' : ''; } }
    ], after: v => done(others.find(x => v.f.to.startsWith(x.n + ' ·')).k) });
  }
  aboVals(s) {
    const S = this.aboList(), T = this.TODAY, fmt = n => this.fmt(n), D = d => this.aboD(d);
    const PLT0 = { solo: ['var(--c-neutral-bg)', 'var(--c-neutral-fg)'], equipe: ['var(--c-primary-100)', 'var(--c-primary-600)'], pro: ['var(--c-warning-bg)', 'var(--c-warning-fg)'] }, PLT = new Proxy(PLT0, { get: (o, k) => o[k] || ['var(--c-info-bg)', 'var(--c-info-fg)'] }), PL = this.ABOPL;
    const STT = { 'Actif': 'success', 'Essai': 'info', 'Impayé': 'danger', 'Suspendu': 'orange', 'Résilié': 'neutral' };
    const price = p => p.price ? fmt(p.price) + ' FCFA' : 'Gratuit', back = () => this.aboroute('/abonnements');
    const out = { isAbo: true, isWorkerModule: false, isList: false, hasPtTop: false };
    const live = x => ['Actif', 'Impayé', 'Essai'].includes(x.st);
    const id = s.aboId, x = id && S.find(y => y.id === id);
    const abo = { isList: !id, isMissing: !!id && !x, isDetail: !!x, isSubs: false, isPlans: false, back, d: {} };
    out.abo = abo;
    if (!id) {
      const tab = s.aboTab || 'abonnes', q = (s.aboQ || '').toLowerCase(), fp = s.aboFp || '', fs = s.aboFs || '';
      abo.tabs = [['abonnes', 'Abonnés', 'group', S.length], ['formules', 'Formules', 'workspace_premium', PL.length]].map(([k, label, icon, count]) => { const on = k === tab; return { label, icon, count, go: () => this.setState({ aboTab: k }), line: on ? 'var(--c-primary)' : 'transparent', fg: on ? 'var(--c-primary)' : 'var(--c-muted)', cBg: on ? 'var(--c-primary)' : 'var(--c-neutral-bg)', cFg: on ? 'var(--c-surface)' : 'var(--c-neutral-fg)' }; });
      abo.isSubs = tab === 'abonnes'; abo.isPlans = tab === 'formules'; abo.addPlan = () => this.aboPlanForm(null); abo.plansLabel = PL.length + ' formules proposées aux promoteurs · la formule Solo (gratuite) ne peut pas être supprimée';
      const rows = S.filter(y => (!fp || y.plan === fp) && (!fs || y.st === fs) && (!q || (y.promo + ' ' + y.resp).toLowerCase().includes(q)));
      const WP = 8, pc = Math.max(1, Math.ceil(rows.length / WP)), pg = Math.min(s.aboPg || 1, pc);
      Object.assign(abo, {
        q: s.aboQ || '', onQ: e => this.setState({ aboQ: e.target.value, aboPg: 1 }),
        fp, onFp: e => this.setState({ aboFp: e.target.value, aboPg: 1 }), fpBd: fp ? 'var(--c-primary-400)' : 'var(--c-line-strong)', fpBg: fp ? 'var(--c-primary-50)' : 'var(--c-surface)', fpOpts: [{ v: '', l: 'Toutes les formules' }].concat(PL.map(p => ({ v: p.k, l: p.n }))),
        fs, onFs: e => this.setState({ aboFs: e.target.value, aboPg: 1 }), fsBd: fs ? 'var(--c-primary-400)' : 'var(--c-line-strong)', fsBg: fs ? 'var(--c-primary-50)' : 'var(--c-surface)', fsOpts: [{ v: '', l: 'Tous les statuts' }].concat(Object.keys(STT).map(k => ({ v: k, l: k }))),
        hasF: !!(q || fp || fs), reset: () => this.setState({ aboQ: '', aboFp: '', aboFs: '', aboPg: 1 }),
        empty: !rows.length, pageLabel: (rows.length ? (pg - 1) * WP + 1 : 0) + '–' + Math.min(pg * WP, rows.length) + ' sur ' + rows.length,
        pages: Array.from({ length: pc }, (_, i) => { const n = i + 1, on = n === pg; return { n, go: () => this.setState({ aboPg: n }), bg: on ? 'var(--c-primary)' : 'var(--c-surface)', fg: on ? 'var(--c-surface)' : 'var(--c-ink-2)', bd: on ? 'var(--c-primary)' : 'var(--c-line-strong)' }; }),
        rows: rows.slice((pg - 1) * WP, pg * WP).map(y => { const pl = this.aboPl(y.plan), n = y.users.length, pn = y.pending && this.aboPl(y.pending.plan); return { promo: y.promo, resp: y.resp, init: this.aboIni(y.promo), plan: pl.n, pBg: PLT[pl.k][0], pFg: PLT[pl.k][1], users: n + ' / ' + pl.max, w: Math.min(100, n / pl.max * 100) + '%', barC: n >= pl.max ? 'var(--c-accent)' : 'var(--c-primary-500)', amt: price(pl), amtSub: pl.per, st: y.st, tone: STT[y.st], next: y.next ? D(y.next) : '—', nextC: y.st === 'Impayé' ? 'var(--c-danger-fg)' : 'var(--c-ink-2)', hasNote: !!pn || y.st === 'Impayé' || y.st === 'Essai', note: pn ? 'Passage à ' + pn.n + ' prévu' : y.st === 'Impayé' ? 'En retard de ' + Math.round((new Date(T) - new Date(y.next)) / 864e5) + ' j' : y.st === 'Essai' ? 'Fin de l’essai' : '', open: () => this.aboroute('/abonnements/' + encodeURIComponent(y.id)) }; }),
        plans: PL.map(p => { const subs = S.filter(y => y.plan === p.k && live(y)), act = subs.filter(y => y.st === 'Actif'); return { n: p.n, sub: p.sub, price: price(p), per: p.per, feats: p.feats, pop: !!p.pop, bd: p.pop ? 'var(--c-primary)' : 'var(--c-line)', bw: p.pop ? '2px' : '1px', nSubs: String(subs.length), mrr: p.price ? fmt(act.length * p.price) : '—', avgU: (subs.reduce((a, y) => a + y.users.length, 0) / (subs.length || 1)).toFixed(1).replace('.', ',') + ' / ' + p.max, canEdit: p.k !== 'solo', edit: () => this.aboEditPlan(p), canDel: p.k !== 'solo', del: () => this.aboDelPlan(p), see: () => this.setState({ aboTab: 'abonnes', aboFp: p.k, aboFs: '', aboPg: 1 }) }; })
      });
      return out;
    }
    if (!x) { out.title = 'Abonnement introuvable'; out.subtitle = ''; return out; }
    const pl = this.aboPl(x.plan), n = x.users.length, pick = s.aboPick || x.plan, pp = this.aboPl(pick), locked = ['Suspendu', 'Résilié'].includes(x.st), full = n >= pl.max;
    out.title = x.promo; out.subtitle = 'Abonnement ' + pl.n + ' · ' + x.resp;
    const chg = pick !== x.plan, up = pp.price > pl.price, tooMany = n > pp.max;
    const chgTxt = !chg ? '' : locked ? 'Réactivez l’abonnement avant de changer de formule.' : tooMany ? 'Ce compte a ' + n + ' utilisateurs. Retirez-en ' + (n - pp.max) + ' avant de passer à ' + pp.n + ' (' + pp.max + ' maximum).' : up ? 'Activation immédiate. ' + (x.plan === 'solo' ? fmt(pp.price) + ' FCFA facturés aujourd’hui' : 'Montant au prorata facturé aujourd’hui') + ', puis ' + fmt(pp.price) + ' FCFA par mois.' : 'Le changement prendra effet à la prochaine échéance (' + D(x.next) + ').';
    const bad = locked || tooMany, pn = x.pending && this.aboPl(x.pending.plan);
    const acts = [];
    if (x.st === 'Impayé') acts.push({ label: 'Relancer le paiement', icon: 'send', variant: 'primary', go: () => { this.aboLog(x, 'Relance de paiement envoyée par SMS et e-mail'); this.toast('Relance envoyée à ' + x.resp + '.'); } }, { label: 'Marquer comme payé', icon: 'check_circle', variant: 'secondary', go: () => this.openModal({ tone: 'success', icon: 'check_circle', title: 'Confirmer le paiement ?', body: 'La facture en échec passe à « Payée » et l’échéance suivante est recalculée.', ok: 'Confirmer', after: () => { x.st = 'Actif'; if (x.inv[0]) x.inv[0].st = 'Payée'; x.next = this.aboAdd(x.next || T, 30); this.aboLog(x, 'Paiement enregistré manuellement'); this.toast('Paiement enregistré.'); } }) });
    if (x.st === 'Essai') acts.push({ label: 'Prolonger l’essai de 7 jours', icon: 'more_time', variant: 'secondary', go: () => { x.next = this.aboAdd(x.next || T, 7); this.aboLog(x, 'Essai prolongé jusqu’au ' + D(x.next)); this.toast('Essai prolongé jusqu’au ' + D(x.next) + '.'); } });
    if (x.st === 'Suspendu') acts.push({ label: 'Réactiver', icon: 'play_circle', variant: 'primary', go: () => this.openModal({ tone: 'success', icon: 'play_circle', title: 'Réactiver l’abonnement ?', body: 'Les collaborateurs retrouvent leur accès immédiatement.', ok: 'Réactiver', after: () => { x.st = 'Actif'; this.aboLog(x, 'Abonnement réactivé'); this.toast('Abonnement réactivé.'); } }) });
    if (x.plan !== 'solo' && ['Actif', 'Impayé', 'Essai'].includes(x.st)) acts.push({ label: 'Suspendre', icon: 'pause_circle', variant: 'secondary', go: () => this.openModal({ kind: 'reason', tone: 'warning', icon: 'pause_circle', title: 'Suspendre l’abonnement ?', body: 'Les collaborateurs perdent l’accès. Le propriétaire conserve son compte avec les droits Solo.', ok: 'Suspendre', reasonLabel: 'Motif de la suspension *', after: v => { x.st = 'Suspendu'; this.aboLog(x, 'Abonnement suspendu · ' + v.reason.trim()); this.toast('Abonnement suspendu.'); } }) });
    if (x.plan !== 'solo' && x.st !== 'Résilié') acts.push({ label: 'Résilier', icon: 'cancel', variant: 'secondary', go: () => this.openModal({ kind: 'reason', tone: 'danger', icon: 'cancel', title: 'Résilier l’abonnement ?', body: 'Le compte repasse en formule Solo : seul le propriétaire conserve l’accès. Les données des chantiers sont conservées.', ok: 'Résilier', reasonLabel: 'Motif de la résiliation *', after: v => { x.st = 'Résilié'; x.was = x.plan; x.plan = 'solo'; x.next = null; x.pending = null; x.users = x.users.filter(u => u.owner); this.aboLog(x, 'Abonnement résilié · ' + v.reason.trim()); this.toast('Abonnement résilié. Le compte repasse en Solo.'); } }) });
    if (x.st === 'Résilié') acts.push({ label: 'Réactiver une formule', icon: 'restart_alt', variant: 'primary', go: () => { x.st = 'Actif'; this.aboLog(x, 'Compte réactivé en formule Solo'); this.setState({ aboPick: x.was && x.was !== 'solo' ? x.was : 'equipe' }); this.toast('Choisissez la formule à activer.'); } });
    abo.d = {
      name: pl.n, desc: pl.desc, badge: pl.price ? fmt(pl.price) + ' FCFA / mois' : 'Gratuit', usersL: n + ' / ' + pl.max + ' utilisateur' + (pl.max > 1 ? 's' : ''), w: Math.min(100, n / pl.max * 100) + '%',
      st: x.st, tone: STT[x.st], promo: x.promo, resp: x.resp, init: this.aboIni(x.promo), phone: x.phone, email: x.email, openPromo: () => this.route('/promoteurs/' + x.id),
      hasPending: !!pn, pendingTxt: pn ? 'Passage à la formule ' + pn.n + ' programmé le ' + D(x.pending.at) + '.' : '', canCancelPending: !!pn, cancelPending: () => { x.pending = null; this.aboLog(x, 'Changement de formule programmé annulé'); this.toast('Changement annulé.'); },
      plans: PL.map(p => { const on = p.k === pick; return { n: p.n, sub: p.sub, price: price(p), per: p.per, feats: p.feats, pop: !!p.pop, cur: p.k === x.plan, on: on ? 'true' : 'false', bd: on ? 'var(--c-primary)' : 'var(--c-line)', bw: on ? '2px' : '1px', rBg: on ? 'var(--c-primary)' : 'var(--c-surface)', rBd: on ? 'var(--c-primary)' : 'var(--c-line-hover)', rIcon: on ? 'check' : '', go: () => this.setState({ aboPick: p.k }) }; }),
      hasChg: chg, chgTxt, chgIcon: bad ? 'error' : up ? 'bolt' : 'schedule', chgBg: bad ? 'var(--c-danger-bg)' : 'var(--c-primary-50)', chgFg: bad ? 'var(--c-danger-fg)' : 'var(--c-primary)', canApply: chg && !bad, applyL: up ? 'Activer ' + pp.n : 'Programmer ' + pp.n, apply: () => this.aboApply(x, pick), chgCancel: () => this.setState({ aboPick: null }),
      canInvite: !full && !locked, hasInviteHint: full || locked, inviteHint: locked ? 'Abonnement ' + x.st.toLowerCase() + ' : les invitations sont bloquées.' : pl.k === 'solo' ? 'La formule Solo est limitée au propriétaire. Passez à Équipe pour inviter des collaborateurs.' : 'Limite de ' + pl.max + ' utilisateurs atteinte. Passez à une formule supérieure pour en ajouter.',
      invite: () => this.openModal({ kind: 'fields', icon: 'person_add', title: 'Inviter un collaborateur', body: 'Le collaborateur reçoit un lien d’activation et rejoint le compte de ' + x.promo + '.', ok: 'Envoyer l’invitation', fields: [{ key: 'name', label: 'Nom complet', req: 1, span: 2 }, { key: 'canal', label: 'Canal', type: 'select', req: 1, options: ['SMS', 'E-mail'], value: 'SMS' }, { key: 'role', label: 'Rôle', type: 'select', req: 1, options: this.ABOROLES }, { key: 'contact', label: 'Téléphone ou e-mail', req: 1, span: 2, validate: (v, f) => f.canal === 'E-mail' ? (/^\S+@\S+\.\S+$/.test(v) ? '' : 'Adresse e-mail invalide.') : (String(v).replace(/\D/g, '').length >= 8 ? '' : 'Numéro invalide (8 chiffres minimum).') }], after: v => { const f = v.f; x.users.push({ id: 'u' + Date.now(), name: f.name.trim(), role: f.role, via: 'Invité par ' + (f.canal === 'SMS' ? 'SMS' : 'e-mail'), st: 'Invitation envoyée' }); this.aboLog(x, 'Invitation envoyée à ' + f.name.trim() + ' (' + f.role + ')'); this.toast('Invitation envoyée par ' + (f.canal === 'SMS' ? 'SMS' : 'e-mail') + '.'); } }),
      users: x.users.map(u => ({ name: u.name, init: this.aboIni(u.name), role: u.role, via: u.via, st: u.st, tone: u.st === 'Actif' ? 'success' : 'warning', avBg: u.owner ? 'var(--c-primary)' : 'var(--c-primary-100)', avFg: u.owner ? 'var(--c-on-dark)' : 'var(--c-primary)', canRemove: !u.owner, remove: () => this.openModal({ tone: 'danger', icon: 'person_remove', title: 'Retirer ' + u.name + ' ?', body: 'Ce collaborateur perd immédiatement l’accès au compte de ' + x.promo + '.', ok: 'Retirer', after: () => { x.users = x.users.filter(y => y !== u); this.aboLog(x, u.name + ' retiré du compte'); this.toast('Accès retiré.'); } }) })),
      kv: [['Formule', pl.n], ['Montant', price(pl) + (pl.price ? ' / mois' : '')], ['Abonné depuis', D(x.since)], [x.st === 'Essai' ? 'Fin de l’essai' : 'Prochaine échéance', x.next ? D(x.next) : '—', x.st === 'Impayé' ? 'var(--c-danger-fg)' : ''], ['Cycle', pl.price ? 'Mensuel' : '—'], ['Moyen de paiement', x.pm]].map(([l, v, c]) => ({ l, v, c: c || 'var(--c-ink)' })),
      acts, hasActs: acts.length > 0,
      inv: x.inv.map(i => ({ ref: i.ref, date: D(i.date), label: i.label, amt: fmt(i.amt) + ' FCFA', st: i.st, tone: i.st === 'Payée' ? 'success' : i.st === 'Échouée' ? 'danger' : 'warning' })), noInv: !x.inv.length,
      log: x.log.slice(0, 8).map(l => ({ txt: l.txt, d: D(l.d) }))
    };
    return out;
  }
});
