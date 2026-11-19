import { FilterSelect, SearchInput } from '@/components/list/ListToolbar';
import Pagination from '@/components/list/Pagination';
import { RowActions, TableFrame, TextCell, tdStyle, thStyle } from '@/components/list/Table';
import Icon from '@/components/ui/Icon';
import StatCard from '@/components/ui/StatCard';
import StateBlock from '@/components/ui/StateBlock';
import StatusBadge from '@/components/ui/StatusBadge';
import { fcfa } from '@/lib/format';
import type { ContractOfferFilters, ContractOfferIndexProps } from '@/types/contractOffers';
import { Head, router } from '@inertiajs/react';

const COLUMNS: [string, 'left' | 'right'][] = [
    ['Offre', 'left'],
    ['Lieu', 'left'],
    ['Prix fixé', 'right'],
    ['Réponses', 'right'],
    ['Statut', 'left'],
    ['Date limite', 'left'],
];

const FILTERS: [keyof ContractOfferIndexProps['filterOptions'], string][] = [
    ['metier', 'Métier'],
    ['ville', 'Ville'],
    ['statut', 'Statut'],
];

/** Offres contractuelles (O’CHANTIER PRO) : KPI, onglets, recherche, filtres et pagination. */
export default function ContractOffersIndex({ offers, filters, filterOptions, tabs, kpis, rates }: ContractOfferIndexProps) {
    const rows = offers.data;
    const current = filters.onglet ?? '';

    const apply = (changes: ContractOfferFilters, keepPage = false) => {
        const next: ContractOfferFilters = { ...filters, ...changes, ...(keepPage ? {} : { page: undefined }) };
        router.get('/offres-contractuelles', Object.fromEntries(Object.entries(next).filter(([, v]) => v)), { preserveState: true, preserveScroll: true, replace: true });
    };

    const filtered = Object.entries(filters).some(([k, v]) => !['onglet', 'page'].includes(k) && v);

    return (
        <>
            <Head title="Offres contractuelles" />

            <div data-component="StatGrid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(230px,1fr))', gap: 'var(--space-4)' }}>
                <StatCard label="Offres ouvertes" value={String(kpis.open)} icon="handshake" tone="b" hint={`dont ${kpis.comparing} avec des réponses à comparer`} />
                <StatCard label="Acomptes en attente" value={String(kpis.deposit)} icon="hourglass_top" tone="a" hint={`Acompte de ${rates.deposit} du total`} highlight={kpis.deposit > 0} />
                <StatCard label="Chantiers attribués" value={String(kpis.attributed)} icon="construction" tone="g" />
                <StatCard label="Commission O’CHANTIER" value={rates.commission} icon="percent" tone="p" hint="Ajoutée au montant du professionnel" />
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
                    <SearchInput value={filters.q ?? ''} placeholder="Rechercher une offre, un chantier, un promoteur…" onChange={(q) => apply({ q })} />
                    {FILTERS.map(([key, label]) => (
                        <FilterSelect key={key} label={label} value={filters[key] ?? ''} options={filterOptions[key]} onChange={(value) => apply({ [key]: value })} />
                    ))}
                    <span style={{ marginLeft: 'auto', alignSelf: 'center', fontSize: 'var(--fs-base)', color: 'var(--c-ink-2)' }}>
                        <b style={{ color: 'var(--c-ink)' }}>{offers.total}</b> {offers.total > 1 ? 'offres' : 'offre'}
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
                        {rows.map((o) => {
                            const open = () => router.visit(`/offres-contractuelles/${o.id}`);
                            return (
                                <tr key={o.id} onClick={open} className="och-hover-soft" style={{ cursor: 'pointer', background: 'var(--c-surface)' }}>
                                    <td style={tdStyle}>
                                        <TextCell strong>{o.site}</TextCell>
                                        <span style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>
                                            {[o.reference, o.promoter, o.quoteReference ? `issue de ${o.quoteReference}` : null].filter(Boolean).join(' · ')}
                                        </span>
                                    </td>
                                    <td style={tdStyle}>
                                        <TextCell>{o.place}</TextCell>
                                        <span style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>{o.period}</span>
                                    </td>
                                    <td style={{ ...tdStyle, textAlign: 'right' }}>
                                        <TextCell strong>{fcfa(o.price)}</TextCell>
                                        {o.retained && <span style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>retenu {fcfa(o.retained.total)}</span>}
                                    </td>
                                    <td style={{ ...tdStyle, textAlign: 'right' }}>
                                        <TextCell muted={!o.responses}>{o.responses}</TextCell>
                                        {o.counterProposals > 0 && <span style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>dont {o.counterProposals} contre-prop.</span>}
                                    </td>
                                    <td style={tdStyle}>
                                        <StatusBadge label={o.status.label} tone={o.status.tone} />
                                        {o.retained && <div style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)', marginTop: 'var(--space-1)' }}>{o.retained.contractor}</div>}
                                    </td>
                                    <td style={tdStyle}>
                                        {o.deadline ? <span style={{ fontWeight: o.deadlineSoon ? 'var(--fw-semibold)' : undefined, color: o.deadlineSoon ? 'var(--c-danger-fg)' : 'var(--c-ink-2)' }}>{o.deadline}</span> : <TextCell muted>—</TextCell>}
                                    </td>
                                    <RowActions actions={[{ icon: 'visibility', label: 'Voir l’offre', onClick: open }]} />
                                </tr>
                            );
                        })}
                    </tbody>
                </TableFrame>

                {rows.length === 0 &&
                    (filtered || current ? (
                        <div style={{ padding: 'var(--space-8)', textAlign: 'center', color: 'var(--c-muted)' }}>
                            <Icon name="search_off" size="var(--icon-xl)" color="var(--c-subtle)" style={{ display: 'block', marginBottom: 'var(--space-1_5)' }} />
                            Aucune offre ne correspond.
                        </div>
                    ) : (
                        <div style={{ paddingTop: 'var(--space-4)' }}>
                            <StateBlock kind="nodata" icon="handshake" title="Aucune offre contractuelle" text="Les offres au forfait publiées par les promoteurs apparaîtront ici avec les réponses des professionnels." />
                        </div>
                    ))}

                <Pagination page={offers.currentPage} lastPage={offers.lastPage} from={offers.from} to={offers.to} total={offers.total} onPage={(page) => apply({ page: String(page) }, true)} />
            </section>
        </>
    );
}
