import ActionStrip from '@/components/list/ActionStrip';
import { FilterChip, FilterSelect, SearchInput } from '@/components/list/ListToolbar';
import Pagination from '@/components/list/Pagination';
import { LocationCell, ProgressCell, RowActions, SortableTh, StackCell, TableFrame, TextCell, tdStyle, thStyle, type SortDirection } from '@/components/list/Table';
import Button from '@/components/ui/Button';
import Icon from '@/components/ui/Icon';
import StatCard from '@/components/ui/StatCard';
import StateBlock from '@/components/ui/StateBlock';
import StatusBadge from '@/components/ui/StatusBadge';
import { formatDate } from '@/lib/format';
import type { OfferFilters, OfferIndexProps } from '@/types/offers';
import { Head, router } from '@inertiajs/react';

const COLUMNS: [string, string | null][] = [
    ['Besoin', 'reference'],
    ['Chantier', null],
    ['Promoteur', null],
    ['Localisation', null],
    ['Métiers', null],
    ['Pourvus', null],
    ['Couverture', 'couverture'],
    ['Démarrage', 'demarrage'],
    ['Date limite', 'limite'],
    ['Statut', 'statut'],
];

const FILTERS: [keyof OfferIndexProps['filterOptions'], string][] = [
    ['statut', 'Statut'],
    ['metier', 'Métier'],
    ['localisation', 'Localisation'],
    ['type', 'Type'],
    ['urgence', 'Urgence'],
];

const QUICK_LABELS: Record<string, string> = { a_matcher: 'À matcher', proposition: 'Proposition à valider', acompte: 'Acompte attendu', incomplet: 'Partiellement pourvus', expires: 'Date limite dépassée' };

