import ActionStrip from '@/components/list/ActionStrip';
import { FilterChip, FilterSelect, SearchInput, SelectionBar } from '@/components/list/ListToolbar';
import Pagination from '@/components/list/Pagination';
import { Checkbox, PersonCell, RatingCell, RowActions, SortableTh, TableFrame, TextCell, tdStyle, thStyle, type SortDirection } from '@/components/list/Table';
import { useWorkerActions } from '@/components/workers/useWorkerActions';
import Button from '@/components/ui/Button';
import Icon from '@/components/ui/Icon';
import type { MenuItem } from '@/components/ui/Menu';
import StatCard from '@/components/ui/StatCard';
import StateBlock from '@/components/ui/StateBlock';
import StatusBadge from '@/components/ui/StatusBadge';
import { useAdminUi } from '@/contexts/AdminUiContext';
import { formatNumber, formatRating, plural } from '@/lib/format';
import type { WorkerFilters, WorkerIndexProps, WorkerRow } from '@/types/workers';
import { Head, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

const COLUMNS: [string, string | null][] = [
    ['Ouvrier', 'ouvrier'],
    ['Métier', null],
    ['Expérience', 'experience'],
    ['Coût journalier', 'cout'],
    ['Disponibilité', null],
    ['Note', 'note'],
    ['Statut', 'statut'],
];

const FILTERS: [keyof WorkerIndexProps['filterOptions'], string][] = [
    ['metier', 'Métier'],
    ['niveau', 'Expérience'],
    ['disponibilite', 'Disponibilité'],
    ['statut', 'Statut du profil'],
    ['localisation', 'Localisation'],
    ['cout', 'Coût journalier'],
    ['note', 'Note'],
];

const QUICK_LABELS: Record<string, string> = { verification: 'Dossiers à vérifier', corrections: 'Corrections en attente', pieces: 'Pièces expirées' };

/** Statuts depuis lesquels un dossier peut être validé. */
const VALIDATABLE = ['soumis', 'en_controle'];

/** Liste des ouvriers : KPI, recherche, filtres, tri, sélection, pagination et dossiers à traiter. */
export default function WorkersIndex({ workers, filters, filterOptions, kpis, todo, can }: WorkerIndexProps) {
    const ui = useAdminUi();
    const actions = useWorkerActions();
    const [selected, setSelected] = useState<string[]>([]);
    const [menuFor, setMenuFor] = useState<string | null>(null);
    const rows = workers.data;

    useEffect(() => setSelected([]), [workers]);

    const apply = (changes: WorkerFilters, keepPage = false) => {
        const next: WorkerFilters = { ...filters, ...changes, ...(keepPage ? {} : { page: undefined }) };
        router.get('/ouvriers', Object.fromEntries(Object.entries(next).filter(([, v]) => v)), { preserveState: true, preserveScroll: true, replace: true });
    };

    const applyQuick = (key: string | null) => {
        apply({ rapide: key ?? undefined });
        if (key) requestAnimationFrame(() => document.querySelector('[data-component="ListPanel"]')?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
    };

    const allSelected = rows.length > 0 && rows.every((r) => selected.includes(r.id));
    const exportUrl = (ids?: string[]) => `/ouvriers/export${ids?.length ? `?ids=${ids.join(',')}` : ''}`;

    const bulkValidate = () => {
        const todoRows = rows.filter((r) => selected.includes(r.id) && VALIDATABLE.includes(r.status.value));
        if (!todoRows.length) return ui.toast('Aucun dossier à valider dans la sélection.', { tone: 'error' });
        ui.confirm({
            icon: 'done_all',
            tone: 'success',
            title: `Valider ${todoRows.length} dossier${todoRows.length > 1 ? 's' : ''} ?`,
            body: `${todoRows.map((r) => r.name).join(', ')}. Les dossiers dont les pièces ne sont pas toutes vérifiées sont ignorés.`,
            ok: 'Valider',
            onConfirm: () => router.post('/ouvriers/validation-groupee', { ids: todoRows.map((r) => r.id) }, { preserveScroll: true }),
        });
    };

    const rowMenu = (w: WorkerRow): MenuItem[] => {
        const items: MenuItem[] = [{ icon: 'folder_shared', label: 'Voir les pièces', onClick: () => router.visit(`/ouvriers/${w.slug}/identite-documents`) }];
        if (can.validate && VALIDATABLE.includes(w.status.value)) items.push({ icon: 'verified', label: 'Valider le dossier', onClick: () => actions.validate(w) });
        if (can.update && w.status.value === 'suspendu') items.push({ icon: 'settings_backup_restore', label: 'Réactiver', onClick: () => actions.reactivate(w) });
        if (can.update && w.status.value === 'valide') items.push({ icon: 'block', label: 'Suspendre', danger: true, onClick: () => actions.suspend(w) });
        return items;
    };

    const filtered = Object.entries(filters).some(([k, v]) => !['tri', 'sens', 'page'].includes(k) && v);

    return (
        <>
            <Head title="Ouvriers" />

            <div style={{ display: 'flex', flexWrap: 'wrap', justifyContent: 'flex-end', gap: 'var(--space-2_5)', marginTop: -6 }}>
                {can.export && <Button label="Exporter" icon="download" variant="secondary" onClick={() => (window.location.href = exportUrl())} />}
                {can.create && <Button label="Ajouter un ouvrier" icon="add" variant="primary" onClick={() => router.visit('/ouvriers/nouveau')} />}
            </div>

            <div data-component="StatGrid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(230px,1fr))', gap: 'var(--space-4)' }}>
                <StatCard label="Total ouvriers" value={String(kpis.total)} icon="groups" tone="g" />
                <StatCard label="Disponibles" value={String(kpis.available)} icon="person" tone="g" />
                <StatCard label="En mission" value={String(kpis.onMission)} icon="engineering" tone="a" />
                <StatCard label="À valider" value={String(kpis.toReview)} icon="warning" tone="a" highlight={kpis.toReview > 0} />
            </div>

            <section data-component="ListPanel" style={{ background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-5) var(--space-5) var(--space-4)', minWidth: 0, scrollMarginTop: 'var(--space-4)' }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-3)', flexWrap: 'wrap', marginBottom: 'var(--space-3)' }}>
                    <Icon name="group" size="var(--icon-md)" color="var(--c-primary)" />
                    <h2 style={{ margin: 0, fontSize: 'var(--fs-2xl)', fontWeight: 'var(--fw-bold)', flex: 1 }}>Liste des ouvriers</h2>
                    {filters.rapide && <FilterChip label={QUICK_LABELS[filters.rapide] ?? filters.rapide} onClear={() => applyQuick(null)} />}
                    <span style={{ fontSize: 'var(--fs-base)', color: 'var(--c-ink-2)' }}>
                        <b style={{ color: 'var(--c-ink)' }}>{workers.total}</b> {workers.total > 1 ? 'ouvriers' : 'ouvrier'}
                    </span>
                </div>

                <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2_5)', marginBottom: 'var(--space-3)' }}>
                    <SearchInput value={filters.q ?? ''} placeholder="Rechercher par nom, téléphone ou référence" onChange={(q) => apply({ q })} />
                    {FILTERS.map(([key, label]) => (
                        <FilterSelect key={key} label={label} value={filters[key] ?? ''} options={filterOptions[key]} onChange={(value) => apply({ [key]: value })} />
                    ))}
                </div>

                {selected.length > 0 && <SelectionBar count={selected.length} bulkLabel="Valider" bulkIcon="done_all" onExport={() => (window.location.href = exportUrl(selected))} onBulk={can.validate ? bulkValidate : () => ui.toast('Vous n’avez pas le droit de valider des dossiers.', { tone: 'error' })} onClear={() => setSelected([])} />}

                <TableFrame>
                    <thead>
                        <tr style={{ background: 'var(--c-surface-3)' }}>
                            <th style={{ ...thStyle, width: 44 }}>
                                <Checkbox label="Tout sélectionner" checked={allSelected} onToggle={() => setSelected(allSelected ? [] : rows.map((r) => r.id))} />
                            </th>
                            {COLUMNS.map(([label, key]) =>
                                key ? (
                                    <SortableTh key={label} label={label} sortKey={key} current={{ key: filters.tri, direction: filters.sens as SortDirection }} onSort={(tri, sens) => apply({ tri: tri ?? undefined, sens: tri ? sens : undefined }, true)} />
                                ) : (
                                    <th key={label} style={thStyle}>
                                        {label}
                                    </th>
                                ),
                            )}
                            <th style={{ ...thStyle, textAlign: 'center' }}>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((w) => {
                            const on = selected.includes(w.id);
                            const open = () => router.visit(`/ouvriers/${w.slug}`);
                            return (
                                <tr key={w.id} onClick={open} className="och-hover-soft" style={{ cursor: 'pointer', background: on ? 'var(--c-primary-50)' : 'var(--c-surface)' }}>
                                    <td style={tdStyle}>
                                        <Checkbox label={`Sélectionner ${w.name}`} checked={on} onToggle={() => setSelected((s) => (s.includes(w.id) ? s.filter((x) => x !== w.id) : [...s, w.id]))} />
                                    </td>
                                    <td style={tdStyle}>
                                        <PersonCell name={w.name} sub={[w.code, w.commune].filter(Boolean).join(' · ')} />
                                    </td>
                                    <td style={tdStyle}>
                                        <TextCell>{w.trade}</TextCell>
                                    </td>
                                    <td style={tdStyle}>
                                        <TextCell>{plural(w.experienceYears, 'an', 'ans')}</TextCell>
                                    </td>
                                    <td style={tdStyle}>
                                        <TextCell muted={!w.dailyRate}>{w.dailyRate ? `${formatNumber(w.dailyRate)} FCFA/jour` : 'Non renseigné'}</TextCell>
                                    </td>
                                    <td style={tdStyle}>
                                        {w.status.value === 'valide' ? <StatusBadge label={w.availability.label} tone={w.availability.tone} /> : <TextCell muted>—</TextCell>}
                                    </td>
                                    <td style={tdStyle}>
                                        {w.rating ? <RatingCell value={formatRating(w.rating)} /> : <TextCell muted>—</TextCell>}
                                    </td>
                                    <td style={tdStyle}>
                                        <StatusBadge label={w.status.label} tone={w.status.tone} icon={w.status.value === 'valide' ? 'verified' : undefined} />
                                    </td>
                                    <RowActions
                                        actions={[
                                            { icon: 'visibility', label: 'Voir la fiche', onClick: open },
                                        ]}
                                        menu={rowMenu(w)}
                                        menuOpen={menuFor === w.id}
                                        onToggleMenu={() => setMenuFor(menuFor === w.id ? null : w.id)}
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
                            <StateBlock kind="nodata" icon="engineering" title="Aucun dossier transmis" text="Les ouvriers enregistrés par les commerciaux apparaîtront ici dès qu’ils transmettent leur dossier." />
                        </div>
                    ))}

                <Pagination page={workers.currentPage} lastPage={workers.lastPage} from={workers.from} to={workers.to} total={workers.total} onPage={(page) => apply({ page: String(page) }, true)} />
            </section>

            <ActionStrip
                title="À traiter aujourd’hui"
                icon="schedule"
                active={filters.rapide ?? null}
                activeLabel={filters.rapide ? QUICK_LABELS[filters.rapide] : undefined}
                ctaLabel={filters.rapide ? 'Afficher tous les ouvriers' : `Voir les dossiers à vérifier (${todo.review})`}
                onCta={() => applyQuick(filters.rapide ? null : 'verification')}
                onSelect={applyQuick}
                items={[
                    { key: 'verification', count: todo.review, label: todo.review > 1 ? 'dossiers à vérifier' : 'dossier à vérifier', icon: 'person_check', tone: 'a' },
                    { key: 'corrections', count: todo.corrections, label: todo.corrections > 1 ? 'dossiers renvoyés au commercial' : 'dossier renvoyé au commercial', icon: 'edit_document', tone: 'o' },
                    { key: 'pieces', count: todo.documents, label: todo.documents > 1 ? 'dossiers avec une pièce expirée' : 'dossier avec une pièce expirée', icon: 'event_busy', tone: 'r' },
                ]}
            />

            {actions.dialog}
        </>
    );
}
