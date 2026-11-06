import { FilterSelect, SearchInput } from '@/components/list/ListToolbar';
import Pagination from '@/components/list/Pagination';
import { StackCell, TableFrame, TextCell, tdStyle, thStyle } from '@/components/list/Table';
import PaymentNav from '@/components/payments/PaymentNav';
import type { Option } from '@/components/ui/Field';
import StatCard from '@/components/ui/StatCard';
import StateBlock from '@/components/ui/StateBlock';
import StatusBadge from '@/components/ui/StatusBadge';
import { fcfa } from '@/lib/format';
import type { RefundCan, RegularisationRow } from '@/types/payouts';
import { Head, router } from '@inertiajs/react';

type Filters = { q?: string; etat?: string; page?: string };

type Props = {
    missions: { data: RegularisationRow[]; currentPage: number; lastPage: number; total: number; from: number; to: number };
    filters: Filters;
    filterOptions: { etat: Option[] };
    kpis: { toDo: number; done: number };
    can: RefundCan;
};

const COLUMNS = ['Mission', 'Promoteur', 'Coût réel', 'Réglé', 'Écart', 'État'];

/** Écart à la fin d’une mission : positif, le promoteur doit un complément ; négatif, il a trop payé. */
export const balanceLabel = (balance: number) => (balance === 0 ? 'Soldée' : balance > 0 ? `Doit ${fcfa(balance)}` : `Trop-perçu ${fcfa(-balance)}`);

/** Missions terminées à comparer à ce que le promoteur a réglé. */
export default function PaymentRegularisations({ missions, filters, filterOptions, kpis, can }: Props) {
    const apply = (changes: Filters) => {
        const next: Filters = { ...filters, page: undefined, ...changes };
        router.get('/paiements/regularisations', Object.fromEntries(Object.entries(next).filter(([, v]) => v)), { preserveState: true, preserveScroll: true, replace: true });
    };

    return (
        <>
            <Head title="Régularisations" />

            <div style={{ display: 'flex', justifyContent: 'flex-end', flexWrap: 'wrap', gap: 'var(--space-2)', marginTop: -6 }}>
                <PaymentNav current="/paiements/regularisations" can={can} />
            </div>

            <div data-component="StatGrid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(230px,1fr))', gap: 'var(--space-4)' }}>
                <StatCard label="À régulariser" value={String(kpis.toDo)} icon="pending_actions" tone="a" />
                <StatCard label="Régularisées" value={String(kpis.done)} icon="verified" tone="g" />
            </div>

            <section data-component="ListPanel" style={{ background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-5) var(--space-6)' }}>
                <div style={{ display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: 'var(--space-2_5)', marginBottom: 'var(--space-3)' }}>
                    <SearchInput value={filters.q ?? ''} placeholder="Rechercher par mission ou chantier" onChange={(q) => apply({ q })} />
                    <FilterSelect label="État" value={filters.etat ?? ''} options={filterOptions.etat} onChange={(etat) => apply({ etat })} />
                </div>

                <TableFrame minWidth={900}>
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
                        {missions.data.map((m) => (
                            <tr key={m.id} onClick={() => router.visit(`/paiements/regularisations/${m.id}`)} className="och-hover-soft" style={{ cursor: 'pointer', background: 'var(--c-surface)' }}>
                                <td style={tdStyle}>
                                    <StackCell main={m.site.label} sub={m.reference} strong />
                                </td>
                                <td style={tdStyle}>
                                    <TextCell>{m.promoter.full_name}</TextCell>
                                </td>
                                <td style={tdStyle}>
                                    <StackCell main={fcfa(m.total)} sub={`${m.days} journée${m.days > 1 ? 's' : ''}`} />
                                </td>
                                <td style={tdStyle}>
                                    <TextCell>{fcfa(m.paid - m.refunded)}</TextCell>
                                </td>
                                <td style={tdStyle}>
                                    <TextCell strong>{balanceLabel(m.balance)}</TextCell>
                                </td>
                                <td style={tdStyle}>
                                    <StatusBadge label={m.blockers.length > 0 ? 'Bloquée' : m.state.label} tone={(m.blockers.length > 0 ? 'r' : m.state.tone) as never} />
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </TableFrame>

                {missions.data.length === 0 && (
                    <div style={{ paddingTop: 'var(--space-4)' }}>
                        <StateBlock kind="nodata" icon="balance" title="Aucune mission" text="Une mission apparaît ici quand son chantier est clôturé." />
                    </div>
                )}

                <Pagination page={missions.currentPage} lastPage={missions.lastPage} from={missions.from} to={missions.to} total={missions.total} onPage={(page) => apply({ page: String(page) })} />
            </section>
        </>
    );
}
