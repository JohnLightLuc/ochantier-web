import ActionStrip from '@/components/list/ActionStrip';
import { FilterChip, FilterSelect, SearchInput } from '@/components/list/ListToolbar';
import Pagination from '@/components/list/Pagination';
import { LocationCell, ProgressCell, RowActions, SortableTh, StackCell, TableFrame, TextCell, ThumbCell, tdStyle, thStyle, type SortDirection } from '@/components/list/Table';
import { useSiteActions } from '@/components/sites/useSiteActions';
import { SiteCalendar, SiteCards, SiteMap } from '@/components/sites/views';
import Button from '@/components/ui/Button';
import Icon from '@/components/ui/Icon';
import StatCard from '@/components/ui/StatCard';
import StateBlock from '@/components/ui/StateBlock';
import StatusBadge from '@/components/ui/StatusBadge';
import { formatDate } from '@/lib/format';
import type { SiteFilters, SiteIndexProps, SiteRow, SiteView } from '@/types/sites';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

const COLUMNS: [string, string][] = [
    ['Chantier', 'chantier'],
    ['Référence', 'reference'],
    ['Promoteur', 'promoteur'],
    ['Localisation', 'localisation'],
    ['Type', 'type'],
    ['Début', 'debut'],
    ['Fin prévue', 'fin'],
    ['Jours exécutés / prévus', 'jours'],
    ['Progression', 'progression'],
    ['Effectif', 'effectif'],
    ['Pointages', 'pointages'],
    ['Paiements', 'paiements'],
    ['Alerte', 'alerte'],
    ['Statut', 'statut'],
];

const FILTERS: [keyof SiteIndexProps['filterOptions'], string][] = [
    ['statut', 'Statut'],
    ['promoteur', 'Promoteur'],
    ['type', 'Type de construction'],
    ['localisation', 'Localisation'],
    ['periode', 'Période'],
    ['progression', 'Progression'],
    ['recrutement', 'Recrutement'],
    ['incident', 'Incident'],
    ['paiements', 'Paiements'],
    ['priorite', 'Priorité'],
];

const VIEWS: [SiteView, string, string][] = [
    ['tableau', 'Tableau', 'table_rows'],
    ['cartes', 'Cartes', 'grid_view'],
    ['calendrier', 'Calendrier', 'calendar_month'],
    ['carte', 'Carte géographique', 'map'],
];

const ALERT_LABELS: Record<string, string> = { retard: 'Chantiers en retard', paiement: 'Paiements à déclencher', remplacement: 'Remplacements urgents' };

const scrollTo = (component: string) => requestAnimationFrame(() => document.querySelector(`[data-component="${component}"]`)?.scrollIntoView({ behavior: 'smooth', block: 'start' }));

