'use strict';
// Utilitaires partagés : formatage, couleurs, initiales, séries, dates
// Couche de la classe Component : voir src/js/och.js (OCH.extend).
OCH.extend(Base => class extends Base {
  norm(c) {
    const TN = this.TN;
    const o = { ...c, open: c.open || null, cursor: c.open ? 'pointer' : 'default', deco: c.open && c.link ? 'underline' : 'none', isTxt: c.k === 'txt', isPer: c.k === 'per', isBdg: c.k === 'bdg', isLoc: c.k === 'loc', isProg: c.k === 'prog', isRate: c.k === 'rate', isStk: c.k === 'stk', isThumb: c.k === 'thumb', isTime: c.k === 'time', isProof: c.k === 'proof' };
    if (o.isTxt) { o.fw = c.b ? 700 : 400; o.color = c.link ? 'var(--c-primary-600)' : c.m ? 'var(--c-muted)' : 'var(--c-ink)'; }
    if (o.isPer) { o.avKind = { t: 'team', o: 'org', x: 'brand' }[c.kind] || 'person';
      o.hasSub = !!c.sub; o.avText = ''; o.avIcon = ''; o.avR = '50%';
      if (c.kind === 't') { o.avIcon = 'groups'; o.avBg = 'var(--c-primary-100)'; o.avFg = 'var(--c-primary-600)'; o.avR = '10px'; }
      else if (c.kind === 'o') { o.avIcon = 'apartment'; o.avBg = 'var(--c-neutral-bg)'; o.avFg = 'var(--c-ink-2)'; o.avR = '10px'; }
      else if (c.kind === 'x') { o.avText = 'O’'; o.avBg = 'var(--c-primary-strong)'; o.avFg = 'var(--c-accent)'; }
      else { const [bg, fg] = this.av(c.name); o.avText = this.ini(c.name); o.avBg = bg; o.avFg = fg; }
    }
    if (o.isBdg) { o.icon = c.icon || ''; const [bg, fg] = TN[c.tn] || TN.n; o.bg = bg; o.fg = fg; o.hasIcon = !!c.icon; }
    if (o.isProg) { o.w = c.v + '%'; o.label = c.v + ' %'; o.barC = c.v < 50 ? 'var(--c-accent-600)' : 'var(--c-primary-500)'; }
    if (o.isRate) o.hasText = !!c.text;
    if (o.isStk) { o.hasSub = !!c.sub; o.hasLoc = !!c.hasLoc; o.fw = c.b || c.link ? 600 : 500; o.color = c.link ? 'var(--c-primary-600)' : 'var(--c-ink)'; }
    if (o.isThumb) { const h = this.hash(c.v); o.tBg = ['var(--c-primary-100)', 'var(--c-warning-bg)', 'var(--c-info-bg)', 'var(--c-purple-bg)'][h % 4]; o.tFg = ['var(--c-primary-600)', 'var(--c-warning-fg)', 'var(--c-info-fg)', 'var(--c-purple-fg)'][h % 4]; }
    if (o.isTime) { o.hasIcon = c.ok !== undefined; o.icon = c.ok ? 'check_circle' : 'cancel'; o.iconC = c.ok ? 'var(--c-success)' : 'var(--c-danger)'; o.hasNote = !!c.note; o.tColor = /\d/.test(c.v) ? 'var(--c-ink)' : 'var(--c-muted)'; }
    if (o.isProof) { const m = { ok: ['check_circle', 'var(--c-success)'], ko: ['cancel', 'var(--c-danger)'], none: ['remove', 'var(--c-neutral)'] }; [o.aIcon, o.aC] = m[c.a]; [o.bIcon, o.bC] = m[c.b]; }
    return o;
  }
  TN = { g: ['var(--c-success-bg)', 'var(--c-success-fg)'], a: ['var(--c-warning-bg)', 'var(--c-warning-fg)'], o: ['var(--c-orange-bg)', 'var(--c-orange-fg)'], r: ['var(--c-danger-bg)', 'var(--c-danger-fg)'], b: ['var(--c-info-bg)', 'var(--c-info-fg)'], p: ['var(--c-purple-bg)', 'var(--c-purple-fg)'], n: ['var(--c-neutral-bg)', 'var(--c-neutral-fg)'], R: ['var(--c-danger)', 'var(--c-surface)'] };
  hash(s) { let h = 0; for (const ch of s) h = (h * 31 + ch.charCodeAt(0)) >>> 0; return h; }
  ini(n) { return n.replace(/[’']/g, ' ').split(' ').filter(Boolean).map(w => w[0]).slice(-2).join('').toUpperCase(); }
  av(n) { const P = [['var(--c-success-bg)', 'var(--c-success-fg)'], ['var(--c-warning-bg)', 'var(--c-warning-fg)'], ['var(--c-danger-bg)', 'var(--c-danger-fg)'], ['var(--c-info-bg)', 'var(--c-info-fg)'], ['var(--c-purple-bg)', 'var(--c-purple-fg)']]; return P[this.hash(n) % P.length]; }
  txt(r) { return r.map(c => [c.v, c.name, c.sub, c.text].filter(Boolean).join(' ')).join(' ').toLowerCase(); }

  smooth(pts) {
    let d = `M${pts[0][0]},${pts[0][1]}`;
    for (let i = 0; i < pts.length - 1; i++) {
      const p0 = pts[i - 1] || pts[i], p1 = pts[i], p2 = pts[i + 1], p3 = pts[i + 2] || p2;
      d += ` C${p1[0] + (p2[0] - p0[0]) / 6},${p1[1] + (p2[1] - p0[1]) / 6} ${p2[0] - (p3[0] - p1[0]) / 6},${p2[1] - (p3[1] - p1[1]) / 6} ${p2[0]},${p2[1]}`;
    }
    return d;
  }
  series(vals, max, labels = []) {
    const n = vals.length, pts = vals.map((v, i) => [i / (n - 1) * 100, 100 - v / max * 100]);
    const line = this.smooth(pts);
    return { line, area: line + ' L100,100 L0,100 Z', dots: pts.map(([x, y], i) => ({ left: x + '%', top: y + '%', label: (labels[i] || '') + ' : ' + vals[i] })) };
  }

  pad(n) { return String(n).padStart(2, '0'); }
  isoOf(d) { return d.getFullYear() + '-' + this.pad(d.getMonth() + 1) + '-' + this.pad(d.getDate()); }
  addDays(iso, n) { const d = new Date(iso + 'T12:00:00'); d.setDate(d.getDate() + n); return this.isoOf(d); }
  wd(iso) { if (!iso) return '—'; const d = new Date(iso + 'T12:00:00'); return isNaN(d) ? String(iso) : d.toLocaleDateString('fr-FR', { day: '2-digit', month: 'short', year: 'numeric' }); }
});
