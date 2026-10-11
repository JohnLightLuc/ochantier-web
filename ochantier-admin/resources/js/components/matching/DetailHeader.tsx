import Button from '@/components/ui/Button';
import Icon from '@/components/ui/Icon';
import Menu, { type MenuItem } from '@/components/ui/Menu';
import StatCard from '@/components/ui/StatCard';
import StatusBadge from '@/components/ui/StatusBadge';
import type { AnyTone, Badge } from '@/types';
import { Link } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';

type Props = {
    icon: string;
    title: string;
    badges: Badge[];
    sub: string;
    code: string;
    meta: [string, string, string][];
    actions: { label: string; icon: string; onClick: () => void }[];
    menu: MenuItem[];
    banner: { tone: string; icon: string; title: string; text: string; cta?: string; onCta?: () => void } | null;
    kpis: { label: string; value: string; icon: string; tone: string; hint: string }[];
    tabs: [string, string, number?][];
    tab: string;
    base: string;
    children: ReactNode;
};

/** Fiche du Matching ou des Affectations : en-tête, bandeau, KPI et onglets (sans onglets, le contenu suit les KPI). */
export default function DetailHeader({ icon, title, badges, sub, code, meta, actions, menu, banner, kpis, tabs, tab, base, children }: Props) {
    const [open, setOpen] = useState(false);

    return (
        <div data-component="MatchingDetail" style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-5)' }}>
            <section style={{ position: 'relative', display: 'flex', flexWrap: 'wrap', gap: 'var(--space-5)', alignItems: 'flex-start', background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-5)' }}>
                <div style={{ width: 64, height: 64, flex: 'none', borderRadius: 'var(--radius-xl)', display: 'grid', placeItems: 'center', background: 'var(--c-primary-100)' }}>
                    <Icon name={icon} size="var(--icon-lg)" color="var(--c-primary-600)" />
                </div>
                <div style={{ flex: '1 1 360px', minWidth: 0 }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2_5)', flexWrap: 'wrap' }}>
                        <h2 style={{ margin: 0, fontSize: 'var(--fs-3xl)', fontWeight: 'var(--fw-bold)', letterSpacing: '-.4px' }}>{title}</h2>
                        {badges.map((b) => (
                            <StatusBadge key={b.label} label={b.label} tone={b.tone} />
                        ))}
                    </div>
                    <div style={{ marginTop: 'var(--space-1)', fontSize: 'var(--fs-lg)', color: 'var(--c-ink-2)' }}>
                        {sub} · <span style={{ color: 'var(--c-muted)' }}>{code}</span>
                    </div>
                    <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2) var(--space-5)', marginTop: 'var(--space-3)' }}>
                        {meta.map(([ic, label, value]) => (
                            <span key={label} style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-1_5)', fontSize: 'var(--fs-md)', color: 'var(--c-muted)' }}>
                                <Icon name={ic} size="var(--icon-xs)" color="var(--c-primary-600)" />
                                {label} : <b style={{ color: 'var(--c-ink)', fontWeight: 'var(--fw-semibold)' }}>{value}</b>
                            </span>
                        ))}
                    </div>
                </div>
                <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2)', alignItems: 'center', position: 'relative' }}>
                    {actions.map((a) => (
                        <Button key={a.label} label={a.label} icon={a.icon} variant="primary" onClick={a.onClick} />
                    ))}
                    <Button icon="more_horiz" variant="secondary" aria-label="Plus d’actions" aria-expanded={open} onClick={() => setOpen(!open)} />
                    {open && <Menu items={menu} width={300} top="52px" right="0" onClose={() => setOpen(false)} />}
                </div>
            </section>

            {banner && (
                <div role="status" style={{ display: 'flex', gap: 'var(--space-3)', alignItems: 'center', flexWrap: 'wrap', padding: 'var(--space-3) var(--space-4)', borderRadius: 'var(--radius-lg)', background: `var(--c-${banner.tone}-bg)`, color: `var(--c-${banner.tone}-fg)` }}>
                    <Icon name={banner.icon} />
                    <span style={{ flex: 1, minWidth: 200, fontSize: 'var(--fs-md)' }}>
                        <b>{banner.title}</b> {banner.text}
                    </span>
                    {banner.cta && banner.onCta && (
                        <button type="button" onClick={banner.onCta} style={{ border: 0, background: 'transparent', padding: 0, color: 'inherit', fontWeight: 'var(--fw-bold)', cursor: 'pointer', textDecoration: 'underline', textUnderlineOffset: 2 }}>
                            {banner.cta}
                        </button>
                    )}
                </div>
            )}

            <div data-component="StatGrid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(230px,1fr))', gap: 'var(--space-4)' }}>
                {kpis.map((k) => (
                    <StatCard key={k.label} label={k.label} value={k.value} icon={k.icon} tone={k.tone as AnyTone} hint={k.hint} />
                ))}
            </div>

            {tabs.length ? (
                <section style={{ background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-2) var(--space-5) var(--space-5)', minWidth: 0 }}>
                    <div role="tablist" style={{ display: 'flex', gap: 'var(--space-1)', borderBottom: '1px solid var(--c-line)', marginBottom: 'var(--space-5)', overflowX: 'auto' }}>
                        {tabs.map(([key, text, count]) => {
                            const on = key === tab;
                            return (
                                <Link
                                    key={key}
                                    href={key ? `${base}/${key}` : base}
                                    preserveScroll
                                    role="tab"
                                    aria-selected={on}
                                    className="och-hover-tab"
                                    style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)', height: 'var(--control-md)', padding: '0 var(--space-4)', borderBottom: `2.5px solid ${on ? 'var(--c-primary)' : 'transparent'}`, marginBottom: -1, fontWeight: 'var(--fw-semibold)', fontSize: 'var(--fs-base)', color: on ? 'var(--c-primary)' : 'var(--c-muted)', whiteSpace: 'nowrap', textDecoration: 'none' }}
                                >
                                    {text}
                                    {!!count && <span style={{ minWidth: 22, padding: '1px var(--space-1_5)', borderRadius: 'var(--radius-pill)', fontSize: 'var(--fs-xs)', textAlign: 'center', background: on ? 'var(--c-primary)' : 'var(--c-warning-bg)', color: on ? 'var(--c-on-dark)' : 'var(--c-warning-fg)' }}>{count}</span>}
                                </Link>
                            );
                        })}
                    </div>
                    <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)' }}>{children}</div>
                </section>
            ) : (
                <section style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)', background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-5)', minWidth: 0 }}>{children}</section>
            )}
        </div>
    );
}
