import { FilterSelect, SearchInput } from '@/components/list/ListToolbar';
import Pagination from '@/components/list/Pagination';
import { TableFrame, TextCell, tdStyle, thStyle } from '@/components/list/Table';
import PeriodFilter from '@/components/finance/PeriodFilter';
import Button from '@/components/ui/Button';
import type { Option } from '@/components/ui/Field';
import StateBlock from '@/components/ui/StateBlock';
import { Head, router } from '@inertiajs/react';

type Log = { id: string; date: string | null; actor: string; module: string; action: string; subject: string | null; ip: string | null };

type Filters = { q?: string; module?: string; du?: string; au?: string; page?: string };

type Props = {
    logs: { data: Log[]; currentPage: number; lastPage: number; total: number; from: number; to: number };
    filters: Filters;
    modules: Option[];
    can: { export: boolean };
};

const COLUMNS = ['Date', 'Auteur', 'Module', 'Action', 'Objet', 'Adresse IP'];

/** Journal d’audit : qui a fait quoi, en consultation seule. */
export default function SettingsAudit({ logs, filters, modules, can }: Props) {
    const apply = (changes: Filters) => {
        const next: Filters = { ...filters, page: undefined, ...changes };
        router.get('/parametres/audit', Object.fromEntries(Object.entries(next).filter(([, v]) => v)), { preserveState: true, preserveScroll: true, replace: true });
    };
    const exportUrl = `/parametres/audit/export?${new URLSearchParams(Object.entries(filters).filter(([k, v]) => v && k !== 'page') as [string, string][]).toString()}`;

    return (
        <>
            <Head title="Journal d’audit" />

            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 'var(--space-2)', marginTop: -6 }}>
                <PeriodFilter from={filters.du ?? ''} to={filters.au ?? ''} onChange={apply} />
                <div style={{ display: 'flex', gap: 'var(--space-2)' }}>
                    <Button label="Paramètres" icon="settings" variant="secondary" onClick={() => router.visit('/parametres')} />
                    {can.export && <Button label="Exporter" icon="download" variant="secondary" onClick={() => (window.location.href = exportUrl)} />}
                </div>
            </div>

            <section data-component="ListPanel" style={{ background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-5) var(--space-6)' }}>
                <div style={{ display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: 'var(--space-2_5)', marginBottom: 'var(--space-3)' }}>
                    <SearchInput value={filters.q ?? ''} placeholder="Rechercher une action" onChange={(q) => apply({ q })} />
                    <FilterSelect label="Module" value={filters.module ?? ''} options={modules} onChange={(module) => apply({ module })} />
                </div>

                <TableFrame minWidth={860}>
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
                        {logs.data.map((l) => (
                            <tr key={l.id} style={{ background: 'var(--c-surface)' }}>
                                {[l.date, l.actor, l.module, l.action, l.subject, l.ip].map((value, index) => (
                                    <td key={index} style={tdStyle}>
                                        <TextCell muted={index !== 3}>{value ?? '—'}</TextCell>
                                    </td>
                                ))}
                            </tr>
                        ))}
                    </tbody>
                </TableFrame>

                {logs.data.length === 0 && (
                    <div style={{ paddingTop: 'var(--space-4)' }}>
                        <StateBlock kind="nodata" icon="history" title="Aucune action" text="Modifiez la recherche ou les filtres." />
                    </div>
                )}

                <Pagination page={logs.currentPage} lastPage={logs.lastPage} from={logs.from} to={logs.to} total={logs.total} onPage={(page) => apply({ page: String(page) })} />
            </section>
        </>
    );
}
