'use strict';
// Formulaires génériques et modales (openModal, validation)
// Couche de la classe Component : voir src/js/och.js (OCH.extend).
OCH.extend(Base => class extends Base {
  initForm(mode, id) { const p = id ? this.P[id] : null; const values = {}; this.FF.forEach(f => { values[f.key] = p ? (p[f.key] || '') : ''; }); return { mode, id, values, dirty: false, submitted: false }; }
  setField(k, v) { this.setState(st => ({ form: { ...st.form, values: { ...st.form.values, [k]: v }, dirty: true } })); }
  validate(f) {
    const secs = f.mode === 'entreprise' ? ['ent', 'rep'] : ['ent', 'rep', 'acc'], e = {}, v = f.values;
    this.FF.filter(x => secs.includes(x.sec)).forEach(x => { if (x.req && !String(v[x.key] || '').trim()) e[x.key] = 'Ce champ est obligatoire.'; });
    if (v.email && !/^[^\s@]+@[^\s@]+\.[a-z]{2,}$/i.test(v.email)) e.email = 'Adresse e-mail invalide.';
    if (v.phone && this.telLocal(v.phone).length !== 10) e.phone = 'Le numéro doit contenir 10 chiffres.';
    if (v.rccm && !/^CI-[A-Z]{3}-\d{4}-[A-Z]-\d{3,6}$/i.test(v.rccm.trim())) e.rccm = 'Format attendu : CI-ABJ-2026-B-00000.';
    return e;
  }
  saveForm(ev) {
    ev && ev.preventDefault && ev.preventDefault();
    const f = this.state.form; if (!f) return;
    const errs = this.validate(f);
    if (Object.keys(errs).length) { this.setState({ form: { ...f, submitted: true } }); window.scrollTo(0, 0); return; }
    const isNew = f.mode === 'new', v = f.values;
    this.openModal({ icon: isNew ? 'person_add' : 'save', title: isNew ? 'Créer ce promoteur ?' : 'Confirmer la modification', ok: isNew ? 'Créer le promoteur' : 'Enregistrer', okIcon: 'check',
      body: isNew ? `Le compte « ${v.name} » sera créé avec le statut « À valider ». Un e-mail d’activation sera envoyé à ${v.email}.` : `Les informations de « ${v.name} » seront mises à jour. L’action est enregistrée dans l’historique.`,
      after: () => {
        let id = f.id;
        if (isNew) {
          id = v.name.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'promoteur';
          while (this.P[id]) id += '-2';
          this.P = { [id]: this.mkPromo({ ...v, id, status: 'À valider', payst: 'En attente', inscrit: '23 sept. 2026', last: 'À l’instant', chi: [], ofi: [], payi: [], avi: [], liti: [], dc: 'mmmmm' }), ...this.P };
          this.logEv(this.P[id], 'a créé le compte promoteur', v.name, 'person_add', 'success', '');
        } else { Object.assign(this.P[id], v); this.logEv(this.P[id], f.mode === 'entreprise' ? 'a modifié les informations de l’entreprise' : 'a modifié le profil', v.name, 'edit', 'purple', ''); }
        this.setState({ form: null });
        this.route('/promoteurs/' + id + (f.mode === 'entreprise' ? '/kyc' : ''), true);
        this.toast(isNew ? `Promoteur « ${v.name} » créé.` : 'Modifications enregistrées.');
      } });
  }
  formVals(s) {
    const f = s.form; if (!f) return { sections: [] };
    const secs = f.mode === 'entreprise' ? ['ent', 'rep'] : ['ent', 'rep', 'acc'];
    const errs = this.validate(f), show = f.submitted, n = Object.keys(errs).length;
    const SECT = { ent: ['Entreprise', 'Identité légale et adresse du siège.', 'domain'], rep: ['Représentant légal', 'Personne habilitée à engager l’entreprise.', 'badge'], acc: ['Compte', 'Paramètres du compte sur la plateforme.', 'manage_accounts'] };
    return {
      sections: secs.map(k => ({ title: SECT[k][0], desc: SECT[k][1], icon: SECT[k][2], fields: this.FF.filter(x => x.sec === k).map(x => { const err = show ? (errs[x.key] || '') : ''; return { label: x.label, req: !!x.req, isText: !x.type || x.type === 'email', isTel: x.type === 'tel', ...this.telVm(f.values[x.key], val => this.setField(x.key, val), x.label), isSelect: x.type === 'select', isArea: x.type === 'textarea', inputType: x.type || 'text', ph: x.ph || '', value: f.values[x.key] || '', onChange: e => this.setField(x.key, e.target.value), options: (x.options || []).map(o => ({ v: o, l: o || 'Sélectionner…' })), hasErr: !!err, err, border: err ? 'var(--c-danger)' : 'var(--c-line-strong)', col: x.span === 2 ? '1 / -1' : 'auto' }; }) })),
      hasSummary: show && n > 0, summary: `${n} champ${n > 1 ? 's' : ''} à corriger avant d’enregistrer.`,
      dirty: f.dirty, save: e => this.saveForm(e), cancel: () => this.route(f.id ? '/promoteurs/' + f.id + (f.mode === 'entreprise' ? '/kyc' : '') : '/promoteurs'),
      saveLabel: f.mode === 'new' ? 'Créer le promoteur' : 'Enregistrer les modifications'
    };
  }

  openModal(m) { this.setState({ modal: m, mv: { reason: '', motif: (m.motifs || [])[0] || '', canal: ['Notification push', 'E-mail'], objet: m.objet || '', message: '', docType: m.docType || 'Attestation fiscale', ref: '', exp: '', file: '', f: Object.fromEntries((m.fields || []).map(x => [x.key, x.value != null ? x.value : ''])) }, merr: {} }); }
  modalOk() {
    const { modal: m, mv } = this.state, e = {};
    if (m.kind === 'reason' && mv.reason.trim().length < (m.minLen || 10)) e.reason = `Ce champ est obligatoire (${m.minLen || 10} caractères minimum).`;
    if (m.kind === 'fields') (m.fields || []).forEach(fl => { const v = (mv.f || {})[fl.key], s = Array.isArray(v) ? v.filter(x => String(x).trim()).join('\n') : String(v == null ? '' : v).trim(); if (fl.req && !s) e['f_' + fl.key] = 'Ce champ est obligatoire.'; else if (fl.minLen && s.length < fl.minLen) e['f_' + fl.key] = fl.minLen + ' caractères minimum.'; else if (fl.validate) { const r = fl.validate(v, mv.f || {}); if (r) e['f_' + fl.key] = r; } });
    if (m.kind === 'notif') { if (!mv.objet.trim()) e.objet = 'L’objet est obligatoire.'; if (!mv.message.trim()) e.message = 'Le message est obligatoire.'; if (!mv.canal.length) e.canal = 'Choisissez au moins un canal.'; }
    if (m.kind === 'doc') { if (!mv.ref.trim()) e.ref = 'La référence est obligatoire.'; if (!mv.file) e.file = 'Ajoutez un fichier (PDF, JPG ou PNG).'; }
    if (Object.keys(e).length) return this.setState({ merr: e });
    this.setState({ modal: null }); m.after && m.after(mv);
  }
  modalVals(s) {
    const m = s.modal; if (!m) return { open: false };
    const mv = s.mv || {}, me = s.merr || {}, kind = m.kind || 'confirm', leave = kind === 'leave';
    const tone = leave ? 'danger' : (m.tone || 'primary');
    const set = k => e => this.setState({ mv: { ...this.state.mv, [k]: e.target.value }, merr: { ...this.state.merr, [k]: '' } });
    const T = { primary: ['var(--c-primary-100)', 'var(--c-primary-600)'], danger: ['var(--c-danger-bg)', 'var(--c-danger-fg)'], warning: ['var(--c-warning-bg)', 'var(--c-warning-fg)'], success: ['var(--c-success-bg)', 'var(--c-success-fg)'] }[tone];
    const bd = k => me[k] ? 'var(--c-danger)' : 'var(--c-line-strong)';
    const body = leave ? 'Les modifications saisies dans ce formulaire seront perdues.' : (m.body || '');
    return {
      open: true, title: leave ? 'Quitter sans enregistrer ?' : m.title, body, hasBody: !!body, icon: leave ? 'warning' : (m.icon || 'help'), iconBg: T[0], iconFg: T[1],
      ok: leave ? 'Quitter sans enregistrer' : (m.ok || 'Confirmer'), okIcon: m.okIcon || (tone === 'danger' || leave ? '' : 'check'), okVariant: tone === 'danger' ? 'danger' : 'primary', cancel: leave ? 'Continuer la saisie' : (m.cancel || 'Annuler'), hasAlt: !!m.alt, altLabel: m.alt ? m.alt.label : '', doAlt: () => { this.setState({ modal: null }); if (m.alt) m.alt.go(); },
      doOk: () => this.modalOk(), doCancel: () => this.setState({ modal: null }),
      hasReason: kind === 'reason', reasonLabel: m.reasonLabel || 'Motif *', reason: mv.reason, onReason: set('reason'), reasonErr: me.reason || '', reasonBorder: bd('reason'), reasonCount: (mv.reason || '').length + ' / 500',
      hasMotifs: !!m.motifs, motifs: (m.motifs || []).map(v => ({ v, l: v })), motif: mv.motif, onMotif: set('motif'),
      hasNotif: kind === 'notif', recipient: m.recipient || '',
      canals: ['Notification push', 'SMS', 'E-mail'].map(c => { const on = (mv.canal || []).includes(c); return { label: c, icon: on ? 'check_box' : 'check_box_outline_blank', bg: on ? 'var(--c-primary-50)' : 'var(--c-surface)', border: on ? 'var(--c-primary-400)' : 'var(--c-line-strong)', toggle: () => this.setState({ mv: { ...this.state.mv, canal: on ? mv.canal.filter(x => x !== c) : [...mv.canal, c] }, merr: { ...this.state.merr, canal: '' } }) }; }),
      canalErr: me.canal || '', objet: mv.objet, onObjet: set('objet'), objetErr: me.objet || '', objetBorder: bd('objet'), message: mv.message, onMessage: set('message'), messageErr: me.message || '', messageBorder: bd('message'),
      hasDoc: kind === 'doc', docTypes: (m.docTypes || this.DOCTYPES).map(v => ({ v, l: v })), width: kind === 'fields' ? 'var(--modal-w-lg)' : 'var(--modal-w)',
      hasFields: kind === 'fields', fields: (m.fields || []).map(fl => { const t = fl.type || 'text', v = (mv.f || {})[fl.key], err = me['f_' + fl.key] || ''; const setF = val => this.setState({ mv: { ...this.state.mv, f: { ...(this.state.mv.f || {}), [fl.key]: val } }, merr: { ...this.state.merr, ['f_' + fl.key]: '' } }); return { label: fl.label, req: !!fl.req, col: fl.span === 2 ? '1 / -1' : 'auto', isText: ['text', 'date', 'time', 'number', 'tel', 'email'].includes(t), inputType: t === 'number' ? 'text' : t, value: v == null ? '' : String(v), ph: fl.ph || '', onChange: ev => setF(ev.target.value), isSelect: t === 'select', options: [{ v: '', l: 'Sélectionner…' }].concat((fl.options || []).map(o => ({ v: o, l: o }))), isArea: t === 'textarea', isLines: t === 'lines', lines: t === 'lines' ? (Array.isArray(v) && v.length ? v : ['']).map((x, j, A) => ({ v: x, ph: (fl.ph || 'Ex. : Rapport global de fin de chantier'), aria: fl.label + ' ' + (j + 1), upOp: j === 0 ? '.35' : '1', onChange: ev => { const B = A.slice(); B[j] = ev.target.value; setF(B); }, up: () => { if (!j) return; const B = A.slice(); [B[j - 1], B[j]] = [B[j], B[j - 1]]; setF(B); }, del: () => { const B = A.filter((_, i) => i !== j); setF(B.length ? B : ['']); } })) : [], addLine: () => setF((Array.isArray(v) && v.length ? v : ['']).concat([''])), isFile: t === 'file', fileLabel: v || 'Glissez un fichier ou cliquez pour parcourir', onFile: ev => { const x = ev.target.files && ev.target.files[0]; setF(x ? x.name + ' · ' + Math.max(1, Math.round(x.size / 1024)) + ' Ko' : ''); }, hasHint: !!fl.hint, hint: fl.hint || '', hasErr: !!err, err, border: err ? 'var(--c-danger)' : 'var(--c-line-strong)' }; }), docType: mv.docType, onDocType: set('docType'), ref: mv.ref, onRef: set('ref'), refErr: me.ref || '', refBorder: bd('ref'), exp: mv.exp, onExp: set('exp'),
      onFile: e => { const fl = e.target.files && e.target.files[0]; this.setState({ mv: { ...this.state.mv, file: fl ? fl.name + ' · ' + Math.max(1, Math.round(fl.size / 1024)) + ' Ko' : '' }, merr: { ...this.state.merr, file: '' } }); },
      fileLabel: mv.file || 'Glissez un fichier ou cliquez pour parcourir', fileErr: me.file || '', fileBorder: bd('file')
    };
  }

});
