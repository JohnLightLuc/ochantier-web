'use strict';
// Bloc d’état : chargement, vide, erreur, accès limité
OCH.component('StateBlock', {
  template: 'src/components/StateBlock.html',
  props: {"kind": {"editor": "enum", "options": ["loading", "empty", "nodata", "error", "forbidden", "success", "partial", "missing", "expired"], "default": "nodata", "tsType": "string"}, "title": {"editor": "text", "default": "", "tsType": "string"}, "text": {"editor": "text", "default": "", "tsType": "string"}, "icon": {"editor": "text", "default": "", "tsType": "string"}, "actionLabel": {"editor": "text", "default": "", "tsType": "string"}, "compact": {"editor": "boolean", "default": false, "tsType": "boolean"}, "onAction": {"editor": null, "tsType": "() => void"}},
  logic: DCLogic =>
  class Component extends DCLogic {
    renderVals() {
      const p = this.props, kind = p.kind || 'nodata', compact = !!p.compact;
      const K = {
        loading: ['', 'primary', 'Chargement…', 'Récupération des données en cours.'],
        empty: ['search_off', 'neutral', 'Aucun résultat', 'Aucun élément ne correspond à vos critères. Modifiez la recherche ou les filtres.'],
        nodata: ['inbox', 'neutral', 'Aucune donnée', 'Rien à afficher pour le moment.'],
        error: ['cloud_off', 'danger', 'Impossible de charger les données', 'Une erreur est survenue. Vérifiez votre connexion puis réessayez.'],
        forbidden: ['lock', 'purple', 'Accès restreint', 'Votre profil ne permet pas de consulter cette information.'],
        success: ['task_alt', 'success', 'Opération réussie', ''],
        partial: ['rule', 'warning', 'Données partielles', 'Certaines informations sont encore en cours de synchronisation.'],
        missing: ['upload_file', 'warning', 'Document KYC manquant', 'Aucun fichier n’a été déposé pour ce document.'],
        expired: ['event_busy', 'orange', 'Document KYC expiré', 'Une version à jour doit être demandée au promoteur.']
      }[kind] || [];
      const tone = K[1] || 'neutral';
      const tinted = ['error', 'success', 'partial', 'missing', 'expired', 'forbidden'].includes(kind);
      const inline = compact || ['partial', 'success', 'expired', 'missing'].includes(kind) && p.inline !== false && compact;
      return {
        role: kind === 'error' || kind === 'forbidden' ? 'alert' : 'status',
        dir: compact ? 'row' : 'column', align: compact ? 'left' : 'center', gap: compact ? 'var(--space-3)' : 'var(--space-2)',
        pad: compact ? 'var(--space-3) var(--space-4)' : 'var(--space-8) var(--space-5)',
        bg: tinted ? `var(--c-${tone}-bg)` : 'var(--c-surface)',
        line: tinted ? 'transparent' : 'var(--c-line-strong)', lineStyle: tinted ? 'solid' : 'dashed',
        isSpin: kind === 'loading', hasIcon: kind !== 'loading',
        icon: p.icon || K[0], iconBg: tinted ? 'var(--c-surface)' : `var(--c-${tone}-bg)`, iconFg: `var(--c-${tone}-fg)`,
        tile: compact ? 'var(--control-sm)' : 'var(--control-lg)', iconSize: compact ? 'var(--icon-sm)' : 'var(--icon-md)',
        title: p.title || K[2], titleFs: compact ? 'var(--fs-md)' : 'var(--fs-lg)',
        text: p.text ?? K[3], hasText: !!(p.text ?? K[3]),
        actionLabel: p.actionLabel, hasAction: !!p.actionLabel, onAction: p.onAction
      };
    }
  }
});
