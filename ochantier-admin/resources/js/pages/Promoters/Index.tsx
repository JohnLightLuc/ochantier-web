import ActionStrip from '@/components/list/ActionStrip';
import { FilterChip, FilterSelect, SearchInput, SelectionBar } from '@/components/list/ListToolbar';
import Pagination from '@/components/list/Pagination';
import { Checkbox, LocationCell, PersonCell, RowActions, SortableTh, StackCell, TableFrame, TextCell, tdStyle, thStyle, type SortDirection } from '@/components/list/Table';
import { usePromoterActions } from '@/components/promoters/usePromoterActions';
import Button from '@/components/ui/Button';
import Icon from '@/components/ui/Icon';
import StatCard from '@/components/ui/StatCard';
import StateBlock from '@/components/ui/StateBlock';
import StatusBadge from '@/components/ui/StatusBadge';
import { useAdminUi } from '@/contexts/AdminUiContext';
import type { PromoterFilters, PromoterIndexProps, PromoterRow } from '@/types/promoters';
import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

const COLUMNS: [string, string | null][] = [
    ['Promoteur', 'promoteur'],
    ['Entreprise', 'entreprise'],
    ['Contact', 'contact'],
    ['Localisation', 'localisation'],
    ['Chantiers', null],
    ['Paiements', 'paiements'],
    ['KYC', null],
    ['Statut', 'statut'],
];

const FILTERS: [keyof PromoterIndexProps['filterOptions'], string][] = [
    ['statut', 'Statut'],
    ['localisation', 'Localisation'],
    ['type', 'Type de compte'],
    ['kyc', 'État KYC'],
    ['inscription', 'Inscription'],
];

const QUICK_LABELS: Record<string, string> = { incomplets: 'Dossiers incomplets', expirees: 'Pièces expirées', signales: 'Comptes signalés', tous: 'Tous les dossiers à traiter' };

