'use strict';
// Carte KPI : valeur, unité, icône, variation
OCH.component('StatCard', {
  template: 'src/components/StatCard.html',
  props: {"label": {"editor": "text", "default": "Total promoteurs", "tsType": "string"}, "value": {"editor": "text", "default": "248", "tsType": "string"}, "unit": {"editor": "text", "default": "", "tsType": "string"}, "icon": {"editor": "text", "default": "apartment", "tsType": "string"}, "tone": {"editor": "enum", "options": ["success", "warning", "orange", "danger", "info", "purple", "neutral"], "default": "success", "tsType": "string"}, "delta": {"editor": "text", "default": "+12 %", "tsType": "string"}, "deltaTone": {"editor": "enum", "options": ["success", "warning", "danger"], "default": "success", "tsType": "string"}, "deltaIcon": {"editor": "text", "default": "north_east", "tsType": "string"}, "compare": {"editor": "text", "default": "vs mois dernier", "tsType": "string"}, "hint": {"editor": "text", "default": "", "tsType": "string"}, "highlight": {"editor": "boolean", "default": false, "tsType": "boolean"}},
  logic: DCLogic =>
  class Component extends DCLogic {
    renderVals() {
      const p = this.props;
      const alias = { g: 'success', a: 'warning', o: 'orange', r: 'danger', b: 'info', p: 'purple', n: 'neutral' };
      const t = alias[p.tone] || p.tone || 'success';
      const dt = alias[p.deltaTone] || p.deltaTone || 'success';
      return {
        label: p.label, value: p.value, valueFs: String(p.value || '').length + (p.unit ? p.unit.length + 1 : 0) > 12 ? 'var(--fs-3xl)' : 'var(--fs-4xl)', unit: p.unit, hasUnit: !!p.unit, icon: p.icon || 'insights',
        iconBg: `var(--c-${t}-bg)`, iconFg: `var(--c-${t}-fg)`,
        deltaIcon: p.deltaIcon || 'north_east', delta: p.delta, hasDelta: !!p.delta, deltaC: `var(--c-${dt}-fg)`, compare: p.compare || 'vs mois dernier',
        hint: p.hint, hasHint: !!p.hint,
        cardBg: p.highlight ? 'var(--c-warning-surface)' : 'var(--c-surface)', cardLine: p.highlight ? 'var(--c-warning-line)' : 'var(--c-line)'
      };
    }
  }
});
