import { FilterSelect, SearchInput } from '@/components/list/ListToolbar';
import Pagination from '@/components/list/Pagination';
import { StackCell, TableFrame, TextCell, tdStyle, thStyle } from '@/components/list/Table';
import FormDialog from '@/components/overlays/FormDialog';
import QualityNav from '@/components/quality/QualityNav';
import Button from '@/components/ui/Button';
import type { Option } from '@/components/ui/Field';
import StatCard from '@/components/ui/StatCard';
import StateBlock from '@/components/ui/StateBlock';
import StatusBadge from '@/components/ui/StatusBadge';
import type { DisputeCan, DisputeRow } from '@/types/disputes';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { PRIORITIES } from './Claims';

type Filters = { q?: string; statut?: string; categorie?: string; urgence?: string; sans_responsable?: string; page?: string };

type Props = {
    disputes: { data: DisputeRow[]; currentPage: number; lastPage: number; total: number; from: number; to: number };
    filters: Filters;
    categories: Option[];
    kpis: { open: number; instructing: number; appeals: number; unassigned: number };
    can: DisputeCan;
};

const COLUMNS = ['Référence', 'Dossier', 'Parties', 'Urgence', 'Responsable', 'Statut'];
const STATUSES = [
    { value: 'ouvert', label: 'Ouvert' },
    { value: 'en_instruction', label: 'En instruction' },
    { value: 'resolu', label: 'Résolu' },
    { value: 'en_recours', label: 'En recours' },
    { value: 'clos', label: 'Clos' },
];
const CLAIMANTS = [
    { value: 'promoter_profile', label: 'Promoteur' },
    { value: 'worker', label: 'Ouvrier' },
    { value: 'commercial_profile', label: 'Commercial' },
];

/** Litiges : les plus urgents en tête, recours compris. */
export default function QualityDisputes({ disputes, filters, categories, kpis, can }: Props) {
    const [adding, setAdding] = useState(false);
    const apply = (changes: Filters) => {
        const next: Filters = { ...filters, page: undefined, ...changes };
        router.get('/avis-litiges/litiges', Object.fromEntries(Object.entries(next).filter(([, v]) => v)), { preserveState: true, preserveScroll: true, replace: true });
    };

    return (
        <>
            <Head title="Litiges" />

            <div style={{ display: 'flex', justifyContent: 'flex-end', flexWrap: 'wrap', gap: 'var(--space-2)', marginTop: -6 }}>
                <QualityNav current="/avis-litiges/litiges" />
                {can.update && <Button label="Ouvrir un litige" icon="add" onClick={() => setAdding(true)} />}
            </div>

            <div data-component="StatGrid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(200px,1fr))', gap: 'var(--space-4)' }}>
                <StatCard label="Ouverts" value={String(kpis.open)} icon="inbox" tone="a" />
                <StatCard label="En instruction" value={String(kpis.instructing)} icon="pending_actions" tone="b" />
                <StatCard label="En recours" value={String(kpis.appeals)} icon="balance" tone="o" />
                <StatCard label="Sans responsable" value={String(kpis.unassigned)} icon="person_off" tone="r" highlight={kpis.unassigned > 0} />
            </div>

            <section data-component="ListPanel" style={{ background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-5) var(--space-6)' }}>
                <div style={{ display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: 'var(--space-2_5)', marginBottom: 'var(--space-3)' }}>
                    <SearchInput value={filters.q ?? ''} placeholder="Rechercher par référence, description ou chantier" onChange={(q) => apply({ q })} />
                    <FilterSelect label="Statut" value={filters.statut ?? ''} options={STATUSES} onChange={(statut) => apply({ statut })} />
                    <FilterSelect label="Catégorie" value={filters.categorie ?? ''} options={categories} onChange={(categorie) => apply({ categorie })} />
                    <FilterSelect label="Urgence" value={filters.urgence ?? ''} options={PRIORITIES} onChange={(urgence) => apply({ urgence })} />
                    <Button label="Sans responsable" icon="person_off" size="sm" variant={filters.sans_responsable ? 'primary' : 'secondary'} onClick={() => apply({ sans_responsable: filters.sans_responsable ? undefined : '1' })} />
                </div>

                <TableFrame minWidth={980}>
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
                        {disputes.data.map((d) => (
                            <tr key={d.id} onClick={() => router.visit(`/avis-litiges/litiges/${d.id}`)} className="och-hover-soft" style={{ cursor: 'pointer', background: 'var(--c-surface)' }}>
                                <td style={tdStyle}>
                                    <StackCell main={d.reference} sub={d.appealOf ? `Recours de ${d.appealOf.reference}` : (d.createdAt ?? '')} />
                                </td>
                                <td style={tdStyle}>
                                    <StackCell main={d.category} sub={d.site?.title ?? 'Sans chantier'} strong />
                                </td>
                                <td style={{ ...tdStyle, maxWidth: 240 }}>
                                    <TextCell muted>{d.parties.map((p) => p.name).join(' / ') || '—'}</TextCell>
                                </td>
                                <td style={tdStyle}>
                                    <StatusBadge label={d.urgency.label} tone={d.urgency.tone as never} />
                                </td>
                                <td style={tdStyle}>
                                    <TextCell muted>{d.owner ?? 'À attribuer'}</TextCell>
                                </td>
                                <td style={tdStyle}>
                                    <StatusBadge label={d.status.label} tone={d.status.tone as never} />
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </TableFrame>

                {disputes.data.length === 0 && (
                    <div style={{ paddingTop: 'var(--space-4)' }}>
                        <StateBlock kind="nodata" icon="gavel" title="Aucun litige" text="Ouvrez un litige ici, ou passez une réclamation en litige depuis sa fiche." />
                    </div>
                )}

                <Pagination page={disputes.currentPage} lastPage={disputes.lastPage} from={disputes.from} to={disputes.to} total={disputes.total} onPage={(page) => apply({ page: String(page) })} />
            </section>

            {adding && (
                <FormDialog
                    title="Ouvrir un litige"
                    body="Le demandeur est retrouvé par son numéro. Sans mission rattachée, le litige ne bloque aucun paiement."
                    icon="gavel"
                    okLabel="Ouvrir"
                    url="/avis-litiges/litiges"
                    initial={{ dial_code: '225', urgency: 'normale' }}
                    onClose={() => setAdding(false)}
                    fields={[
                        { name: 'claimant_type', label: 'Demandeur', type: 'select', required: true, options: CLAIMANTS },
                        { name: 'category', label: 'Catégorie', type: 'select', required: true, options: categories },
                        { name: 'dial_code', label: 'Indicatif', required: true },
                        { name: 'phone_number', label: 'Numéro', required: true },
                        { name: 'mission_reference', label: 'Référence de la mission' },
                        { name: 'urgency', label: 'Urgence', type: 'select', required: true, options: PRIORITIES },
                        { name: 'description', label: 'Description', type: 'textarea', required: true },
                    ]}
                />
            )}
        </>
    );
}
