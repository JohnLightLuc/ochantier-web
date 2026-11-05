import ActionStrip from '@/components/list/ActionStrip';
import { FilterChip, FilterSelect, SearchInput } from '@/components/list/ListToolbar';
import Pagination from '@/components/list/Pagination';
import { LocationCell, RowActions, SortableTh, StackCell, TableFrame, TextCell, tdStyle, thStyle, type SortDirection } from '@/components/list/Table';
import { useSiteActions } from '@/components/sites/useSiteActions';
import Button from '@/components/ui/Button';
import Icon from '@/components/ui/Icon';
import StatCard from '@/components/ui/StatCard';
import StateBlock from '@/components/ui/StateBlock';
import StatusBadge from '@/components/ui/StatusBadge';
import { formatDate } from '@/lib/format';
import type { SiteFilters, SiteIndexProps } from '@/types/sites';
import { Head, router } from '@inertiajs/react';

const COLUMNS: [string, string | null][] = [
    ['Chantier', 'chantier'],
    ['Promoteur', null],
    ['Localisation', null],
    ['Type', null],
    ['Début', 'debut'],
    ['Fin prévue', 'fin'],
    ['Effectif', 'effectif'],
    ['Alertes', null],
    ['Statut', 'statut'],
];

const FILTERS: [keyof SiteIndexProps['filterOptions'], string][] = [
    ['statut', 'Statut'],
    ['type', 'Type de construction'],
    ['localisation', 'Localisation'],
    ['periode', 'Période'],
    ['priorite', 'Priorité'],
];

const QUICK_LABELS: Record<string, string> = { retard: 'Chantiers en retard', effectif: 'Effectif incomplet', incidents: 'Avec incident ouvert', cloturer: 'À clôturer', pointages: 'Pointages à valider' };

