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
import type { ClaimRow } from '@/types/claims';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

type Filters = { q?: string; statut?: string; categorie?: string; priorite?: string; demandeur?: string; retard?: string; page?: string };

type Props = {
    claims: { data: ClaimRow[]; currentPage: number; lastPage: number; total: number; from: number; to: number };
    filters: Filters;
    categories: Option[];
    kpis: { open: number; processing: number; overdue: number; resolved: number };
    can: { update: boolean };
};

const COLUMNS = ['Référence', 'Objet', 'Demandeur', 'Priorité', 'Responsable', 'Réponse attendue', 'Statut'];
const STATUSES = [
    { value: 'ouverte', label: 'Ouverte' },
    { value: 'en_traitement', label: 'En traitement' },
    { value: 'resolue', label: 'Résolue' },
    { value: 'rejetee', label: 'Rejetée' },
    { value: 'close', label: 'Close' },
];
export const PRIORITIES = [
    { value: 'basse', label: 'Basse' },
    { value: 'normale', label: 'Normale' },
    { value: 'haute', label: 'Haute' },
    { value: 'critique', label: 'Critique' },
];
const CLAIMANTS = [
    { value: 'promoter_profile', label: 'Promoteur' },
    { value: 'worker', label: 'Ouvrier' },
    { value: 'commercial_profile', label: 'Commercial' },
];

/** Réclamations : les plus urgentes en tête, déposées dans l’application ou saisies au nom du demandeur. */
export default function QualityClaims({ claims, filters, categories, kpis, can }: Props) {
    const [adding, setAdding] = useState(false);
    const apply = (changes: Filters) => {
        const next: Filters = { ...filters, page: undefined, ...changes };
        router.get('/avis-litiges/reclamations', Object.fromEntries(Object.entries(next).filter(([, v]) => v)), { preserveState: true, preserveScroll: true, replace: true });
    };

    return (
        <>
            <Head title="Réclamations" />

            <div style={{ display: 'flex', justifyContent: 'flex-end', flexWrap: 'wrap', gap: 'var(--space-2)', marginTop: -6 }}>
                <QualityNav current="/avis-litiges/reclamations" />
                {can.update && <Button label="Nouvelle réclamation" icon="add" onClick={() => setAdding(true)} />}
            </div>

            <div data-component="StatGrid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(200px,1fr))', gap: 'var(--space-4)' }}>
                <StatCard label="Ouvertes" value={String(kpis.open)} icon="inbox" tone="a" />
                <StatCard label="En traitement" value={String(kpis.processing)} icon="pending_actions" tone="b" />
                <StatCard label="En retard" value={String(kpis.overdue)} icon="schedule" tone="r" highlight={kpis.overdue > 0} />
                <StatCard label="Résolues" value={String(kpis.resolved)} icon="verified" tone="g" />
            </div>

            <section data-component="ListPanel" style={{ background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-5) var(--space-6)' }}>
                <div style={{ display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: 'var(--space-2_5)', marginBottom: 'var(--space-3)' }}>
                    <SearchInput value={filters.q ?? ''} placeholder="Rechercher par référence, objet ou chantier" onChange={(q) => apply({ q })} />
                    <FilterSelect label="Statut" value={filters.statut ?? ''} options={STATUSES} onChange={(statut) => apply({ statut })} />
                    <FilterSelect label="Catégorie" value={filters.categorie ?? ''} options={categories} onChange={(categorie) => apply({ categorie })} />
                    <FilterSelect label="Priorité" value={filters.priorite ?? ''} options={PRIORITIES} onChange={(priorite) => apply({ priorite })} />
                    <FilterSelect label="Demandeur" value={filters.demandeur ?? ''} options={CLAIMANTS} onChange={(demandeur) => apply({ demandeur })} />
                    <Button label="En retard" icon="schedule" size="sm" variant={filters.retard ? 'primary' : 'secondary'} onClick={() => apply({ retard: filters.retard ? undefined : '1' })} />
                </div>

                <TableFrame minWidth={1020}>
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
                        {claims.data.map((c) => (
                            <tr key={c.id} onClick={() => router.visit(`/avis-litiges/reclamations/${c.id}`)} className="och-hover-soft" style={{ cursor: 'pointer', background: 'var(--c-surface)' }}>
                                <td style={tdStyle}>
                                    <TextCell>{c.reference}</TextCell>
                                </td>
                                <td style={{ ...tdStyle, maxWidth: 280 }}>
                                    <StackCell main={c.subject} sub={c.site ? `${c.category} · ${c.site.title}` : c.category} strong />
                                </td>
                                <td style={tdStyle}>
                                    <StackCell main={c.claimant.name} sub={c.claimant.type_label} />
                                </td>
                                <td style={tdStyle}>
                                    <StatusBadge label={c.priority.label} tone={c.priority.tone as never} />
                                </td>
                                <td style={tdStyle}>
                                    <TextCell muted>{c.owner ?? 'À attribuer'}</TextCell>
                                </td>
                                <td style={tdStyle}>
                                    {c.overdue ? <StatusBadge label={c.dueAt ?? 'En retard'} tone="r" /> : <TextCell muted>{c.dueAt ?? '—'}</TextCell>}
                                </td>
                                <td style={tdStyle}>
                                    <StatusBadge label={c.status.label} tone={c.status.tone as never} />
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </TableFrame>

                {claims.data.length === 0 && (
                    <div style={{ paddingTop: 'var(--space-4)' }}>
                        <StateBlock kind="nodata" icon="support_agent" title="Aucune réclamation" text="Les réclamations déposées dans l’application Promoteur ou saisies ici apparaissent dans cette liste." />
                    </div>
                )}

                <Pagination page={claims.currentPage} lastPage={claims.lastPage} from={claims.from} to={claims.to} total={claims.total} onPage={(page) => apply({ page: String(page) })} />
            </section>

            {adding && (
                <FormDialog
                    title="Nouvelle réclamation"
                    body="Enregistrez la demande reçue au nom du demandeur, retrouvé par son numéro de téléphone."
                    icon="support_agent"
                    okLabel="Enregistrer"
                    url="/avis-litiges/reclamations"
                    initial={{ dial_code: '225', priority: 'normale' }}
                    onClose={() => setAdding(false)}
                    fields={[
                        { name: 'claimant_type', label: 'Demandeur', type: 'select', required: true, options: CLAIMANTS },
                        { name: 'category', label: 'Catégorie', type: 'select', required: true, options: categories },
                        { name: 'dial_code', label: 'Indicatif', required: true },
                        { name: 'phone_number', label: 'Numéro', required: true },
                        { name: 'site_reference', label: 'Référence du chantier', placeholder: 'CHT-2026-000123' },
                        { name: 'priority', label: 'Priorité', type: 'select', required: true, options: PRIORITIES },
                        { name: 'subject', label: 'Objet', required: true, full: true },
                        { name: 'description', label: 'Description', type: 'textarea', required: true },
                    ]}
                />
            )}
        </>
    );
}
