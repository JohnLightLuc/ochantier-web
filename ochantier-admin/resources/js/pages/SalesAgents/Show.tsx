import { AccessTab, EarningsTab, ObjectivesTab, RankingTab, TerritoryTab } from '@/components/sales/performanceTabs';
import SalesAgentHeader from '@/components/sales/SalesAgentHeader';
import { FieldTab, HistoryTab, OverviewTab, ProfileTab, WorkersTab } from '@/components/sales/tabs';
import { useSalesActions } from '@/components/sales/useSalesActions';
import StatCard from '@/components/ui/StatCard';
import type { SalesShowProps, SalesTab } from '@/types/sales';
import { Head, Link } from '@inertiajs/react';

const TABS: [SalesTab, string][] = [
    ['', 'Vue d’ensemble'],
    ['profil-documents', 'Profil et documents'],
    ['ouvriers', 'Ouvriers enregistrés'],
    ['activite', 'Activité terrain'],
    ['zones-disponibilites', 'Zones et disponibilités'],
    ['objectifs-performances', 'Objectifs et performances'],
    ['classement', 'Classement'],
    ['gains-paiements', 'Gains et paiements'],
    ['acces-securite', 'Accès et sécurité'],
    ['historique', 'Historique'],
];

/** Fiche du commercial : en-tête, KPI et dix onglets. */
export default function SalesAgentShow(props: SalesShowProps) {
    const { agent, tab, kpis, tabCounts } = props;
    const actions = useSalesActions();
    const tabProps = { ...props, actions };
    const base = `/commerciaux/${agent.slug}`;
    const label = TABS.find(([key]) => key === tab)?.[1];

    return (
        <>
            <Head title={`${agent.name}${tab ? ` · ${label}` : ''}`} />
            <div data-component="SalesAgentModule" style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-5)' }}>
                <SalesAgentHeader {...props} actions={actions} />

                <div data-component="StatGrid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(230px,1fr))', gap: 'var(--space-4)' }}>
                    {kpis.map((k) => (
                        <StatCard key={k.label} label={k.label} value={k.value} unit={k.unit} icon={k.icon} tone={k.tone as never} hint={k.hint} />
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

                    {tab === '' && <OverviewTab {...tabProps} />}
                    {tab === 'profil-documents' && <ProfileTab {...tabProps} />}
                    {tab === 'ouvriers' && <WorkersTab {...tabProps} />}
                    {tab === 'activite' && <FieldTab {...tabProps} />}
                    {tab === 'zones-disponibilites' && <TerritoryTab {...tabProps} />}
                    {tab === 'objectifs-performances' && <ObjectivesTab {...tabProps} />}
                    {tab === 'classement' && <RankingTab {...tabProps} />}
                    {tab === 'gains-paiements' && <EarningsTab {...tabProps} />}
                    {tab === 'acces-securite' && <AccessTab {...tabProps} />}
                    {tab === 'historique' && <HistoryTab {...tabProps} />}
                </section>
            </div>

            {actions.dialog}
        </>
    );
}
