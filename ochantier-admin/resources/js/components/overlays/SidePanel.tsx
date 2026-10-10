import Button, { type ButtonVariant } from '@/components/ui/Button';
import Icon from '@/components/ui/Icon';
import StateBlock, { type StateKind } from '@/components/ui/StateBlock';
import StatusBadge from '@/components/ui/StatusBadge';
import { toneColors } from '@/lib/tone';
import type { AnyTone } from '@/types';
import { useEffect, type ReactNode } from 'react';

export type PanelListItem = {
    icon: string;
    tone?: AnyTone;
    name: string;
    sub?: string;
    badge?: string;
    badgeTone?: AnyTone;
    kbd?: string;
    onClick?: () => void;
};

export type PanelAction = {
    label: string;
    icon?: string;
    variant?: ButtonVariant;
    onClick: () => void;
};

export type PanelData = {
    kicker: string;
    title: string;
    badges?: { label: string; tone: AnyTone }[];
    text?: string;
    progress?: number;
    meta?: [string, string][];
    listTitle?: string;
    list?: PanelListItem[];
    foot?: string;
    state?: { kind: StateKind; title: string; text: string };
    actions?: PanelAction[];
};

type Props = {
    panel: PanelData;
    onClose: () => void;
};

type DrawerShellProps = {
    kicker: string;
    title: string;
    onClose: () => void;
    actions?: PanelAction[];
    children: ReactNode;
};

