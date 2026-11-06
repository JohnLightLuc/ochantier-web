import { FilterChip, FilterSelect, SearchInput } from '@/components/list/ListToolbar';
import Pagination from '@/components/list/Pagination';
import { StackCell, TableFrame, TextCell, tdStyle, thStyle } from '@/components/list/Table';
import PaymentNav from '@/components/payments/PaymentNav';
import Button from '@/components/ui/Button';
import type { Option } from '@/components/ui/Field';
import StatCard from '@/components/ui/StatCard';
import StateBlock from '@/components/ui/StateBlock';
import StatusBadge from '@/components/ui/StatusBadge';
import { fcfa, formatDate } from '@/lib/format';
import type { PayoutCan, PayoutRow } from '@/types/payouts';
import { Head, router } from '@inertiajs/react';

type Filters = { q?: string; statut?: string; lot?: string; page?: string };

type Props = {
    payouts: { data: PayoutRow[]; currentPage: number; lastPage: number; total: number; from: number; to: number };
    filters: Filters;
    filterOptions: { statut: Option[] };
    kpis: { paid: number; confirmed: number; rejected: number };
    can: PayoutCan;
};

const COLUMNS = ['Référence', 'Ouvrier', 'Chantier', 'Période', 'Journées', 'Net', 'Lot', 'Statut'];

/** Reversements : ce qui a été préparé, envoyé ou refusé par l’opérateur. */
export default function PaymentPayouts({ payouts, filters, filterOptions, kpis, can }: Props) {
    const apply = (changes: Filters) => {
        const next: Filters = { ...filters, page: undefined, ...changes };
        router.get('/paiements/reversements', Object.fromEntries(Object.entries(next).filter(([, v]) => v)), { preserveState: true, preserveScroll: true, replace: true });
    };
    const exportUrl = `/paiements/reversements/export?${new URLSearchParams(Object.entries(filters).filter(([k, v]) => v && k !== 'page') as [string, string][]).toString()}`;

    return (
        <>
            <Head title="Reversements" />

            <div style={{ display: 'flex', justifyContent: 'flex-end', flexWrap: 'wrap', gap: 'var(--space-2)', marginTop: -6 }}>
                <PaymentNav current="/paiements/reversements" can={can} />
                {can.export && <Button label="Exporter" icon="download" variant="secondary" onClick={() => (window.location.href = exportUrl)} />}
            </div>

            <div data-component="StatGrid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(230px,1fr))', gap: 'var(--space-4)' }}>
                <StatCard label="Reversé" value={fcfa(kpis.paid)} icon="verified" tone="g" hint={`${kpis.confirmed} reversement(s) confirmé(s)`} />
                <StatCard label="Rejetés à traiter" value={String(kpis.rejected)} icon="error" tone="r" />
            </div>

            <section data-component="ListPanel" style={{ background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-5) var(--space-6)' }}>
                <div style={{ display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: 'var(--space-2_5)', marginBottom: 'var(--space-3)' }}>
                    <SearchInput value={filters.q ?? ''} placeholder="Rechercher par référence, ouvrier ou chantier" onChange={(q) => apply({ q })} />
                    <FilterSelect label="Statut" value={filters.statut ?? ''} options={filterOptions.statut} onChange={(statut) => apply({ statut })} />
                    {filters.lot && <FilterChip label="Un lot" onClear={() => apply({ lot: undefined })} />}
                </div>

                <TableFrame minWidth={1000}>
                    <thead>
                        <tr style={{ background: 'var(--c-surface-3)' }}>
                            {COLUMNS.map((label) => (
                                <th key={label} style={thStyle}>
                                    {label}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {payouts.data.map((p) => (
                            <tr key={p.id} onClick={() => router.visit(`/paiements/reversements/${p.id}`)} className="och-hover-soft" style={{ cursor: 'pointer', background: 'var(--c-surface)' }}>
                                <td style={tdStyle}>
                                    <TextCell>{p.reference}</TextCell>
                                </td>
                                <td style={tdStyle}>
                                    <StackCell main={p.worker.full_name} sub={p.number ?? '—'} strong />
                                </td>
                                <td style={tdStyle}>
                                    <TextCell>{p.site?.label ?? '—'}</TextCell>
                                </td>
                                <td style={tdStyle}>
                                    <TextCell>{`${formatDate(p.periodStart)} au ${formatDate(p.periodEnd)}`}</TextCell>
                                </td>
                                <td style={tdStyle}>
                                    <TextCell>{p.days}</TextCell>
                                </td>
                                <td style={tdStyle}>
                                    <TextCell strong>{fcfa(p.net)}</TextCell>
                                </td>
                                <td style={tdStyle}>
                                    <TextCell muted>{p.batch?.reference ?? '—'}</TextCell>
                                </td>
                                <td style={tdStyle}>
                                    <StatusBadge label={p.status.label} tone={p.status.tone as never} />
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </TableFrame>

                {payouts.data.length === 0 && (
                    <div style={{ paddingTop: 'var(--space-4)' }}>
                        <StateBlock kind="nodata" icon="account_balance_wallet" title="Aucun reversement" text="Les reversements apparaissent ici dès qu’un lot est préparé." />
                    </div>
                )}

                <Pagination page={payouts.currentPage} lastPage={payouts.lastPage} from={payouts.from} to={payouts.to} total={payouts.total} onPage={(page) => apply({ page: String(page) })} />
            </section>
        </>
    );
}
