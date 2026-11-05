import ActionStrip from '@/components/list/ActionStrip';
import { FilterChip, FilterSelect, SearchInput } from '@/components/list/ListToolbar';
import Pagination from '@/components/list/Pagination';
import { RowActions, SortableTh, StackCell, TableFrame, TextCell, tdStyle, thStyle, type SortDirection } from '@/components/list/Table';
import Button from '@/components/ui/Button';
import type { Option } from '@/components/ui/Field';
import Icon from '@/components/ui/Icon';
import StatCard from '@/components/ui/StatCard';
import StateBlock from '@/components/ui/StateBlock';
import StatusBadge from '@/components/ui/StatusBadge';
import { formatDate } from '@/lib/format';
import { Head, router } from '@inertiajs/react';

type Filters = Partial<Record<'q' | 'statut' | 'metier' | 'localisation' | 'rapide' | 'chantier' | 'ouvrier' | 'periode' | 'tri' | 'sens' | 'page', string>>;

type Row = {
    id: string;
    date: string;
    status: { value: string; label: string; tone: string };
    worker: { id: string; reference: string; full_name: string };
    site: { id: string; label: string; commune: string | null };
    promoter: { name: string };
    trade: string | null;
    arrival: string | null;
    departure: string | null;
    minutes: number | null;
    outOfZone: boolean;
    openAnomalies: number;
};

type Props = {
    attendances: { data: Row[]; currentPage: number; lastPage: number; total: number; from: number; to: number };
    filters: Filters;
    filterOptions: Record<'periode' | 'statut' | 'metier' | 'localisation', Option[]>;
    kpis: { pending: number; validated: number; absent: number; anomalies: number };
    todo: { retard: number; incomplets: number; absences: number; hors_zone: number; anomalies: number };
    can: { update: boolean; export: boolean };
};

const COLUMNS: [string, string | null][] = [
    ['Date', 'date'],
    ['Ouvrier', 'ouvrier'],
    ['Chantier', null],
    ['Métier', null],
    ['Arrivée', null],
    ['Départ', null],
    ['Durée', 'duree'],
    ['Statut', 'statut'],
];

const FILTERS: ['periode' | 'statut' | 'metier' | 'localisation', string][] = [
    ['periode', 'Période'],
    ['statut', 'Statut'],
    ['metier', 'Métier'],
    ['localisation', 'Localisation'],
];

const QUICK_LABELS: Record<string, string> = { retard: 'À valider en retard', incomplets: 'Sans départ', absences: 'Absences', hors_zone: 'Hors zone', anomalies: 'Anomalie ouverte' };

export const duration = (minutes: number | null) => (minutes == null ? '—' : `${Math.floor(minutes / 60)} h ${String(minutes % 60).padStart(2, '0')}`);

