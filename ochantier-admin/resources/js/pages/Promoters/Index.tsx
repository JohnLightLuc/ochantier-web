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
import { Head, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

const COLUMNS: [string, string | null][] = [
    ['Promoteur', 'promoteur'],
    ['Entreprise', null],
    ['Contact', null],
    ['Localisation', null],
    ['Chantiers', null],
    ['Inscription', 'inscription'],
    ['Signalements', null],
    ['Statut', 'statut'],
];

const FILTERS: [keyof PromoterIndexProps['filterOptions'], string][] = [
    ['statut', 'Statut'],
    ['localisation', 'Localisation'],
    ['type', 'Type de compte'],
    ['inscription', 'Inscription'],
];

const QUICK_LABELS: Record<string, string> = { a_verifier: 'Dossiers à vérifier', incomplets: 'Dossiers incomplets', pieces_expirees: 'Pièces expirées', signales: 'Comptes signalés' };

/** Dossiers en attente d’une décision du Back Office. */
const REVIEWABLE = ['soumis', 'en_controle'];

/** Liste des promoteurs : KPI, recherche, filtres, tri, sélection, pagination et dossiers à traiter. */
export default function PromotersIndex({ promoters, filters, filterOptions, kpis, todo }: PromoterIndexProps) {
    const ui = useAdminUi();
    const actions = usePromoterActions();
    const [selected, setSelected] = useState<string[]>([]);
    const [menuFor, setMenuFor] = useState<string | null>(null);
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
    const toggle = (id: string) => setSelected((s) => (s.includes(id) ? s.filter((x) => x !== id) : [...s, id]));

    const exportUrl = (ids?: string[]) => `/promoteurs/export${ids?.length ? `?ids=${ids.join(',')}` : ''}`;

    const bulkValidate = () => {
        const pending = rows.filter((r) => selected.includes(r.id) && REVIEWABLE.includes(r.status.value));
        if (!pending.length) return ui.toast('Aucun dossier à vérifier dans la sélection.', { tone: 'error' });
        ui.confirm({
            icon: 'done_all',
            tone: 'success',
            title: `Valider ${pending.length} compte${pending.length > 1 ? 's' : ''} ?`,
            body: `${pending.map((r) => r.representative).join(', ')}. Seuls les dossiers complets dont toutes les pièces obligatoires sont vérifiées seront validés ; les autres sont signalés.`,
            ok: 'Valider',
            onConfirm: () => router.post('/promoteurs/validation-groupee', { ids: pending.map((r) => r.id) }, { preserveScroll: true }),
        });
    };

    const rowMenu = (p: PromoterRow) => [
        { icon: 'folder_shared', label: 'Contrôler le dossier', onClick: () => router.visit(`/promoteurs/${p.slug}/kyc`) },
        { icon: 'send', label: 'Envoyer un message', onClick: () => actions.notify(p) },
        p.status.value === 'suspendu'
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
            </div>

            <div data-component="StatGrid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(230px,1fr))', gap: 'var(--space-4)' }}>
                <StatCard label="Total promoteurs" value={String(kpis.total)} icon="apartment" tone="g" />
                <StatCard label="Comptes validés" value={String(kpis.active)} icon="verified" tone="g" />
                <StatCard label="À vérifier" value={String(kpis.pending)} icon="warning" tone="a" highlight={kpis.pending > 0} />
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
                                        <StackCell main={p.name} sub={p.kind} />
                                    </td>
                                    <td style={tdStyle}>
                                        <StackCell main={p.phone} sub={p.email} />
                                    </td>
                                    <td style={tdStyle}>
                                        <LocationCell value={p.location} />
                                    </td>
                                    <td style={{ ...tdStyle, whiteSpace: 'nowrap' }}>
                                        {p.sitesCount} {plural(p.sitesCount, 'chantier', 'chantiers')}
                                    </td>
                                    <td style={{ ...tdStyle, whiteSpace: 'nowrap' }}>
                                        <TextCell>{p.registeredAt ?? '—'}</TextCell>
                                    </td>
                                    <td style={tdStyle}>
                                        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-1)' }}>
                                            {p.expiredDocuments > 0 && <StatusBadge label={`${p.expiredDocuments} ${plural(p.expiredDocuments, 'pièce expirée', 'pièces expirées')}`} tone="r" icon="event_busy" />}
                                            {p.openDisputes > 0 && <StatusBadge label={`${p.openDisputes} ${plural(p.openDisputes, 'litige', 'litiges')}`} tone="r" icon="gavel" />}
                                            {!p.expiredDocuments && !p.openDisputes && <span style={{ color: 'var(--c-muted)' }}>—</span>}
                                        </div>
                                    </td>
                                    <td style={tdStyle}>
                                        <StatusBadge label={p.status.label} tone={p.status.tone} />
                                    </td>
                                    <RowActions
                                        actions={[{ icon: 'visibility', label: 'Voir le profil', onClick: open }]}
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
                            <StateBlock kind="nodata" icon="apartment" title="Aucun promoteur inscrit" text="Les promoteurs inscrits depuis l’application apparaîtront ici." />
                        </div>
                    ))}

                <Pagination page={promoters.currentPage} lastPage={promoters.lastPage} from={promoters.from} to={promoters.to} total={promoters.total} onPage={(page) => apply({ page: String(page) }, true)} />
            </section>

            <ActionStrip
                title="À traiter aujourd’hui"
                icon="schedule"
                active={filters.rapide ?? null}
                activeLabel={filters.rapide ? QUICK_LABELS[filters.rapide] : undefined}
                ctaLabel={filters.rapide === 'a_verifier' ? 'Afficher tous les promoteurs' : `Voir les dossiers à vérifier (${todo.a_verifier})`}
                onCta={() => applyQuick(filters.rapide === 'a_verifier' ? null : 'a_verifier')}
                onSelect={applyQuick}
                items={[
                    { key: 'a_verifier', count: todo.a_verifier, label: `${plural(todo.a_verifier, 'dossier à vérifier', 'dossiers à vérifier')} (soumis ou en contrôle)`, icon: 'fact_check', tone: 'a' },
                    { key: 'incomplets', count: todo.incomplets, label: `${plural(todo.incomplets, 'dossier incomplet', 'dossiers incomplets')} (brouillon ou à corriger)`, icon: 'description', tone: 'a' },
                    { key: 'pieces_expirees', count: todo.pieces_expirees, label: `${plural(todo.pieces_expirees, 'promoteur', 'promoteurs')} avec une pièce expirée`, icon: 'event_busy', tone: 'r' },
                    { key: 'signales', count: todo.signales, label: `${plural(todo.signales, 'compte signalé', 'comptes signalés')} (litige ouvert)`, icon: 'flag', tone: 'r' },
                ]}
            />

            {actions.dialog}
        </>
    );
}
