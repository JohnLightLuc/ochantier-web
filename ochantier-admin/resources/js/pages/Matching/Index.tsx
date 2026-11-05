import { FilterSelect, SearchInput } from '@/components/list/ListToolbar';
import Pagination from '@/components/list/Pagination';
import { LocationCell, RowActions, StackCell, TableFrame, TextCell, tdStyle, thStyle } from '@/components/list/Table';
import Icon from '@/components/ui/Icon';
import type { Option } from '@/components/ui/Field';
import StatCard from '@/components/ui/StatCard';
import StateBlock from '@/components/ui/StateBlock';
import StatusBadge from '@/components/ui/StatusBadge';
import { formatDate } from '@/lib/format';
import type { OfferRow } from '@/types/offers';
import { Head, router } from '@inertiajs/react';

type Filters = Partial<Record<'q' | 'metier' | 'localisation' | 'page', string>>;

type Props = {
    offers: { data: (OfferRow & { lastRunAt: string | null })[]; currentPage: number; lastPage: number; total: number; from: number; to: number };
    filters: Filters;
    filterOptions: Record<'metier' | 'localisation', Option[]>;
    kpis: { toMatch: number; proposals: number; deposits: number; partial: number };
};

const COLUMNS = ['Besoin', 'Chantier', 'Localisation', 'Métiers', 'Pourvus', 'Démarrage', 'Dernier calcul', 'Statut'];

/** File du matching : les besoins ouverts, à calculer ou à reprendre. */
export default function MatchingIndex({ offers, filters, filterOptions, kpis }: Props) {
    const apply = (changes: Filters, keepPage = false) => {
        const next: Filters = { ...filters, ...changes, ...(keepPage ? {} : { page: undefined }) };
        router.get('/matching', Object.fromEntries(Object.entries(next).filter(([, v]) => v)), { preserveState: true, preserveScroll: true, replace: true });
    };

    return (
        <>
            <Head title="Matching" />
            <div data-component="StatGrid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(230px,1fr))', gap: 'var(--space-4)' }}>
                <StatCard label="Besoins à matcher" value={String(kpis.toMatch)} icon="join_inner" tone="a" />
                <StatCard label="Propositions chez le promoteur" value={String(kpis.proposals)} icon="send" tone="b" />
                <StatCard label="Acomptes attendus" value={String(kpis.deposits)} icon="payments" tone="a" />
                <StatCard label="Partiellement pourvus" value={String(kpis.partial)} icon="group_remove" tone="g" />
            </div>

            <section data-component="ListPanel" style={{ background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-5) var(--space-5) var(--space-4)', minWidth: 0 }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-3)', marginBottom: 'var(--space-3)' }}>
                    <Icon name="join_inner" size="var(--icon-md)" color="var(--c-primary)" />
                    <h2 style={{ margin: 0, fontSize: 'var(--fs-2xl)', fontWeight: 'var(--fw-bold)', flex: 1 }}>Besoins ouverts</h2>
                    <span style={{ fontSize: 'var(--fs-base)', color: 'var(--c-ink-2)' }}>
                        <b style={{ color: 'var(--c-ink)' }}>{offers.total}</b> {offers.total > 1 ? 'besoins' : 'besoin'}
                    </span>
                </div>

                <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2_5)', marginBottom: 'var(--space-3)' }}>
                    <SearchInput value={filters.q ?? ''} placeholder="Rechercher par référence, chantier ou promoteur" onChange={(q) => apply({ q })} />
                    <FilterSelect label="Métier" value={filters.metier ?? ''} options={filterOptions.metier} onChange={(metier) => apply({ metier })} />
                    <FilterSelect label="Localisation" value={filters.localisation ?? ''} options={filterOptions.localisation} onChange={(localisation) => apply({ localisation })} />
                </div>

                <TableFrame minWidth={1100}>
                    <thead>
                        <tr style={{ background: 'var(--c-surface-3)' }}>
                            {COLUMNS.map((c) => (
                                <th key={c} style={thStyle}>
                                    {c}
                                </th>
                            ))}
                            <th style={{ ...thStyle, textAlign: 'center' }}>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        {offers.data.map((o) => {
                            const open = () => router.visit(`/matching/besoins/${o.id}`);
                            return (
                                <tr key={o.id} onClick={open} className="och-hover-soft" style={{ cursor: 'pointer', background: 'var(--c-surface)' }}>
                                    <td style={tdStyle}>
                                        <StackCell main={o.title} sub={o.reference} strong />
                                    </td>
                                    <td style={tdStyle}>
                                        <StackCell main={o.site.label} sub={o.promoter.name} />
                                    </td>
                                    <td style={tdStyle}>
                                        <LocationCell value={o.site.commune ?? '—'} />
                                    </td>
                                    <td style={tdStyle}>
                                        <StackCell main={o.mainTrade ?? '—'} sub={o.tradesCount > 1 ? `${o.tradesCount} métiers` : undefined} />
                                    </td>
                                    <td style={tdStyle}>
                                        <TextCell>{`${o.assigned} / ${o.required}`}</TextCell>
                                    </td>
                                    <td style={tdStyle}>
                                        <TextCell muted={!o.startsOn}>{o.startsOn ? formatDate(o.startsOn) : '—'}</TextCell>
                                    </td>
                                    <td style={tdStyle}>
                                        <TextCell muted={!o.lastRunAt}>{o.lastRunAt ? formatDate(o.lastRunAt) : 'Jamais'}</TextCell>
                                    </td>
                                    <td style={tdStyle}>
                                        <StatusBadge label={o.proposalPending ? 'Proposition envoyée' : o.status.label} tone={o.proposalPending ? 'b' : o.status.tone} />
                                    </td>
                                    <RowActions actions={[{ icon: 'join_inner', label: 'Ouvrir le matching', onClick: open }]} />
                                </tr>
                            );
                        })}
                    </tbody>
                </TableFrame>

                {offers.data.length === 0 && (
                    <div style={{ paddingTop: 'var(--space-4)' }}>
                        <StateBlock kind="nodata" icon="join_inner" title="Aucun besoin ouvert" text="Les besoins publiés par les promoteurs apparaîtront ici." />
                    </div>
                )}

                <Pagination page={offers.currentPage} lastPage={offers.lastPage} from={offers.from} to={offers.to} total={offers.total} onPage={(page) => apply({ page: String(page) }, true)} />
            </section>
        </>
    );
}
