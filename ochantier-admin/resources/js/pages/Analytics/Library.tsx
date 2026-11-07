import Pagination from '@/components/list/Pagination';
import { TableFrame, TextCell, tdStyle, thStyle } from '@/components/list/Table';
import FormDialog from '@/components/overlays/FormDialog';
import { RelationTable } from '@/components/promoters/RelationTabs';
import Button from '@/components/ui/Button';
import type { Option } from '@/components/ui/Field';
import StateBlock from '@/components/ui/StateBlock';
import StatusBadge from '@/components/ui/StatusBadge';
import { Block } from '@/components/workers/blocks';
import { useAdminUi } from '@/contexts/AdminUiContext';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { FORMATS } from './Report';

type Run = { id: string; kind: string; name: string; format: string; row_count: number | null; by: string; generated_at: string | null };

type Schedule = { id: string; kind: string; name: string; frequency: { value: string; label: string }; format: string; recipients: string[]; next_run_at: string | null; status: { value: string; label: string } };

type Props = {
    runs: { data: Run[]; currentPage: number; lastPage: number; total: number; from: number; to: number };
    kinds: Option[];
    schedules: Schedule[];
    can: { export: boolean; create: boolean };
};

const COLUMNS = ['Rapport', 'Format', 'Lignes', 'Généré par', 'Généré le', ''];
const FREQUENCIES = [
    { value: 'quotidien', label: 'Chaque jour (la veille)' },
    { value: 'hebdomadaire', label: 'Chaque semaine (7 derniers jours)' },
    { value: 'mensuel', label: 'Chaque mois (mois précédent)' },
];

/** Bibliothèque des rapports générés et programmations envoyées par e-mail. */
export default function ReportLibrary({ runs, kinds, schedules, can }: Props) {
    const ui = useAdminUi();
    const [adding, setAdding] = useState(false);

    return (
        <>
            <Head title="Bibliothèque des rapports" />

            <div style={{ display: 'flex', justifyContent: 'flex-end', flexWrap: 'wrap', gap: 'var(--space-2)', marginTop: -6 }}>
                <Button label="Rapports" icon="dashboard" variant="secondary" onClick={() => router.visit('/rapports')} />
                {can.create && <Button label="Programmer un rapport" icon="schedule_send" onClick={() => setAdding(true)} />}
            </div>

            <Block icon="schedule_send" title="Programmations" count={`${schedules.length}`}>
                <RelationTable
                    columns={['Nom', 'Rythme', 'Format', 'Destinataires', 'Prochain envoi', 'Statut', '']}
                    empty={{ icon: 'schedule_send', title: 'Aucune programmation', text: 'Un rapport programmé est généré et envoyé tout seul.' }}
                    rows={schedules.map((s) => ({
                        key: s.id,
                        cells: [
                            s.name,
                            s.frequency.label,
                            s.format.toUpperCase(),
                            s.recipients.join(', '),
                            s.next_run_at ?? '—',
                            <StatusBadge label={s.status.label} tone={s.status.value === 'actif' ? 'g' : 'n'} />,
                            can.create ? (
                                <div style={{ display: 'flex', gap: 'var(--space-1)' }}>
                                    <Button label={s.status.value === 'actif' ? 'Suspendre' : 'Réactiver'} size="sm" variant="secondary" onClick={() => router.post(`/rapports/programmations/${s.id}/statut`, {}, { preserveScroll: true })} />
                                    <Button label="Supprimer" size="sm" variant="secondary" onClick={() => ui.confirm({ tone: 'danger', icon: 'delete', title: 'Supprimer cette programmation ?', body: 'Les rapports déjà générés restent dans la bibliothèque.', ok: 'Supprimer', onConfirm: () => router.delete(`/rapports/programmations/${s.id}`, { preserveScroll: true }) })} />
                                </div>
                            ) : (
                                ''
                            ),
                        ],
                    }))}
                />
            </Block>

            <section data-component="ListPanel" style={{ background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-5) var(--space-6)' }}>
                <TableFrame minWidth={820}>
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
                            <tr key={r.id} style={{ background: 'var(--c-surface)' }}>
                                <td style={tdStyle}>
                                    <TextCell strong>{r.name}</TextCell>
                                </td>
                                <td style={tdStyle}>
                                    <TextCell muted>{r.format.toUpperCase()}</TextCell>
                                </td>
                                <td style={tdStyle}>
                                    <TextCell muted>{String(r.row_count ?? 0)}</TextCell>
                                </td>
                                <td style={tdStyle}>
                                    <TextCell muted>{r.by}</TextCell>
                                </td>
                                <td style={tdStyle}>
                                    <TextCell muted>{r.generated_at ?? '—'}</TextCell>
                                </td>
                                <td style={tdStyle}>{can.export && <Button label="Télécharger" icon="download" size="sm" variant="secondary" onClick={() => (window.location.href = `/rapports/fichiers/${r.id}`)} />}</td>
                            </tr>
                        ))}
                    </tbody>
                </TableFrame>

                {runs.data.length === 0 && (
                    <div style={{ paddingTop: 'var(--space-4)' }}>
                        <StateBlock kind="nodata" icon="folder_open" title="Aucun rapport" text="Les fichiers générés depuis un rapport apparaissent ici." />
                    </div>
                )}

                <Pagination page={runs.currentPage} lastPage={runs.lastPage} from={runs.from} to={runs.to} total={runs.total} onPage={(page) => router.get('/rapports/bibliotheque', { page: String(page) }, { preserveScroll: true })} />
            </section>

            {adding && (
                <FormDialog
                    title="Programmer un rapport"
                    body="Envoi à 6 h par e-mail, à des administrateurs actifs qui ont accès à ce rapport. Le fichier reste dans la bibliothèque si l’e-mail échoue."
                    icon="schedule_send"
                    okLabel="Programmer"
                    url="/rapports/programmations"
                    initial={{ format: 'xlsx', frequency: 'hebdomadaire' }}
                    onClose={() => setAdding(false)}
                    fields={[
                        { name: 'name', label: 'Nom', required: true, full: true },
                        { name: 'kind', label: 'Rapport', type: 'select', required: true, options: kinds },
                        { name: 'frequency', label: 'Rythme', type: 'select', required: true, options: FREQUENCIES },
                        { name: 'format', label: 'Format', type: 'select', required: true, options: FORMATS },
                        { name: 'first_run_on', label: 'Premier envoi', type: 'date', required: true },
                        { name: 'recipients', label: 'Destinataires (e-mails séparés par une virgule)', type: 'textarea', required: true },
                    ]}
                />
            )}
        </>
    );
}