/** Liste des chantiers : indicateurs, filtres, tri et suivi. */
export default function SitesIndex({ sites, filters, filterOptions, kpis, todo, can }: SiteIndexProps) {
    const actions = useSiteActions();
    const rows = sites.data;

    const apply = (changes: SiteFilters, keepPage = false) => {
        const next: SiteFilters = { ...filters, ...changes, ...(keepPage ? {} : { page: undefined }) };
        router.get('/chantiers', Object.fromEntries(Object.entries(next).filter(([, v]) => v)), { preserveState: true, preserveScroll: true, replace: true });
    };

    const applyQuick = (key: string | null) => apply({ rapide: key ?? undefined });
    const filtered = Object.entries(filters).some(([k, v]) => !['tri', 'sens', 'page'].includes(k) && v);
    const exportUrl = `/chantiers/export?${new URLSearchParams(Object.entries(filters).filter(([k, v]) => v && k !== 'page') as [string, string][]).toString()}`;

    return (
        <>
            <Head title="Chantiers" />

            {can.export && (
                <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: -6 }}>
                    <Button label="Exporter" icon="download" variant="secondary" onClick={() => (window.location.href = exportUrl)} />
                </div>
            )}

            <div data-component="StatGrid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(230px,1fr))', gap: 'var(--space-4)' }}>
                <StatCard label="Total des chantiers" value={String(kpis.total)} icon="domain" tone="g" />
                <StatCard label="Chantiers actifs" value={String(kpis.active)} icon="construction" tone="g" />
                <StatCard label="En recherche d’ouvriers" value={String(kpis.searching)} icon="person_search" tone="b" />
                <StatCard label="En retard" value={String(kpis.late)} icon="schedule" tone="a" />
            </div>

            <section data-component="ListPanel" style={{ background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-5) var(--space-5) var(--space-4)', minWidth: 0 }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-3)', flexWrap: 'wrap', marginBottom: 'var(--space-3)' }}>
                    <Icon name="construction" size="var(--icon-md)" color="var(--c-primary)" />
                    <h2 style={{ margin: 0, fontSize: 'var(--fs-2xl)', fontWeight: 'var(--fw-bold)', flex: 1 }}>Liste des chantiers</h2>
                    {filters.rapide && <FilterChip label={QUICK_LABELS[filters.rapide] ?? filters.rapide} onClear={() => applyQuick(null)} />}
                    <span style={{ fontSize: 'var(--fs-base)', color: 'var(--c-ink-2)' }}>
                        <b style={{ color: 'var(--c-ink)' }}>{sites.total}</b> {sites.total > 1 ? 'chantiers' : 'chantier'}
                    </span>
                </div>

                <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2_5)', marginBottom: 'var(--space-3)' }}>
                    <SearchInput value={filters.q ?? ''} placeholder="Rechercher par chantier, référence ou promoteur" onChange={(q) => apply({ q })} />
                    {FILTERS.map(([key, label]) => (
                        <FilterSelect key={key} label={label} value={filters[key] ?? ''} options={filterOptions[key]} onChange={(value) => apply({ [key]: value })} />
                    ))}
                </div>

                <TableFrame minWidth={1200}>
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
                        {rows.map((s) => {
                            const open = () => router.visit(`/chantiers/${s.id}`);
                            return (
                                <tr key={s.id} onClick={open} className="och-hover-soft" style={{ cursor: 'pointer', background: 'var(--c-surface)' }}>
                                    <td style={tdStyle}>
                                        <StackCell main={s.name} sub={s.reference} strong />
                                    </td>
                                    <td style={tdStyle}>
                                        <TextCell>{s.promoter}</TextCell>
                                    </td>
                                    <td style={tdStyle}>
                                        <LocationCell value={s.commune ?? '—'} />
                                    </td>
                                    <td style={tdStyle}>
                                        <TextCell muted={!s.type}>{s.type ?? '—'}</TextCell>
                                    </td>
                                    <td style={tdStyle}>
                                        <TextCell muted={!s.startsOn}>{s.startsOn ? formatDate(s.startsOn) : '—'}</TextCell>
                                    </td>
                                    <td style={tdStyle}>
                                        <TextCell muted={!s.endsOn}>{s.endsOn ? formatDate(s.endsOn) : '—'}</TextCell>
                                    </td>
                                    <td style={tdStyle}>
                                        <TextCell>
                                            {s.assigned} / {s.required}
                                        </TextCell>
                                    </td>
                                    <td style={tdStyle}>
                                        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-1)' }}>
                                            {s.isLate && <StatusBadge label="Retard" tone="r" />}
                                            {s.openIncidents > 0 && <StatusBadge label={`${s.openIncidents} incident(s)`} tone="o" />}
                                            {s.pendingDays > 0 && <StatusBadge label={`${s.pendingDays} à valider`} tone="a" />}
                                            {!s.isLate && !s.openIncidents && !s.pendingDays && <TextCell muted>—</TextCell>}
                                        </div>
                                    </td>
                                    <td style={tdStyle}>
                                        <StatusBadge label={s.archived ? 'Archivé' : s.status.label} tone={s.archived ? 'n' : s.status.tone} />
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
                            <StateBlock kind="nodata" icon="construction" title="Aucun chantier" text="Les chantiers créés par les promoteurs apparaîtront ici." />
                        </div>
                    ))}

                <Pagination page={sites.currentPage} lastPage={sites.lastPage} from={sites.from} to={sites.to} total={sites.total} onPage={(page) => apply({ page: String(page) }, true)} />
            </section>

            <ActionStrip
                title="Suivi des chantiers"
                icon="schedule"
                active={filters.rapide ?? null}
                activeLabel={filters.rapide ? QUICK_LABELS[filters.rapide] : undefined}
                ctaLabel="Afficher tous les chantiers"
                onCta={() => applyQuick(null)}
                onSelect={applyQuick}
                items={[
                    { key: 'retard', count: todo.retard, label: 'chantiers en retard', icon: 'schedule', tone: 'r' },
                    { key: 'effectif', count: todo.effectif, label: 'chantiers à effectif incomplet', icon: 'group_remove', tone: 'o' },
                    { key: 'incidents', count: todo.incidents, label: 'chantiers avec incident ouvert', icon: 'report', tone: 'r' },
                    { key: 'cloturer', count: todo.cloturer, label: 'chantiers à clôturer', icon: 'task_alt', tone: 'a' },
                    { key: 'pointages', count: todo.pointages, label: 'chantiers avec pointages à valider', icon: 'pending_actions', tone: 'a' },
                ]}
            />

            {actions.dialog}
        </>
    );
}
