import { FilterChip, FilterSelect, SearchInput } from '@/components/list/ListToolbar';
import Pagination from '@/components/list/Pagination';
import { SortableTh, StackCell, TableFrame, TextCell, tdStyle, thStyle, type SortDirection } from '@/components/list/Table';
import Button from '@/components/ui/Button';
import type { Option } from '@/components/ui/Field';
import Icon from '@/components/ui/Icon';
import StatCard from '@/components/ui/StatCard';
import StateBlock from '@/components/ui/StateBlock';
import StatusBadge from '@/components/ui/StatusBadge';
import { fcfa } from '@/lib/format';
import { Head, router } from '@inertiajs/react';

type FilterKey = 'periode' | 'statut' | 'nature' | 'operateur';

type Filters = Partial<Record<FilterKey | 'q' | 'chantier' | 'tri' | 'sens' | 'page', string>>;

type Row = {
    id: string;
    reference: string;
    status: { value: string; label: string; tone: string };
    purpose: string;
    label: string | null;
    site: { id: string; label: string } | null;
    promoter: { name: string };
    operator: string | null;
    amount: number;
    fees: number;
    total: number;
    date: string | null;
};

type Props = {
    transactions: { data: Row[]; currentPage: number; lastPage: number; total: number; from: number; to: number };
    filters: Filters;
    filterOptions: Record<FilterKey, Option[]>;
    kpis: { collected: number; month: number; fees: number; failed: number };
    can: { update: boolean; export: boolean };
};

const COLUMNS: [string, string | null][] = [
    ['Date', 'date'],
    ['Référence', null],
    ['Promoteur', null],
    ['Nature', null],
    ['Opérateur', null],
    ['Montant', 'montant'],
    ['Frais', null],
    ['Statut', 'statut'],
];

const FILTERS: [FilterKey, string][] = [
    ['periode', 'Période'],
    ['statut', 'Statut'],
    ['nature', 'Nature'],
    ['operateur', 'Opérateur'],
];

/** Encaissements : une ligne par paiement reçu ou tenté par un promoteur. */
export default function PaymentsIndex({ transactions, filters, filterOptions, kpis, can }: Props) {
    const apply = (changes: Filters, keepPage = false) => {
        const next: Filters = { ...filters, ...changes, ...(keepPage ? {} : { page: undefined }) };
        router.get('/paiements', Object.fromEntries(Object.entries(next).filter(([, v]) => v)), { preserveState: true, preserveScroll: true, replace: true });
    };
    const filtered = Object.entries(filters).some(([k, v]) => !['tri', 'sens', 'page'].includes(k) && v);
    const exportUrl = `/paiements/export?${new URLSearchParams(Object.entries(filters).filter(([k, v]) => v && k !== 'page') as [string, string][]).toString()}`;

    return (
        <>
            <Head title="Paiements" />

            <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 'var(--space-2)', marginTop: -6 }}>
                <Button label="Échéances" icon="event_note" variant="secondary" onClick={() => router.visit('/paiements/echeances')} />
                {can.export && <Button label="Exporter" icon="download" variant="secondary" onClick={() => (window.location.href = exportUrl)} />}
            </div>

            <div data-component="StatGrid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(230px,1fr))', gap: 'var(--space-4)' }}>
                <StatCard label="Encaissé au total" value={fcfa(kpis.collected)} icon="payments" tone="g" />
                <StatCard label="Encaissé ce mois-ci" value={fcfa(kpis.month)} icon="calendar_month" tone="b" />
                <StatCard label="Frais des opérateurs" value={fcfa(kpis.fees)} icon="account_balance" tone="a" />
                <StatCard label="Paiements échoués" value={String(kpis.failed)} icon="error" tone="r" />
            </div>

            <section data-component="ListPanel" style={{ background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-5) var(--space-5) var(--space-4)', minWidth: 0 }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-3)', flexWrap: 'wrap', marginBottom: 'var(--space-3)' }}>
                    <Icon name="payments" size="var(--icon-md)" color="var(--c-primary)" />
                    <h2 style={{ margin: 0, fontSize: 'var(--fs-2xl)', fontWeight: 'var(--fw-bold)', flex: 1 }}>Encaissements</h2>
                    {filters.chantier && <FilterChip label="Un chantier" onClear={() => apply({ chantier: undefined })} />}
                    <span style={{ fontSize: 'var(--fs-base)', color: 'var(--c-ink-2)' }}>
                        <b style={{ color: 'var(--c-ink)' }}>{transactions.total}</b> {transactions.total > 1 ? 'paiements' : 'paiement'}
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
                        {transactions.data.map((t) => (
                            <tr key={t.id} onClick={() => router.visit(`/paiements/${t.id}`)} className="och-hover-soft" style={{ cursor: 'pointer', background: 'var(--c-surface)' }}>
                                <td style={tdStyle}>
                                    <TextCell>{t.date ?? '—'}</TextCell>
                                </td>
                                <td style={tdStyle}>
                                    <TextCell>{t.reference}</TextCell>
                                </td>
                                <td style={tdStyle}>
                                    <StackCell main={t.promoter.name} sub={t.site?.label ?? '—'} strong />
                                </td>
                                <td style={tdStyle}>
                                    <StackCell main={t.purpose} sub={t.label ?? '—'} />
                                </td>
                                <td style={tdStyle}>
                                    <TextCell muted={!t.operator}>{t.operator ?? '—'}</TextCell>
                                </td>
                                <td style={tdStyle}>
                                    <TextCell>{fcfa(t.amount)}</TextCell>
                                </td>
                                <td style={tdStyle}>
                                    <TextCell>{fcfa(t.fees)}</TextCell>
                                </td>
                                <td style={tdStyle}>
                                    <StatusBadge label={t.status.label} tone={t.status.tone as never} />
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </TableFrame>

                {transactions.data.length === 0 && (
                    <div style={{ paddingTop: 'var(--space-4)' }}>
                        {filtered ? <StateBlock kind="empty" icon="filter_alt_off" title="Aucun résultat" text="Aucun paiement ne correspond à ces filtres." /> : <StateBlock kind="nodata" icon="payments" title="Aucun paiement" text="Les paiements apparaissent dès qu’un promoteur règle un acompte, une échéance ou un abonnement." />}
                    </div>
                )}

                <Pagination page={transactions.currentPage} lastPage={transactions.lastPage} from={transactions.from} to={transactions.to} total={transactions.total} onPage={(page) => apply({ page: String(page) }, true)} />
            </section>
        </>
    );
}
