import { FilterSelect, SearchInput } from '@/components/list/ListToolbar';
import Pagination from '@/components/list/Pagination';
import { StackCell, TableFrame, TextCell, tdStyle, thStyle } from '@/components/list/Table';
import PaymentNav from '@/components/payments/PaymentNav';
import Button from '@/components/ui/Button';
import type { Option } from '@/components/ui/Field';
import StatCard from '@/components/ui/StatCard';
import StateBlock from '@/components/ui/StateBlock';
import StatusBadge from '@/components/ui/StatusBadge';
import { fcfa } from '@/lib/format';
import type { RefundCan, RefundRow } from '@/types/payouts';
import { Head, router } from '@inertiajs/react';

type Filters = { q?: string; statut?: string; page?: string };

type Props = {
    refunds: { data: RefundRow[]; currentPage: number; lastPage: number; total: number; from: number; to: number };
    filters: Filters;
    filterOptions: { statut: Option[] };
    kpis: { executed: number; pending: number; failed: number };
    can: RefundCan;
};

const COLUMNS = ['Référence', 'Promoteur', 'Mission', 'Origine', 'Montant', 'Date', 'Statut'];

/** Remboursements aux promoteurs : à valider, envoyés ou refusés par l’opérateur. */
export default function PaymentRefunds({ refunds, filters, filterOptions, kpis, can }: Props) {
    const apply = (changes: Filters) => {
        const next: Filters = { ...filters, page: undefined, ...changes };
        router.get('/paiements/remboursements', Object.fromEntries(Object.entries(next).filter(([, v]) => v)), { preserveState: true, preserveScroll: true, replace: true });
    };
    const exportUrl = `/paiements/remboursements/export?${new URLSearchParams(Object.entries(filters).filter(([k, v]) => v && k !== 'page') as [string, string][]).toString()}`;

    return (
        <>
            <Head title="Remboursements" />

            <div style={{ display: 'flex', justifyContent: 'flex-end', flexWrap: 'wrap', gap: 'var(--space-2)', marginTop: -6 }}>
                <PaymentNav current="/paiements/remboursements" can={can} />
                {can.exportRefunds && <Button label="Exporter" icon="download" variant="secondary" onClick={() => (window.location.href = exportUrl)} />}
            </div>

            <div data-component="StatGrid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(230px,1fr))', gap: 'var(--space-4)' }}>
                <StatCard label="Remboursé" value={fcfa(kpis.executed)} icon="verified" tone="g" />
                <StatCard label="À valider" value={fcfa(kpis.pending)} icon="pending_actions" tone="a" />
                <StatCard label="Échecs à traiter" value={String(kpis.failed)} icon="error" tone="r" />
            </div>

            <section data-component="ListPanel" style={{ background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-5) var(--space-6)' }}>
                <div style={{ display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: 'var(--space-2_5)', marginBottom: 'var(--space-3)' }}>
                    <SearchInput value={filters.q ?? ''} placeholder="Rechercher par référence, chantier ou promoteur" onChange={(q) => apply({ q })} />
                    <FilterSelect label="Statut" value={filters.statut ?? ''} options={filterOptions.statut} onChange={(statut) => apply({ statut })} />
                </div>

                <TableFrame minWidth={900}>
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
                        {refunds.data.map((r) => (
                            <tr key={r.id} onClick={() => router.visit(`/paiements/remboursements/${r.id}`)} className="och-hover-soft" style={{ cursor: 'pointer', background: 'var(--c-surface)' }}>
                                <td style={tdStyle}>
                                    <TextCell>{r.reference}</TextCell>
                                </td>
                                <td style={tdStyle}>
                                    <TextCell>{r.promoter.full_name}</TextCell>
                                </td>
                                <td style={tdStyle}>
                                    <StackCell main={r.site.label} sub={r.mission.reference} strong />
                                </td>
                                <td style={tdStyle}>
                                    <TextCell muted>{r.origin.label}</TextCell>
                                </td>
                                <td style={tdStyle}>
                                    <TextCell strong>{fcfa(r.amount)}</TextCell>
                                </td>
                                <td style={tdStyle}>
                                    <TextCell muted>{r.date ?? '—'}</TextCell>
                                </td>
                                <td style={tdStyle}>
                                    <StatusBadge label={r.status.label} tone={r.status.tone as never} />
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </TableFrame>

                {refunds.data.length === 0 && (
                    <div style={{ paddingTop: 'var(--space-4)' }}>
                        <StateBlock kind="nodata" icon="undo" title="Aucun remboursement" text="Un trop-perçu de fin de mission crée ici une demande à valider." />
                    </div>
                )}

                <Pagination page={refunds.currentPage} lastPage={refunds.lastPage} from={refunds.from} to={refunds.to} total={refunds.total} onPage={(page) => apply({ page: String(page) })} />
            </section>
        </>
    );
}
