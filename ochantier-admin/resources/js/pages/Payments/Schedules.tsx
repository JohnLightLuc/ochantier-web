import ActionStrip from '@/components/list/ActionStrip';
import { FilterChip, FilterSelect, SearchInput } from '@/components/list/ListToolbar';
import Pagination from '@/components/list/Pagination';
import { SortableTh, StackCell, TableFrame, TextCell, tdStyle, thStyle, type SortDirection } from '@/components/list/Table';
import Button from '@/components/ui/Button';
import type { Option } from '@/components/ui/Field';
import Icon from '@/components/ui/Icon';
import StatCard from '@/components/ui/StatCard';
import StateBlock from '@/components/ui/StateBlock';
import StatusBadge from '@/components/ui/StatusBadge';
import { fcfa, formatDate } from '@/lib/format';
import { Head, router } from '@inertiajs/react';

type FilterKey = 'etat' | 'type';

type Filters = Partial<Record<FilterKey | 'q' | 'chantier' | 'tri' | 'sens' | 'page', string>>;

type Row = {
    id: string;
    reference: string;
    state: { value: string; label: string; tone: string };
    type: string;
    sequence: number;
    site: { id: string; label: string };
    promoter: { name: string };
    trade: string | null;
    dueDate: string | null;
    isFlexible: boolean;
    due: number;
    paid: number;
    remaining: number;
};

type Props = {
    schedules: { data: Row[]; currentPage: number; lastPage: number; total: number; from: number; to: number };
    filters: Filters;
    filterOptions: Record<FilterKey, Option[]>;
    kpis: { open: number; late: number; lateCount: number; paid: number };
    states: Record<string, number>;
    can: { update: boolean; export: boolean };
};

const COLUMNS: [string, string | null][] = [
    ['Échéance', 'echeance'],
    ['Référence', null],
    ['Promoteur', null],
    ['Type', null],
    ['Dû', 'montant'],
    ['Payé', null],
    ['Reste', null],
    ['État', 'etat'],
];

const FILTERS: [FilterKey, string][] = [
    ['etat', 'État'],
    ['type', 'Type'],
];

