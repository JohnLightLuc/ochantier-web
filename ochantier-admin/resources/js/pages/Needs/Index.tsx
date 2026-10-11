import ActionStrip from '@/components/list/ActionStrip';
import { FilterChip, FilterSelect, SearchInput, SelectionBar } from '@/components/list/ListToolbar';
import Pagination from '@/components/list/Pagination';
import { Checkbox, LocationCell, ProgressCell, RowActions, SortableTh, TableFrame, TextCell, tdStyle, thStyle, type SortDirection } from '@/components/list/Table';
import { useNeedActions } from '@/components/needs/useNeedActions';
import Dialog from '@/components/overlays/Dialog';
import Button from '@/components/ui/Button';
import Field, { TextInput } from '@/components/ui/Field';
import Icon from '@/components/ui/Icon';
import StatCard from '@/components/ui/StatCard';
import StateBlock from '@/components/ui/StateBlock';
import StatusBadge from '@/components/ui/StatusBadge';
import { useAdminUi } from '@/contexts/AdminUiContext';
import { formatDate } from '@/lib/format';
import { toneColors } from '@/lib/tone';
import type { AnyTone } from '@/types';
import type { NeedCard, NeedFilters, NeedIndexProps, NeedRow } from '@/types/needs';
import { Head, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

const COLUMNS: [string, string][] = [
    ['Référence', 'reference'],
    ['Chantier', 'chantier'],
    ['Type de chantier', 'type_chantier'],
    ['Promoteur', 'promoteur'],
    ['Commune / ville', 'commune'],
    ['Métier principal', 'metier'],
    ['Métiers', 'metiers'],
    ['Sélectionnés / recherchés', 'selection'],
    ['Manquants', 'manquants'],
    ['Couverture', 'couverture'],
    ['Démarrage', 'demarrage'],
    ['Date limite', 'limite'],
    ['Durée', 'duree'],
    ['Urgence', 'urgence'],
    ['Type de besoin', 'type'],
    ['Statut', 'statut'],
    ['Statut de couverture', 'statut_couverture'],
    ['Création', 'creation'],
];

const SORTS: [string, string][] = [
    ['creation', 'Date de création'],
    ['demarrage', 'Date de début'],
    ['limite', 'Date d’expiration'],
    ['urgence', 'Urgence'],
    ['type_chantier', 'Type de chantier'],
    ['postes', 'Nombre de postes'],
    ['couverture', 'Taux de couverture'],
    ['remuneration', 'Montant de rémunération'],
    ['activite', 'Dernière activité'],
];

const TAB_TITLES: Record<string, [string, string]> = {
    attente: ['Besoins en attente d’ouvriers', 'assignment'],
    expires: ['Besoins expirés à actualiser', 'timer_off'],
    suspendus: ['Besoins suspendus, annulés ou clôturés', 'block'],
};

const CHIPS = ['urgents', 'expire', 'partiel', 'sansouv'];

const ALERT_LABELS: Record<string, string> = { demarrage: 'Besoins à démarrer sans effectif suffisant', critique: 'Besoins critiques sans affectation', expire: 'Besoins avec une offre bientôt expirée' };

type Saved = { name: string; query: NeedFilters };

const SAVED_KEY = 'och_bo_saved2';

const DEFAULT_SAVED: Saved[] = [
    { name: 'Urgences en attente', query: { urgence: 'urgent' } },
    { name: 'Offres qui expirent', query: { rapide: 'expire' } },
    { name: 'Expirées sans couverture', query: { onglet: 'expires', couverture: 'Non couvert' } },
];

function readSaved(): Saved[] {
    try {
        return JSON.parse(localStorage.getItem(SAVED_KEY) ?? 'null') ?? DEFAULT_SAVED;
    } catch {
        return DEFAULT_SAVED;
    }
}

const scrollTo = (component: string) => requestAnimationFrame(() => document.querySelector(`[data-component="${component}"]`)?.scrollIntoView({ behavior: 'smooth', block: 'start' }));

/** Liste des besoins : raccourcis, onglets, vue Cartes, filtres avancés, tri, filtres enregistrés et alertes de couverture. */
export default function NeedsIndex({ needs, tab, tabs, filters, view, filterDefs, quick, stats, todo, cards }: NeedIndexProps) {
    const ui = useAdminUi();
    const actions = useNeedActions();
    const [selected, setSelected] = useState<number[]>([]);
    const [menuFor, setMenuFor] = useState<number | null>(null);
    const [saved, setSaved] = useState<Saved[]>(DEFAULT_SAVED);
    const [naming, setNaming] = useState<string | null>(null);
    const rows = needs.data;
    const q = Object.fromEntries(quick.map((k) => [k.key, k]));
    const advanced = !!filters.plus;

    useEffect(() => setSaved(readSaved()), []);
    useEffect(() => setSelected([]), [needs]);

    const apply = (changes: NeedFilters, keepPage = false) => {
        const next: NeedFilters = { ...filters, ...changes, ...(keepPage ? {} : { page: undefined }) };
        router.get('/besoins', Object.fromEntries(Object.entries(next).filter(([, v]) => v)), { preserveState: true, preserveScroll: true, replace: true });
    };

    const pickQuick = (key: string) => {
        const on = filters.rapide === key;
        apply({ rapide: on ? undefined : key, alerte: undefined, statut: undefined, onglet: on ? filters.onglet : key === 'expirees' ? 'expires' : undefined });
        scrollTo('BesoinsViews');
    };
    const resetQuick = () => apply({ rapide: undefined, onglet: undefined });

    const applyAlert = (key: string | null) => {
        apply({ alerte: key ?? undefined, rapide: undefined, onglet: undefined, vue: undefined });
        if (key) scrollTo('ListPanel');
    };

    const switchTab = (key: string) => key !== tab && apply({ onglet: key === 'attente' ? undefined : key, statut: undefined, rapide: undefined, alerte: undefined, tri: undefined, sens: undefined });

    const pickSaved = (value: string) => {
        if (value === '__reset') {
            router.get('/besoins', {}, { preserveScroll: true });
            return ui.toast('Filtres réinitialisés.');
        }
        if (value === '__save') {
            const active = Object.entries(filters).filter(([k, v]) => v && !['page', 'vue', 'tri', 'sens', 'plus'].includes(k));
            if (!active.length) return ui.toast('Aucun filtre actif à enregistrer.', { tone: 'error' });
            return setNaming('');
        }
        const item = saved[Number(value)];
        if (!item) return;
        router.get('/besoins', { ...item.query, plus: '1' }, { preserveScroll: true });
        ui.toast(`Filtre « ${item.name} » appliqué.`);
    };

    const saveCurrent = () => {
        const name = (naming ?? '').trim();
        if (!name) return;
        const query = Object.fromEntries(Object.entries(filters).filter(([k, v]) => v && !['page', 'vue', 'plus'].includes(k)));
        const next = [...saved, { name, query }];
        setSaved(next);
        try {
            localStorage.setItem(SAVED_KEY, JSON.stringify(next));
        } catch {
            // Stockage indisponible : le filtre reste disponible pour cette session.
        }
        setNaming(null);
        ui.toast(`Filtre « ${name} » enregistré.`);
    };

    const exportUrl = (ids?: number[]) => {
        const params = new URLSearchParams(Object.entries({ ...filters, onglet: tab, ...(ids?.length ? { ids: ids.join(',') } : {}) }).filter(([k, v]) => v && !['page', 'vue', 'plus'].includes(k)) as [string, string][]);
        return `/besoins/export?${params}`;
    };

    const bulk = () => {
        const list = rows.filter((r) => selected.includes(r.id));
        if (tab === 'expires') {
            const expired = list.filter((r) => r.actions.expiredOffers.length);
            if (!expired.length) return ui.toast('Aucun besoin expiré dans la sélection.', { tone: 'error' });
            return actions.refreshMany(expired.map((r) => r.id), expired[0].actions.today);
        }
        const open = list.find((r) => r.actions.canMatch);
        if (!open) return ui.toast('Aucun besoin ouvert non couvert dans la sélection.', { tone: 'error' });
        setSelected([]);
        actions.match(open.actions);
    };

    const rowActions = (r: NeedRow) => {
        const first = r.actions.expiredOffers.length
            ? { icon: 'autorenew', label: 'Actualiser le besoin', onClick: () => actions.refreshAll(r.actions) }
            : { icon: 'edit', label: 'Modifier', onClick: () => (r.actions.manual ? ui.toast(`Besoin ${r.status.label.toLowerCase()} : modification impossible.`, { tone: 'error' }) : router.visit(`/besoins/${r.slug}/modifier`)) };
        return [{ icon: 'visibility', label: 'Voir la fiche', onClick: () => router.visit(`/besoins/${r.slug}`) }, first];
    };

    const allSelected = rows.length > 0 && rows.every((r) => selected.includes(r.id));
    const visibleFilters = filterDefs.filter((d) => !d.advanced || advanced);
    const filtered = Object.entries(filters).some(([k, v]) => !['tri', 'sens', 'page', 'vue', 'plus', 'onglet'].includes(k) && v);
    const [title, icon] = TAB_TITLES[tab];
    const kpis: [string, string, () => void][] = [
        ['attente', 'Recherche d’ouvriers en cours', () => pickQuick('attente')],
        ['pourvoir', `Sélectionnés / recherchés : ${stats.pendingSelected} / ${stats.pendingNeed}`, resetQuick],
        ['expirees', 'À actualiser', () => pickQuick('expirees')],
        ['taux', `Délai moyen de couverture : ${stats.delay}`, resetQuick],
    ];

    return (
        <>
            <Head title="Besoins" />

            <div style={{ display: 'flex', flexWrap: 'wrap', justifyContent: 'flex-end', gap: 'var(--space-2_5)', marginTop: -6 }}>
                <Button label="Exporter" icon="download" variant="secondary" onClick={() => (window.location.href = exportUrl())} />
                <Button label="Créer un besoin" icon="add" variant="primary" onClick={() => router.visit('/besoins/nouveau-besoin')} />
            </div>

            <div data-component="StatGrid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(210px,1fr))', gap: 'var(--space-4)' }}>
                {kpis.map(([key, hint, onClick]) => {
                    const on = filters.rapide === key;
                    return (
                        <button key={key} type="button" onClick={onClick} aria-label={q[key].label} aria-pressed={on} className="och-hover-card" style={{ display: 'block', minWidth: 0, padding: 0, border: 0, background: 'transparent', textAlign: 'left', borderRadius: 'var(--radius-2xl)', cursor: 'pointer' }}>
                            <StatCard label={q[key].label} value={q[key].value} icon={q[key].icon} tone={q[key].tone as AnyTone} hint={on ? 'Filtre appliqué · cliquer pour retirer' : hint} highlight={on} />
                        </button>
                    );
                })}
            </div>

            <div data-component="BesoinsViews" style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-3)', flexWrap: 'wrap', scrollMarginTop: 'var(--space-4)' }}>
                <div role="group" aria-label="Filtres rapides" style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2)' }}>
                    {CHIPS.map((key) => {
                        const on = filters.rapide === key;
                        const { bg, fg } = toneColors(q[key].tone as AnyTone);
                        return (
                            <button key={key} type="button" onClick={() => pickQuick(key)} aria-pressed={on} style={{ display: 'inline-flex', alignItems: 'center', gap: 'var(--space-1_5)', height: 'var(--control-sm)', padding: '0 var(--space-2) 0 var(--space-3)', border: `1px solid ${on ? 'var(--c-primary-400)' : 'var(--c-line-strong)'}`, borderRadius: 'var(--radius-pill)', background: on ? 'var(--c-primary-50)' : 'var(--c-surface)', color: on ? 'var(--c-primary)' : 'var(--c-ink-2)', fontWeight: 'var(--fw-semibold)', fontSize: 'var(--fs-sm)', cursor: 'pointer' }}>
                                <Icon name={q[key].icon} size="var(--icon-xs)" />
                                {q[key].label}
                                <span style={{ minWidth: 22, height: 20, padding: '0 6px', borderRadius: 'var(--radius-pill)', background: on ? 'var(--c-primary)' : bg, color: on ? 'var(--c-on-dark)' : fg, display: 'inline-grid', placeItems: 'center', fontSize: 'var(--fs-xs)' }}>{q[key].value}</span>
                            </button>
                        );
                    })}
                </div>
                <div role="tablist" aria-label="Mode d’affichage" style={{ display: 'flex', border: '1px solid var(--c-line-strong)', borderRadius: 'var(--radius-md)', overflow: 'hidden' }}>
                    {(
                        [
                            ['tableau', 'Tableau', 'table_rows'],
                            ['cartes', 'Cartes', 'grid_view'],
                        ] as const
                    ).map(([key, label, ic]) => {
                        const on = view === key;
                        return (
                            <button key={key} type="button" role="tab" aria-selected={on} onClick={() => apply({ vue: key === 'tableau' ? undefined : key }, true)} style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-1_5)', height: 'var(--control-sm)', padding: '0 var(--space-3)', border: 0, background: on ? 'var(--c-primary)' : 'var(--c-surface)', color: on ? 'var(--c-on-dark)' : 'var(--c-ink-2)', fontWeight: 'var(--fw-semibold)', fontSize: 'var(--fs-sm)', cursor: 'pointer' }}>
                                <Icon name={ic} size="var(--icon-sm)" />
                                {label}
                            </button>
                        );
                    })}
                </div>
                {filters.rapide && !CHIPS.includes(filters.rapide) && q[filters.rapide] && <FilterChip label={q[filters.rapide].label} onClear={resetQuick} />}
                <span style={{ flex: 1 }} />
                <span style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-1_5)', fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>
                    <Icon name="tune" size="var(--icon-xs)" />
                    Besoins couverts suivis dans Affectations & Offres · manquants actualisés automatiquement
                </span>
            </div>

            {view === 'cartes' ? (
                <NeedCards cards={cards} />
            ) : (
                <section data-component="ListPanel" style={{ background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-5) var(--space-5) var(--space-4)', minWidth: 0, scrollMarginTop: 'var(--space-4)' }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-3)', flexWrap: 'wrap', marginBottom: 'var(--space-3)' }}>
                        <Icon name={icon} size="var(--icon-md)" color="var(--c-primary)" />
                        <h2 style={{ margin: 0, fontSize: 'var(--fs-2xl)', fontWeight: 'var(--fw-bold)', flex: 1 }}>{title}</h2>
                        {filters.alerte && <FilterChip label={ALERT_LABELS[filters.alerte] ?? filters.alerte} onClear={() => applyAlert(null)} />}
                        <span style={{ fontSize: 'var(--fs-base)', color: 'var(--c-ink-2)' }}>
                            <b style={{ color: 'var(--c-ink)' }}>{needs.total}</b> {needs.total > 1 ? 'besoins' : 'besoin'}
                        </span>
                    </div>

                    <div role="tablist" style={{ display: 'flex', gap: 'var(--space-1)', borderBottom: '1px solid var(--c-line)', marginBottom: 'var(--space-3)', overflowX: 'auto' }}>
                        {tabs.map((t) => {
                            const on = t.key === tab;
                            return (
                                <button key={t.key} type="button" role="tab" aria-selected={on} onClick={() => switchTab(t.key)} className="och-hover-tab" style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)', height: 'var(--control-md)', padding: '0 var(--space-4)', border: 0, borderBottom: `2.5px solid ${on ? 'var(--c-primary)' : 'transparent'}`, marginBottom: -1, background: 'transparent', fontWeight: 'var(--fw-semibold)', fontSize: 'var(--fs-base)', color: on ? 'var(--c-primary)' : 'var(--c-muted)', whiteSpace: 'nowrap', cursor: 'pointer' }}>
                                    {t.label}
                                    <span style={{ minWidth: 22, padding: '1px var(--space-1_5)', borderRadius: 'var(--radius-pill)', fontSize: 'var(--fs-xs)', textAlign: 'center', background: on ? 'var(--c-primary)' : t.key === 'expires' ? 'var(--c-warning-bg)' : 'var(--c-neutral-bg)', color: on ? 'var(--c-on-dark)' : t.key === 'expires' ? 'var(--c-warning-fg)' : 'var(--c-neutral-fg)' }}>{t.count}</span>
                                </button>
                            );
                        })}
                    </div>

                    <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2_5)', marginBottom: 'var(--space-3)' }}>
                        <SearchInput value={filters.q ?? ''} placeholder="Référence, chantier, promoteur, métier, commune, quartier ou mot-clé" onChange={(value) => apply({ q: value })} />
                        {visibleFilters.map((d) => (
                            <FilterSelect key={d.key} label={d.label} value={filters[d.key] ?? ''} options={d.options} onChange={(value) => apply({ [d.key]: value })} />
                        ))}
                        <FilterSelect label="Trier par" value={filters.tri && SORTS.some(([k]) => k === filters.tri) ? `${filters.tri}:${filters.sens ?? 'asc'}` : ''} options={SORTS.flatMap(([k, l]) => [{ value: `${k}:desc`, label: `${l} · décroissant` }, { value: `${k}:asc`, label: `${l} · croissant` }])} onChange={(value) => apply({ tri: value.split(':')[0] || undefined, sens: value.split(':')[1] || undefined }, true)} />
                        <FilterSelect label="Filtres enregistrés" value="" options={[...saved.map((s, i) => ({ value: String(i), label: s.name })), { value: '__save', label: '+ Enregistrer les filtres actuels' }, { value: '__reset', label: 'Réinitialiser les filtres' }]} onChange={pickSaved} />
                        <Button label={advanced ? 'Moins de filtres' : 'Plus de filtres'} icon="tune" variant="secondary" onClick={() => apply({ plus: advanced ? undefined : '1' }, true)} />
                    </div>

                    {selected.length > 0 && <SelectionBar count={selected.length} bulkLabel={tab === 'expires' ? 'Actualiser' : 'Lancer le matching'} bulkIcon={tab === 'expires' ? 'autorenew' : 'join_inner'} onExport={() => (window.location.href = exportUrl(selected))} onBulk={tab === 'suspendus' ? undefined : bulk} onClear={() => setSelected([])} />}

                    <TableFrame minWidth={2300}>
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
                            {rows.map((r) => {
                                const on = selected.includes(r.id);
                                const open = () => router.visit(`/besoins/${r.slug}`);
                                const menu = actions.available(r.actions);
                                return (
                                    <tr key={r.id} onClick={open} className="och-hover-soft" style={{ cursor: 'pointer', background: on ? 'var(--c-primary-50)' : 'var(--c-surface)' }}>
                                        <td style={tdStyle}>
                                            <Checkbox label={`Sélectionner ${r.reference}`} checked={on} onToggle={() => setSelected((s) => (s.includes(r.id) ? s.filter((x) => x !== r.id) : [...s, r.id]))} />
                                        </td>
                                        <td style={tdStyle}>
                                            <TextCell strong link>
                                                {r.reference}
                                            </TextCell>
                                        </td>
                                        <td style={tdStyle}>
                                            <TextCell>{r.site}</TextCell>
                                        </td>
                                        <td style={tdStyle}>
                                            <TextCell>{r.siteType}</TextCell>
                                        </td>
                                        <td style={tdStyle}>
                                            <TextCell>{r.promoter}</TextCell>
                                        </td>
                                        <td style={tdStyle}>
                                            <LocationCell value={`${r.commune}, ${r.city}`} />
                                        </td>
                                        <td style={tdStyle}>
                                            <TextCell>{r.mainTrade}</TextCell>
                                        </td>
                                        <td style={tdStyle}>
                                            <TextCell>
                                                {r.tradesCount} métier{r.tradesCount > 1 ? 's' : ''}
                                            </TextCell>
                                        </td>
                                        <td style={tdStyle}>
                                            <TextCell strong>
                                                {r.selected} / {r.need}
                                            </TextCell>
                                        </td>
                                        <td style={tdStyle}>
                                            <TextCell>{r.rest}</TextCell>
                                        </td>
                                        <td style={tdStyle}>
                                            <ProgressCell value={Math.min(100, r.percent)} />
                                        </td>
                                        <td style={tdStyle}>
                                            <TextCell>{formatDate(r.startsOn)}</TextCell>
                                        </td>
                                        <td style={tdStyle}>
                                            <TextCell muted={!r.deadline}>{formatDate(r.deadline)}</TextCell>
                                        </td>
                                        <td style={tdStyle}>
                                            <TextCell>{r.duration} j</TextCell>
                                        </td>
                                        <td style={tdStyle}>
                                            <StatusBadge label={r.urgency.label} tone={r.urgency.tone} />
                                        </td>
                                        <td style={tdStyle}>
                                            <TextCell>{r.type}</TextCell>
                                        </td>
                                        <td style={tdStyle}>
                                            <StatusBadge label={r.status.label} tone={r.status.tone} />
                                        </td>
                                        <td style={tdStyle}>
                                            <StatusBadge label={r.coverage.label} tone={r.coverage.tone} />
                                        </td>
                                        <td style={tdStyle}>
                                            <TextCell>{formatDate(r.createdOn)}</TextCell>
                                        </td>
                                        <RowActions actions={rowActions(r)} menu={menu.length ? menu : undefined} menuOpen={menuFor === r.id} onToggleMenu={() => setMenuFor(menuFor === r.id ? null : r.id)} />
                                    </tr>
                                );
                            })}
                        </tbody>
                    </TableFrame>

                    {rows.length === 0 &&
                        (filtered || tabs.some((t) => t.count) ? (
                            <div style={{ padding: 'var(--space-8)', textAlign: 'center', color: 'var(--c-muted)' }}>
                                <Icon name="search_off" size="var(--icon-xl)" color="var(--c-subtle)" style={{ display: 'block', marginBottom: 'var(--space-1_5)' }} />
                                Aucun résultat pour ces critères.
                            </div>
                        ) : (
                            <div style={{ paddingTop: 'var(--space-4)' }}>
                                <StateBlock kind="nodata" icon="assignment" title="Aucun besoin enregistré" text="Les besoins exprimés par les promoteurs depuis leurs chantiers apparaîtront ici." actionLabel="Créer un besoin" onAction={() => router.visit('/besoins/nouveau-besoin')} />
                            </div>
                        ))}

                    <Pagination page={needs.currentPage} lastPage={needs.lastPage} from={needs.from} to={needs.to} total={needs.total} onPage={(page) => apply({ page: String(page) }, true)} />
                </section>
            )}

            <ActionStrip
                title="Couverture et alertes"
                icon="notifications_active"
                active={filters.alerte ?? null}
                activeLabel={filters.alerte ? ALERT_LABELS[filters.alerte] : undefined}
                ctaLabel={filters.alerte ? 'Afficher tous les besoins' : 'Voir les alertes'}
                onCta={() => applyAlert(filters.alerte ? null : ((Object.keys(todo) as (keyof typeof todo)[]).find((k) => todo[k] > 0) ?? null))}
                onSelect={applyAlert}
                items={[
                    { key: 'demarrage', count: todo.demarrage, label: 'besoins à démarrer sans effectif suffisant', icon: 'event_upcoming', tone: 'r' },
                    { key: 'critique', count: todo.critique, label: 'besoins critiques sans affectation', icon: 'crisis_alert', tone: 'r' },
                    { key: 'expire', count: todo.expire, label: 'besoins avec une offre bientôt expirée', icon: 'timer', tone: 'a' },
                ]}
            />

            {naming !== null && (
                <Dialog title="Enregistrer les filtres" body="Les filtres actifs seront proposés dans « Filtres enregistrés »." icon="bookmark_add" okLabel="Enregistrer" onClose={() => setNaming(null)} onSubmit={saveCurrent}>
                    <Field label="Nom du filtre" required>
                        <TextInput value={naming} onChange={(e) => setNaming(e.target.value)} placeholder="Ex. Maçons à Cocody" aria-label="Nom du filtre" autoFocus />
                    </Field>
                </Dialog>
            )}

            {actions.dialog}
        </>
    );
}

