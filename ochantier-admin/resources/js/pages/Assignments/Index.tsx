import ActionStrip from '@/components/list/ActionStrip';
import { FilterChip, FilterSelect, SearchInput } from '@/components/list/ListToolbar';
import Pagination from '@/components/list/Pagination';
import { LocationCell, RowActions, SortableTh, StackCell, TableFrame, TextCell, tdStyle, thStyle, type SortDirection } from '@/components/list/Table';
import Button from '@/components/ui/Button';
import type { Option } from '@/components/ui/Field';
import Icon from '@/components/ui/Icon';
import StatCard from '@/components/ui/StatCard';
import StateBlock from '@/components/ui/StateBlock';
import StatusBadge from '@/components/ui/StatusBadge';
import { formatDate, formatNumber } from '@/lib/format';
import { Head, router } from '@inertiajs/react';

type Filters = Partial<Record<'q' | 'statut' | 'metier' | 'localisation' | 'rapide' | 'chantier' | 'tri' | 'sens' | 'page', string>>;

type Row = {
    id: string;
    status: { value: string; label: string; tone: string };
    worker: { id: string; reference: string; full_name: string; phone: string | null };
    site: { id: string; reference: string; label: string; commune: string | null };
    promoter: { name: string };
    trade: string | null;
    lead: boolean;
    startsOn: string | null;
    endsOn: string | null;
    promoterRate: number | null;
    netRate: number | null;
    replacement: { id: string; status: { label: string } } | null;
};

type Props = {
    assignments: { data: Row[]; currentPage: number; lastPage: number; total: number; from: number; to: number };
    filters: Filters;
    filterOptions: Record<'statut' | 'metier' | 'localisation', Option[]>;
    kpis: { active: number; confirmed: number; replacements: number; finished: number };
    todo: { remplacements: number; demarrage: number; fin: number };
    can: { update: boolean; export: boolean };
};

const COLUMNS: [string, string | null][] = [
    ['Ouvrier', 'ouvrier'],
    ['Chantier', null],
    ['Localisation', null],
    ['Métier', null],
    ['Début', 'debut'],
    ['Fin', 'fin'],
    ['Tarif net', null],
    ['Statut', 'statut'],
];

const FILTERS: ['statut' | 'metier' | 'localisation', string][] = [
    ['statut', 'Statut'],
    ['metier', 'Métier'],
    ['localisation', 'Localisation'],
];

const QUICK_LABELS: Record<string, string> = { remplacements: 'Remplacement en cours', demarrage: 'Démarrent sous 7 jours', fin: 'Terminent sous 7 jours' };

