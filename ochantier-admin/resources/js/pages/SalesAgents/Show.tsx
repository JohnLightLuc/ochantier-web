import SalesAgentHeader from '@/components/sales/SalesAgentHeader';
import { OverviewTab, ProfileTab } from '@/components/sales/tabs';
import { useSalesActions } from '@/components/sales/useSalesActions';
import StatCard from '@/components/ui/StatCard';
import type { SalesShowProps, SalesTab } from '@/types/sales';
import { Head, Link } from '@inertiajs/react';

const TABS: [SalesTab, string][] = [
    ['', 'Vue d’ensemble'],
    ['profil', 'Profil'],
];

/** Fiche du commercial : en-tête, indicateurs et onglets. */
export default function SalesAgentShow({ agent, tab, kpis, banner, can }: SalesShowProps) {
    const actions = useSalesActions();
    const base = `/commerciaux/${agent.id}`;
    const label = TABS.find(([key]) => key === tab)?.[1];

    return (
        <>
            <Head title={`${agent.name}${tab ? ` · ${label}` : ''}`} />
            <div data-component="SalesAgentModule" style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-5)' }}>
                <SalesAgentHeader agent={agent} banner={banner} can={can} actions={actions} />

                <div data-component="StatGrid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(230px,1fr))', gap: 'var(--space-4)' }}>
                    {kpis.map((k) => (
                        <StatCard key={k.label} label={k.label} value={k.value} unit={k.unit} icon={k.icon} tone={k.tone as never} hint={k.hint} />
                    ))}
                </div>

                <section style={{ background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-2) var(--space-5) var(--space-5)', minWidth: 0 }}>
                    <div role="tablist" style={{ display: 'flex', gap: 'var(--space-1)', borderBottom: '1px solid var(--c-line)', marginBottom: 'var(--space-5)', overflowX: 'auto' }}>
                        {TABS.map(([key, text]) => {
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
                                </Link>
                            );
                        })}
                    </div>

                    {tab === '' && <OverviewTab agent={agent} />}
                    {tab === 'profil' && <ProfileTab agent={agent} />}
                </section>
            </div>

            {actions.dialog}
        </>
    );
}