/** Vue Cartes : une vignette par besoin avec couverture, urgence et alerte principale. */
function NeedCards({ cards }: { cards: NeedCard[] }) {
    if (!cards.length) return <StateBlock kind="empty" title="Aucun résultat" text="Aucun élément ne correspond à la recherche ou aux filtres." />;

    return (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,300px),1fr))', gap: 'var(--space-4)' }}>
            {cards.map((c) => (
                <button key={c.id} type="button" onClick={() => router.visit(`/besoins/${c.slug}`)} className="och-hover-card" style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-1_5)', padding: 'var(--space-4)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', background: 'var(--c-surface)', cursor: 'pointer', textAlign: 'left', minWidth: 0 }}>
                    <span style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)' }}>
                        <b style={{ flex: 1, minWidth: 0, fontSize: 'var(--fs-lg)', color: 'var(--c-ink)' }}>{c.site}</b>
                        <StatusBadge label={c.status.label} tone={c.status.tone} />
                    </span>
                    <span style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>
                        {c.reference} · {c.siteType} · {c.promoter} · {c.commune}
                    </span>
                    <span style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-ink-2)' }}>{c.line}</span>
                    <span style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)' }}>
                        <span style={{ flex: 1, height: 7, borderRadius: 'var(--radius-pill)', background: 'var(--c-line-soft)', overflow: 'hidden' }}>
                            <span style={{ display: 'block', height: '100%', width: `${Math.min(100, c.percent)}%`, background: c.percent < 50 ? 'var(--c-accent-600)' : 'var(--c-primary-500)' }} />
                        </span>
                        <b style={{ fontSize: 'var(--fs-sm)' }}>{c.percent} %</b>
                    </span>
                    <span style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)', flexWrap: 'wrap' }}>
                        <StatusBadge label={c.urgency.label} tone={c.urgency.tone} />
                        <span style={{ fontSize: 'var(--fs-sm)', fontWeight: 'var(--fw-semibold)', color: c.alert.tone === 'muted' ? 'var(--c-muted)' : `var(--c-${c.alert.tone === 'danger' ? 'danger' : 'warning'}-fg)` }}>{c.alert.text}</span>
                    </span>
                </button>
            ))}
        </div>
    );
}
