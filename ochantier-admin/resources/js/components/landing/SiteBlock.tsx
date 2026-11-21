import Button from '@/components/ui/Button';
import Icon from '@/components/ui/Icon';
import StatusBadge from '@/components/ui/StatusBadge';
import type { LandingCheck, LandingSite } from '@/types/landing';
import { router } from '@inertiajs/react';

/** Enregistre l’ordre et l’affichage des sections dans le brouillon. */
export function saveLayout(order: string[], hidden: string[]) {
    router.put('/landing-page/structure', { order, hidden }, { preserveScroll: true, preserveState: true });
}

/** Interrupteur accessible (affichage d’une section, champ oui / non). */
export function Switch({ on, onChange, disabled, label }: { on: boolean; onChange: (on: boolean) => void; disabled?: boolean; label: string }) {
    return (
        <button
            type="button"
            role="switch"
            aria-checked={on}
            aria-label={label}
            disabled={disabled}
            onClick={() => onChange(!on)}
            style={{ position: 'relative', width: 40, height: 22, flex: 'none', borderRadius: 'var(--radius-pill)', border: 0, padding: 0, cursor: disabled ? 'not-allowed' : 'pointer', opacity: disabled ? 0.55 : 1, background: on ? 'var(--c-primary)' : 'var(--c-line-strong)', transition: 'background .15s' }}
        >
            <span style={{ position: 'absolute', top: 3, left: on ? 21 : 3, width: 16, height: 16, borderRadius: '50%', background: 'var(--c-surface)', transition: 'left .15s' }} />
        </button>
    );
}

export function CheckList({ checks, empty, onGo }: { checks: LandingCheck[]; empty: string; onGo?: (section: string) => void }) {
    if (!checks.length) {
        return (
            <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)', padding: 'var(--space-3)', borderRadius: 'var(--radius-lg)', background: 'var(--c-success-bg)', color: 'var(--c-success-fg)', fontSize: 'var(--fs-md)' }}>
                <Icon name="check_circle" />
                {empty}
            </div>
        );
    }

    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-2)' }}>
            {checks.map((c, i) => {
                const error = c.level === 'error';
                return (
                    <div key={i} role={error ? 'alert' : undefined} style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2_5)', padding: 'var(--space-2_5) var(--space-3)', borderRadius: 'var(--radius-lg)', background: error ? 'var(--c-danger-bg)' : 'var(--c-warning-bg)', color: error ? 'var(--c-danger-fg)' : 'var(--c-warning-fg)', fontSize: 'var(--fs-md)' }}>
                        <Icon name={error ? 'error' : 'warning'} />
                        <span style={{ flex: 1 }}>
                            <b>{c.sectionLabel}</b> · {c.message}
                        </span>
                        {onGo && <Button label="Corriger" size="sm" variant="ghost" onClick={() => onGo(c.section)} />}
                    </div>
                );
            })}
        </div>
    );
}

/**
 * Vue d’ensemble, bloc « Site public » : version en ligne, contrôles avant publication et structure de la page
 * (ordre et affichage des sections, de haut en bas).
 */