/** Liste des promoteurs : KPI, recherche, filtres, tri, sélection, pagination et dossiers à traiter. */
export default function PromotersIndex({ promoters, filters, filterOptions, kpis, todo }: PromoterIndexProps) {
    const ui = useAdminUi();
    const actions = usePromoterActions();
    const [selected, setSelected] = useState<number[]>([]);
    const [menuFor, setMenuFor] = useState<number | null>(null);
    const rows = promoters.data;

    // La sélection ne survit pas à un changement de page ou de filtre.
    useEffect(() => setSelected([]), [promoters]);

    const apply = (changes: PromoterFilters, keepPage = false) => {
        const next: PromoterFilters = { ...filters, ...changes, ...(keepPage ? {} : { page: undefined }) };
        const query = Object.fromEntries(Object.entries(next).filter(([, v]) => v !== undefined && v !== ''));
        router.get('/promoteurs', query, { preserveState: true, preserveScroll: true, replace: true });
    };

    const applyQuick = (key: string | null) => {
        apply({ rapide: key ?? undefined });
        if (key) requestAnimationFrame(() => document.querySelector('[data-component="ListPanel"]')?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
    };

    const allSelected = rows.length > 0 && rows.every((r) => selected.includes(r.id));
    const toggle = (id: number) => setSelected((s) => (s.includes(id) ? s.filter((x) => x !== id) : [...s, id]));

    const exportUrl = (ids?: number[]) => `/promoteurs/export${ids?.length ? `?ids=${ids.join(',')}` : ''}`;

    const bulkValidate = () => {
        const pending = rows.filter((r) => selected.includes(r.id) && r.status.value === 'pending');
        if (!pending.length) return ui.toast('Aucun compte « À valider » dans la sélection.', { tone: 'error' });
        ui.confirm({
            icon: 'done_all',
            tone: 'success',
            title: `Valider ${pending.length} compte${pending.length > 1 ? 's' : ''} ?`,
            body: `${pending.map((r) => r.name).join(', ')}. Les comptes déjà actifs ou suspendus sont ignorés.`,
            ok: 'Valider',
            onConfirm: () => router.post('/promoteurs/validation-groupee', { ids: pending.map((r) => r.id) }, { preserveScroll: true }),
        });
    };

    const rowMenu = (p: PromoterRow) => [
        { icon: 'construction', label: 'Consulter les chantiers', onClick: () => router.visit(`/promoteurs/${p.slug}/chantiers`) },
        { icon: 'payments', label: 'Consulter les paiements', onClick: () => router.visit(`/promoteurs/${p.slug}/paiements`) },
        { icon: 'send', label: 'Envoyer une notification', onClick: () => actions.notify(p) },
        p.status.value === 'suspended'
            ? { icon: 'settings_backup_restore', label: 'Réactiver', onClick: () => actions.reactivate(p) }
            : { icon: 'block', label: 'Suspendre', danger: true, onClick: () => actions.suspend(p) },
    ];

    const plural = (n: number, one: string, many: string) => (n > 1 ? many : one);
    const filtered = Object.entries(filters).some(([k, v]) => !['tri', 'sens', 'page'].includes(k) && v);

    return (
        <>
            <Head title="Promoteurs" />

            <div style={{ display: 'flex', flexWrap: 'wrap', justifyContent: 'flex-end', gap: 'var(--space-2_5)', marginTop: -6 }}>
                <Button label="Exporter" icon="download" variant="secondary" onClick={() => (window.location.href = exportUrl())} />
                <Button label="Ajouter un promoteur" icon="add" variant="primary" onClick={() => router.visit('/promoteurs/nouveau')} />
            </div>

            <div data-component="StatGrid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(230px,1fr))', gap: 'var(--space-4)' }}>
                <StatCard label="Total promoteurs" value={String(kpis.total)} icon="apartment" tone="g" />
                <StatCard label="Comptes actifs" value={String(kpis.active)} icon="groups" tone="g" />
                <StatCard label="À valider" value={String(kpis.pending)} icon="warning" tone="a" highlight={kpis.pending > 0} />
                <StatCard label="Suspendus" value={String(kpis.suspended)} icon="person_off" tone="r" />
            </div>

            <section data-component="ListPanel" style={{ background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-5) var(--space-5) var(--space-4)', minWidth: 0, scrollMarginTop: 'var(--space-4)' }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-3)', flexWrap: 'wrap', marginBottom: 'var(--space-3)' }}>
                    <Icon name="apartment" size="var(--icon-md)" color="var(--c-primary)" />
                    <h2 style={{ margin: 0, fontSize: 'var(--fs-2xl)', fontWeight: 'var(--fw-bold)', flex: 1 }}>Liste des promoteurs</h2>
                    {filters.rapide && <FilterChip label={QUICK_LABELS[filters.rapide] ?? filters.rapide} onClear={() => applyQuick(null)} />}
                    <span style={{ fontSize: 'var(--fs-base)', color: 'var(--c-ink-2)' }}>
                        <b style={{ color: 'var(--c-ink)' }}>{promoters.total}</b> {plural(promoters.total, 'promoteur', 'promoteurs')}
                    </span>
                </div>

                <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2_5)', marginBottom: 'var(--space-3)' }}>
                    <SearchInput value={filters.q ?? ''} placeholder="Rechercher par nom, entreprise, téléphone ou e-mail" onChange={(q) => apply({ q })} />
                    {FILTERS.map(([key, label]) => (
                        <FilterSelect key={key} label={label} value={filters[key] ?? ''} options={filterOptions[key]} onChange={(value) => apply({ [key]: value })} />
                    ))}
                </div>

                {selected.length > 0 && (
                    <SelectionBar count={selected.length} bulkLabel="Valider" bulkIcon="done_all" onExport={() => (window.location.href = exportUrl(selected))} onBulk={bulkValidate} onClear={() => setSelected([])} />
                )}

                <TableFrame>
                    <thead>
                        <tr style={{ background: 'var(--c-surface-3)' }}>
                            <th style={{ ...thStyle, width: 44 }}>
                                <Checkbox label="Tout sélectionner" checked={allSelected} onToggle={() => setSelected(allSelected ? [] : rows.map((r) => r.id))} />
                            </th>
                            {COLUMNS.map(([label, key]) => (
                                <SortableTh key={label} label={label} sortKey={key} current={{ key: filters.tri, direction: filters.sens as SortDirection }} onSort={(tri, sens) => apply({ tri: tri ?? undefined, sens: tri ? sens : undefined }, true)} />
                            ))}
                            <th style={{ ...thStyle, textAlign: 'center' }}>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((p) => {
                            const on = selected.includes(p.id);
                            const open = () => router.visit(`/promoteurs/${p.slug}`);
                            return (
                                <tr key={p.id} onClick={open} className="och-hover-soft" style={{ cursor: 'pointer', background: on ? 'var(--c-primary-50)' : 'var(--c-surface)' }}>
                                    <td style={tdStyle}>
                                        <Checkbox label={`Sélectionner ${p.name}`} checked={on} onToggle={() => toggle(p.id)} />
                                    </td>
                                    <td style={tdStyle}>
                                        <PersonCell name={p.representative} />
                                    </td>
                                    <td style={tdStyle}>
                                        <TextCell>{p.name}</TextCell>
                                    </td>
                                    <td style={tdStyle}>
                                        <StackCell main={p.phone} sub={p.email} />
                                    </td>
                                    <td style={tdStyle}>
                                        <LocationCell value={p.location} />
                                    </td>
                                    <td style={tdStyle}>
                                        <Link href={`/promoteurs/${p.slug}/chantiers`} onClick={(e) => e.stopPropagation()} style={{ whiteSpace: 'nowrap' }}>
                                            {p.sitesCount} {plural(p.sitesCount, 'chantier', 'chantiers')}
                                        </Link>
                                    </td>
                                    <td style={tdStyle}>
                                        <StatusBadge label={p.payment.label} tone={p.payment.tone} />
                                    </td>
                                    <td style={tdStyle}>
                                        <StatusBadge label={p.kyc.label} tone={p.kyc.tone} icon={p.kyc.icon} />
                                    </td>
                                    <td style={tdStyle}>
                                        <StatusBadge label={p.status.label} tone={p.status.tone} />
                                    </td>
                                    <RowActions
                                        actions={[
                                            { icon: 'visibility', label: 'Voir le profil', onClick: open },
                                            { icon: 'edit', label: 'Modifier', onClick: () => router.visit(`/promoteurs/${p.slug}/modifier`) },
                                        ]}
                                        menu={rowMenu(p)}
                                        menuOpen={menuFor === p.id}
                                        onToggleMenu={() => setMenuFor(menuFor === p.id ? null : p.id)}
                                    />
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
                            <StateBlock kind="nodata" icon="apartment" title="Aucun promoteur enregistré" text="Les promoteurs créés ou inscrits apparaîtront ici." actionLabel="Ajouter un promoteur" onAction={() => router.visit('/promoteurs/nouveau')} />
                        </div>
                    ))}

                <Pagination page={promoters.currentPage} lastPage={promoters.lastPage} from={promoters.from} to={promoters.to} total={promoters.total} onPage={(page) => apply({ page: String(page) }, true)} />
            </section>

            <ActionStrip
                title="À traiter aujourd’hui"
                icon="schedule"
                active={filters.rapide ?? null}
                activeLabel={filters.rapide ? QUICK_LABELS[filters.rapide] : undefined}
                ctaLabel={filters.rapide === 'tous' ? 'Afficher tous les promoteurs' : `Voir les dossiers (${todo.total})`}
                onCta={() => applyQuick(filters.rapide === 'tous' ? null : 'tous')}
                onSelect={applyQuick}
                items={[
                    { key: 'incomplets', count: todo.incomplete, label: `${plural(todo.incomplete, 'dossier incomplet', 'dossiers incomplets')} (pièce KYC obligatoire manquante)`, icon: 'description', tone: 'a' },
                    { key: 'expirees', count: todo.expiredDocuments, label: `${plural(todo.expiredDocuments, 'pièce expirée', 'pièces expirées')} · ${todo.expiredPromoters} ${plural(todo.expiredPromoters, 'promoteur', 'promoteurs')}`, icon: 'event_busy', tone: 'r' },
                    { key: 'signales', count: todo.reported, label: `${plural(todo.reported, 'compte signalé', 'comptes signalés')} (litige ouvert)`, icon: 'flag', tone: 'r' },
                ]}
            />

            {actions.dialog}
        </>
    );
}
