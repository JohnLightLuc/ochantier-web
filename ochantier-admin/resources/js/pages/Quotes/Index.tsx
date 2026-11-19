import { FilterSelect, SearchInput } from '@/components/list/ListToolbar';
import Pagination from '@/components/list/Pagination';
import { RowActions, TableFrame, TextCell, tdStyle, thStyle } from '@/components/list/Table';
import Icon from '@/components/ui/Icon';
import StatCard from '@/components/ui/StatCard';
import StateBlock from '@/components/ui/StateBlock';
import StatusBadge from '@/components/ui/StatusBadge';
import { fcfa } from '@/lib/format';
import type { QuoteFilters, QuoteIndexProps } from '@/types/quotes';
import { Head, router } from '@inertiajs/react';

const COLUMNS: [string, 'left' | 'right'][] = [
    ['Demande', 'left'],
    ['Métier · lieu', 'left'],
    ['Propositions', 'right'],
    ['Montant promoteur', 'right'],
    ['Statut', 'left'],
    ['Échéance', 'left'],
];

const FILTERS: [keyof QuoteIndexProps['filterOptions'], string][] = [
    ['metier', 'Métier'],
    ['ville', 'Ville'],
    ['statut', 'Statut'],
];

/** Demandes de devis (O’CHANTIER PRO) : une ligne par métier, KPI, onglets, recherche, filtres et pagination. */
export default function QuotesIndex({ quotes, filters, filterOptions, tabs, kpis }: QuoteIndexProps) {
    const rows = quotes.data;
    const current = filters.onglet ?? '';

    const apply = (changes: QuoteFilters, keepPage = false) => {
        const next: QuoteFilters = { ...filters, ...changes, ...(keepPage ? {} : { page: undefined }) };
        router.get('/demandes-devis', Object.fromEntries(Object.entries(next).filter(([, v]) => v)), { preserveState: true, preserveScroll: true, replace: true });
    };

    const filtered = Object.entries(filters).some(([k, v]) => !['onglet', 'page'].includes(k) && v);

    return (
        <>
            <Head title="Demandes de devis" />

            <div data-component="StatGrid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(230px,1fr))', gap: 'var(--space-4)' }}>
                <StatCard label="Demandes ouvertes" value={String(kpis.open)} icon="request_quote" tone="b" hint="En attente de propositions ou du choix du promoteur" />
                <StatCard label="Visites & devis en cours" value={String(kpis.inProgress)} icon="event_available" tone="a" />
                <StatCard label="En attente d’une réponse" value={String(kpis.toNudge)} icon="notifications_active" tone="o" hint="Report de visite ou devis à confirmer par le promoteur" highlight={kpis.toNudge > 0} />
                <StatCard label="Devis reçus" value={String(kpis.received)} icon="task" tone="g" />
            </div>

            <section data-component="ListPanel" style={{ background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-2) var(--space-5) var(--space-4)', minWidth: 0 }}>
                <div role="tablist" style={{ display: 'flex', gap: 'var(--space-1)', borderBottom: '1px solid var(--c-line)', marginBottom: 'var(--space-4)', overflowX: 'auto' }}>
                    {tabs.map((t) => {
                        const on = t.key === current;
                        return (
                            <button
                                key={t.key}
                                type="button"
                                role="tab"
                                aria-selected={on}
                                className="och-hover-tab"
                                onClick={() => apply({ onglet: t.key || undefined, statut: undefined })}
                                style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)', height: 'var(--control-md)', padding: '0 var(--space-4)', border: 0, borderBottom: `2.5px solid ${on ? 'var(--c-primary)' : 'transparent'}`, marginBottom: -1, background: 'transparent', fontWeight: 'var(--fw-semibold)', fontSize: 'var(--fs-base)', color: on ? 'var(--c-primary)' : 'var(--c-muted)', whiteSpace: 'nowrap', cursor: 'pointer' }}
                            >
                                <Icon name={t.icon} size="var(--icon-sm)" />
                                {t.label}
                                <span style={{ minWidth: 22, padding: '1px var(--space-1_5)', borderRadius: 'var(--radius-pill)', fontSize: 'var(--fs-xs)', textAlign: 'center', background: on ? 'var(--c-primary)' : 'var(--c-surface-3)', color: on ? 'var(--c-on-dark)' : 'var(--c-ink-2)' }}>{t.count}</span>
                            </button>
                        );
                    })}
                </div>

                <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2_5)', marginBottom: 'var(--space-3)' }}>
                    <SearchInput value={filters.q ?? ''} placeholder="Rechercher une demande, un chantier, un promoteur…" onChange={(q) => apply({ q })} />
                    {FILTERS.map(([key, label]) => (
                        <FilterSelect key={key} label={label} value={filters[key] ?? ''} options={filterOptions[key]} onChange={(value) => apply({ [key]: value })} />
                    ))}
                    <span style={{ marginLeft: 'auto', alignSelf: 'center', fontSize: 'var(--fs-base)', color: 'var(--c-ink-2)' }}>
                        <b style={{ color: 'var(--c-ink)' }}>{quotes.total}</b> {quotes.total > 1 ? 'lignes' : 'ligne'}
                    </span>
                </div>

                <TableFrame minWidth={1000}>
                    <thead>
                        <tr style={{ background: 'var(--c-surface-3)' }}>
                            {COLUMNS.map(([label, align]) => (
                                <th key={label} style={{ ...thStyle, textAlign: align }}>
                                    {label}
                                </th>
                            ))}
                            <th style={{ ...thStyle, textAlign: 'center' }}>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((q) => {
                            const open = () => router.visit(`/demandes-devis/${q.id}`);
                            return (
                                <tr key={q.id} onClick={open} className="och-hover-soft" style={{ cursor: 'pointer', background: 'var(--c-surface)' }}>
                                    <td style={tdStyle}>
                                        <TextCell strong>{q.site}</TextCell>
                                        <span style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>
                                            {q.reference} · {q.promoter}
                                        </span>
                                    </td>
                                    <td style={tdStyle}>
                                        <TextCell>{q.trade}</TextCell>
                                        <span style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>{q.place}</span>
                                    </td>
                                    <td style={{ ...tdStyle, textAlign: 'right' }}>
                                        <TextCell muted={!q.bidsCount}>{q.bidsCount}</TextCell>
                                        {q.contractor && <span style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>{q.contractor}</span>}
                                    </td>
                                    <td style={{ ...tdStyle, textAlign: 'right' }}>
                                        <TextCell strong={q.amount !== null} muted={q.amount === null}>{q.amount !== null ? fcfa(q.amount) : '—'}</TextCell>
                                        {q.amountLabel && <span style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>{q.amountLabel}</span>}
                                    </td>
                                    <td style={tdStyle}>
                                        <StatusBadge label={q.status.label} tone={q.status.tone} />
                                    </td>
                                    <td style={tdStyle}>
                                        {q.deadline ? (
                                            <span style={{ fontWeight: q.deadlineSoon ? 'var(--fw-semibold)' : undefined, color: q.deadlineSoon ? 'var(--c-danger-fg)' : 'var(--c-ink-2)' }}>Limite {q.deadline}</span>
                                        ) : q.visit ? (
                                            <TextCell>Visite {q.visit}</TextCell>
                                        ) : (
                                            <TextCell muted>—</TextCell>
                                        )}
                                    </td>
                                    <RowActions actions={[{ icon: 'visibility', label: 'Voir la demande', onClick: open }]} />
                                </tr>
                            );
                        })}
                    </tbody>
                </TableFrame>

                {rows.length === 0 &&
                    (filtered || current ? (
                        <div style={{ padding: 'var(--space-8)', textAlign: 'center', color: 'var(--c-muted)' }}>
                            <Icon name="search_off" size="var(--icon-xl)" color="var(--c-subtle)" style={{ display: 'block', marginBottom: 'var(--space-1_5)' }} />
                            Aucune demande ne correspond.
                        </div>
                    ) : (
                        <div style={{ paddingTop: 'var(--space-4)' }}>
                            <StateBlock kind="nodata" icon="request_quote" title="Aucune demande de devis" text="Les demandes de devis publiées par les promoteurs apparaîtront ici, un métier par ligne." />
                        </div>
                    ))}

                <Pagination page={quotes.currentPage} lastPage={quotes.lastPage} from={quotes.from} to={quotes.to} total={quotes.total} onPage={(page) => apply({ page: String(page) }, true)} />
            </section>
        </>
    );
}