export default function SiteBlock({ site, canEdit }: { site: LandingSite; canEdit: boolean }) {
    const errors = site.checks.filter((c) => c.level === 'error').length;
    const warnings = site.checks.length - errors;
    const edit = (key: string) => router.visit(`/landing-page/contenu/${key}`);
    const move = (key: string, dir: -1 | 1) => {
        const order = [...site.order];
        const i = order.indexOf(key);
        const j = i + dir;
        if (i < 0 || j < 0 || j >= order.length) return;
        [order[i], order[j]] = [order[j], order[i]];
        saveLayout(order, site.hidden);
    };
    const toggle = (key: string, visible: boolean) => saveLayout(site.order, visible ? site.hidden.filter((k) => k !== key) : [...site.hidden, key]);
    const fact = (label: string, value: string) => (
        <div>
            <div style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>{label}</div>
            <div style={{ fontSize: 'var(--fs-lg)', fontWeight: 'var(--fw-bold)' }}>{value}</div>
        </div>
    );

    return (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,420px),1fr))', gap: 'var(--space-4)' }}>
            <div style={{ border: '1px solid var(--c-line)', borderRadius: 'var(--radius-xl)', padding: 'var(--space-4)', display: 'flex', flexDirection: 'column', gap: 'var(--space-4)' }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)' }}>
                    <StatusBadge label="Site public · en ligne" tone="g" icon="public" />
                </div>
                <div>
                    <div style={{ fontSize: 'var(--fs-xl)', fontWeight: 'var(--fw-bold)' }}>Landing page O’CHANTIER</div>
                    <div style={{ fontSize: 'var(--fs-md)', color: 'var(--c-muted)' }}>Le site vitrine des promoteurs et des ouvriers. Les demandes envoyées depuis le site arrivent dans ce module.</div>
                </div>
                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3,minmax(0,1fr))', gap: 'var(--space-3)' }}>
                    {fact('Version en ligne', `v${site.live.number}`)}
                    {fact('Publiée le', site.live.publishedAt ?? '—')}
                    {fact('Sections visibles', `${site.visible} / ${site.movable}`)}
                </div>
                <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2)' }}>
                    <Button label="Voir le site" icon="open_in_new" variant="secondary" size="sm" onClick={() => window.open(site.url, '_blank', 'noopener')} />
                    <Button label="Historique des publications" icon="history" variant="ghost" size="sm" onClick={() => router.visit('/landing-page/publications')} />
                </div>
                <div>
                    <div style={{ fontWeight: 'var(--fw-bold)', marginBottom: 'var(--space-1)' }}>Contrôles avant publication</div>
                    <div style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)', marginBottom: 'var(--space-2)' }}>
                        {errors ? `${errors} erreur${errors > 1 ? 's bloquent' : ' bloque'} la publication` : warnings ? `Aucune erreur bloquante · ${warnings} point${warnings > 1 ? 's' : ''} d’attention` : 'Tout est prêt'}
                    </div>
                    <CheckList checks={site.checks} empty="Aucune erreur : le brouillon peut être publié." onGo={edit} />
                </div>
            </div>

            <div style={{ border: '1px solid var(--c-line)', borderRadius: 'var(--radius-xl)', padding: 'var(--space-4)' }}>
                <div style={{ display: 'flex', alignItems: 'flex-start', gap: 'var(--space-3)', marginBottom: 'var(--space-3)' }}>
                    <div style={{ flex: 1 }}>
                        <div style={{ fontWeight: 'var(--fw-bold)', fontSize: 'var(--fs-lg)' }}>Structure de la page</div>
                        <div style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>Ordre et affichage des sections, de haut en bas. Le menu du site suit les sections visibles.</div>
                    </div>
                    <Button label="Modifier le contenu" icon="edit_document" size="sm" variant="secondary" onClick={() => edit('cover')} />
                </div>
                <div style={{ display: 'flex', flexDirection: 'column' }}>
                    {site.sections.map((x) => {
                        const i = site.order.indexOf(x.key);
                        return (
                            <div key={x.key} style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2_5)', padding: 'var(--space-2) 0', borderTop: '1px solid var(--c-line)', opacity: x.hidden ? 0.6 : 1 }}>
                                <span style={{ width: 24, fontFamily: 'var(--font-mono, monospace)', fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>{x.number}</span>
                                <Icon name={x.icon} size="var(--icon-sm)" />
                                <div style={{ flex: 1, minWidth: 0 }}>
                                    <div style={{ display: 'flex', alignItems: 'center', flexWrap: 'wrap', gap: 'var(--space-1_5)', fontWeight: 'var(--fw-semibold)' }}>
                                        {x.label}
                                        {x.changed && <StatusBadge label="Modifiée" tone="a" />}
                                        {x.errors > 0 && !x.hidden && <StatusBadge label={`${x.errors} erreur${x.errors > 1 ? 's' : ''}`} tone="r" />}
                                    </div>
                                    <div style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>{x.hidden ? 'Masquée · non affichée sur le site' : x.description}</div>
                                </div>
                                {x.hideable ? <Switch on={!x.hidden} disabled={!canEdit} label={`Afficher ${x.label}`} onChange={(on) => toggle(x.key, on)} /> : <span style={{ fontSize: 'var(--fs-xs)', color: 'var(--c-muted)' }}>Toujours affichée</span>}
                                {x.movable && (
                                    <>
                                        <Button icon="arrow_upward" size="sm" variant="ghost" aria-label={`Monter ${x.label}`} title="Monter" disabled={!canEdit || i <= 0} onClick={() => move(x.key, -1)} />
                                        <Button icon="arrow_downward" size="sm" variant="ghost" aria-label={`Descendre ${x.label}`} title="Descendre" disabled={!canEdit || i >= site.order.length - 1} onClick={() => move(x.key, 1)} />
                                    </>
                                )}
                                <Button icon="edit" size="sm" variant="ghost" aria-label={`Modifier ${x.label}`} title="Modifier" onClick={() => edit(x.key)} />
                            </div>
                        );
                    })}
                </div>
            </div>
        </div>
    );
}
