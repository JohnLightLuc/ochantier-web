'use strict';
// Avatar : initiales colorées, équipe, organisation ou marque
OCH.component('Avatar', {
  template: 'src/components/Avatar.html',
  props: {"name": {"editor": "text", "default": "Koffi Adjoumani", "tsType": "string"}, "kind": {"editor": "enum", "options": ["person", "team", "org", "brand"], "default": "person", "tsType": "'person'|'team'|'org'|'brand'"}, "size": {"editor": "enum", "options": ["sm", "md", "lg"], "default": "md", "tsType": "'sm'|'md'|'lg'"}},
  logic: DCLogic =>
  class Component extends DCLogic {
    renderVals() {
      const p = this.props, name = p.fullName || '', kind = p.kind || 'person', size = p.size || 'md';
      let h = 0; for (const ch of name) h = (h * 31 + ch.charCodeAt(0)) >>> 0;
      const tones = ['success', 'warning', 'danger', 'info', 'purple'];
      const t = tones[h % tones.length];
      const ini = name.replace(/[’']/g, ' ').split(' ').filter(Boolean).map(w => w[0]).slice(-2).join('').toUpperCase();
      const S = { sm: ['var(--avatar-sm)', 'var(--fs-xs)', 'var(--icon-xs)'], md: ['var(--avatar-md)', 'var(--fs-sm)', 'var(--icon-sm)'], lg: ['var(--avatar-lg)', 'var(--fs-3xl)', 'var(--icon-lg)'] }[size];
      const K = {
        person: [`var(--c-${t}-bg)`, `var(--c-${t}-fg)`, 'var(--radius-round)', ini, ''],
        team: ['var(--c-primary-100)', 'var(--c-primary-600)', 'var(--radius-lg)', '', 'groups'],
        org: ['var(--c-neutral-bg)', 'var(--c-ink-2)', 'var(--radius-lg)', '', 'apartment'],
        brand: ['var(--c-primary-strong)', 'var(--c-accent)', 'var(--radius-round)', 'O’', '']
      }[kind] || [];
      return { name, s: S[0], fs: S[1], is: S[2], bg: K[0], fg: K[1], r: K[2], text: K[3], icon: K[4], hasIcon: !!K[4] };
    }
  }
});