/** Liste des affectations : une ligne par ouvrier affecté à une mission. */
export default function AssignmentsIndex({ assignments, filters, filterOptions, kpis, todo, can }: Props) {
    const rows = assignments.data;
    const apply = (changes: Filters, keepPage = false) => {
        const next: Filters = { ...filters, ...changes, ...(keepPage ? {} : { page: undefined }) };
        router.get('/affectations', Object.fromEntries(Object.entries(next).filter(([, v]) => v)), { preserveState: true, preserveScroll: true, replace: true });
    };
    const applyQuick = (key: string | null) => apply({ rapide: key ?? undefined });
    const filtered = Object.entries(filters).some(([k, v]) => !['tri', 'sens', 'page'].includes(k) && v);
    const exportUrl = `/affectations/export?${new URLSearchParams(Object.entries(filters).filter(([k, v]) => v && k !== 'page') as [string, string][]).toString()}`;

    return (
        <>
            <Head title="Affectations" />

            {can.export && (
                <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: -6 }}>
                    <Button label="Exporter" icon="download" variant="secondary" onClick={() => (window.location.href = exportUrl)} />
                </div>
            )}

            <div data-component="StatGrid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(230px,1fr))', gap: 'var(--space-4)' }}>
                <StatCard label="Affectations actives" value={String(kpis.active)} icon="engineering" tone="g" />
                <StatCard label="Confirmées, à démarrer" value={String(kpis.confirmed)} icon="event_available" tone="b" />
                <StatCard label="Remplacements en cours" value={String(kpis.replacements)} icon="swap_horiz" tone="a" />
                <StatCard label="Terminées" value={String(kpis.finished)} icon="task_alt" tone="g" />
            </div>

            <section data-component="ListPanel" style={{ background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-5) var(--space-5) var(--space-4)', minWidth: 0 }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-3)', flexWrap: 'wrap', marginBottom: 'var(--space-3)' }}>
                    <Icon name="assignment_ind" size="var(--icon-md)" color="var(--c-primary)" />
                    <h2 style={{ margin: 0, fontSize: 'var(--fs-2xl)', fontWeight: 'var(--fw-bold)', flex: 1 }}>Liste des affectations</h2>
                    {filters.rapide && <FilterChip label={QUICK_LABELS[filters.rapide] ?? filters.rapide} onClear={() => applyQuick(null)} />}
                    <span style={{ fontSize: 'var(--fs-base)', color: 'var(--c-ink-2)' }}>
                        <b style={{ color: 'var(--c-ink)' }}>{assignments.total}</b> {assignments.total > 1 ? 'affectations' : 'affectation'}
                    </span>
                </div>

                <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2_5)', marginBottom: 'var(--space-3)' }}>
                    <SearchInput value={filters.q ?? ''} placeholder="Rechercher par ouvrier, chantier ou mission" onChange={(q) => apply({ q })} />
                    {FILTERS.map(([key, label]) => (
                        <FilterSelect key={key} label={label} value={filters[key] ?? ''} options={filterOptions[key]} onChange={(value) => apply({ [key]: value })} />
                    ))}
                </div>

                <TableFrame minWidth={1100}>
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
                        {rows.map((a) => {
                            const open = () => router.visit(`/affectations/${a.id}`);
                            return (
                                <tr key={a.id} onClick={open} className="och-hover-soft" style={{ cursor: 'pointer', background: 'var(--c-surface)' }}>
                                    <td style={tdStyle}>
                                        <StackCell main={a.worker.full_name} sub={[a.worker.reference, a.worker.phone].filter(Boolean).join(' · ')} strong />
                                    </td>
                                    <td style={tdStyle}>
                                        <StackCell main={a.site.label} sub={a.promoter.name} />
                                    </td>
                                    <td style={tdStyle}>
                                        <LocationCell value={a.site.commune ?? '—'} />
                                    </td>
                                    <td style={tdStyle}>
                                        <TextCell>{a.lead ? `${a.trade ?? '—'} · chef d’équipe` : (a.trade ?? '—')}</TextCell>
                                    </td>
                                    <td style={tdStyle}>
                                        <TextCell muted={!a.startsOn}>{a.startsOn ? formatDate(a.startsOn) : '—'}</TextCell>
                                    </td>
                                    <td style={tdStyle}>
                                        <TextCell muted={!a.endsOn}>{a.endsOn ? formatDate(a.endsOn) : '—'}</TextCell>
                                    </td>
                                    <td style={tdStyle}>
                                        <TextCell muted={a.netRate == null}>{a.netRate != null ? `${formatNumber(a.netRate)} FCFA` : '—'}</TextCell>
                                    </td>
                                    <td style={tdStyle}>
                                        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-1)' }}>
                                            <StatusBadge label={a.status.label} tone={a.status.tone as never} />
                                            {a.replacement && <StatusBadge label="Remplacement" tone="a" />}
                                        </div>
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
                            <StateBlock kind="nodata" icon="assignment_ind" title="Aucune affectation" text="Les affectations apparaissent après le paiement de l’acompte par le promoteur." />
                        </div>
                    ))}

                <Pagination page={assignments.currentPage} lastPage={assignments.lastPage} from={assignments.from} to={assignments.to} total={assignments.total} onPage={(page) => apply({ page: String(page) }, true)} />
            </section>

            <ActionStrip
                title="Suivi des affectations"
                icon="schedule"
                active={filters.rapide ?? null}
                activeLabel={filters.rapide ? QUICK_LABELS[filters.rapide] : undefined}
                ctaLabel="Afficher toutes les affectations"
                onCta={() => applyQuick(null)}
                onSelect={applyQuick}
                items={[
                    { key: 'remplacements', count: todo.remplacements, label: 'remplacements en cours', icon: 'swap_horiz', tone: 'a' },
                    { key: 'demarrage', count: todo.demarrage, label: 'démarrent sous 7 jours', icon: 'event_available', tone: 'b' },
                    { key: 'fin', count: todo.fin, label: 'terminent sous 7 jours', icon: 'event_busy', tone: 'o' },
                ]}
            />
        </>
    );
}
