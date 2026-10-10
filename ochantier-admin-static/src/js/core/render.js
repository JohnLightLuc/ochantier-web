'use strict';
// Rendu : renderVals() construit l’objet lu par les templates
// Couche de la classe Component : voir src/js/och.js (OCH.extend).
OCH.extend(Base => class extends Base {
  renderVals() {
    if (!this.state.ps) this.state.ps = this.psInit();
    const s = this.state, P = this.props, TN = this.TN;
    const scr = s.screen, S = this.D.S;
    if (scr === 'besoins' || scr === 'matching' || scr === 'affectations') { this.boSync(); this.boTick(); }
    const collapsed = !!s.side;
    const NAV = [['dashboard', 'home', 'Vue d’ensemble'], ['promoteurs', 'apartment', 'Promoteurs'], ['ouvriers', 'group', 'Ouvriers'], ['commerciaux', 'trending_up', 'Commerciaux'], ['chantiers', 'construction', 'Chantiers'], ['besoins', 'assignment', 'Besoins'], ['matching', 'join_inner', 'Matching'], ['affectations', 'event_available', 'Affectations & Offres', this.affPending()], ['pointages', 'schedule', 'Pointages', this.ptBadge()], ['paiements', 'credit_card', 'Paiements', this.pyBadge()], ['abonnements', 'workspace_premium', 'Abonnements', this.aboBadge()], ['finance', 'monitoring', 'Finance & Reporting'], ['avis', 'forum', 'Avis & litiges', 5], ['rapports', 'bar_chart', 'Rapports'], ['parametres', 'settings', 'Paramètres']];
    const navItems = NAV.map(([k, icon, label, badge], i) => {
      const on = k === scr;
      return { label, icon, current: on ? 'page' : undefined, go: () => this.go(k), bg: on ? 'linear-gradient(90deg,var(--c-primary-500),var(--c-primary-600))' : 'transparent', shadow: on ? 'var(--shadow-nav-active)' : 'none', fw: on ? 600 : 400, iconC: on ? 'var(--c-surface)' : 'var(--c-on-dark-muted)', showLabel: !collapsed, hasBadge: !!badge && !collapsed, badge };
    });
    const idx = NAV.findIndex(n => n[0] === scr);
    const HDR = {
      dashboard: ['Bonjour, Administrateur', 'Voici la situation opérationnelle de la plateforme aujourd’hui'],
      matching: ['Matching intelligent', 'Identifiez les ouvriers les plus adaptés à chaque besoin'],
      rapports: ['Rapports & analyses', 'Pilotez les performances opérationnelles et financières de la plateforme'],
      finance: ['Finance & Reporting', 'Centre financier O’CHANTIER'],
      abonnements: ['Abonnements', 'Gérez les formules et les abonnés des promoteurs'],
      parametres: ['Paramètres de la plateforme', 'Configurez les règles métier, les accès et les services O’CHANTIER']
    };
    const cfg = S[scr];
    const title = cfg ? cfg.t : (HDR[scr] || HDR.dashboard)[0];
    const subtitle = cfg ? cfg.s : (HDR[scr] || HDR.dashboard)[1];
    let acts = cfg ? cfg.a : [];
    let period = cfg && cfg.p;
    if (scr === 'matching') acts = (s.mroute || '/matching') === '/matching' ? [{ label: 'Actualiser', icon: 'refresh', go: () => this.mxRefresh() }] : [];
    if (scr === 'chantiers') acts = [{ label: 'Exporter', icon: 'download', go: () => this.chExport(Object.values(this.CHS)) }, { label: 'Créer un chantier', icon: 'add', primary: 1, go: () => this.chCreate() }];
    if (scr === 'commerciaux') acts = [{ label: 'Exporter', icon: 'download', go: () => this.cExport(Object.values(this.C)) }, { label: 'Classement', icon: 'leaderboard', go: () => this.croute('/commerciaux/classement') }, { label: 'Ajouter un commercial', icon: 'add', primary: 1, go: () => this.croute('/commerciaux/nouveau') }];
    if (scr === 'ouvriers') acts = [{ label: 'Exporter', icon: 'download', go: () => this.wExport(Object.values(this.W)) }, { label: 'Ajouter un ouvrier', icon: 'add', primary: 1, go: () => this.wroute('/ouvriers/nouveau') }];
    if (scr === 'besoins') acts = [{ label: 'Exporter', icon: 'download', go: () => this.boExport(this.boVisRows(s.tab.besoins || 0).map(x => x.r), s.tab.besoins || 0) }, { label: 'Matching', icon: 'join_inner', go: () => this.go('matching') }, { label: 'Nouveau besoin', icon: 'add', primary: 1, go: () => this.broute('/besoins/nouveau-besoin') }];
    if (scr === 'avis') acts = [{ label: 'Exporter', icon: 'download', go: () => this.alExport(this.alFilterSort(this.alRows().map(r => ({ r }))).map(x => x.r)) }, { label: 'Créer un dossier', icon: 'add_circle', primary: 1, go: () => this.alGo('/litiges/nouveau') }];
    if (scr === 'abonnements') acts = s.aboId ? [] : [{ label: 'Exporter', icon: 'download', go: () => this.aboExport() }];
    if (scr === 'rapports') { acts = [{ label: 'Programmer', icon: 'schedule' }, { label: 'Exporter le rapport', icon: 'download', primary: 1 }]; period = 'Janvier – Septembre 2026'; }
    const actions = acts.map(a => ({ ...a, go: a.go || null, variant: a.primary ? 'primary' : 'secondary' }));

    let kpis = [];
    if (cfg) kpis = cfg.k;
    if (scr === 'chantiers' || scr === 'besoins') kpis = [];
    if (scr === 'matching') kpis = [];
    if (scr === 'avis') kpis = this.alKpis0();
    if (scr === 'rapports') kpis = [{ label: 'Volume d’affaires', value: '286 500 000', unit: 'FCFA', icon: 'paid', tn: 'a', delta: '+18 %', cmp: 'vs période préc.' }, { label: 'Chantiers réalisés', value: '124', icon: 'domain', tn: 'b', delta: '+12 %', cmp: 'vs période préc.' }, { label: 'Taux d’occupation', value: '86 %', icon: 'pie_chart', tn: 'r', delta: '+7 pts', cmp: 'vs période préc.' }, { label: 'Satisfaction moyenne', value: '4,6 / 5', icon: 'star', tn: 'a', delta: '+0,3', cmp: 'vs période préc.' }];
    if (scr === 'abonnements') kpis = s.aboId ? [] : this.aboKpis();
    kpis = kpis.map(k => this.kpi(k));

    const out = {
      isLogin: scr === 'login', isApp: scr !== 'login', collapsed, expanded: !collapsed,
      navRef: this.navRef, showTagline: !collapsed && (s.vh || 900) >= 860,
      sideW: collapsed ? 'var(--sidebar-w-collapsed)' : 'var(--sidebar-w)', sideIcon: collapsed ? 'keyboard_double_arrow_right' : 'keyboard_double_arrow_left',
      toggleSide: () => { this.setState({ side: !collapsed }); try { localStorage.setItem('och_side', collapsed ? '0' : '1'); } catch (e) {} this.revealActive(); },
      navItems, title, subtitle, actions, hasActions: actions.length > 0 || !!period, hasPeriod: !!period, period,
      kpis, hasKpis: kpis.length > 0,
      screenLabel: String(idx + 1).padStart(2, '0') + ' ' + (NAV[idx] ? NAV[idx][2] : ''),
      isDashboard: scr === 'dashboard', isList: !!cfg, isMatching: scr === 'matching', isRapports: scr === 'rapports', isParams: scr === 'parametres', titleC: scr === 'parametres' ? 'var(--c-info-fg)' : 'var(--c-ink)', subtitleC: scr === 'parametres' ? 'var(--c-info-fg)' : 'var(--c-muted)',
      menuOpen: s.menu, toggleMenu: () => this.setState({ menu: !s.menu, notif: false }), logout: () => this.confirmLogout(),
      ...this.topVals(s),
      mo: this.modalVals(s), closeMenus: () => this.setState({ rowMenu: null, more: false, notif: false, menu: false, pickOpen: false }),
      hasToast: !!s.toast, toast: s.toast || '', closeToast: () => this.setState({ toast: null, toastRetry: null }), toastIcon: { info: 'hourglass_top', error: 'error' }[s.toastTone] || 'check_circle', toastIconC: { info: 'var(--c-on-dark-muted)', error: 'var(--c-danger-bg)' }[s.toastTone] || 'var(--c-brand-green)', hasToastRetry: !!s.toastRetry, toastRetry: () => { const r = s.toastRetry; this.setState({ toast: null, toastRetry: null }); if (r) r(); },
      cellPad: 'var(--space-3)'
    };

    if (out.isLogin) {
      const a = s.auth, E = s.err;
      out.auth = {
        isLogin: a === 'login', isForgot: a === 'forgot', isSent: a === 'sent', isOtp: a === 'otp',
        features: [{ icon: 'verified_user', label: 'Données\nsécurisées' }, { icon: 'monitoring', label: 'Suivi en\ntemps réel' }, { icon: 'groups', label: 'Accès\ncontrôlé' }],
        email: s.email, pwd: s.pwd, pwdType: s.showPwd ? 'text' : 'password', eyeIcon: s.showPwd ? 'visibility_off' : 'visibility',
        onEmail: e => this.setState({ email: e.target.value, err: '' }), onPwd: e => this.setState({ pwd: e.target.value, err: '' }),
        togglePwd: () => this.setState({ showPwd: !s.showPwd }), toggleRemember: () => this.setState({ remember: !s.remember }),
        cbBg: s.remember ? 'var(--c-primary)' : 'var(--c-surface)', cbBorder: s.remember ? 'var(--c-primary)' : 'var(--c-line-hover)', cbIcon: s.remember ? 'check' : '',
        hasErr: !!E, err: E, emailBorder: E && /e-mail/.test(E) ? 'var(--c-danger)' : 'var(--c-line-strong)', pwdBorder: E && /passe/.test(E) ? 'var(--c-danger)' : 'var(--c-line-strong)',
        submit: e => { e.preventDefault(); if (!s.email.trim()) return this.setState({ err: 'Saisissez votre adresse e-mail professionnelle.' }); if (!s.pwd) return this.setState({ err: 'Saisissez votre mot de passe.' }); this.setState({ auth: 'otp', otp: '', err: '' }); },
        goForgot: e => { e && e.preventDefault(); this.setState({ auth: 'forgot', err: '' }); },
        goLogin: e => { e && e.preventDefault && e.preventDefault(); this.setState({ auth: 'login', err: '', otp: '' }); },
        sendReset: e => { e.preventDefault(); if (!s.email.trim()) return this.setState({ err: 'Saisissez votre adresse e-mail.' }); this.setState({ auth: 'sent', err: '' }); },
        otp: s.otp, onOtp: e => this.setState({ otp: e.target.value.replace(/\D/g, '').slice(0, 6), err: '' }),
        otpDigits: Array.from({ length: 6 }, (_, i) => ({ d: s.otp[i] || '', border: i === s.otp.length ? 'var(--c-primary)' : (s.otp[i] ? 'var(--c-primary-400)' : 'var(--c-line-strong)') })),
        verify: e => { e.preventDefault(); if (s.otp.length < 6) return this.setState({ err: 'Le code doit contenir 6 chiffres.' }); this.setState({ auth: 'login', otp: '', pwd: '' }); this.go('dashboard'); },
        maskedEmail: (s.email || 'n.yao@ochantier.ci').replace(/^(.).*(@.*)$/, '$1•••$2')
      };
    }

    if (cfg) {
      const q = (s.q[scr] || '').toLowerCase(), tab = s.tab[scr] || 0, sel = s.sel[scr] || [];
      const tabs = scr === 'besoins' ? [] : (cfg.tabs || []);
      const isW = scr === 'ouvriers', wErr = isW && this.props.etatOuvriers === 'Erreur de chargement' && !s.wListOk, isC = scr === 'commerciaux', cErr = isC && this.props.etatCommerciaux === 'Erreur de chargement' && !s.cListOk, isCh = scr === 'chantiers', chErr = isCh && this.props.etatChantiers === 'Erreur de chargement' && !s.chListOk, isBo = scr === 'besoins', boErr = isBo && this.props.etatBesoins === 'Erreur de chargement' && !s.boListOk, isAl = scr === 'avis', isPg0 = isW || isC || isCh || isBo || isAl, isPg = true, lErr = wErr || cErr || chErr || boErr, pk = isC ? 'cpage' : isCh ? 'chpage' : isBo ? 'bopage' : isAl ? 'alpage' : isW ? 'wpage' : scr + 'Page';
      const all = (scr === 'promoteurs' ? this.promoRows() : isW ? this.wRows() : isC ? this.cRows() : isCh ? this.chRows() : isBo ? this.boRows(tab) : isAl ? this.alRows() : cfg.rows).map((r, i) => ({ r, i, txt: this.txt(r) + (r.txtx ? ' ' + r.txtx : '') }));
      const match = (x, ti) => { const m = tabs[ti] && tabs[ti][1]; return !m || (isAl ? x.r.type === m : x.txt.includes(m.toLowerCase())); };
      let vis = all.filter(x => match(x, tab) && (!q || x.txt.includes(q))); if (scr === 'promoteurs') vis = this.promoFilterSort(vis); if (isW) vis = this.wFilterSort(vis); if (isC) vis = this.cFilterSort(vis); if (isCh) vis = this.chFilterSort(vis); if (isBo) vis = this.boFilterSort(vis, tab); if (isAl) vis = this.alFilterSort(vis); const LX = (s.lx || {})[scr] || {}; Object.entries(LX).forEach(([ci, v]) => { if (v) vis = vis.filter(x => { const c = x.r[+ci]; return c && this.lxVal(c) === v; }); }); if (lErr) vis = []; const lf = (s.lf || {})[scr]; if (lf) vis = vis.filter(x => x.r.some(c => c && c.v === lf));
      const allSel = vis.length > 0 && vis.every(x => sel.includes(x.i));
      const setSel = v => this.setState({ sel: { ...s.sel, [scr]: v } });
      const bw = cfg.b; const listTotal = scr === 'promoteurs' ? String(241 + Object.keys(this.P).length) : isPg0 ? (lErr ? '—' : String(all.length)) : cfg.total;
      const WP = 8, pc = isPg ? Math.max(1, Math.ceil(vis.length / WP)) : 1, pg = isPg ? Math.min(s[pk] || 1, pc) : 1, pageVis = isPg ? vis.slice((pg - 1) * WP, pg * WP) : vis;
      out.list = {
        title: cfg.lt, icon: cfg.li, total: listTotal, unit: cfg.unit, searchPh: cfg.ph, filters: scr === 'promoteurs' ? this.promoFilters() : isW ? this.wFilters() : isC ? this.cFilters() : isCh ? this.chFilters() : isBo ? this.boFilters(tab) : isAl ? this.alFilters() : this.lxBase(scr, cfg, all, pk), cols: this.listCols(scr, cfg),
        rowActions: cfg.act || ['visibility', 'edit', 'more_vert'],
        hasLf: !!lf, lfLabel: 'Filtre : ' + (lf || ''), clearLf: () => this.setState({ lf: { ...(this.state.lf || {}), [scr]: null } }), q: s.q[scr] || '', onQ: e => this.setState({ q: { ...s.q, [scr]: e.target.value }, [pk]: 1 }),
        hasTabs: tabs.length > 0,
        tabs: tabs.map((tb, ti) => { const on = ti === tab; return { label: tb[0], count: all.filter(x => match(x, ti)).length, go: () => this.setState({ tab: { ...s.tab, [scr]: ti }, [pk]: 1 }), line: on ? 'var(--c-primary)' : 'transparent', fg: on ? 'var(--c-primary)' : 'var(--c-muted)', cBg: on ? 'var(--c-primary)' : 'var(--c-neutral-bg)', cFg: on ? 'var(--c-surface)' : 'var(--c-neutral-fg)' }; }),
        rows: s.loading ? [] : pageVis.map(x => { const on = sel.includes(x.i); return { ...this.rowActs(scr, x, cfg), cells: x.r.map(c => this.norm(c)), bg: on ? 'var(--c-primary-50)' : 'var(--c-surface)', cbBg: on ? 'var(--c-primary)' : 'var(--c-surface)', cbBorder: on ? 'var(--c-primary)' : 'var(--c-line-hover)', cbIcon: on ? 'check' : '', toggle: () => setSel(on ? sel.filter(j => j !== x.i) : [...sel, x.i]) }; }),
        empty: !s.loading && vis.length === 0 && !lErr && !(isPg0 && !all.length), loading: s.loading, isError: lErr && !s.loading, showTable: !isCh || (s.chView || 'table') === 'table', errTitle: isBo ? 'Impossible de charger les besoins et offres' : isCh ? 'Impossible de charger les chantiers' : isC ? 'Impossible de charger les commerciaux' : 'Impossible de charger les ouvriers', ndIcon: isC ? 'badge' : 'engineering', ndTitle: isC ? 'Aucun commercial enregistré' : 'Aucun ouvrier enregistré', ndText: isC ? 'Créez un premier commercial pour recruter des ouvriers sur le terrain.' : 'Les ouvriers inscrits par les commerciaux apparaîtront ici.', ndAction: isC ? 'Ajouter un commercial' : 'Ajouter un ouvrier', retry: () => { clearTimeout(this._lt); this.setState({ wListOk: true, cListOk: true, chListOk: true, loading: true }); this._lt = setTimeout(() => this.setState({ loading: false }), 500); }, noData: isPg0 && !all.length && !s.loading, addNew: isC ? () => this.croute('/commerciaux/nouveau') : () => this.wroute('/ouvriers/nouveau'), exportSel: isAl ? () => this.alExport(sel.map(i => all[i] && all[i].r).filter(Boolean)) : isBo ? () => this.boExport(sel.map(i => all[i] && all[i].r).filter(Boolean), tab) : isCh ? () => this.chExport(sel.map(i => all[i] && all[i].r.ch).filter(Boolean)) : isW ? () => this.wExport(sel.map(i => all[i] && all[i].r.w).filter(Boolean)) : isC ? () => this.cExport(sel.map(i => all[i] && all[i].r.c).filter(Boolean)) : null, prev: isPg ? () => this.setState({ [pk]: Math.max(1, pg - 1) }) : null, next: isPg ? () => this.setState({ [pk]: Math.min(pc, pg + 1) }) : null, skel: [1, 2, 3, 4, 5, 6], ...this.lxMore(scr, cfg, all, pk), prevC: pg > 1 ? 'var(--c-ink)' : 'var(--c-subtle)', nextC: pg < pc ? 'var(--c-ink)' : 'var(--c-subtle)', prevCur: pg > 1 ? 'pointer' : 'default', nextCur: pg < pc ? 'pointer' : 'default', bulkLabel: 'Valider', bulkIcon: 'done_all',
        askConfirm: isBo ? () => this.boBulk(sel.map(i => all[i] && all[i].r).filter(Boolean), setSel, tab) : isW ? () => { const ws = sel.map(i => all[i] && all[i].r.w).filter(Boolean), todo = ws.filter(w => ['À vérifier', 'En vérification', 'Profil incomplet', 'Rejeté'].includes(w.status)); if (!todo.length) return this.toast('Aucun profil à valider dans la sélection.', { tone: 'error' }); this.openModal({ icon: 'done_all', tone: 'success', title: `Valider ${todo.length} profil${todo.length > 1 ? 's' : ''} ?`, body: todo.map(w => w.name).join(', ') + '. Les profils déjà validés sont ignorés.', ok: 'Valider', after: () => this.wAct(() => { todo.forEach(w => { const o = w.status; w.status = 'Validé'; if (w.avail === 'Indisponible') w.avail = 'Disponible'; this.wlog(w, 'validation', 'a validé le profil', o, 'Validé', 'Validation groupée'); }); setSel([]); }, `${todo.length} profil${todo.length > 1 ? 's validés' : ' validé'}.`) }); } : () => this.openModal({ icon: 'done_all', title: 'Valider ' + sel.length + (sel.length > 1 ? ' éléments ?' : ' élément ?'), body: 'Les éléments sélectionnés seront marqués comme validés. L’action est enregistrée dans le journal d’audit.', ok: 'Valider', after: () => { setSel([]); this.toast(sel.length + (sel.length > 1 ? ' éléments validés' : ' élément validé') + ' avec succès.'); } }),
        allBg: allSel ? 'var(--c-primary)' : 'var(--c-surface)', allBorder: allSel ? 'var(--c-primary)' : 'var(--c-line-hover)', allIcon: allSel ? 'check' : '',
        toggleAll: () => setSel(allSel ? [] : vis.map(x => x.i)),
        hasSel: sel.length > 0, selLabel: sel.length + (sel.length > 1 ? ' éléments sélectionnés' : ' élément sélectionné'), clearSel: () => setSel([]),
        pageLabel: isPg ? `${vis.length ? (pg - 1) * WP + 1 : 0}–${Math.min(pg * WP, vis.length)} sur ${vis.length}` : `1–${vis.length} sur ${listTotal}`,
        pages: isPg ? (pc > 7 ? [...new Set([1, pg - 1, pg, pg + 1, pc].filter(n => n >= 1 && n <= pc))].sort((x, y) => x - y).reduce((acc, n, i, arr) => { if (i && n - arr[i - 1] > 1) acc.push('…'); acc.push(n); return acc; }, []) : Array.from({ length: pc }, (_, i) => i + 1)).map(n => { if (n === '…') return { n, go: null, bg: 'var(--c-surface)', fg: 'var(--c-ink-2)', border: 'transparent' }; const on = n === pg; return { n, go: () => this.setState({ [pk]: n }), bg: on ? 'var(--c-primary)' : 'var(--c-surface)', fg: on ? 'var(--c-surface)' : 'var(--c-ink-2)', border: on ? 'var(--c-primary)' : 'var(--c-line-strong)' }; }) : cfg.pages.map(n => ({ n, go: null, bg: n === 1 ? 'var(--c-primary)' : 'var(--c-surface)', fg: n === 1 ? 'var(--c-surface)' : 'var(--c-ink-2)', border: n === 1 ? 'var(--c-primary)' : (n === '…' ? 'transparent' : 'var(--c-line-strong)') })),
        bottom: scr === 'promoteurs' ? this.promoBottom() : isW ? this.wBottom() : isC ? this.cBottom() : isCh ? this.chBottom() : isBo ? this.boBottom() : isAl ? this.alBottom() : { title: bw.title, icon: bw.icon, iconC: bw.warn ? 'var(--c-accent-600)' : 'var(--c-primary)', cta: bw.cta, ctaGo: null, hasActive: false, activeLabel: '', clear: null, items: bw.items.map(b => ({ ...b, bg: TN[b.tn][0], fg: TN[b.tn][1], go: null, on: false, off: false, border: 'var(--c-line)', cardBg: 'var(--c-surface)', cursor: 'pointer', op: 1, arrow: 'arrow_forward', arrowC: 'var(--c-ink-2)' })) }
      };
    }

    if (cfg && scr === 'besoins') { Object.assign(out.list, this.boListExtra(s)); const nAdv = this.boFilterDefs(s.tab.besoins || 0).filter(d => d.adv).length; Object.assign(out.list, { moreOpen: false, moreLabel: s.boMore ? 'Moins de filtres' : 'Plus de filtres' + (nAdv ? ' (+' + nAdv + ')' : ''), moreBd: s.boMore ? 'var(--c-primary-400)' : 'var(--c-line-strong)', moreBg: s.boMore ? 'var(--c-primary-50)' : 'var(--c-surface)', moreIcon: s.boMore ? 'filter_alt_off' : 'filter_alt' }); }
    if (out.isDashboard) out.db = this.dashVals(s);
    if (out.isParams) out.pr = this.paramsVals(s);

    if (scr === 'matching') Object.assign(out, this.mxVals(s));

    Object.assign(out, scr === 'promoteurs' ? this.promoVals(s) : { isPromoModule: false, drawer: { open: false }, hasCrumbs: false, crumbs: [] });
    out.hasCotm = false; out.isAbo = false;
    if (scr === 'ouvriers') Object.assign(out, this.workerVals(s)); else if (scr === 'commerciaux') Object.assign(out, this.commVals(s)); else if (scr === 'chantiers') Object.assign(out, this.chVals(s)); else if (scr === 'besoins') Object.assign(out, this.boVals(s)); else if (scr === 'affectations') { Object.assign(out, this.ofxVals(s)); if (out.hasOfx) out.isList = false; } else if (scr === 'pointages') Object.assign(out, this.ptVals(s)); else if (scr === 'paiements') Object.assign(out, this.pyVals(s)); else if (scr === 'finance') Object.assign(out, this.finVals(s)); else if (scr === 'avis' && this.alSub(s)) Object.assign(out, this.alVals(s)); else if (scr === 'abonnements') Object.assign(out, this.aboVals(s)); else if (scr !== 'matching' || out.isMatching) out.isWorkerModule = false; if (scr !== 'pointages' && scr !== 'finance' && scr !== 'paiements' && !(scr === 'avis' && this.alSub(s))) out.hasPtTop = false; if (scr !== 'paiements') out.hasPyTop = false; if (out.ptTop) out.ptTop = { ...out.ptTop, navFull: !out.ptTop.compact, perPills: !out.ptTop.compact };
    if (scr !== 'chantiers') out.hasChViews = false;
    if (scr !== 'affectations' || !out.hasOfx) { out.hasOfx = false; out.ofx = { chips: [], rows: [] }; out.ab = { chips: [], rows: [] }; }
    if (scr !== 'besoins') { out.hasBoViews = false; out.bov = { kpis: [], chips: [], views: [], cards: [] }; }
    if (out.wm) { out.wm.missTitle = out.wm.missTitle || 'Ouvrier introuvable'; out.wm.missText = out.wm.missText || 'Le profil demandé n’existe pas ou a été supprimé.'; }
    if (out.wm && out.wm.form) { const F = out.wm.form; F.hasExtra = !!F.hasExtra; F.extra = F.extra || []; F.hasAddLine = !!F.hasAddLine; F.addLineLabel = F.addLineLabel || ''; F.addLine = F.addLine || null; (F.sections || []).forEach(sec => { sec.canRemoveLine = !!sec.canRemoveLine; sec.removeLine = sec.removeLine || null; sec.removeLabel = sec.removeLabel || ''; (sec.docs || []).forEach(d => { d.canDelete = !!d.canDelete; d.del = d.del || null; }); }); }
    if (scr === 'rapports') this.rpVals(s, out);
    if (s.gd) out.drawer = this.genDrawer(s.gd);
    return out;
  }
});
