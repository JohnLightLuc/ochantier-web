import { useContractorActions } from '@/components/contractors/useContractorActions';
import { FilterSelect, SearchInput } from '@/components/list/ListToolbar';
import Pagination from '@/components/list/Pagination';
import { PersonCell, RatingCell, RowActions, TableFrame, TextCell, tdStyle, thStyle } from '@/components/list/Table';
import Icon from '@/components/ui/Icon';
import type { MenuItem } from '@/components/ui/Menu';
import StatCard from '@/components/ui/StatCard';
import StateBlock from '@/components/ui/StateBlock';
import StatusBadge from '@/components/ui/StatusBadge';
import { formatRating } from '@/lib/format';
import type { ContractorFilters, ContractorIndexProps, ContractorRow } from '@/types/contractors';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

const COLUMNS = ['Professionnel', 'Type', 'Métiers', 'Zones', 'Formule', 'Activité', 'Statut'];

const FILTERS: [keyof ContractorIndexProps['filterOptions'], string][] = [
    ['type', 'Type'],
    ['metier', 'Métier'],
    ['zone', 'Zone'],
];

/** Liste des entrepreneurs et techniciens (O’CHANTIER PRO) : KPI, onglets par état du dossier, recherche, filtres, pagination. */
export default function ContractorsIndex({ contractors, filters, filterOptions, tabs, kpis, can }: ContractorIndexProps) {
    const actions = useContractorActions();
    const [menuFor, setMenuFor] = useState<string | null>(null);
    const rows = contractors.data;
    const current = filters.onglet ?? '';

    const apply = (changes: ContractorFilters, keepPage = false) => {
        const next: ContractorFilters = { ...filters, ...changes, ...(keepPage ? {} : { page: undefined }) };
        router.get('/entrepreneurs', Object.fromEntries(Object.entries(next).filter(([, v]) => v)), { preserveState: true, preserveScroll: true, replace: true });
    };

    const rowMenu = (c: ContractorRow): MenuItem[] => {
        const items: MenuItem[] = [{ icon: 'folder_shared', label: 'Voir les pièces', onClick: () => router.visit(`/entrepreneurs/${c.id}/pieces`) }];
        if (can.update && c.state.value === 'suspendu') items.push({ icon: 'settings_backup_restore', label: 'Réactiver le compte', onClick: () => actions.reactivate(c) });
        if (can.update && c.state.value === 'verifie') items.push({ icon: 'block', label: 'Suspendre', danger: true, onClick: () => actions.suspend(c) });
        return items;
    };

    const filtered = Object.entries(filters).some(([k, v]) => !['onglet', 'page'].includes(k) && v);

    return (
        <>
            <Head title="Entrepreneurs / Techniciens" />

            <div data-component="StatGrid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(230px,1fr))', gap: 'var(--space-4)' }}>
                <StatCard label="Professionnels vérifiés" value={String(kpis.verified)} unit={`sur ${kpis.total}`} icon="verified" tone="g" />
                <StatCard label="Dossiers à vérifier" value={String(kpis.toReview)} icon="pending_actions" tone="a" highlight={kpis.toReview > 0} />
                <StatCard label="Compléments en attente" value={String(kpis.corrections)} icon="edit_document" tone="o" hint="Dossiers renvoyés au professionnel" />
                <StatCard label="Rejetés & suspendus" value={String(tabs.find((t) => t.key === 'rejetes')?.count ?? 0)} icon="block" tone="r" />
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
                                onClick={() => apply({ onglet: t.key || undefined })}
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
                    <SearchInput value={filters.q ?? ''} placeholder="Rechercher un professionnel, une référence, un téléphone…" onChange={(q) => apply({ q })} />
                    {FILTERS.map(([key, label]) => (
                        <FilterSelect key={key} label={label} value={filters[key] ?? ''} options={filterOptions[key]} onChange={(value) => apply({ [key]: value })} />
                    ))}
                    <span style={{ marginLeft: 'auto', alignSelf: 'center', fontSize: 'var(--fs-base)', color: 'var(--c-ink-2)' }}>
                        <b style={{ color: 'var(--c-ink)' }}>{contractors.total}</b> {contractors.total > 1 ? 'professionnels' : 'professionnel'}
                    </span>
                </div>

                <TableFrame minWidth={1000}>
                    <thead>
                        <tr style={{ background: 'var(--c-surface-3)' }}>
                            {COLUMNS.map((label) => (
                                <th key={label} style={{ ...thStyle, textAlign: label === 'Activité' ? 'right' : 'left' }}>
                                    {label}
                                </th>
                            ))}
                            <th style={{ ...thStyle, textAlign: 'center' }}>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((c) => {
                            const open = () => router.visit(`/entrepreneurs/${c.id}`);
                            return (
                                <tr key={c.id} onClick={open} className="och-hover-soft" style={{ cursor: 'pointer', background: 'var(--c-surface)' }}>
                                    <td style={tdStyle}>
                                        <PersonCell name={c.name} sub={[c.code, c.city].filter(Boolean).join(' · ')} kind={c.kind.value === 'entreprise' ? 'team' : 'person'} />
                                    </td>
                                    <td style={tdStyle}>
                                        <TextCell>{c.kind.label}</TextCell>
                                        <span style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>{c.kind.check}</span>
                                    </td>
                                    <td style={tdStyle}>
                                        <TextCell muted={!c.trades.length}>{c.trades.join(', ') || '—'}</TextCell>
                                    </td>
                                    <td style={tdStyle}>
                                        <TextCell muted={!c.zones.length}>{c.zones.join(', ') || '—'}</TextCell>
                                    </td>
                                    <td style={tdStyle}>
                                        <TextCell>{c.plan.name}</TextCell>
                                        <span style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>{c.plan.seats > 1 ? `${c.plan.seats} membres` : 'Titulaire seul'}</span>
                                    </td>
                                    <td style={{ ...tdStyle, textAlign: 'right' }}>
                                        <TextCell>{c.activity.open} en cours</TextCell>
                                        <span style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>{c.activity.total} au total</span>
                                    </td>
                                    <td style={tdStyle}>
                                        <StatusBadge label={c.state.label} tone={c.state.tone} icon={c.state.value === 'verifie' ? 'verified' : undefined} />
                                        {c.rating.count > 0 && (
                                            <div style={{ marginTop: 'var(--space-1)' }}>
                                                <RatingCell value={`${formatRating(c.rating.average ?? 0)} · ${c.rating.count} avis`} />
                                            </div>
                                        )}
                                    </td>
                                    <RowActions actions={[{ icon: 'visibility', label: 'Voir la fiche', onClick: open }]} menu={rowMenu(c)} menuOpen={menuFor === c.id} onToggleMenu={() => setMenuFor(menuFor === c.id ? null : c.id)} />
                                </tr>
                            );
                        })}
                    </tbody>
                </TableFrame>

                {rows.length === 0 &&
                    (filtered || current ? (
                        <div style={{ padding: 'var(--space-8)', textAlign: 'center', color: 'var(--c-muted)' }}>
                            <Icon name="search_off" size="var(--icon-xl)" color="var(--c-subtle)" style={{ display: 'block', marginBottom: 'var(--space-1_5)' }} />
                            Aucun professionnel ne correspond.
                        </div>
                    ) : (
                        <div style={{ paddingTop: 'var(--space-4)' }}>
                            <StateBlock kind="nodata" icon="handyman" title="Aucun professionnel inscrit" text="Les entrepreneurs et techniciens inscrits dans l’application O’CHANTIER PRO apparaîtront ici." />
                        </div>
                    ))}

                <Pagination page={contractors.currentPage} lastPage={contractors.lastPage} from={contractors.from} to={contractors.to} total={contractors.total} onPage={(page) => apply({ page: String(page) }, true)} />
            </section>

            {actions.dialog}
        </>
    );
}
