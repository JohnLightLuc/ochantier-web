'use strict';
// Bouton : variantes primaire, secondaire, fantôme, danger
OCH.component('Button', {
  template: 'src/components/Button.html',
  props: {"label": {"editor": "text", "default": "Ajouter un promoteur", "tsType": "string"}, "icon": {"editor": "text", "default": "add", "tsType": "string"}, "iconRight": {"editor": "text", "default": "", "tsType": "string"}, "variant": {"editor": "enum", "options": ["primary", "secondary", "ghost", "accent", "danger", "danger-soft"], "default": "primary", "tsType": "'primary'|'secondary'|'ghost'|'accent'|'danger'|'danger-soft'"}, "size": {"editor": "enum", "options": ["sm", "md", "lg"], "default": "md", "tsType": "'sm'|'md'|'lg'"}, "full": {"editor": "boolean", "default": false, "tsType": "boolean"}, "disabled": {"editor": "boolean", "default": false, "tsType": "boolean"}, "type": {"editor": "enum", "options": ["button", "submit"], "default": "button", "tsType": "string"}, "onClick": {"editor": null, "tsType": "() => void"}},
  logic: DCLogic =>
  class Component extends DCLogic {
    renderVals() {
      const p = this.props;
      const V = {
        primary: ['var(--c-primary)', 'var(--c-on-dark)', 'var(--c-primary)'],
        secondary: ['var(--c-surface)', 'var(--c-ink)', 'var(--c-line-strong)'],
        ghost: ['transparent', 'var(--c-primary-600)', 'transparent'],
        accent: ['var(--c-accent)', 'var(--c-primary-strong)', 'var(--c-accent)'],
        danger: ['var(--c-danger)', 'var(--c-on-dark)', 'var(--c-danger)'],
        'danger-soft': ['var(--c-danger-bg)', 'var(--c-danger-fg)', 'var(--c-danger-bg)']
      }[p.variant || 'secondary'] || [];
      const size = p.size || 'md';
      const iconOnly = !p.label;
      return {
        type: p.type || 'button', onClick: p.onClick, disabled: !!p.disabled,
        bg: V[0], fg: V[1], border: V[2],
        h: { sm: 'var(--control-xs)', md: 'var(--control-md)', lg: 'var(--control-lg)' }[size],
        px: iconOnly ? '0' : ({ sm: 'var(--space-3)', md: 'var(--space-4)', lg: 'var(--space-6)' }[size]),
        w: p.full ? '100%' : (iconOnly ? ({ sm: 'var(--control-xs)', md: 'var(--control-md)', lg: 'var(--control-lg)' }[size]) : 'auto'),
        fs: { sm: 'var(--fs-sm)', md: 'var(--fs-base)', lg: 'var(--fs-xl)' }[size],
        shadow: p.variant === 'primary' && size === 'lg' ? 'var(--shadow-primary)' : 'none',
        cursor: p.disabled ? 'not-allowed' : 'pointer', op: p.disabled ? 0.5 : 1,
        icon: p.icon, hasIcon: !!p.icon, label: p.label, hasLabel: !!p.label, iconRight: p.iconRight, hasIconRight: !!p.iconRight
      };
    }
  }
});