/** Liste des besoins : une ligne par offre d’un promoteur, avec sa couverture. */
export default function NeedsIndex({ offers, filters, filterOptions, kpis, todo, can }: OfferIndexProps) {
    const rows = offers.data;

    const apply = (changes: OfferFilters, keepPage = false) => {
        const next: OfferFilters = { ...filters, ...changes, ...(keepPage ? {} : { page: undefined }) };
        router.get('/besoins', Object.fromEntries(Object.entries(next).filter(([, v]) => v)), { preserveState: true, preserveScroll: true, replace: true });
    };

    const applyQuick = (key: string | null) => apply({ rapide: key ?? undefined });
    const filtered = Object.entries(filters).some(([k, v]) => !['tri', 'sens', 'page'].includes(k) && v);
    const exportUrl = `/besoins/export?${new URLSearchParams(Object.entries(filters).filter(([k, v]) => v && k !== 'page') as [string, string][]).toString()}`;

    return (
        <>
            <Head title="Besoins" />

            {can.export && (
                <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: -6 }}>
                    <Button label="Exporter" icon="download" variant="secondary" onClick={() => (window.location.href = exportUrl)} />
                </div>
            )}

            <div data-component="StatGrid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(230px,1fr))', gap: 'var(--space-4)' }}>
                <StatCard label="Total des besoins" value={String(kpis.total)} icon="assignment" tone="g" />
                <StatCard label="En recherche" value={String(kpis.open)} icon="person_search" tone="b" />
                <StatCard label="Pourvus" value={String(kpis.filled)} icon="task_alt" tone="g" />
                <StatCard label="Suspendus" value={String(kpis.suspended)} icon="pause_circle" tone="a" />
            </div>

            <section data-component="ListPanel" style={{ background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-5) var(--space-5) var(--space-4)', minWidth: 0 }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-3)', flexWrap: 'wrap', marginBottom: 'var(--space-3)' }}>
                    <Icon name="assignment" size="var(--icon-md)" color="var(--c-primary)" />
                    <h2 style={{ margin: 0, fontSize: 'var(--fs-2xl)', fontWeight: 'var(--fw-bold)', flex: 1 }}>Liste des besoins</h2>
                    {filters.rapide && <FilterChip label={QUICK_LABELS[filters.rapide] ?? filters.rapide} onClear={() => applyQuick(null)} />}
                    <span style={{ fontSize: 'var(--fs-base)', color: 'var(--c-ink-2)' }}>
                        <b style={{ color: 'var(--c-ink)' }}>{offers.total}</b> {offers.total > 1 ? 'besoins' : 'besoin'}
                    </span>
                </div>

                <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2_5)', marginBottom: 'var(--space-3)' }}>
                    <SearchInput value={filters.q ?? ''} placeholder="Rechercher par référence, chantier ou promoteur" onChange={(q) => apply({ q })} />
                    {FILTERS.map(([key, label]) => (
                        <FilterSelect key={key} label={label} value={filters[key] ?? ''} options={filterOptions[key]} onChange={(value) => apply({ [key]: value })} />
                    ))}
                </div>

                <TableFrame minWidth={1300}>
                    <thead>
                        <tr style={{ background: 'var(--c-surface-3)' }}>
                            {COLUMNS.map(([label, key]) =>
                                key ? (
                                    <SortableTh key={label} label={label} sortKey={key} current={{ key: filters.tri, direction: filters.sens as SortDirection }} onSort={(tri, sens) => apply({ tri: tri ?? undefined, sens: tri ? sens : undefined }, true)} />
                                ) : (
                                    <th key={label} style={thStyle}>
                                        {label}
                                    </th>
                                ),
                            )}
                            <th style={{ ...thStyle, textAlign: 'center' }}>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((o) => {
                            const open = () => router.visit(`/besoins/${o.id}`);
                            return (
                                <tr key={o.id} onClick={open} className="och-hover-soft" style={{ cursor: 'pointer', background: 'var(--c-surface)' }}>
                                    <td style={tdStyle}>
                                        <StackCell main={o.title} sub={o.reference} strong />
                                    </td>
                                    <td style={tdStyle}>
                                        <StackCell main={o.site.label} sub={o.site.reference} />
                                    </td>
                                    <td style={tdStyle}>
                                        <TextCell>{o.promoter.name}</TextCell>
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
                                        <ProgressCell value={o.percent} />
                                    </td>
                                    <td style={tdStyle}>
                                        <TextCell muted={!o.startsOn}>{o.startsOn ? formatDate(o.startsOn) : '—'}</TextCell>
                                    </td>
                                    <td style={tdStyle}>
                                        <TextCell muted={!o.deadline}>{o.deadline ? formatDate(o.deadline) : '—'}</TextCell>
                                    </td>
                                    <td style={tdStyle}>
                                        <StatusBadge label={o.status.label} tone={o.status.tone} />
                                    </td>
                                    <RowActions actions={[{ icon: 'visibility', label: 'Voir la fiche', onClick: open }]} />
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
                            <StateBlock kind="nodata" icon="assignment" title="Aucun besoin" text="Les réservations publiées par les promoteurs apparaîtront ici." />
                        </div>
                    ))}

                <Pagination page={offers.currentPage} lastPage={offers.lastPage} from={offers.from} to={offers.to} total={offers.total} onPage={(page) => apply({ page: String(page) }, true)} />
            </section>

            <ActionStrip
                title="Suivi des besoins"
                icon="schedule"
                active={filters.rapide ?? null}
                activeLabel={filters.rapide ? QUICK_LABELS[filters.rapide] : undefined}
                ctaLabel="Afficher tous les besoins"
                onCta={() => applyQuick(null)}
                onSelect={applyQuick}
                items={[
                    { key: 'a_matcher', count: todo.a_matcher, label: 'besoins à matcher', icon: 'join_inner', tone: 'a' },
                    { key: 'proposition', count: todo.proposition, label: 'propositions à valider par le promoteur', icon: 'send', tone: 'b' },
                    { key: 'acompte', count: todo.acompte, label: 'acomptes attendus', icon: 'payments', tone: 'a' },
                    { key: 'incomplet', count: todo.incomplet, label: 'besoins partiellement pourvus', icon: 'group_remove', tone: 'o' },
                    { key: 'expires', count: todo.expires, label: 'dates limites dépassées', icon: 'hourglass_empty', tone: 'r' },
                ]}
            />
        </>
    );
}
