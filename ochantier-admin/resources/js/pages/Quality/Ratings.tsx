import { FilterSelect, SearchInput } from '@/components/list/ListToolbar';
import Pagination from '@/components/list/Pagination';
import { StackCell, TableFrame, TextCell, tdStyle, thStyle } from '@/components/list/Table';
import Button from '@/components/ui/Button';
import StatCard from '@/components/ui/StatCard';
import StateBlock from '@/components/ui/StateBlock';
import StatusBadge from '@/components/ui/StatusBadge';
import type { RatingRow } from '@/types/ratings';
import { Head, router } from '@inertiajs/react';

type Filters = { q?: string; statut?: string; moderation?: string; type?: string; note?: string; suspects?: string; page?: string };

type Props = {
    ratings: { data: RatingRow[]; currentPage: number; lastPage: number; total: number; from: number; to: number };
    filters: Filters;
    kpis: { published: number; hidden: number; reported: number; suspected: number; average: number | null };
};

const COLUMNS = ['Évalué', 'Promoteur', 'Note', 'Commentaire', 'Publié le', 'Statut'];
const STATUSES = [
    { value: 'publie', label: 'Publié' },
    { value: 'masque', label: 'Masqué' },
];
const MODERATIONS = [
    { value: 'signale', label: 'Signalé' },
    { value: 'maintenu', label: 'Maintenu' },
    { value: 'masque', label: 'Masqué' },
];
const TYPES = [
    { value: 'individuel', label: 'Ouvrier' },
    { value: 'equipe', label: 'Équipe' },
];
const NOTES = [5, 4, 3, 2, 1].map((n) => ({ value: String(n), label: `${n} étoile${n > 1 ? 's' : ''}` }));

/** Avis laissés par les promoteurs : les avis signalés remontent en tête de liste. */
export default function QualityRatings({ ratings, filters, kpis }: Props) {
    const apply = (changes: Filters) => {
        const next: Filters = { ...filters, page: undefined, ...changes };
        router.get('/avis-litiges/avis', Object.fromEntries(Object.entries(next).filter(([, v]) => v)), { preserveState: true, preserveScroll: true, replace: true });
    };

    return (
        <>
            <Head title="Avis" />

            <div style={{ display: 'flex', justifyContent: 'flex-end', flexWrap: 'wrap', gap: 'var(--space-2)', marginTop: -6 }}>
                <Button label="Vue d’ensemble" icon="dashboard" variant="secondary" onClick={() => router.visit('/avis-litiges')} />
                <Button label="Réclamations" icon="support_agent" variant="secondary" onClick={() => router.visit('/avis-litiges/reclamations')} />
                <Button label="Litiges" icon="gavel" variant="secondary" onClick={() => router.visit('/avis-litiges/litiges')} />
            </div>

            <div data-component="StatGrid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(200px,1fr))', gap: 'var(--space-4)' }}>
                <StatCard label="Avis publiés" value={String(kpis.published)} icon="reviews" tone="g" />
                <StatCard label="Note moyenne" value={kpis.average !== null ? `${String(kpis.average).replace('.', ',')} / 5` : '—'} icon="star" tone="a" />
                <StatCard label="À modérer" value={String(kpis.reported)} icon="flag" tone="r" highlight={kpis.reported > 0} />
                <StatCard label="Suspects" value={String(kpis.suspected)} icon="policy" tone="o" />
                <StatCard label="Masqués" value={String(kpis.hidden)} icon="visibility_off" tone="b" />
            </div>

            <section data-component="ListPanel" style={{ background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-5) var(--space-6)' }}>
                <div style={{ display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: 'var(--space-2_5)', marginBottom: 'var(--space-3)' }}>
                    <SearchInput value={filters.q ?? ''} placeholder="Rechercher un ouvrier, un chantier, un promoteur ou un commentaire" onChange={(q) => apply({ q })} />
                    <FilterSelect label="Statut" value={filters.statut ?? ''} options={STATUSES} onChange={(statut) => apply({ statut })} />
                    <FilterSelect label="Modération" value={filters.moderation ?? ''} options={MODERATIONS} onChange={(moderation) => apply({ moderation })} />
                    <FilterSelect label="Type" value={filters.type ?? ''} options={TYPES} onChange={(type) => apply({ type })} />
                    <FilterSelect label="Note" value={filters.note ?? ''} options={NOTES} onChange={(note) => apply({ note })} />
                    <Button label="Suspects" icon="policy" size="sm" variant={filters.suspects ? 'primary' : 'secondary'} onClick={() => apply({ suspects: filters.suspects ? undefined : '1' })} />
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
                        {ratings.data.map((r) => (
                            <tr key={r.id} onClick={() => router.visit(`/avis-litiges/avis/${r.id}`)} className="och-hover-soft" style={{ cursor: 'pointer', background: 'var(--c-surface)' }}>
                                <td style={tdStyle}>
                                    <StackCell main={r.target.name} sub={r.site?.title ?? r.scope} strong />
                                </td>
                                <td style={tdStyle}>
                                    <TextCell>{r.promoter.name}</TextCell>
                                </td>
                                <td style={tdStyle}>
                                    <StackCell main={`${r.score} / 5`} sub={r.tag ?? ''} />
                                </td>
                                <td style={{ ...tdStyle, maxWidth: 280 }}>
                                    <TextCell muted>{r.comment ?? '—'}</TextCell>
                                </td>
                                <td style={tdStyle}>
                                    <TextCell muted>{r.publishedAt ?? '—'}</TextCell>
                                </td>
                                <td style={tdStyle}>
                                    <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-1)' }}>
                                        <StatusBadge label={r.status.label} tone={r.status.tone as never} />
                                        {r.moderation?.value === 'signale' && <StatusBadge label={r.moderation.label} tone="r" />}
                                        {r.suspected && <StatusBadge label="Suspect" tone="o" />}
                                    </div>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </TableFrame>

                {ratings.data.length === 0 && (
                    <div style={{ paddingTop: 'var(--space-4)' }}>
                        <StateBlock kind="nodata" icon="reviews" title="Aucun avis" text="Les avis laissés par les promoteurs dans l’application apparaissent ici." />
                    </div>
                )}

                <Pagination page={ratings.currentPage} lastPage={ratings.lastPage} from={ratings.from} to={ratings.to} total={ratings.total} onPage={(page) => apply({ page: String(page) })} />
            </section>
        </>
    );
}