/** Échéances : ce que chaque mission doit régler, avec son état du jour. */
export default function PaymentSchedules({ schedules, filters, filterOptions, kpis, states, can }: Props) {
    const apply = (changes: Filters, keepPage = false) => {
        const next: Filters = { ...filters, ...changes, ...(keepPage ? {} : { page: undefined }) };
        router.get('/paiements/echeances', Object.fromEntries(Object.entries(next).filter(([, v]) => v)), { preserveState: true, preserveScroll: true, replace: true });
    };
    const applyState = (key: string | null) => apply({ etat: key ?? undefined });
    const filtered = Object.entries(filters).some(([k, v]) => !['tri', 'sens', 'page'].includes(k) && v);
    const exportUrl = `/paiements/echeances/export?${new URLSearchParams(Object.entries(filters).filter(([k, v]) => v && k !== 'page') as [string, string][]).toString()}`;
    const stateLabel = filterOptions.etat.find((o) => o.value === filters.etat)?.label;

    return (
        <>
            <Head title="Échéances" />

            <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 'var(--space-2)', marginTop: -6 }}>
                <Button label="Encaissements" icon="payments" variant="secondary" onClick={() => router.visit('/paiements')} />
                {can.export && <Button label="Exporter" icon="download" variant="secondary" onClick={() => (window.location.href = exportUrl)} />}
            </div>

            <div data-component="StatGrid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(230px,1fr))', gap: 'var(--space-4)' }}>
                <StatCard label="À encaisser maintenant" value={fcfa(kpis.open)} icon="pending_actions" tone="a" />
                <StatCard label="Dont en retard" value={fcfa(kpis.late)} icon="schedule" tone="r" hint={`${kpis.lateCount} échéance(s)`} />
                <StatCard label="Déjà réglé" value={fcfa(kpis.paid)} icon="verified" tone="g" />
            </div>

            <section data-component="ListPanel" style={{ background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-5) var(--space-5) var(--space-4)', minWidth: 0 }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-3)', flexWrap: 'wrap', marginBottom: 'var(--space-3)' }}>
                    <Icon name="event_note" size="var(--icon-md)" color="var(--c-primary)" />
                    <h2 style={{ margin: 0, fontSize: 'var(--fs-2xl)', fontWeight: 'var(--fw-bold)', flex: 1 }}>Liste des échéances</h2>
                    {filters.chantier && <FilterChip label="Un chantier" onClear={() => apply({ chantier: undefined })} />}
                    <span style={{ fontSize: 'var(--fs-base)', color: 'var(--c-ink-2)' }}>
                        <b style={{ color: 'var(--c-ink)' }}>{schedules.total}</b> {schedules.total > 1 ? 'échéances' : 'échéance'}
                    </span>
                </div>

                <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2_5)', marginBottom: 'var(--space-3)' }}>
                    <SearchInput value={filters.q ?? ''} placeholder="Rechercher par référence, promoteur ou chantier" onChange={(q) => apply({ q })} />
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
                        </tr>
                    </thead>
                    <tbody>
                        {schedules.data.map((s) => (
                            <tr key={s.id} onClick={() => router.visit(`/paiements/echeances/${s.id}`)} className="och-hover-soft" style={{ cursor: 'pointer', background: 'var(--c-surface)' }}>
                                <td style={tdStyle}>
                                    <StackCell main={formatDate(s.dueDate)} sub={s.isFlexible ? 'Paiement flexible' : '—'} />
                                </td>
                                <td style={tdStyle}>
                                    <TextCell>{s.reference}</TextCell>
                                </td>
                                <td style={tdStyle}>
                                    <StackCell main={s.promoter.name} sub={s.site.label} strong />
                                </td>
                                <td style={tdStyle}>
                                    <StackCell main={s.type} sub={s.trade ?? '—'} />
                                </td>
                                <td style={tdStyle}>
                                    <TextCell>{fcfa(s.due)}</TextCell>
                                </td>
                                <td style={tdStyle}>
                                    <TextCell>{fcfa(s.paid)}</TextCell>
                                </td>
                                <td style={tdStyle}>
                                    <TextCell>{fcfa(s.remaining)}</TextCell>
                                </td>
                                <td style={tdStyle}>
                                    <StatusBadge label={s.state.label} tone={s.state.tone as never} />
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </TableFrame>

                {schedules.data.length === 0 && (
                    <div style={{ paddingTop: 'var(--space-4)' }}>
                        {filtered ? <StateBlock kind="empty" icon="filter_alt_off" title="Aucun résultat" text="Aucune échéance ne correspond à ces filtres." /> : <StateBlock kind="nodata" icon="event_note" title="Aucune échéance" text="Les échéances sont créées quand un promoteur paie son acompte." />}
                    </div>
                )}

                <Pagination page={schedules.currentPage} lastPage={schedules.lastPage} from={schedules.from} to={schedules.to} total={schedules.total} onPage={(page) => apply({ page: String(page) }, true)} />
            </section>

            <ActionStrip
                title="Suivi des échéances"
                icon="event_note"
                active={filters.etat ?? null}
                activeLabel={stateLabel}
                ctaLabel="Afficher toutes les échéances"
                onCta={() => applyState(null)}
                onSelect={applyState}
                items={[
                    { key: 'en_retard', count: states.en_retard, label: 'en retard', icon: 'schedule', tone: 'r' },
                    { key: 'exigible', count: states.exigible, label: 'exigibles', icon: 'pending_actions', tone: 'a' },
                    { key: 'partiellement_payee', count: states.partiellement_payee, label: 'partiellement payées', icon: 'hourglass_top', tone: 'o' },
                ]}
            />
        </>
    );
}
