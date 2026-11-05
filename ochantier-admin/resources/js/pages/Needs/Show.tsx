import OfferRelationTabs, { OverviewTab } from '@/components/needs/OfferTabs';
import { useOfferActions } from '@/components/needs/useOfferActions';
import Button from '@/components/ui/Button';
import Icon from '@/components/ui/Icon';
import Menu, { type MenuItem } from '@/components/ui/Menu';
import StatCard from '@/components/ui/StatCard';
import StatusBadge from '@/components/ui/StatusBadge';
import type { OfferShowProps, OfferTab } from '@/types/offers';
import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';

const TABS: [OfferTab, string][] = [
    ['', 'Vue d’ensemble'],
    ['ouvriers', 'Propositions et ouvriers'],
    ['documents', 'Documents'],
    ['historique', 'Historique'],
];

/** Fiche d’un besoin : en-tête, décisions, indicateurs et onglets. */
export default function NeedShow({ offer: o, tab, relations, kpis, banner, can }: OfferShowProps) {
    const actions = useOfferActions();
    const [moreOpen, setMoreOpen] = useState(false);
    const base = `/besoins/${o.id}`;
    const label = TABS.find(([key]) => key === tab)?.[1];

    const more: MenuItem[] = [
        ...(can.update && o.can.extend ? [{ icon: 'event_repeat', label: 'Prolonger la date limite', onClick: () => actions.extend(o) }] : []),
        ...(can.validate && o.can.cancel ? [{ icon: 'cancel', label: 'Annuler le besoin', danger: true, onClick: () => actions.cancel(o) }] : []),
    ];

    return (
        <>
            <Head title={`${o.reference}${tab ? ` · ${label}` : ''}`} />
            <div data-component="NeedModule" style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-5)' }}>
                <section style={{ position: 'relative', display: 'flex', flexWrap: 'wrap', gap: 'var(--space-5)', alignItems: 'flex-start', background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-5)' }}>
                    <div style={{ flex: '1 1 360px', minWidth: 0 }}>
                        <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2_5)', flexWrap: 'wrap' }}>
                            <h2 style={{ margin: 0, fontSize: 'var(--fs-4xl)', fontWeight: 'var(--fw-bold)', letterSpacing: '-.4px' }}>{o.title}</h2>
                            <StatusBadge label={o.status.label} tone={o.status.tone} />
                            {o.proposalPending && <StatusBadge label="Proposition à valider" tone="a" />}
                        </div>
                        <div style={{ marginTop: 'var(--space-1)', fontSize: 'var(--fs-lg)', color: 'var(--c-ink-2)' }}>
                            {o.site.label} · {o.promoter.name} · <span style={{ fontVariantNumeric: 'tabular-nums', color: 'var(--c-muted)' }}>{o.reference}</span>
                        </div>
                    </div>
                    <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2)', alignItems: 'center', position: 'relative' }}>
                        {can.update && o.can.resume && <Button label="Reprendre" icon="play_circle" variant="primary" onClick={() => actions.resume(o)} />}
                        {can.update && o.can.suspend && <Button label="Suspendre" icon="pause_circle" variant="danger-soft" onClick={() => actions.suspend(o)} />}
                        {can.validate && o.can.close && <Button label="Clôturer" icon="task_alt" variant="primary" onClick={() => actions.close(o)} />}
                        {more.length > 0 && <Button icon="more_horiz" variant="secondary" aria-label="Plus d’actions" aria-expanded={moreOpen} onClick={() => setMoreOpen(!moreOpen)} />}
                        {moreOpen && <Menu items={more} width={260} top="52px" right="0" onClose={() => setMoreOpen(false)} />}
                    </div>
                </section>

                {banner && (
                    <div role="status" style={{ display: 'flex', gap: 'var(--space-3)', alignItems: 'center', flexWrap: 'wrap', padding: 'var(--space-3) var(--space-4)', borderRadius: 'var(--radius-lg)', background: `var(--c-${banner.tone}-bg)`, color: `var(--c-${banner.tone}-fg)` }}>
                        <Icon name={banner.icon} />
                        <span style={{ flex: 1, minWidth: 200, fontSize: 'var(--fs-md)' }}>
                            <b>{banner.title}</b> {banner.text}
                        </span>
                    </div>
                )}

                <div data-component="StatGrid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(230px,1fr))', gap: 'var(--space-4)' }}>
                    {kpis.map((k) => (
                        <StatCard key={k.label} label={k.label} value={k.value} unit={k.unit ?? undefined} icon={k.icon} tone={k.tone as never} hint={k.hint} />
                    ))}
                </div>

                <section style={{ background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-2) var(--space-5) var(--space-5)', minWidth: 0 }}>
                    <div role="tablist" style={{ display: 'flex', gap: 'var(--space-1)', borderBottom: '1px solid var(--c-line)', marginBottom: 'var(--space-5)', overflowX: 'auto' }}>
                        {TABS.map(([key, text]) => {
                            const on = key === tab;
                            return (
                                <Link key={key} href={key ? `${base}/${key}` : base} preserveScroll role="tab" aria-selected={on} className="och-hover-tab" style={{ display: 'flex', alignItems: 'center', height: 'var(--control-md)', padding: '0 var(--space-4)', borderBottom: `2.5px solid ${on ? 'var(--c-primary)' : 'transparent'}`, marginBottom: -1, fontWeight: 'var(--fw-semibold)', fontSize: 'var(--fs-base)', color: on ? 'var(--c-primary)' : 'var(--c-muted)', whiteSpace: 'nowrap', textDecoration: 'none' }}>
                                    {text}
                                </Link>
                            );
                        })}
                    </div>

                    {tab === '' && <OverviewTab offer={o} />}
                    {relations && <OfferRelationTabs relations={relations} />}
                </section>
            </div>

            {actions.dialog}
        </>
    );
}
