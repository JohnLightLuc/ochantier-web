import SiteRelationTabs from '@/components/sites/RelationTabs';
import SiteHeader from '@/components/sites/SiteHeader';
import { OverviewTab } from '@/components/sites/tabs';
import { useSiteActions } from '@/components/sites/useSiteActions';
import StatCard from '@/components/ui/StatCard';
import type { SiteShowProps, SiteTab } from '@/types/sites';
import { Head, Link } from '@inertiajs/react';

const TABS: [SiteTab, string][] = [
    ['', 'Vue d’ensemble'],
    ['equipe', 'Équipe et pointages'],
    ['incidents', 'Incidents'],
    ['documents', 'Documents'],
    ['historique', 'Historique'],
];

/** Fiche du chantier : en-tête, indicateurs et onglets. */
export default function SiteShow({ site, tab, relations, tabCounts, kpis, banner, can }: SiteShowProps) {
    const actions = useSiteActions();
    const base = `/chantiers/${site.id}`;
    const label = TABS.find(([key]) => key === tab)?.[1];

    return (
        <>
            <Head title={`${site.name}${tab ? ` · ${label}` : ''}`} />
            <div data-component="SiteModule" style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-5)' }}>
                <SiteHeader site={site} banner={banner} can={can} actions={actions} />

                <div data-component="StatGrid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(230px,1fr))', gap: 'var(--space-4)' }}>
                    {kpis.map((k) => (
                        <StatCard key={k.label} label={k.label} value={k.value} unit={k.unit ?? undefined} icon={k.icon} tone={k.tone as never} hint={k.hint} />
                    ))}
                </div>

                <section style={{ background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-2) var(--space-5) var(--space-5)', minWidth: 0 }}>
                    <div role="tablist" style={{ display: 'flex', gap: 'var(--space-1)', borderBottom: '1px solid var(--c-line)', marginBottom: 'var(--space-5)', overflowX: 'auto' }}>
                        {TABS.map(([key, text]) => {
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

                    {tab === '' && <OverviewTab site={site} />}
                    {relations && <SiteRelationTabs site={site} relations={relations} can={can} actions={actions} />}
                </section>
            </div>

            {actions.dialog}
        </>
    );
}
