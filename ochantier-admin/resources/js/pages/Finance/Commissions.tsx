import PeriodFilter from '@/components/finance/PeriodFilter';
import { SearchInput } from '@/components/list/ListToolbar';
import Pagination from '@/components/list/Pagination';
import { StackCell, TableFrame, TextCell, tdStyle, thStyle } from '@/components/list/Table';
import Button from '@/components/ui/Button';
import StateBlock from '@/components/ui/StateBlock';
import { fcfa } from '@/lib/format';
import { Head, router } from '@inertiajs/react';

type Row = { id: string; reference?: string; site_id?: string; site_label?: string; promoter_name?: string; label?: string; missions?: number; days: number; y_amount: number; z_amount: number; earned: number; collected: number; refunded: number; remaining: number };

type Filters = { from: string; to: string; axe: string; q: string };

type Props = {
    rows: { data: Row[]; currentPage: number; lastPage: number; total: number; from: number; to: number };
    filters: Filters;
    can: { export: boolean };
};

const AXES = [
    ['mission', 'Par mission'],
    ['chantier', 'Par chantier'],
    ['promoteur', 'Par promoteur'],
];

/** Journal des commissions : acquises sur le travail validé, encaissées sur l’argent reçu. */
export default function FinanceCommissions({ rows, filters, can }: Props) {
    const query = { du: filters.from, au: filters.to, axe: filters.axe, q: filters.q };
    const apply = (changes: Partial<typeof query> & { page?: string }) => {
        router.get('/finance-reporting/commissions', Object.fromEntries(Object.entries({ ...query, ...changes }).filter(([, v]) => v)), { preserveState: true, preserveScroll: true, replace: true });
    };
    const exportUrl = `/finance-reporting/commissions/export?${new URLSearchParams(Object.entries(query).filter(([, v]) => v) as [string, string][]).toString()}`;
    const mission = filters.axe === 'mission';

    return (
        <>
            <Head title="Journal des commissions" />

            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 'var(--space-2)', marginTop: -6 }}>
                <PeriodFilter from={filters.from} to={filters.to} onChange={apply} />
                <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2)' }}>
                    <Button label="Vue financière" icon="monitoring" variant="secondary" onClick={() => router.get('/finance-reporting', { du: filters.from, au: filters.to })} />
                    {can.export && <Button label="Exporter" icon="download" variant="secondary" onClick={() => (window.location.href = exportUrl)} />}
                </div>
            </div>

            <section data-component="ListPanel" style={{ background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-5) var(--space-6)' }}>
                <div style={{ display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: 'var(--space-2_5)', marginBottom: 'var(--space-3)' }}>
                    <SearchInput value={filters.q} placeholder="Rechercher une mission, un chantier ou un promoteur" onChange={(q) => apply({ q })} />
                    {AXES.map(([value, label]) => (
                        <Button key={value} label={label} size="sm" variant={filters.axe === value ? 'primary' : 'secondary'} onClick={() => apply({ axe: value })} />
                    ))}
                </div>

                <TableFrame minWidth={980}>
                    <thead>
                        <tr style={{ background: 'var(--c-surface-3)' }}>
                            {[mission ? 'Mission' : filters.axe === 'chantier' ? 'Chantier' : 'Promoteur', 'Journées', 'Y', 'Z', 'Acquise', 'Encaissée', 'Remboursée', 'Reste à recevoir'].map((label) => (
                                <th key={label} style={thStyle}>
                                    {label}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {rows.data.map((r) => (
                            <tr key={r.id} onClick={mission ? () => router.visit(`/paiements/regularisations/${r.id}`) : undefined} className={mission ? 'och-hover-soft' : undefined} style={{ cursor: mission ? 'pointer' : 'default', background: 'var(--c-surface)' }}>
                                <td style={tdStyle}>{mission ? <StackCell main={r.site_label ?? '—'} sub={`${r.reference} · ${r.promoter_name}`} strong /> : <StackCell main={r.label ?? '—'} sub={`${r.missions} mission(s)`} strong />}</td>
                                <td style={tdStyle}>
                                    <TextCell muted>{String(r.days)}</TextCell>
                                </td>
                                <td style={tdStyle}>
                                    <TextCell muted>{fcfa(r.y_amount)}</TextCell>
                                </td>
                                <td style={tdStyle}>
                                    <TextCell muted>{fcfa(r.z_amount)}</TextCell>
                                </td>
                                <td style={tdStyle}>
                                    <TextCell strong>{fcfa(r.earned)}</TextCell>
                                </td>
                                <td style={tdStyle}>
                                    <TextCell strong>{fcfa(r.collected)}</TextCell>
                                </td>
                                <td style={tdStyle}>
                                    <TextCell muted>{fcfa(r.refunded)}</TextCell>
                                </td>
                                <td style={tdStyle}>
                                    <TextCell>{fcfa(r.remaining)}</TextCell>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </TableFrame>

                {rows.data.length === 0 && (
                    <div style={{ paddingTop: 'var(--space-4)' }}>
                        <StateBlock kind="nodata" icon="receipt_long" title="Aucun mouvement" text="Aucune journée validée ni aucun paiement sur cette période." />
                    </div>
                )}

                <Pagination page={rows.currentPage} lastPage={rows.lastPage} from={rows.from} to={rows.to} total={rows.total} onPage={(page) => apply({ page: String(page) })} />
            </section>
        </>
    );
}
