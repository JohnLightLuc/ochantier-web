import Pagination from '@/components/list/Pagination';
import { TableFrame, TextCell, tdStyle, thStyle } from '@/components/list/Table';
import FormDialog from '@/components/overlays/FormDialog';
import Button from '@/components/ui/Button';
import type { Option } from '@/components/ui/Field';
import StatCard from '@/components/ui/StatCard';
import StateBlock from '@/components/ui/StateBlock';
import StatusBadge from '@/components/ui/StatusBadge';
import { fcfa } from '@/lib/format';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

export type ReconciliationRun = {
    id: string;
    provider: string;
    period: { from: string; to: string };
    summary: { file_rows: number; matched: number; matched_amount: number; file_amount: number; discrepancies: number } | null;
    openItems: number;
    by: string | null;
    importedAt: string | null;
};

type Props = {
    runs: { data: ReconciliationRun[]; currentPage: number; lastPage: number; total: number; from: number; to: number };
    kpis: { runs: number; open_items: number; unreconciled: number };
    operators: Option[];
    can: { update: boolean };
};

const COLUMNS = ['Opérateur', 'Période', 'Lignes', 'Rapprochées', 'Montant rapproché', 'Écarts ouverts', 'Importé le', 'Par'];

/** Rapprochements : chaque relevé importé et les écarts qui restent à traiter. */
export default function FinanceReconciliations({ runs, kpis, operators, can }: Props) {
    const [importing, setImporting] = useState(false);

    return (
        <>
            <Head title="Rapprochements" />

            <div style={{ display: 'flex', justifyContent: 'flex-end', flexWrap: 'wrap', gap: 'var(--space-2)', marginTop: -6 }}>
                <Button label="Vue financière" icon="monitoring" variant="secondary" onClick={() => router.visit('/finance-reporting')} />
                {can.update && <Button label="Importer un relevé" icon="upload_file" onClick={() => setImporting(true)} />}
            </div>

            <div data-component="StatGrid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(220px,1fr))', gap: 'var(--space-4)' }}>
                <StatCard label="Écarts à traiter" value={String(kpis.open_items)} icon="rule" tone="r" highlight={kpis.open_items > 0} />
                <StatCard label="Opérations à rapprocher" value={String(kpis.unreconciled)} icon="pending_actions" tone="a" />
                <StatCard label="Relevés importés" value={String(kpis.runs)} icon="description" tone="b" />
            </div>

            <section data-component="ListPanel" style={{ background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-5) var(--space-6)' }}>
                <TableFrame minWidth={940}>
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
                        {runs.data.map((r) => (
                            <tr key={r.id} onClick={() => router.visit(`/finance-reporting/rapprochements/${r.id}`)} className="och-hover-soft" style={{ cursor: 'pointer', background: 'var(--c-surface)' }}>
                                <td style={tdStyle}>
                                    <TextCell strong>{r.provider}</TextCell>
                                </td>
                                <td style={tdStyle}>
                                    <TextCell muted>{`${r.period.from} au ${r.period.to}`}</TextCell>
                                </td>
                                <td style={tdStyle}>
                                    <TextCell muted>{String(r.summary?.file_rows ?? 0)}</TextCell>
                                </td>
                                <td style={tdStyle}>
                                    <TextCell>{String(r.summary?.matched ?? 0)}</TextCell>
                                </td>
                                <td style={tdStyle}>
                                    <TextCell>{fcfa(r.summary?.matched_amount ?? 0)}</TextCell>
                                </td>
                                <td style={tdStyle}>{r.openItems ? <StatusBadge label={String(r.openItems)} tone="r" /> : <TextCell muted>0</TextCell>}</td>
                                <td style={tdStyle}>
                                    <TextCell muted>{r.importedAt ?? '—'}</TextCell>
                                </td>
                                <td style={tdStyle}>
                                    <TextCell muted>{r.by ?? '—'}</TextCell>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </TableFrame>

                {runs.data.length === 0 && (
                    <div style={{ paddingTop: 'var(--space-4)' }}>
                        <StateBlock kind="nodata" icon="sync_alt" title="Aucun rapprochement" text="Importez le relevé d’un opérateur pour le comparer aux opérations de la plateforme." />
                    </div>
                )}

                <Pagination page={runs.currentPage} lastPage={runs.lastPage} from={runs.from} to={runs.to} total={runs.total} onPage={(page) => router.get('/finance-reporting/rapprochements', { page: String(page) }, { preserveScroll: true })} />
            </section>

            {importing && (
                <FormDialog
                    title="Importer un relevé"
                    body="CSV avec séparateur ; ou virgule, et une ligne de titres : « reference » et « montant », plus « statut » si l’opérateur le fournit. Un encaissement se compare frais inclus."
                    icon="upload_file"
                    okLabel="Lancer le rapprochement"
                    url="/finance-reporting/rapprochements"
                    onClose={() => setImporting(false)}
                    fields={[
                        { name: 'operator_id', label: 'Opérateur', type: 'select', required: true, full: true, options: operators },
                        { name: 'from', label: 'Du', type: 'date', required: true },
                        { name: 'to', label: 'Au', type: 'date', required: true },
                        { name: 'file', label: 'Relevé CSV', type: 'file', accept: '.csv,.txt', required: true },
                    ]}
                />
            )}
        </>
    );
}
