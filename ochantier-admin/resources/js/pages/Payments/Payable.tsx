import { FilterSelect, SearchInput } from '@/components/list/ListToolbar';
import Pagination from '@/components/list/Pagination';
import { StackCell, TableFrame, TextCell, tdStyle, thStyle } from '@/components/list/Table';
import PaymentNav from '@/components/payments/PaymentNav';
import Button from '@/components/ui/Button';
import type { Option } from '@/components/ui/Field';
import StatCard from '@/components/ui/StatCard';
import StateBlock from '@/components/ui/StateBlock';
import StatusBadge from '@/components/ui/StatusBadge';
import { fcfa, formatDate } from '@/lib/format';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

type Filters = { q?: string; etat?: string; page?: string };

type Row = {
    key: string;
    schedule_id: string;
    assignment_id: string;
    schedule: { id: string; reference: string; sequence: number };
    worker: { id: string; reference: string; full_name: string };
    site: { id: string; label: string };
    first_day: string;
    last_day: string;
    days: number;
    gross_amount: number;
    commission_amount: number;
    net_amount: number;
    number: string | null;
    blockers: string[];
};

type Props = {
    rows: { data: Row[]; currentPage: number; lastPage: number; total: number; from: number; to: number };
    filters: Filters;
    filterOptions: { etat: Option[] };
    kpis: { payable: number; blocked: number; payable_amount: number; blocked_amount: number; pendingMethods: number };
    can: { create: boolean; approve: boolean; export: boolean };
};

const COLUMNS = ['', 'Ouvrier', 'Chantier', 'Période', 'Journées', 'Brut', 'Net', 'Numéro', 'État'];

/** À reverser : journées validées dont l’échéance du promoteur est réglée ; on en compose un lot. */
export default function PaymentPayable({ rows, filters, filterOptions, kpis, can }: Props) {
    const [selected, setSelected] = useState<Record<string, Row>>({});
    const picked = Object.values(selected);
    const ready = rows.data.filter((r) => r.blockers.length === 0);
    const apply = (changes: Filters) => {
        const next: Filters = { ...filters, page: undefined, ...changes };
        router.get('/paiements/a-reverser', Object.fromEntries(Object.entries(next).filter(([, v]) => v)), { preserveState: true, preserveScroll: true, replace: true });
    };
    const toggle = (row: Row) =>
        setSelected((current) => {
            const next = { ...current };
            if (next[row.key]) delete next[row.key];
            else next[row.key] = row;
            return next;
        });
    const selectPage = () => setSelected((current) => ({ ...current, ...Object.fromEntries(ready.map((r) => [r.key, r])) }));
    const prepare = () => router.post('/paiements/a-reverser/lot', { items: picked.map((r) => ({ schedule_id: r.schedule_id, assignment_id: r.assignment_id })) });

    return (
        <>
            <Head title="À reverser" />

            <div style={{ display: 'flex', justifyContent: 'flex-end', flexWrap: 'wrap', gap: 'var(--space-2)', marginTop: -6 }}>
                <PaymentNav current="/paiements/a-reverser" payouts />
            </div>

            <div data-component="StatGrid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(230px,1fr))', gap: 'var(--space-4)' }}>
                <StatCard label="Prêt à reverser" value={fcfa(kpis.payable_amount)} icon="savings" tone="g" hint={`${kpis.payable} ligne(s)`} />
                <StatCard label="Bloqué" value={fcfa(kpis.blocked_amount)} icon="block" tone="r" hint={`${kpis.blocked} ligne(s)`} />
                <StatCard label="Numéros à confirmer" value={String(kpis.pendingMethods)} icon="phone_iphone" tone="a" />
            </div>

            <section data-component="ListPanel" style={{ background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-5) var(--space-6)' }}>
                <div style={{ display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: 'var(--space-2_5)', marginBottom: 'var(--space-3)' }}>
                    <SearchInput value={filters.q ?? ''} placeholder="Rechercher par ouvrier, chantier ou échéance" onChange={(q) => apply({ q })} />
                    <FilterSelect label="État" value={filters.etat ?? ''} options={filterOptions.etat} onChange={(etat) => apply({ etat })} />
                    {can.create && ready.length > 0 && <Button label="Tout cocher sur la page" icon="done_all" variant="secondary" onClick={selectPage} />}
                    {can.create && (
                        <Button label={`Préparer le lot (${picked.length})`} icon="inventory_2" disabled={picked.length === 0} onClick={prepare} />
                    )}
                </div>

                <TableFrame minWidth={1100}>
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
                        {rows.data.map((r) => (
                            <tr key={r.key} style={{ background: 'var(--c-surface)' }}>
                                <td style={tdStyle}>
                                    {can.create && r.blockers.length === 0 && <input type="checkbox" aria-label={`Choisir ${r.worker.full_name}`} checked={!!selected[r.key]} onChange={() => toggle(r)} />}
                                </td>
                                <td style={tdStyle}>
                                    <StackCell main={r.worker.full_name} sub={r.worker.reference} strong />
                                </td>
                                <td style={tdStyle}>
                                    <StackCell main={r.site.label} sub={`Semaine ${r.schedule.sequence} · ${r.schedule.reference}`} />
                                </td>
                                <td style={tdStyle}>
                                    <TextCell>{r.first_day === r.last_day ? formatDate(r.first_day) : `${formatDate(r.first_day)} au ${formatDate(r.last_day)}`}</TextCell>
                                </td>
                                <td style={tdStyle}>
                                    <TextCell>{r.days}</TextCell>
                                </td>
                                <td style={tdStyle}>
                                    <TextCell>{fcfa(r.gross_amount)}</TextCell>
                                </td>
                                <td style={tdStyle}>
                                    <TextCell strong>{fcfa(r.net_amount)}</TextCell>
                                </td>
                                <td style={tdStyle}>
                                    <TextCell muted>{r.number ?? '—'}</TextCell>
                                </td>
                                <td style={tdStyle}>
                                    {r.blockers.length === 0 ? (
                                        <StatusBadge label="Prêt" tone="g" />
                                    ) : (
                                        <span style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-1)' }}>
                                            <StatusBadge label="Bloqué" tone="r" />
                                            {r.blockers.map((b) => (
                                                <span key={b} style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>
                                                    {b}
                                                </span>
                                            ))}
                                        </span>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </TableFrame>

                {rows.data.length === 0 && (
                    <div style={{ paddingTop: 'var(--space-4)' }}>
                        <StateBlock kind="nodata" icon="savings" title="Rien à reverser" text="Une journée apparaît ici quand elle est validée et que l’échéance de sa semaine est réglée." />
                    </div>
                )}

                <Pagination page={rows.currentPage} lastPage={rows.lastPage} from={rows.from} to={rows.to} total={rows.total} onPage={(page) => apply({ page: String(page) })} />
            </section>
        </>
    );
}