/** Liste des pointages : une ligne par ouvrier et par jour. */
export default function AttendancesIndex({ attendances, filters, filterOptions, kpis, todo, can }: Props) {
    const rows = attendances.data;
    const apply = (changes: Filters, keepPage = false) => {
        const next: Filters = { ...filters, ...changes, ...(keepPage ? {} : { page: undefined }) };
        router.get('/pointages', Object.fromEntries(Object.entries(next).filter(([, v]) => v)), { preserveState: true, preserveScroll: true, replace: true });
    };
    const applyQuick = (key: string | null) => apply({ rapide: key ?? undefined });
    const filtered = Object.entries(filters).some(([k, v]) => !['tri', 'sens', 'page'].includes(k) && v);
    const exportUrl = `/pointages/export?${new URLSearchParams(Object.entries(filters).filter(([k, v]) => v && k !== 'page') as [string, string][]).toString()}`;
    const remind = (id: string) => router.post(`/pointages/${id}/relance`, {}, { preserveScroll: true });

    return (
        <>
            <Head title="Pointages" />

            {can.export && (
                <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: -6 }}>
                    <Button label="Exporter" icon="download" variant="secondary" onClick={() => (window.location.href = exportUrl)} />
                </div>
            )}

            <div data-component="StatGrid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(230px,1fr))', gap: 'var(--space-4)' }}>
                <StatCard label="À valider par le promoteur" value={String(kpis.pending)} icon="pending_actions" tone="a" />
                <StatCard label="Journées validées" value={String(kpis.validated)} icon="verified" tone="g" />
                <StatCard label="Absences" value={String(kpis.absent)} icon="person_off" tone="r" />
                <StatCard label="Avec anomalie ouverte" value={String(kpis.anomalies)} icon="report" tone="o" />
            </div>

            <section data-component="ListPanel" style={{ background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-5) var(--space-5) var(--space-4)', minWidth: 0 }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-3)', flexWrap: 'wrap', marginBottom: 'var(--space-3)' }}>
                    <Icon name="schedule" size="var(--icon-md)" color="var(--c-primary)" />
                    <h2 style={{ margin: 0, fontSize: 'var(--fs-2xl)', fontWeight: 'var(--fw-bold)', flex: 1 }}>Liste des pointages</h2>
                    {filters.rapide && <FilterChip label={QUICK_LABELS[filters.rapide] ?? filters.rapide} onClear={() => applyQuick(null)} />}
                    <span style={{ fontSize: 'var(--fs-base)', color: 'var(--c-ink-2)' }}>
                        <b style={{ color: 'var(--c-ink)' }}>{attendances.total}</b> {attendances.total > 1 ? 'journées' : 'journée'}
                    </span>
                </div>

                <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2_5)', marginBottom: 'var(--space-3)' }}>
                    <SearchInput value={filters.q ?? ''} placeholder="Rechercher par ouvrier ou chantier" onChange={(q) => apply({ q })} />
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
                            const open = () => router.visit(`/pointages/${a.id}`);
                            return (
                                <tr key={a.id} onClick={open} className="och-hover-soft" style={{ cursor: 'pointer', background: 'var(--c-surface)' }}>
                                    <td style={tdStyle}>
                                        <TextCell>{formatDate(a.date)}</TextCell>
                                    </td>
                                    <td style={tdStyle}>
                                        <StackCell main={a.worker.full_name} sub={a.worker.reference} strong />
                                    </td>
                                    <td style={tdStyle}>
                                        <StackCell main={a.site.label} sub={a.promoter.name} />
                                    </td>
                                    <td style={tdStyle}>
                                        <TextCell muted={!a.trade}>{a.trade ?? '—'}</TextCell>
                                    </td>
                                    <td style={tdStyle}>
                                        <TextCell muted={!a.arrival}>{a.arrival ?? '—'}</TextCell>
                                    </td>
                                    <td style={tdStyle}>
                                        <TextCell muted={!a.departure}>{a.departure ?? '—'}</TextCell>
                                    </td>
                                    <td style={tdStyle}>
                                        <TextCell muted={a.minutes == null}>{duration(a.minutes)}</TextCell>
                                    </td>
                                    <td style={tdStyle}>
                                        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-1)' }}>
                                            <StatusBadge label={a.status.label} tone={a.status.tone as never} />
                                            {a.outOfZone && <StatusBadge label="Hors zone" tone="o" />}
                                            {a.openAnomalies > 0 && <StatusBadge label={a.openAnomalies > 1 ? `${a.openAnomalies} anomalies` : '1 anomalie'} tone="a" />}
                                        </div>
                                    </td>
                                    <RowActions actions={[{ icon: 'visibility', label: 'Voir la fiche', onClick: open }, ...(can.update && a.status.value === 'a_valider' ? [{ icon: 'notifications_active', label: 'Relancer le promoteur', onClick: () => remind(a.id) }] : [])]} />
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
                            <StateBlock kind="nodata" icon="schedule" title="Aucun pointage" text="Les pointages apparaissent dès que le promoteur enregistre une arrivée ou une absence." />
                        </div>
                    ))}

                <Pagination page={attendances.currentPage} lastPage={attendances.lastPage} from={attendances.from} to={attendances.to} total={attendances.total} onPage={(page) => apply({ page: String(page) }, true)} />
            </section>

            <ActionStrip
                title="Suivi des pointages"
                icon="schedule"
                active={filters.rapide ?? null}
                activeLabel={filters.rapide ? QUICK_LABELS[filters.rapide] : undefined}
                ctaLabel="Afficher tous les pointages"
                onCta={() => applyQuick(null)}
                onSelect={applyQuick}
                items={[
                    { key: 'retard', count: todo.retard, label: 'à valider en retard', icon: 'pending_actions', tone: 'a' },
                    { key: 'incomplets', count: todo.incomplets, label: 'sans départ', icon: 'logout', tone: 'o' },
                    { key: 'absences', count: todo.absences, label: 'absences', icon: 'person_off', tone: 'r' },
                    { key: 'hors_zone', count: todo.hors_zone, label: 'hors zone', icon: 'wrong_location', tone: 'o' },
                ]}
            />
        </>
    );
}