/** Coque du panneau latéral : en-tête, contenu défilant, actions en pied. */
export function DrawerShell({ kicker, title, onClose, actions, children }: DrawerShellProps) {
    useEffect(() => {
        const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onClose();
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [onClose]);

    return (
        <div data-component="SidePanel" style={{ position: 'fixed', inset: 0, zIndex: 40, display: 'flex', justifyContent: 'flex-end' }}>
            <div onClick={onClose} style={{ position: 'absolute', inset: 0, background: 'var(--c-scrim)' }} />
            <aside
                role="dialog"
                aria-modal="true"
                aria-label={title}
                style={{ position: 'relative', width: 'min(560px,100%)', height: '100%', background: 'var(--c-surface)', boxShadow: 'var(--shadow-modal)', display: 'flex', flexDirection: 'column', animation: 'och-rise var(--dur) var(--ease)' }}
            >
                <div style={{ display: 'flex', alignItems: 'flex-start', gap: 'var(--space-3)', padding: 'var(--space-5)', borderBottom: '1px solid var(--c-line)' }}>
                    <div style={{ flex: 1, minWidth: 0 }}>
                        <div style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>{kicker}</div>
                        <h3 style={{ margin: 'var(--space-0_5) 0 0', fontSize: 'var(--fs-2xl)' }}>{title}</h3>
                    </div>
                    <Button icon="close" size="sm" variant="secondary" aria-label="Fermer" onClick={onClose} />
                </div>

                <div style={{ flex: 1, overflowY: 'auto', padding: 'var(--space-5)', display: 'flex', flexDirection: 'column', gap: 'var(--space-4)' }}>{children}</div>

                {actions && actions.length > 0 && (
                    <div style={{ display: 'flex', flexWrap: 'wrap', justifyContent: 'flex-end', gap: 'var(--space-2)', padding: 'var(--space-4) var(--space-5)', borderTop: '1px solid var(--c-line)' }}>
                        {actions.map((a) => (
                            <Button key={a.label} label={a.label} icon={a.icon} variant={a.variant ?? 'secondary'} onClick={a.onClick} />
                        ))}
                    </div>
                )}
            </aside>
        </div>
    );
}

/** Lignes « libellé : valeur » d’un panneau. */
export function MetaList({ items, labelWidth = 130 }: { items: [string, string][]; labelWidth?: number }) {
    return (
        <div>
            {items.map(([k, v]) => (
                <div key={k} style={{ display: 'flex', gap: 'var(--space-3)', padding: 'var(--space-2) 0', borderTop: '1px solid var(--c-line-soft)' }}>
                    <span style={{ width: labelWidth, flex: 'none', color: 'var(--c-muted)' }}>{k}</span>
                    <b style={{ flex: 1, minWidth: 0, fontWeight: 'var(--fw-semibold)', overflowWrap: 'anywhere' }}>{v}</b>
                </div>
            ))}
        </div>
    );
}

/** Panneau latéral générique (détail rapide d’un élément). */
export default function SidePanel({ panel, onClose }: Props) {
    const run = (fn?: () => void) => () => {
        if (!fn) return;
        onClose();
        fn();
    };

    return (
        <DrawerShell kicker={panel.kicker} title={panel.title} onClose={onClose} actions={panel.actions?.map((a) => ({ ...a, onClick: run(a.onClick) }))}>
            {panel.badges && panel.badges.length > 0 && (
                <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2)' }}>
                    {panel.badges.map((b) => (
                        <StatusBadge key={b.label} label={b.label} tone={b.tone} />
                    ))}
                </div>
            )}

            {panel.text && <p style={{ margin: 0, fontSize: 'var(--fs-base)', color: 'var(--c-ink-2)', lineHeight: 'var(--lh-normal)', textWrap: 'pretty' }}>{panel.text}</p>}

            {panel.progress != null && (
                <div>
                    <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 'var(--fs-md)', marginBottom: 'var(--space-1_5)' }}>
                        <span style={{ color: 'var(--c-muted)' }}>Progression</span>
                        <b>{panel.progress} %</b>
                    </div>
                    <div style={{ height: 10, borderRadius: 'var(--radius-pill)', background: 'var(--c-line-soft)', overflow: 'hidden' }}>
                        <div style={{ height: '100%', width: `${panel.progress}%`, background: panel.progress < 50 ? 'var(--c-accent-600)' : 'var(--c-primary-500)' }} />
                    </div>
                </div>
            )}

            {panel.meta && panel.meta.length > 0 && <MetaList items={panel.meta} labelWidth={160} />}

            {panel.list && panel.list.length > 0 && (
                <div>
                    {panel.listTitle && <h4 style={{ margin: '0 0 var(--space-2)', fontSize: 'var(--fs-lg)' }}>{panel.listTitle}</h4>}
                    {panel.list.map((it, i) => {
                        const { bg, fg } = toneColors(it.tone ?? 'g');
                        return (
                            <button
                                key={i}
                                type="button"
                                onClick={run(it.onClick)}
                                className="och-hover-soft"
                                style={{ width: '100%', display: 'flex', alignItems: 'center', gap: 'var(--space-3)', padding: 'var(--space-2_5) var(--space-1_5)', border: 0, borderTop: '1px solid var(--c-line-soft)', background: 'transparent', cursor: it.onClick ? 'pointer' : 'default', textAlign: 'left', borderRadius: 'var(--radius-md)' }}
                            >
                                <span style={{ width: 'var(--control-sm)', height: 'var(--control-sm)', flex: 'none', borderRadius: 'var(--radius-round)', display: 'grid', placeItems: 'center', background: bg }}>
                                    <Icon name={it.icon} color={fg} />
                                </span>
                                <span style={{ flex: 1, minWidth: 0 }}>
                                    <b style={{ display: 'block', fontWeight: 'var(--fw-semibold)' }}>{it.name}</b>
                                    {it.sub && <span style={{ display: 'block', fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>{it.sub}</span>}
                                </span>
                                {it.badge && <StatusBadge label={it.badge} tone={it.badgeTone ?? 'n'} />}
                                {it.kbd && <kbd style={kbdStyle}>{it.kbd}</kbd>}
                            </button>
                        );
                    })}
                    {panel.foot && <div style={{ paddingTop: 'var(--space-2)', fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>{panel.foot}</div>}
                </div>
            )}

            {panel.state && <StateBlock kind={panel.state.kind} title={panel.state.title} text={panel.state.text} />}
        </DrawerShell>
    );
}

export const kbdStyle = {
    flex: 'none',
    fontFamily: 'var(--font-sans)',
    fontSize: 'var(--fs-xs)',
    fontWeight: 'var(--fw-semibold)',
    color: 'var(--c-muted)',
    border: '1px solid var(--c-line-strong)',
    borderBottomWidth: 2,
    borderRadius: 'var(--radius-sm)',
    padding: '1px var(--space-1_5)',
    background: 'var(--c-surface-2)',
} as const;
