import { FilterSelect, SearchInput } from '@/components/list/ListToolbar';
import Pagination from '@/components/list/Pagination';
import { RowActions, StackCell, TableFrame, TextCell, tdStyle, thStyle } from '@/components/list/Table';
import FormDialog from '@/components/overlays/FormDialog';
import type { Option } from '@/components/ui/Field';
import Icon from '@/components/ui/Icon';
import StatCard from '@/components/ui/StatCard';
import StateBlock from '@/components/ui/StateBlock';
import StatusBadge from '@/components/ui/StatusBadge';
import { formatDate } from '@/lib/format';
import { Head, router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';

type Row = {
    id: string;
    type: string;
    status: { value: string; label: string; tone: string };
    detectedBy: string;
    comment: string | null;
    resolution: string | null;
    resolvedBy: string | null;
    date: string | null;
    dayId: string | null;
    worker: { full_name: string; reference: string };
    site: { label: string };
    isOpen: boolean;
};

type Filters = Partial<Record<'q' | 'statut' | 'type' | 'page', string>>;

type Props = {
    anomalies: { data: Row[]; currentPage: number; lastPage: number; total: number; from: number; to: number };
    filters: Filters;
    filterOptions: { statut: Option[]; type: Option[] };
    kpis: { open: number; working: number; resolved: number; rejected: number };
    can: { update: boolean };
};

/** Actions de traitement, partagées avec la fiche d’une journée. */
export function anomalyActions(x: { id: string; type: string; isOpen: boolean; status: { value: string } }, setDialog: (node: ReactNode) => void) {
    if (!x.isOpen) return [];

    const closeDialog = () =>
        setDialog(
            <FormDialog
                title={`Clôturer l’anomalie : ${x.type}`}
                body="La résolution reste visible sur la fiche du pointage."
                icon="task_alt"
                okLabel="Clôturer"
                url={`/pointages/anomalies/${x.id}/cloture`}
                onClose={() => setDialog(null)}
                fields={[
                    {
                        name: 'outcome',
                        label: 'Issue',
                        type: 'select',
                        required: true,
                        full: true,
                        options: [
                            { value: 'resolue', label: 'Résolue' },
                            { value: 'rejetee', label: 'Rejetée, pas une anomalie' },
                        ],
                    },
                    { name: 'resolution', label: 'Résolution', type: 'textarea', required: true },
                ]}
            />,
        );

    return [...(x.status.value === 'ouverte' ? [{ icon: 'play_arrow', label: 'Prendre en charge', onClick: () => router.post(`/pointages/anomalies/${x.id}/prise-en-charge`, {}, { preserveScroll: true }) }] : []), { icon: 'task_alt', label: 'Clôturer', onClick: closeDialog }];
}

/** Anomalies de pointage : les ouvertes d’abord, des plus anciennes aux plus récentes. */
export default function AttendanceAnomalies({ anomalies, filters, filterOptions, kpis, can }: Props) {
    const [dialog, setDialog] = useState<ReactNode>(null);
    const rows = anomalies.data;
    const apply = (changes: Filters, keepPage = false) => {
        const next: Filters = { ...filters, ...changes, ...(keepPage ? {} : { page: undefined }) };
        router.get('/pointages/anomalies', Object.fromEntries(Object.entries(next).filter(([, v]) => v)), { preserveState: true, preserveScroll: true, replace: true });
    };
    const filtered = Object.entries(filters).some(([k, v]) => k !== 'page' && v);

    return (
        <>
            <Head title="Anomalies de pointage" />

            <div data-component="StatGrid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(230px,1fr))', gap: 'var(--space-4)' }}>
                <StatCard label="Ouvertes" value={String(kpis.open)} icon="report" tone="r" />
                <StatCard label="En traitement" value={String(kpis.working)} icon="pending_actions" tone="a" />
                <StatCard label="Résolues" value={String(kpis.resolved)} icon="task_alt" tone="g" />
                <StatCard label="Rejetées" value={String(kpis.rejected)} icon="block" tone="n" />
            </div>

            <section data-component="ListPanel" style={{ background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-5) var(--space-5) var(--space-4)', minWidth: 0 }}>
                <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2_5)', marginBottom: 'var(--space-3)' }}>
                    <SearchInput value={filters.q ?? ''} placeholder="Rechercher par ouvrier ou chantier" onChange={(q) => apply({ q })} />
                    <FilterSelect label="Statut" value={filters.statut ?? ''} options={filterOptions.statut} onChange={(value) => apply({ statut: value })} />
                    <FilterSelect label="Type" value={filters.type ?? ''} options={filterOptions.type} onChange={(value) => apply({ type: value })} />
                </div>

                <TableFrame minWidth={1000}>
                    <thead>
                        <tr style={{ background: 'var(--c-surface-3)' }}>
                            {['Date', 'Ouvrier', 'Chantier', 'Type', 'Détectée par', 'Commentaire', 'Statut'].map((label) => (
                                <th key={label} style={thStyle}>
                                    {label}
                                </th>
                            ))}
                            <th style={{ ...thStyle, textAlign: 'center' }}>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((x) => {
                            const open = () => x.dayId && router.visit(`/pointages/${x.dayId}`);
                            return (
                                <tr key={x.id} onClick={open} className="och-hover-soft" style={{ cursor: 'pointer', background: 'var(--c-surface)' }}>
                                    <td style={tdStyle}>
                                        <TextCell muted={!x.date}>{x.date ? formatDate(x.date) : '—'}</TextCell>
                                    </td>
                                    <td style={tdStyle}>
                                        <StackCell main={x.worker.full_name} sub={x.worker.reference} strong />
                                    </td>
                                    <td style={tdStyle}>
                                        <TextCell>{x.site.label}</TextCell>
                                    </td>
                                    <td style={tdStyle}>
                                        <TextCell>{x.type}</TextCell>
                                    </td>
                                    <td style={tdStyle}>
                                        <TextCell>{x.detectedBy}</TextCell>
                                    </td>
                                    <td style={tdStyle}>
                                        <StackCell main={x.comment ?? '—'} sub={x.resolution ? `Résolution : ${x.resolution}${x.resolvedBy ? ` (${x.resolvedBy})` : ''}` : undefined} />
                                    </td>
                                    <td style={tdStyle}>
                                        <StatusBadge label={x.status.label} tone={x.status.tone as never} />
                                    </td>
                                    <RowActions actions={[{ icon: 'visibility', label: 'Voir le pointage', onClick: open }, ...(can.update ? anomalyActions(x, setDialog) : [])]} />
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
                            <StateBlock kind="nodata" icon="report" title="Aucune anomalie" text="Les anomalies apparaissent au pointage ou quand un opérateur en signale une." />
                        </div>
                    ))}

                <Pagination page={anomalies.currentPage} lastPage={anomalies.lastPage} from={anomalies.from} to={anomalies.to} total={anomalies.total} onPage={(page) => apply({ page: String(page) }, true)} />
            </section>

            {dialog}
        </>
    );
}