/** Liste des chantiers : KPI filtrants, quatre vues (tableau, cartes, calendrier, carte), filtres, tri et alertes. */
export default function SitesIndex({ sites, filters, view, filterOptions, kpis, todo, cards, events, communes, referenceDate, options }: SiteIndexProps) {
    const actions = useSiteActions();
    const [menuFor, setMenuFor] = useState<number | null>(null);
    const rows = sites.data;
    const quickLabel = kpis.find((k) => k.key === filters.rapide)?.label;

    const apply = (changes: SiteFilters, keepPage = false) => {
        const next: SiteFilters = { ...filters, ...changes, ...(keepPage ? {} : { page: undefined }) };
        router.get('/chantiers', Object.fromEntries(Object.entries(next).filter(([, v]) => v && v !== 'tableau')), { preserveState: true, preserveScroll: true, replace: true });
    };

    const applyQuick = (key: string) => {
        apply({ rapide: filters.rapide === key || key === 'total' ? undefined : key });
        scrollTo('SiteViews');
    };

    const applyAlert = (key: string | null) => {
        apply({ alerte: key ?? undefined, vue: undefined });
        if (key) scrollTo('ListPanel');
    };

    const rowMenu = (s: SiteRow) => {
        const items = actions.available(s).filter((a) => !a.primary);
        return items.length ? items : undefined;
    };
    const filtered = Object.entries(filters).some(([k, v]) => !['tri', 'sens', 'page', 'vue'].includes(k) && v);

    return (
        <>
            <Head title="Chantiers" />

            <div style={{ display: 'flex', flexWrap: 'wrap', justifyContent: 'flex-end', gap: 'var(--space-2_5)', marginTop: -6 }}>
                <Button label="Exporter" icon="download" variant="secondary" onClick={() => (window.location.href = '/chantiers/export')} />
                <Button label="Créer un chantier" icon="add" variant="primary" onClick={() => actions.create(options.promoters, options.types, options.communes)} />
            </div>

            <div data-component="StatGrid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(210px,1fr))', gap: 'var(--space-4)' }}>
                {kpis.map((k) => {
                    const on = filters.rapide === k.key;
                    return (
                        <button key={k.key} type="button" onClick={() => applyQuick(k.key)} aria-label={k.label} aria-pressed={on} className="och-hover-card" style={{ display: 'block', minWidth: 0, padding: 0, border: 0, background: 'transparent', textAlign: 'left', borderRadius: 'var(--radius-2xl)', cursor: 'pointer' }}>
                            <StatCard label={k.label} value={String(k.value)} icon={k.icon} tone={k.tone as never} hint={on ? 'Filtre appliqué · cliquer pour retirer' : 'Cliquer pour filtrer'} highlight={on} />
                        </button>
                    );
                })}
            </div>

            <div data-component="SiteViews" style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-3)', flexWrap: 'wrap', scrollMarginTop: 'var(--space-4)' }}>
                <div role="tablist" aria-label="Mode d’affichage" style={{ display: 'flex', flexWrap: 'wrap', border: '1px solid var(--c-line-strong)', borderRadius: 'var(--radius-md)', overflow: 'hidden' }}>
                    {VIEWS.map(([key, label, icon]) => {
                        const on = view === key;
                        return (
                            <button key={key} type="button" role="tab" aria-selected={on} onClick={() => apply({ vue: key }, true)} style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-1_5)', height: 'var(--control-sm)', padding: '0 var(--space-3)', border: 0, background: on ? 'var(--c-primary)' : 'var(--c-surface)', color: on ? 'var(--c-on-dark)' : 'var(--c-ink-2)', fontWeight: 'var(--fw-semibold)', fontSize: 'var(--fs-sm)', cursor: 'pointer' }}>
                                <Icon name={icon} size="var(--icon-sm)" />
                                {label}
                            </button>
                        );
                    })}
                </div>
                {quickLabel && filters.rapide !== 'total' && <FilterChip label={quickLabel} onClear={() => apply({ rapide: undefined })} />}
            </div>

            {view === 'cartes' && <SiteCards cards={cards} />}
            {view === 'calendrier' && <SiteCalendar events={events} referenceDate={referenceDate} />}
            {view === 'carte' && <SiteMap cards={cards} communes={communes} onZone={(commune) => apply({ localisation: commune, vue: undefined })} />}

            {view === 'tableau' && (
                <section data-component="ListPanel" style={{ background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-5) var(--space-5) var(--space-4)', minWidth: 0, scrollMarginTop: 'var(--space-4)' }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-3)', flexWrap: 'wrap', marginBottom: 'var(--space-3)' }}>
                        <Icon name="construction" size="var(--icon-md)" color="var(--c-primary)" />
                        <h2 style={{ margin: 0, fontSize: 'var(--fs-2xl)', fontWeight: 'var(--fw-bold)', flex: 1 }}>Liste des chantiers</h2>
                        {filters.alerte && <FilterChip label={ALERT_LABELS[filters.alerte] ?? filters.alerte} onClear={() => applyAlert(null)} />}
                        <span style={{ fontSize: 'var(--fs-base)', color: 'var(--c-ink-2)' }}>
                            <b style={{ color: 'var(--c-ink)' }}>{sites.total}</b> {sites.total > 1 ? 'chantiers' : 'chantier'}
                        </span>
                    </div>

                    <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2_5)', marginBottom: 'var(--space-3)' }}>
                        <SearchInput value={filters.q ?? ''} placeholder="Rechercher par chantier, référence, responsable, promoteur ou localisation" onChange={(q) => apply({ q })} />
                        {FILTERS.map(([key, label]) => (
                            <FilterSelect key={key} label={label} value={filters[key] ?? ''} options={filterOptions[key]} onChange={(value) => apply({ [key]: value })} />
                        ))}
                    </div>

                    <TableFrame minWidth={1700}>
                        <thead>
                            <tr style={{ background: 'var(--c-surface-3)' }}>
                                {COLUMNS.map(([label, key]) => (
                                    <SortableTh key={label} label={label} sortKey={key} current={{ key: filters.tri, direction: filters.sens as SortDirection }} onSort={(tri, sens) => apply({ tri: tri ?? undefined, sens: tri ? sens : undefined }, true)} />
                                ))}
                                <th style={{ ...thStyle, textAlign: 'center' }}>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((s) => {
                                const open = () => router.visit(`/chantiers/${s.slug}`);
                                return (
                                    <tr key={s.id} onClick={open} className="och-hover-soft" style={{ cursor: 'pointer', background: 'var(--c-surface)' }}>
                                        <td style={tdStyle}>
                                            <ThumbCell value={s.name} />
                                        </td>
                                        <td style={tdStyle}>
                                            <TextCell muted>{s.reference}</TextCell>
                                        </td>
                                        <td style={tdStyle}>
                                            <TextCell>{s.promoter}</TextCell>
                                        </td>
                                        <td style={tdStyle}>
                                            <LocationCell value={s.commune} />
                                        </td>
                                        <td style={tdStyle}>
                                            <TextCell>{s.type}</TextCell>
                                        </td>
                                        <td style={tdStyle}>
                                            <TextCell>{formatDate(s.startsOn)}</TextCell>
                                        </td>
                                        <td style={tdStyle}>
                                            <TextCell>{formatDate(s.endsOn)}</TextCell>
                                        </td>
                                        <td style={tdStyle}>
                                            <StackCell main={`${s.executedDays} / ${s.plannedDays} j`} sub="exécutés / prévus" />
                                        </td>
                                        <td style={tdStyle}>
                                            <ProgressCell value={s.progress} />
                                        </td>
                                        <td style={tdStyle}>
                                            <StackCell main={`${s.assigned} / ${s.required}`} sub={s.staffing.label} />
                                        </td>
                                        <td style={tdStyle}>
                                            <StatusBadge label={s.timesheets.label} tone={s.timesheets.tone} />
                                        </td>
                                        <td style={tdStyle}>
                                            <StatusBadge label={s.payments.label} tone={s.payments.tone} />
                                        </td>
                                        <td style={tdStyle}>
                                            <StatusBadge label={s.alert.label} tone={s.alert.tone} />
                                        </td>
                                        <td style={tdStyle}>
                                            <StatusBadge label={s.status.label} tone={s.status.tone} />
                                        </td>
                                        <RowActions
                                            actions={[
                                                { icon: 'visibility', label: 'Voir la fiche', onClick: open },
                                                ...(['cancelled', 'archived'].includes(s.status.value) ? [] : [{ icon: 'edit', label: 'Modifier les informations autorisées', onClick: () => actions.edit(s) }]),
                                            ]}
                                            menu={rowMenu(s)}
                                            menuOpen={menuFor === s.id}
                                            onToggleMenu={() => setMenuFor(menuFor === s.id ? null : s.id)}
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
                                <StateBlock kind="nodata" icon="construction" title="Aucun chantier enregistré" text="Les chantiers publiés par les promoteurs apparaîtront ici." />
                            </div>
                        ))}

                    <Pagination page={sites.currentPage} lastPage={sites.lastPage} from={sites.from} to={sites.to} total={sites.total} onPage={(page) => apply({ page: String(page) }, true)} />
                </section>
            )}

            <ActionStrip
                title="Alertes et échéances"
                icon="schedule"
                active={filters.alerte ?? null}
                activeLabel={filters.alerte ? ALERT_LABELS[filters.alerte] : undefined}
                ctaLabel={filters.alerte ? 'Afficher tous les chantiers' : 'Voir les alertes'}
                onCta={() => applyAlert(filters.alerte ? null : ((Object.keys(todo) as (keyof typeof todo)[]).find((k) => todo[k] > 0) ?? null))}
                onSelect={applyAlert}
                items={[
                    { key: 'retard', count: todo.retard, label: 'chantiers en retard', icon: 'schedule', tone: 'r' },
                    { key: 'paiement', count: todo.paiement, label: 'paiements à déclencher (seuil atteint ou paiement en attente)', icon: 'credit_card', tone: 'a' },
                    { key: 'remplacement', count: todo.remplacement, label: 'remplacements urgents (indisponibilité ou poste vacant)', icon: 'person_alert', tone: 'r' },
                ]}
            />

            {actions.dialog}
        </>
    );
}
