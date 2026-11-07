import PeriodFilter from '@/components/finance/PeriodFilter';
import Pagination from '@/components/list/Pagination';
import { TableFrame, TextCell, tdStyle, thStyle } from '@/components/list/Table';
import Button from '@/components/ui/Button';
import StatCard from '@/components/ui/StatCard';
import StateBlock from '@/components/ui/StateBlock';
import { fcfa, formatNumber } from '@/lib/format';
import { Head, router } from '@inertiajs/react';

type Column = { key: string; label: string; money?: boolean };

type Props = {
    report: {
        kind: string;
        title: string;
        period: { from: string; to: string };
        indicators: { label: string; value: number | string; money?: boolean }[];
        columns: Column[];
        rows: Record<string, string | number | null>[];
    };
    pagination: { currentPage: number; lastPage: number; total: number; from: number; to: number };
    can: { export: boolean };
};

const cell = (value: string | number | null | undefined, column: Column) => (value === null || value === undefined ? '—' : column.money ? fcfa(Number(value)) : typeof value === 'number' ? formatNumber(value) : value);

/** Un rapport sur une période : indicateurs, tableau et export. */
export default function ReportShow({ report: r, pagination: p, can }: Props) {
    const period = { du: r.period.from, au: r.period.to };
    const url = `/rapports/${r.kind}`;

    return (
        <>
            <Head title={r.title} />

            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 'var(--space-2)', marginTop: -6 }}>
                <PeriodFilter from={r.period.from} to={r.period.to} onChange={(next) => router.get(url, next, { preserveState: true, preserveScroll: true, replace: true })} />
                {can.export && <Button label="Exporter en CSV" icon="download" variant="secondary" onClick={() => (window.location.href = `${url}/export?${new URLSearchParams(period).toString()}`)} />}
            </div>

            <div data-component="StatGrid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(200px,1fr))', gap: 'var(--space-4)' }}>
                {r.indicators.map((i) => (
                    <StatCard key={i.label} label={i.label} value={i.money ? fcfa(Number(i.value)) : typeof i.value === 'number' ? formatNumber(i.value) : i.value} icon="insights" tone="b" />
                ))}
            </div>

            <section data-component="ListPanel" style={{ background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-5) var(--space-6)' }}>
                <TableFrame minWidth={860}>
                    <thead>
                        <tr style={{ background: 'var(--c-surface-3)' }}>
                            {r.columns.map((c) => (
                                <th key={c.key} style={thStyle}>
                                    {c.label}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {r.rows.map((row, index) => (
                            <tr key={index} style={{ background: 'var(--c-surface)' }}>
                                {r.columns.map((c) => (
                                    <td key={c.key} style={tdStyle}>
                                        <TextCell>{String(cell(row[c.key], c))}</TextCell>
                                    </td>
                                ))}
                            </tr>
                        ))}
                    </tbody>
                </TableFrame>

                {r.rows.length === 0 && (
                    <div style={{ paddingTop: 'var(--space-4)' }}>
                        <StateBlock kind="nodata" icon="description" title="Aucune donnée" text="Rien à afficher sur cette période." />
                    </div>
                )}

                <Pagination page={p.currentPage} lastPage={p.lastPage} from={p.from} to={p.to} total={p.total} onPage={(page) => router.get(url, { ...period, page: String(page) }, { preserveScroll: true })} />
            </section>
        </>
    );
}
