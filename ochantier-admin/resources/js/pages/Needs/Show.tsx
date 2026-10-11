import { AssignmentsTab, DocumentsTab, HistoryTab, OverviewTab, TradesTab } from '@/components/needs/tabs';
import { useNeedActions } from '@/components/needs/useNeedActions';
import Button from '@/components/ui/Button';
import Icon from '@/components/ui/Icon';
import Menu, { type MenuItem } from '@/components/ui/Menu';
import StatCard from '@/components/ui/StatCard';
import StatusBadge from '@/components/ui/StatusBadge';
import { formatDate } from '@/lib/format';
import type { AnyTone } from '@/types';
import type { NeedShowProps, NeedTabKey } from '@/types/needs';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

const TABS: [NeedTabKey, string][] = [
    ['', 'Vue d’ensemble'],
    ['metiers', 'Métiers et effectifs'],
    ['affectations', 'Ouvriers trouvés'],
    ['documents', 'Documents'],
    ['historique', 'Activité et historique'],
];

/** Fiche du besoin : en-tête, KPI et onglets (« Ouvriers trouvés » dès qu’un ouvrier est sélectionné). */
export default function NeedShow(props: NeedShowProps) {
    const { need: n, actionRef, coverage: c, tab, kpis, tabCounts, banner } = props;
    const actions = useNeedActions();
    const [moreOpen, setMoreOpen] = useState(false);
    const tabProps = { ...props, actions };
    const base = `/besoins/${n.slug}`;
    const tabs = TABS.filter(([key]) => key !== 'affectations' || c.selected > 0);
    const label = TABS.find(([key]) => key === tab)?.[1];
    const available = actions.available(actionRef);
    const primary = available.filter((a) => a.primary).slice(0, 2);
    const more: MenuItem[] = [
        ...available.filter((a) => !primary.includes(a)),
        { icon: 'construction', label: 'Voir le chantier', onClick: () => router.visit(`/chantiers/${n.site.slug}`) },
        { icon: 'apartment', label: 'Voir le promoteur', onClick: () => router.visit(`/promoteurs?q=${encodeURIComponent(n.site.promoter)}`) },
        { icon: 'download', label: 'Exporter la fiche', onClick: () => (window.location.href = `${base}/fiche`) },
    ];
    const meta: [string, string, string][] = [
        ['donut_large', 'Couverture', `${c.percent} % (${c.label.toLowerCase()})`],
        ['groups', 'Postes', `${c.selected} / ${c.need}`],
        ['event', 'Début', formatDate(n.startsOn)],
        ['badge', 'Responsable', n.admin ?? '—'],
    ];

    return (
        <>
            <Head title={`${n.reference}${tab ? ` · ${label}` : ''}`} />
            <div data-component="NeedModule" style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-5)' }}>
                <section data-component="NeedHeader" style={{ position: 'relative', display: 'flex', flexWrap: 'wrap', gap: 'var(--space-5)', alignItems: 'flex-start', background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-5)' }}>
                    <div style={{ width: 64, height: 64, flex: 'none', borderRadius: 'var(--radius-xl)', display: 'grid', placeItems: 'center', background: 'var(--c-primary-100)' }}>
                        <Icon name="assignment" size="var(--icon-lg)" color="var(--c-primary-600)" />
                    </div>
                    <div style={{ flex: '1 1 360px', minWidth: 0 }}>
                        <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2_5)', flexWrap: 'wrap' }}>
                            <h2 style={{ margin: 0, fontSize: 'var(--fs-4xl)', fontWeight: 'var(--fw-bold)', letterSpacing: '-.4px' }}>
                                {n.reference} · {n.site.name}
                            </h2>
                            <StatusBadge label={actionRef.status.label} tone={actionRef.status.tone} />
                            <StatusBadge label={`Urgence ${n.urgency.label.toLowerCase()}`} tone={n.urgency.tone} />
                        </div>
                        <div style={{ marginTop: 'var(--space-1)', fontSize: 'var(--fs-lg)', color: 'var(--c-ink-2)' }}>
                            Promoteur : {n.site.promoter} · {n.type} · <span style={{ color: 'var(--c-muted)' }}>Créé le {formatDate(n.createdOn)}</span>
                        </div>
                        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2) var(--space-5)', marginTop: 'var(--space-3)' }}>
                            {meta.map(([icon, l, v]) => (
                                <span key={l} style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-1_5)', fontSize: 'var(--fs-md)', color: 'var(--c-muted)' }}>
                                    <Icon name={icon} size="var(--icon-xs)" color="var(--c-primary-600)" />
                                    {l} : <b style={{ color: 'var(--c-ink)', fontWeight: 'var(--fw-semibold)' }}>{v}</b>
                                </span>
                            ))}
                        </div>
                    </div>
                    <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2)', alignItems: 'center', position: 'relative' }}>
                        {!actionRef.manual && <Button label="Modifier" icon="edit" variant="secondary" onClick={() => router.visit(`${base}/modifier`)} />}
                        {primary.map((a) => (
                            <Button key={a.label} label={a.label} icon={a.icon} variant="primary" onClick={a.onClick} />
                        ))}
                        <Button icon="more_horiz" variant="secondary" aria-label="Plus d’actions" aria-expanded={moreOpen} onClick={() => setMoreOpen(!moreOpen)} />
                        {moreOpen && <Menu items={more} width={300} top="52px" right="0" onClose={() => setMoreOpen(false)} />}
                    </div>
                </section>

                {banner && (
                    <div role="status" style={{ display: 'flex', gap: 'var(--space-3)', alignItems: 'center', flexWrap: 'wrap', padding: 'var(--space-3) var(--space-4)', borderRadius: 'var(--radius-lg)', background: `var(--c-${banner.tone}-bg)`, color: `var(--c-${banner.tone}-fg)` }}>
                        <Icon name={banner.icon} />
                        <span style={{ flex: 1, minWidth: 200, fontSize: 'var(--fs-md)' }}>
                            <b>{banner.title}</b> {banner.text}
                        </span>
                        {banner.cta && (
                            <button type="button" onClick={() => actions.follow(actionRef)} style={{ border: 0, background: 'transparent', padding: 0, color: 'inherit', fontWeight: 'var(--fw-bold)', cursor: 'pointer', textDecoration: 'underline', textUnderlineOffset: 2 }}>
                                Ouvrir dans Affectations & Offres
                            </button>
                        )}
                    </div>
                )}

                <div data-component="StatGrid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(230px,1fr))', gap: 'var(--space-4)' }}>
                    {kpis.map((k) => (
                        <StatCard key={k.label} label={k.label} value={k.value} icon={k.icon} tone={k.tone as AnyTone} hint={k.hint} />
                    ))}
                </div>

                <section style={{ background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-2) var(--space-5) var(--space-5)', minWidth: 0 }}>
                    <div role="tablist" style={{ display: 'flex', gap: 'var(--space-1)', borderBottom: '1px solid var(--c-line)', marginBottom: 'var(--space-5)', overflowX: 'auto' }}>
                        {tabs.map(([key, text]) => {
                            const on = key === tab;
                            const count = tabCounts[key];
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
                                    {!!count && (
                                        <span style={{ minWidth: 22, padding: '1px var(--space-1_5)', borderRadius: 'var(--radius-pill)', fontSize: 'var(--fs-xs)', textAlign: 'center', background: on ? 'var(--c-primary)' : 'var(--c-warning-bg)', color: on ? 'var(--c-on-dark)' : 'var(--c-warning-fg)' }}>{count}</span>
                                    )}
                                </Link>
                            );
                        })}
                    </div>

                    {tab === '' && <OverviewTab {...tabProps} />}
                    {tab === 'metiers' && <TradesTab {...tabProps} />}
                    {tab === 'affectations' && <AssignmentsTab {...tabProps} />}
                    {tab === 'documents' && <DocumentsTab {...tabProps} />}
                    {tab === 'historique' && <HistoryTab {...tabProps} />}
                </section>
            </div>

            {actions.dialog}
        </>
    );
}
