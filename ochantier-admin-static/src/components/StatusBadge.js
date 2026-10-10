'use strict';
// Badge de statut coloré
OCH.component('StatusBadge', {
  template: 'src/components/StatusBadge.html',
  props: {"label": {"editor": "text", "default": "En cours", "tsType": "string"}, "tone": {"editor": "enum", "options": ["success", "warning", "orange", "danger", "danger-solid", "info", "purple", "neutral"], "default": "success", "tsType": "string"}, "icon": {"editor": "text", "default": "", "tsType": "string"}},
  logic: DCLogic =>
  class Component extends DCLogic {
    renderVals() {
      const p = this.props;
      const alias = { g: 'success', a: 'warning', o: 'orange', r: 'danger', b: 'info', p: 'purple', n: 'neutral', R: 'danger-solid' };
      const t = alias[p.tone] || p.tone || 'neutral';
      const solid = t === 'danger-solid';
      const base = solid ? 'danger' : t;
      return {
        label: p.label, icon: p.icon, hasIcon: !!p.icon,
        bg: solid ? 'var(--c-danger)' : `var(--c-${base}-bg)`,
        fg: solid ? 'var(--c-on-dark)' : `var(--c-${base}-fg)`
      };
    }
  }
});
