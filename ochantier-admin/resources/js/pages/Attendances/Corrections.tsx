import { FilterSelect, SearchInput } from '@/components/list/ListToolbar';
import Pagination from '@/components/list/Pagination';
import { RowActions, StackCell, TableFrame, TextCell, tdStyle, thStyle } from '@/components/list/Table';
import type { Option } from '@/components/ui/Field';
import Icon from '@/components/ui/Icon';
import StatCard from '@/components/ui/StatCard';
import StateBlock from '@/components/ui/StateBlock';
import StatusBadge from '@/components/ui/StatusBadge';
import { formatDate } from '@/lib/format';
import { Head, router } from '@inertiajs/react';

type Row = {
    id: string;
    status: { value: string; label: string; tone: string };
    reason: string;
    changes: string;
    requestedBy: string | null;
    decidedBy: string | null;
    date: string;
    dayId: string;
    worker: { full_name: string; reference: string };
    site: { label: string };
    isPending: boolean;
    canApprove: boolean;
};

type Filters = Partial<Record<'q' | 'statut' | 'page', string>>;

type Props = {
    corrections: { data: Row[]; currentPage: number; lastPage: number; total: number; from: number; to: number };
    filters: Filters;
    filterOptions: { statut: Option[] };
    kpis: { pending: number; approved: number; refused: number };
    can: { update: boolean; validate: boolean };
};

/** Décisions sur une correction, partagées avec la fiche d’une journée. L’auteur peut refuser, pas approuver. */
export function correctionActions(x: { id: string; isPending: boolean; canApprove: boolean }) {
    if (!x.isPending) return [];

    return [...(x.canApprove ? [{ icon: 'check_circle', label: 'Approuver', onClick: () => router.post(`/pointages/corrections/${x.id}/approbation`, {}, { preserveScroll: true }) }] : []), { icon: 'cancel', label: 'Refuser', onClick: () => router.post(`/pointages/corrections/${x.id}/refus`, {}, { preserveScroll: true }) }];
}

/** Corrections de pointage : celles à décider d’abord. */
export default function AttendanceCorrections({ corrections, filters, filterOptions, kpis, can }: Props) {
    const rows = corrections.data;
    const apply = (changes: Filters, keepPage = false) => {
        const next: Filters = { ...filters, ...changes, ...(keepPage ? {} : { page: undefined }) };
        router.get('/pointages/corrections', Object.fromEntries(Object.entries(next).filter(([, v]) => v)), { preserveState: true, preserveScroll: true, replace: true });
    };
    const filtered = Object.entries(filters).some(([k, v]) => k !== 'page' && v);

    return (
        <>
            <Head title="Corrections de pointage" />

            <div data-component="StatGrid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(230px,1fr))', gap: 'var(--space-4)' }}>
                <StatCard label="À décider" value={String(kpis.pending)} icon="pending_actions" tone="a" />
                <StatCard label="Approuvées" value={String(kpis.approved)} icon="check_circle" tone="g" />
                <StatCard label="Refusées" value={String(kpis.refused)} icon="cancel" tone="r" />
            </div>

            <section data-component="ListPanel" style={{ background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-5) var(--space-5) var(--space-4)', minWidth: 0 }}>
                <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2_5)', marginBottom: 'var(--space-3)' }}>
                    <SearchInput value={filters.q ?? ''} placeholder="Rechercher par ouvrier ou chantier" onChange={(q) => apply({ q })} />
                    <FilterSelect label="Statut" value={filters.statut ?? ''} options={filterOptions.statut} onChange={(value) => apply({ statut: value })} />
                </div>

                <TableFrame minWidth={1000}>
                    <thead>
                        <tr style={{ background: 'var(--c-surface-3)' }}>
                            {['Date', 'Ouvrier', 'Chantier', 'Changement', 'Motif', 'Demandée par', 'Statut'].map((label) => (
                                <th key={label} style={thStyle}>
                                    {label}
                                </th>
                            ))}
                            <th style={{ ...thStyle, textAlign: 'center' }}>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((x) => {
                            const open = () => router.visit(`/pointages/${x.dayId}`);
                            return (
                                <tr key={x.id} onClick={open} className="och-hover-soft" style={{ cursor: 'pointer', background: 'var(--c-surface)' }}>
                                    <td style={tdStyle}>
                                        <TextCell>{formatDate(x.date)}</TextCell>
                                    </td>
                                    <td style={tdStyle}>
                                        <StackCell main={x.worker.full_name} sub={x.worker.reference} strong />
                                    </td>
                                    <td style={tdStyle}>
                                        <TextCell>{x.site.label}</TextCell>
                                    </td>
                                    <td style={tdStyle}>
                                        <TextCell>{x.changes}</TextCell>
                                    </td>
                                    <td style={tdStyle}>
                                        <TextCell>{x.reason}</TextCell>
                                    </td>
                                    <td style={tdStyle}>
                                        <StackCell main={x.requestedBy ?? '—'} sub={x.decidedBy ? `Décidée par ${x.decidedBy}` : undefined} />
                                    </td>
                                    <td style={tdStyle}>
                                        <StatusBadge label={x.status.label} tone={x.status.tone as never} />
                                    </td>
                                    <RowActions actions={[{ icon: 'visibility', label: 'Voir le pointage', onClick: open }, ...(can.validate ? correctionActions(x) : [])]} />
                                </tr>
                            );
                        })}
                    </tbody>
                </TableFrame>

                {rows.length === 0 &&
                    (filtered ? (
                        <div style={{ padding: 'var(--space-8)', textAlign: 'center', color: 'var(--c-muted)' }}>
                            <Icon name="search_off" size="var(--icon-xl)" color="var(--c-subtle)" style={{ display: 'block', marginBottom: 'var(--space-1_5)' }} />
                            Aucun résultat pour ces critères.
                        </div>
                    ) : (
                        <div style={{ paddingTop: 'var(--space-4)' }}>
                            <StateBlock kind="nodata" icon="edit_note" title="Aucune correction" text="Les corrections se demandent depuis la fiche d’un pointage." />
                        </div>
                    ))}

                <Pagination page={corrections.currentPage} lastPage={corrections.lastPage} from={corrections.from} to={corrections.to} total={corrections.total} onPage={(page) => apply({ page: String(page) }, true)} />
            </section>
        </>
    );
}
