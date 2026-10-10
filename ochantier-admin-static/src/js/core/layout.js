'use strict';
// Mise en page : période, recherche globale, topbar, panneau latéral
// Couche de la classe Component : voir src/js/och.js (OCH.extend).
OCH.extend(Base => class extends Base {
  now() { const d = new Date(); return String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0'); }
  demo() { return this.props.etatDemo || 'Normal'; }
  isOffline(s) { return !!s.offline || (this.demo() === 'Connexion perdue' && !s.recoOk); }
  openGd(g) { this.setState({ gd: g, notif: false, menu: false }); }
  goQ(k, q) { this.go(k); this.setState({ q: { ...this.state.q, [k]: String(q).toLowerCase() }, tab: { ...this.state.tab, [k]: 0 } }); }
  goFilter(k, labels) {
    this.go(k);
    if (k === 'ouvriers') { if (labels && labels.length) this.wFilterFrom(labels); return; }
    const cfg = this.D.S[k];
    if (!cfg || !labels || !labels.length) return;
    const tabs = cfg.tabs || [];
    for (const l of labels) {
      const ti = tabs.findIndex(t => t[0] === l || t[1] === l);
      if (ti >= 0) { this.setState({ tab: { ...this.state.tab, [k]: ti }, q: { ...this.state.q, [k]: '' } }); this.toast(`Filtre appliqué : ${tabs[ti][0]}`); return; }
      if (k !== 'promoteurs' && (cfg.rows || []).some(r => r.some(c => c && c.v === l))) { this.setState({ lf: { [k]: l }, tab: { ...this.state.tab, [k]: 0 }, q: { ...this.state.q, [k]: '' } }); this.toast(`Filtre appliqué : ${l}`); return; }
    }
    this.toast(`Filtre « ${labels[0]} » : aucun élément correspondant dans les données de démonstration.`);
  }
  setRange(k) { clearTimeout(this._clt); this.setState({ range: k, hp: null, cLoad: true, pickOpen: false }); this._clt = setTimeout(() => this.setState({ cLoad: false }), 420); }
  setFRange(k) { clearTimeout(this._flt); this.setState({ fRange: k, fLoad: true, fxHp: null }); this._flt = setTimeout(() => this.setState({ fLoad: false }), 420); }
  reconnect() {
    if (typeof navigator !== 'undefined' && navigator.onLine === false) return this.toast('Toujours hors connexion. Nouvelle tentative automatique dans 30 s.');
    clearTimeout(this._rct); clearTimeout(this._rct2);
    this.setState({ offline: false, recoOk: true, reco: true, updated: this.now(), cLoad: true, fLoad: true });
    this._rct = setTimeout(() => this.setState({ cLoad: false, fLoad: false }), 700);
    this._rct2 = setTimeout(() => this.setState({ reco: false }), 4500);
  }
  refreshDash() {
    if (this.isOffline(this.state)) return this.toast('Actualisation impossible : connexion perdue.');
    clearTimeout(this._rft); this.setState({ cLoad: true, fLoad: true });
    this._rft = setTimeout(() => { this.setState({ cLoad: false, fLoad: false, updated: this.now() }); this.toast('Données mises à jour.'); }, 650);
  }
  confirmLogout() {
    this.setState({ menu: false });
    this.openModal({ tone: 'danger', icon: 'logout', title: 'Voulez-vous vraiment vous déconnecter ?', body: 'Votre session sera fermée sur cet appareil.', ok: 'Se déconnecter', after: () => { this.setState({ auth: 'login', pwd: '', otp: '', gd: null }); this.go('login'); } });
  }
  genCustom(from, to) {
    const E = { l: [], d: [], a: [], b: [] };
    if (!from || !to || from > '2026-09-23' || to < '2024-01-01') return E;
    const a = new Date(from + 'T00:00:00'), b = new Date(to + 'T00:00:00');
    const days = Math.max(1, Math.round((b - a) / 864e5)), n = Math.max(2, Math.min(7, days + 1));
    let h = this.hash(from + to); const R = { l: [], d: [], a: [], b: [] };
    for (let i = 0; i < n; i++) {
      const t = new Date(a.getTime() + days * 864e5 * i / (n - 1));
      R.l.push(t.toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' }));
      R.d.push(t.toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'short', year: 'numeric' }).replace(/^./, c => c.toUpperCase()));
      h = (h * 1103515245 + 12345) >>> 0; R.a.push(14 + Math.round(i * 10 / (n - 1)) + (h % 3)); R.b.push((h >>> 5) % 6);
    }
    return R;
  }
  fmtDate(v) { try { return new Date(v + 'T00:00:00').toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' }); } catch (e) { return v; } }
  niceStep(v) { const p = Math.pow(10, Math.floor(Math.log10(v))); for (const m of [1, 2, 2.5, 5, 10]) if (m * p >= v) return m * p; return 10 * p; }
  fmtM(v) { if (!v) return '0'; const r = Math.round(v * 10) / 10; return String(r).replace('.', ',') + ' M'; }
  chantierPool() { return [...this.DCH, ...this.POOL.CH.map(c => ({ name: c.name, promo: 'SICOGI', loc: c.loc, pct: c.pct, st: c.st, tn: this.tone(c.st), ouv: String(c.ouv), type: c.type, period: c.period, link: '/promoteurs/sicogi/chantiers/' + c.id, pid: 'sicogi' }))]; }
  openChantier(c) {
    this.openGd({ kicker: 'Détail du chantier', title: c.name, badges: [{ label: c.st, tone: c.tn }], prog: c.pct,
      meta: [['Promoteur', c.promo], ['Localisation', c.loc], ['Type de construction', c.type], ['Période', c.period], ['Ouvriers affectés', c.ouv]],
      actions: [(() => { const ch = Object.values(this.CHS).find(x => x.name === c.name); return ch ? { label: 'Ouvrir la fiche chantier', icon: 'open_in_new', go: () => this.chroute('/chantiers/' + ch.id) } : { label: 'Ouvrir dans Chantiers', icon: 'open_in_new', go: () => this.goQ('chantiers', c.name) }; })(), c.link ? { label: 'Voir la fiche complète', icon: 'arrow_forward', variant: 'primary', go: () => this.route(c.link) } : null] });
  }
  openWorker(name, score) {
    let w = this.WK[name];
    if (!w) { const r = (this.D.S.ouvriers.rows || []).find(r => r[0].name === name); w = r ? { job: r[1].v, exp: r[2].v, rate: r[3].v, avail: r[4].v, note: r[5].v + ' / 5', st: r[6].v, loc: '—' } : { job: '—', exp: '—', rate: '—', avail: '—', note: '—', st: '—', loc: '—' }; }
    this.openGd({ kicker: 'Profil ouvrier', title: name, badges: [{ label: w.avail, tone: w.avail === 'Disponible' ? 'g' : 'a' }, { label: w.st, tone: w.st === 'Vérifié' ? 'g' : 'a' }],
      meta: [['Métier', w.job], ['Expérience', w.exp], ['Localisation', w.loc], ['Coût journalier', w.rate], ['Note moyenne', w.note]].concat(score ? [['Compatibilité', score + ' %']] : []),
      actions: [(() => { const ww = Object.values(this.W).find(x => x.name === name || (x.nom + ' ' + x.prenom) === name); return ww ? { label: 'Voir la fiche', icon: 'open_in_new', go: () => this.wroute('/ouvriers/' + ww.id) } : { label: 'Voir dans Ouvriers', icon: 'open_in_new', go: () => this.goQ('ouvriers', name) }; })(), { label: 'Proposer une affectation', icon: 'join_inner', variant: 'primary', go: () => this.go('matching') }] });
  }
  openPoint(name, date, v, k) {
    const pool = this.chantierPool().filter(c => k === 'a' ? !['Brouillon', 'Terminé'].includes(c.st) : true);
    const start = this.hash(date) % pool.length, list = [];
    for (let i = 0; i < Math.min(v, 6, pool.length); i++) list.push(pool[(start + i) % pool.length]);
    const pl = v > 1 ? 's' : '';
    this.openGd({ kicker: 'Activité des chantiers · ' + date, title: `${name} : ${v}`,
      text: v ? (k === 'a' ? `${v} chantier${pl} actif${pl} à cette date.` : `${v} chantier${pl} créé${pl} à cette date.`) : '',
      listTitle: v ? 'Chantiers concernés' : '', list: list.map(c => ({ icon: 'construction', tone: 'g', name: c.name, sub: c.promo + ' · ' + c.loc, badge: c.st, badgeTn: c.tn, go: () => this.openChantier(c) })),
      foot: v > list.length ? `+ ${v - list.length} autres chantiers` : '', state: v ? null : ['nodata', 'Aucun nouveau chantier', 'Aucun chantier n’a été créé à cette date.'],
      actions: [{ label: 'Voir dans Chantiers', icon: 'open_in_new', go: () => this.goFilter('chantiers', k === 'a' ? ['En cours'] : ['À démarrer']) }] });
  }
  openHelp() {
    this.openGd({ kicker: 'Aide', title: 'Centre d’aide', text: 'Raccourcis et ressources pour utiliser le Back-office O’CHANTIER ADMIN.', listTitle: 'Raccourcis et ressources',
      list: [{ icon: 'search', name: 'Recherche globale', sub: 'Chantiers, ouvriers, promoteurs, commerciaux, offres, transactions', kbd: '⌘ K', go: () => this.openSearch() },
        { icon: 'close', tone: 'n', name: 'Fermer une fenêtre ou un panneau', sub: 'Recherche, notifications, panneaux latéraux', kbd: 'Échap' },
        { icon: 'menu_book', tone: 'b', name: 'Guide de l’administrateur', sub: 'Documentation des modules du Back-office', go: () => this.toast('Guide de l’administrateur : lien à connecter.') },
        { icon: 'support_agent', tone: 'a', name: 'Contacter le support', sub: 'support@ochantier.ci · +225 27 22 00 00 00', go: () => this.toast('Demande envoyée au support O’CHANTIER.') }] });
  }
  openSearch() { this.setState({ gs: { q: '', cat: 'Tout' }, gd: null, notif: false, menu: false }); const f = () => { const i = document.querySelector('[data-component="GlobalSearch"] input'); if (i) i.focus(); return !!i; }; requestAnimationFrame(() => { if (!f()) setTimeout(f, 80); }); setTimeout(f, 200); }
  searchIndex() {
    const S = this.D.S, idx = [];
    const add = (cat, icon, title, sub, go, id) => idx.push({ cat, icon, title, sub, go, id: cat + ':' + (id || title), txt: (title + ' ' + sub).toLowerCase() });
    this.chantierPool().forEach(c => add('Chantiers', 'construction', c.name, c.promo + ' · ' + c.loc + ' · ' + c.st, () => this.openChantier(c)));
    Object.values(this.W).forEach(w => add('Ouvriers', 'engineering', w.name, w.code + ' · ' + w.metier + ' · ' + w.avail, () => this.wroute('/ouvriers/' + w.id), w.id));
    Object.values(this.P).forEach(p => add('Promoteurs', 'apartment', p.name, p.resp + ' · ' + p.loc, () => this.route('/promoteurs/' + p.id), p.id));
    Object.values(this.C).forEach(c => add('Commerciaux', 'trending_up', c.name, c.code + ' · ' + c.zone + ' · ' + c.status, () => this.croute('/commerciaux/' + c.id), c.id));
    this.POOL.OF.forEach(o => add('Offres', 'assignment', o.name, o.ch + ' · ' + o.metier + ' · ' + o.st, () => this.route('/promoteurs/sicogi/offres/' + o.id), o.id));
    this.POOL.PAY.forEach(x => add('Transactions', 'receipt_long', x.ref, this.fmt(x.amount) + ' FCFA · ' + x.type + ' · ' + x.st, () => this.route('/promoteurs/sicogi/paiements/' + x.id), x.id));
    return idx;
  }
  gsVals(s) {
    const g = s.gs; if (!g) return { open: false };
    const q = g.q.trim().toLowerCase(), idx = this.searchIndex(), offline = this.isOffline(s);
    const CATS = ['Tout', 'Chantiers', 'Ouvriers', 'Promoteurs', 'Commerciaux', 'Offres', 'Transactions'];
    const all = q ? idx.filter(x => x.txt.includes(q)) : [];
    const res = all.filter(x => g.cat === 'Tout' || x.cat === g.cat);
    const pick = it => () => {
      const st = this.state, qv = (st.gs && st.gs.q || '').trim();
      const hist = qv ? [qv, ...(st.gsHist || []).filter(h => h.toLowerCase() !== qv.toLowerCase())].slice(0, 6) : (st.gsHist || []);
      const rec = [it.id, ...(st.gsRecent || []).filter(x => x !== it.id)].slice(0, 5);
      try { localStorage.setItem('och_search_hist', JSON.stringify(hist)); localStorage.setItem('och_search_recent', JSON.stringify(rec)); } catch (e) {}
      this.setState({ gs: null, gsHist: hist, gsRecent: rec }); it.go();
    };
    const loading = !!q && !!s.gsLoad && !offline, error = !!q && offline;
    let first = null;
    const groups = CATS.slice(1).map(c => { const items = res.filter(x => x.cat === c); return { label: c, count: String(items.length), items: items.slice(0, g.cat === 'Tout' ? 4 : 30) }; }).filter(x => x.items.length)
      .map(gr => ({ ...gr, items: gr.items.map(it => { const f = !first; if (f) first = it; return { ...it, go: pick(it), first: f, bg: f ? 'var(--c-surface-3)' : 'transparent' }; }) }));
    const setQ = v => { clearTimeout(this._gst); this.setState({ gs: { ...this.state.gs, q: v }, gsLoad: !!v.trim() }); this._gst = setTimeout(() => this.setState({ gsLoad: false }), 260); };
    const hist = s.gsHist || [], recent = (s.gsRecent || []).map(id => idx.find(x => x.id === id)).filter(Boolean);
    return {
      open: true, q: g.q, hasQ: !!g.q, onQ: e => setQ(e.target.value), clear: () => setQ(''),
      onKey: e => { if (e.key === 'Enter' && first) { e.preventDefault(); pick(first)(); } },
      close: () => this.setState({ gs: null }), stop: e => e.stopPropagation(),
      cats: CATS.map(c => { const on = g.cat === c, n = c === 'Tout' ? all.length : all.filter(x => x.cat === c).length; return { label: c, on, hasCount: !!q, count: String(n), go: () => this.setState({ gs: { ...this.state.gs, cat: c } }), bg: on ? 'var(--c-primary)' : 'var(--c-surface)', fg: on ? 'var(--c-on-dark)' : 'var(--c-ink-2)', border: on ? 'var(--c-primary)' : 'var(--c-line-strong)' }; }),
      loading, error, retry: () => this.reconnect(),
      empty: !!q && !loading && !error && !res.length, emptyTitle: `Aucun résultat pour « ${g.q.trim()} »` + (g.cat !== 'Tout' ? ` dans ${g.cat}` : ''),
      hasResults: !!q && !loading && !error && res.length > 0, groups,
      showHome: !q, hasHist: hist.length > 0, clearHist: () => { try { localStorage.setItem('och_search_hist', '[]'); } catch (e) {} this.setState({ gsHist: [] }); },
      hist: hist.map(h => ({ label: h, aria: 'Retirer « ' + h + ' » de l’historique', go: () => setQ(h), remove: () => { const n = (this.state.gsHist || []).filter(x => x !== h); try { localStorage.setItem('och_search_hist', JSON.stringify(n)); } catch (e) {} this.setState({ gsHist: n }); } })),
      recent: recent.map(it => ({ ...it, go: pick(it) })), noRecent: !recent.length
    };
  }
  topVals(s) {
    const read = s.readIds || [], offline = this.isOffline(s), NT = this.NT, PT = { Urgent: 'r', 'Élevé': 'o', Moyen: 'a' };
    const unreadN = NT.filter(n => !read.includes(n.id)).length, tab = s.ntTab || 'unread';
    const list = NT.filter(n => tab === 'all' || (tab === 'unread' ? !read.includes(n.id) : read.includes(n.id)));
    const openN = n => () => { const r = this.state.readIds || []; this.setState({ readIds: r.includes(n.id) ? r : [...r, n.id], notif: false }); n.go(); };
    const loading = !!s.ntLoad && !offline;
    return {
      notifOpen: !!s.notif, hasUnread: unreadN > 0, unread: String(unreadN),
      toggleNotif: () => { const o = !s.notif; clearTimeout(this._ntt); this.setState({ notif: o, menu: false, ntLoad: o }); if (o) this._ntt = setTimeout(() => this.setState({ ntLoad: false }), 380); },
      nt: {
        tabs: [['unread', 'Non lues', unreadN], ['read', 'Lues', NT.length - unreadN], ['all', 'Toutes', NT.length]].map(([k, label, count]) => { const on = k === tab; return { label, on, count: String(count), go: () => this.setState({ ntTab: k }), line: on ? 'var(--c-primary)' : 'transparent', fg: on ? 'var(--c-primary)' : 'var(--c-muted)' }; }),
        loading, error: offline, empty: !loading && !offline && !list.length, hasItems: !loading && !offline && list.length > 0, skel: [1, 2, 3],
        items: list.map(n => { const un = !read.includes(n.id); return { ...n, unread: un, fw: un ? 700 : 500, bg: un ? 'var(--c-primary-50)' : 'var(--c-surface)', iBg: `var(--c-${n.tn}-bg)`, iFg: `var(--c-${n.tn}-fg)`, ptn: PT[n.prio], go: openN(n) }; }),
        emptyTitle: tab === 'unread' ? 'Aucune notification non lue' : 'Aucune notification', emptyText: tab === 'unread' ? 'Vous êtes à jour.' : 'Les notifications apparaîtront ici.',
        noUnread: !unreadN, markC: unreadN ? 'var(--c-primary-600)' : 'var(--c-subtle)', markCur: unreadN ? 'pointer' : 'default',
        markAll: () => { if (!unreadN) return; this.setState({ readIds: NT.map(n => n.id) }); this.toast('Toutes les notifications ont été marquées comme lues.'); },
        retry: () => this.reconnect(),
        seeAll: () => this.openGd({ kicker: 'Centre de notifications', title: 'Toutes les notifications', text: `${unreadN} non lue${unreadN > 1 ? 's' : ''} sur ${NT.length}.`, list: NT.map(n => ({ icon: n.icon, tone: n.tn, name: (read.includes(n.id) ? '' : '● ') + n.t, sub: n.d + ' · ' + n.when, badge: n.prio, badgeTn: PT[n.prio], go: openN(n) })) })
      },
      openSearch: () => this.openSearch(), openHelp: () => this.openHelp(), gs: this.gsVals(s),
      profileItems: [
        ['person', 'Mon profil', () => this.openGd({ kicker: 'Compte administrateur', title: 'N. Yao', badges: [{ label: 'Super administrateur', tone: 'p' }, { label: 'Actif', tone: 'g' }], meta: [['E-mail', 'n.yao@ochantier.ci'], ['Téléphone', '+225 07 08 12 34 56'], ['Dernière connexion', 'Aujourd’hui · 08:57'], ['Authentification', 'Double facteur (OTP) activée']], actions: [{ label: 'Modifier dans Paramètres', icon: 'edit', variant: 'primary', go: () => this.go('parametres') }] })],
        ['tune', 'Préférences', () => { this.go('parametres'); this.toast('Paramètres · Préférences'); }],
        ['shield_lock', 'Sécurité', () => { this.go('parametres'); this.toast('Paramètres · Sécurité'); }],
        ['help', 'Aide', () => this.openHelp()],
        ['logout', 'Déconnexion', () => this.confirmLogout(), 1]
      ].map(([icon, label, fn, danger]) => ({ icon, label, sep: !!danger, color: danger ? 'var(--c-danger-fg)' : 'var(--c-ink)', iconC: danger ? 'var(--c-danger-fg)' : 'var(--c-muted)', go: () => { this.setState({ menu: false }); fn(); } }))
    };
  }
  genDrawer(g) {
    const t = x => this.AL[x] || x || 'success';
    const list = (g.list || []).map(it => ({ icon: it.icon, iBg: `var(--c-${t(it.tone || 'g')}-bg)`, iFg: `var(--c-${t(it.tone || 'g')}-fg)`, name: it.name, sub: it.sub || '', hasBadge: !!it.badge, badge: it.badge || '', tone: it.badgeTn || 'n', hasKbd: !!it.kbd, kbd: it.kbd || '', go: it.go || null, cursor: it.go ? 'pointer' : 'default' }));
    const st = g.state;
    return { open: true, isGen: true, isDoc: false, isOffre: false, kicker: g.kicker, title: g.title, close: () => this.setState({ gd: null }),
      actions: (g.actions || []).filter(Boolean).map(a => ({ label: a.label, icon: a.icon || '', variant: a.variant || 'secondary', go: () => { this.setState({ gd: null }); a.go(); } })),
      gen: { hasPreviews: !!(g.previews && g.previews.length), previews: (g.previews || []).map((p, i) => { const z = g.zoom === i; return { label: p.label, sub: p.sub || '', ph: p.ph || '', h: z ? '440px' : (p.h || '160px'), col: z ? '1 / -1' : 'auto', canZoom: !p.missing, zoomIcon: z ? 'zoom_in_map' : 'zoom_out_map', cursor: p.missing ? 'default' : 'zoom-in', aria: (z ? 'Réduire ' : 'Agrandir ') + p.label, toggle: p.missing ? null : () => this.setState(st => ({ gd: { ...st.gd, zoom: st.gd.zoom === i ? null : i } })) }; }), badges: g.badges || [], hasBadges: !!(g.badges && g.badges.length), text: g.text || '', hasText: !!g.text, hasProg: g.prog != null, progL: g.prog + ' %', progW: g.prog + '%', progC: g.prog < 50 ? 'var(--c-accent-600)' : 'var(--c-primary-500)',
        meta: (g.meta || []).map(([k, v]) => ({ k, v })), hasMeta: !!(g.meta && g.meta.length), list, hasList: list.length > 0, listTitle: g.listTitle || '', hasListTitle: !!g.listTitle, foot: g.foot || '', hasFoot: !!g.foot,
        hasState: !!st, stateKind: st ? st[0] : '', stateTitle: st ? st[1] : '', stateText: st ? st[2] : '' } };
  }
});
