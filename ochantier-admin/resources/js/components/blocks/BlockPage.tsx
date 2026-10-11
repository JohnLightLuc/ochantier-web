import { PeriodBar } from '@/components/attendances/blocks';
import { useApply } from '@/components/payments/blocks';
import Icon from '@/components/ui/Icon';
import StatCard from '@/components/ui/StatCard';
import StateBlock from '@/components/ui/StateBlock';
import { AlertList, Block, TileGrid } from '@/components/workers/blocks';
import { HistoryList } from '@/components/workers/tabs';
import type { BlockAlert, BlockPageProps } from '@/types/blocks';
import { Head, router } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { ActionBar, BarsBlock, BlockNav, BlockSelectInput, DonutBlock, FlowSteps, LinesBlock, PanelBlock, ProfileHeader, StackBar, TabBar, TableBlock, type Run } from './blocks';

const grid = (min: number) => ({ display: 'grid', gridTemplateColumns: `repeat(auto-fit,minmax(min(100%,${min}px),1fr))`, gap: 'var(--space-3)' });

type Props = BlockPageProps & {
    /** Nom du module (navigation accessible, retour depuis une page refusée). */
    module: string;
    run: Run;
    /** Libellé affiché à côté des bornes de la période. */
    periodLabel?: string;
    dialog: ReactNode;
};

/**
 * Page dont les blocs et les actions sont décrits par le serveur : liens du module, période et recherche, KPI,
 * pastilles, bandeau, en-tête de fiche, onglets, filtres, barre d’actions, alertes, tuiles, parcours, graphiques,
 * panneaux, tableaux et journal.
 */
export default function BlockPage(props: Props) {
    const { module, run, periodLabel, dialog, nav, period, periods, query, search, banner, selects, views, kpis, chips, alerts, tiles, toolbar, flow, charts, lines = [], donuts = [], panels, tables, timeline, missing, header, tabs, profile } = props;
    const apply = useApply(query);
    const alertItems = (list: BlockAlert[]) => list.map((a) => ({ ...a, onAction: a.action ? () => run(a.action) : undefined }));

    if (missing) {
        return (
            <>
                <Head title={missing.title} />
                <BlockNav nav={nav} label={`Sections du module ${module}`} />
                <section style={{ background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-5)' }}>
                    <StateBlock kind="forbidden" title={missing.title} text={missing.text} actionLabel={`Retour à ${module}`} onAction={() => router.visit(missing.back)} />
                </section>
            </>
        );
    }

    return (
        <>
            <Head title={header.title} />
            <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)' }}>
                <BlockNav nav={nav} label={`Sections du module ${module}`} />
                {!profile && <PeriodBar period={{ ...period, label: periodLabel ?? period.range }} periods={periods} query={search ? (query.q ?? '') : undefined} onChange={(c) => apply(c)} />}
            </div>
            {kpis.length > 0 && (
                <div data-component="StatGrid" style={grid(230)}>
                    {kpis.map((k) => (
                        <button key={k.label} type="button" onClick={() => run(k.action)} className="och-hover-card" style={{ display: 'block', minWidth: 0, padding: 0, border: 0, background: 'transparent', textAlign: 'left', borderRadius: 'var(--radius-2xl)', cursor: 'pointer' }}>
                            <StatCard label={k.label} value={k.value} icon={k.icon} tone={k.tone} hint={k.hint} />
                        </button>
                    ))}
                </div>
            )}
            {chips.length > 0 && (
                <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2)' }}>
                    {chips.map((c) => (
                        <button key={c.label} type="button" onClick={() => run(c.action)} className="och-hover-line" style={{ display: 'inline-flex', alignItems: 'center', gap: 'var(--space-1_5)', height: 'var(--control-sm)', padding: '0 var(--space-3)', border: '1px solid var(--c-line-strong)', borderRadius: 'var(--radius-pill)', background: 'var(--c-surface)', color: 'var(--c-ink-2)', fontWeight: 'var(--fw-semibold)', fontSize: 'var(--fs-sm)', cursor: 'pointer', whiteSpace: 'nowrap' }}>
                            <Icon name={c.icon} size="var(--icon-xs)" />
                            {c.label}
                            <b style={{ padding: '0 var(--space-2)', borderRadius: 'var(--radius-pill)', background: 'var(--c-surface-3)', color: 'var(--c-ink)' }}>{c.value}</b>
                        </button>
                    ))}
                </div>
            )}
            {banner && <AlertList alerts={alertItems([banner])} />}
            {profile && <ProfileHeader profile={profile} run={run} />}
            <section style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)', background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-5)', minWidth: 0 }}>
                {tabs && tabs.length > 0 && <TabBar tabs={tabs} label={profile ? `Onglets de ${profile.title}` : `Vues de ${module}`} />}
                {(selects.length > 0 || toolbar.length > 0) && (
                    <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2)', alignItems: 'center' }}>
                        {selects.map((s) => (
                            <BlockSelectInput key={s.key} select={s} onChange={(v) => apply({ [s.key]: v || undefined })} />
                        ))}
                        {selects.length > 0 && views.length > 0 && (
                            <BlockSelectInput select={{ key: 'vue-enregistree', label: 'Vues enregistrées', value: '', options: [{ value: '', label: `Vues enregistrées (${views.length})` }, ...views.map((v) => ({ value: v.href, label: v.name }))] }} onChange={(href) => href && router.visit(href)} />
                        )}
                        <span style={{ flex: 1 }} />
                        <ActionBar buttons={toolbar} run={run} />
                    </div>
                )}
                <AlertList alerts={alertItems(alerts)} />
                {(lines.length > 0 || donuts.length > 0) && (
                    <div style={{ ...grid(420), gap: 'var(--space-4)' }}>
                        {lines.map((l) => (
                            <LinesBlock key={l.title} chart={l} />
                        ))}
                        {donuts.map((d) => (
                            <DonutBlock key={d.title} donut={d} />
                        ))}
                    </div>
                )}
                {tiles.length > 0 && <TileGrid tiles={tiles} />}
                {flow && (
                    <>
                        <FlowSteps steps={flow.steps} run={run} />
                        {flow.stacks.length > 0 && (
                            <div style={grid(420)}>
                                {flow.stacks.map((s) => (
                                    <StackBar key={s.title} stack={s} />
                                ))}
                            </div>
                        )}
                    </>
                )}
                {charts.length > 0 && (
                    <div style={{ ...grid(420), gap: 'var(--space-4)' }}>
                        {charts.map((c) => (
                            <BarsBlock key={c.title} chart={c} />
                        ))}
                    </div>
                )}
                {panels.map((p) => (
                    <PanelBlock key={p.title} panel={p} run={run} />
                ))}
                {tables.map((t) => (
                    <TableBlock key={t.title} table={t} run={run} onPage={(page) => apply({ page: String(page) }, true)} />
                ))}
                {timeline.length > 0 && (
                    <Block icon="history" title="Journal" count={`${timeline.length} événement(s)`}>
                        <HistoryList activities={timeline} />
                    </Block>
                )}
            </section>
            {dialog}
        </>
    );
}
