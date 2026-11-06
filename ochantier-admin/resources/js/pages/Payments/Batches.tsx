import { FilterSelect } from '@/components/list/ListToolbar';
import Pagination from '@/components/list/Pagination';
import { StackCell, TableFrame, TextCell, tdStyle, thStyle } from '@/components/list/Table';
import PaymentNav from '@/components/payments/PaymentNav';
import type { Option } from '@/components/ui/Field';
import StatCard from '@/components/ui/StatCard';
import StateBlock from '@/components/ui/StateBlock';
import StatusBadge from '@/components/ui/StatusBadge';
import { fcfa } from '@/lib/format';
import type { BatchRow, PayoutCan } from '@/types/payouts';
import { Head, router } from '@inertiajs/react';

type Filters = { statut?: string; page?: string };

type Props = {
    batches: { data: BatchRow[]; currentPage: number; lastPage: number; total: number; from: number; to: number };
    filters: Filters;
    filterOptions: { statut: Option[] };
    counts: Record<string, number>;
    can: PayoutCan;
};

const COLUMNS = ['Référence', 'Préparé', 'Reversements', 'Net', 'Résultat', 'Statut'];

/** Lots de reversements : une personne prépare, une autre valide, ce qui exécute le lot. */
export default function PaymentBatches({ batches, filters, filterOptions, counts, can }: Props) {
    const apply = (changes: Filters) => {
        const next: Filters = { ...filters, page: undefined, ...changes };
        router.get('/paiements/lots', Object.fromEntries(Object.entries(next).filter(([, v]) => v)), { preserveState: true, preserveScroll: true, replace: true });
    };

    return (
        <>
            <Head title="Lots de reversements" />

            <div style={{ display: 'flex', justifyContent: 'flex-end', flexWrap: 'wrap', gap: 'var(--space-2)', marginTop: -6 }}>
                <PaymentNav current="/paiements/lots" can={can} />
            </div>

            <div data-component="StatGrid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(230px,1fr))', gap: 'var(--space-4)' }}>
                <StatCard label="En préparation" value={String(counts.brouillon)} icon="edit_note" tone="b" />
                <StatCard label="À valider" value={String(counts.a_valider)} icon="pending_actions" tone="a" />
                <StatCard label="Partiels ou échoués" value={String(counts.partiel + counts.echoue)} icon="error" tone="r" />
            </div>

            <section data-component="ListPanel" style={{ background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-5) var(--space-6)' }}>
                <div style={{ marginBottom: 'var(--space-3)' }}>
                    <FilterSelect label="Statut" value={filters.statut ?? ''} options={filterOptions.statut} onChange={(statut) => apply({ statut })} />
                </div>

                <TableFrame minWidth={800}>
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
                        {batches.data.map((b) => (
                            <tr key={b.id} onClick={() => router.visit(`/paiements/lots/${b.id}`)} className="och-hover-soft" style={{ cursor: 'pointer', background: 'var(--c-surface)' }}>
                                <td style={tdStyle}>
                                    <TextCell>{b.reference}</TextCell>
                                </td>
                                <td style={tdStyle}>
                                    <StackCell main={b.createdAt ?? '—'} sub={b.preparedBy ?? '—'} />
                                </td>
                                <td style={tdStyle}>
                                    <TextCell>{b.count}</TextCell>
                                </td>
                                <td style={tdStyle}>
                                    <TextCell strong>{fcfa(b.net)}</TextCell>
                                </td>
                                <td style={tdStyle}>
                                    <TextCell muted>{b.results?.reussis !== undefined ? `${b.results.reussis} réussi(s), ${b.results.echoues ?? 0} échoué(s)` : '—'}</TextCell>
                                </td>
                                <td style={tdStyle}>
                                    <StatusBadge label={b.status.label} tone={b.status.tone as never} />
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </TableFrame>

                {batches.data.length === 0 && (
                    <div style={{ paddingTop: 'var(--space-4)' }}>
                        <StateBlock kind="nodata" icon="inventory_2" title="Aucun lot" text="Préparez un lot depuis « À reverser »." />
                    </div>
                )}

                <Pagination page={batches.currentPage} lastPage={batches.lastPage} from={batches.from} to={batches.to} total={batches.total} onPage={(page) => apply({ page: String(page) })} />
            </section>
        </>
    );
}
