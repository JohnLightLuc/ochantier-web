import SiteHeader from '@/components/sites/SiteHeader';
import { OverviewTab } from '@/components/sites/tabs';
import { useSiteActions } from '@/components/sites/useSiteActions';
import StatCard from '@/components/ui/StatCard';
import type { SiteShowProps } from '@/types/sites';
import { Head } from '@inertiajs/react';

/** Fiche du chantier : en-tête, indicateurs et vue d’ensemble. */
export default function SiteShow({ site, kpis, banner, can }: SiteShowProps) {
    const actions = useSiteActions();

    return (
        <>
            <Head title={site.name} />
            <div data-component="SiteModule" style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-5)' }}>
                <SiteHeader site={site} banner={banner} can={can} actions={actions} />

                <div data-component="StatGrid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(230px,1fr))', gap: 'var(--space-4)' }}>
                    {kpis.map((k) => (
                        <StatCard key={k.label} label={k.label} value={k.value} unit={k.unit ?? undefined} icon={k.icon} tone={k.tone as never} hint={k.hint} />
                    ))}
                </div>

                <section style={{ background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-5)', minWidth: 0 }}>
                    <OverviewTab site={site} />
                </section>
            </div>

            {actions.dialog}
        </>
    );
}
