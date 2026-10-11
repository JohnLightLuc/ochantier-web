import ActionStrip from '@/components/list/ActionStrip';
import { FilterChip, FilterSelect, SearchInput, SelectionBar } from '@/components/list/ListToolbar';
import Pagination from '@/components/list/Pagination';
import { Checkbox, LocationCell, PersonCell, ProgressCell, RowActions, SortableTh, StackCell, TableFrame, TextCell, tdStyle, thStyle, type SortDirection } from '@/components/list/Table';
import { fcfa, useSalesActions, type AgentRef } from '@/components/sales/useSalesActions';
import { CommercialOfTheMonthCard } from '@/components/sales/visuals';
import Button from '@/components/ui/Button';
import Icon from '@/components/ui/Icon';
import type { MenuItem } from '@/components/ui/Menu';
import StatCard from '@/components/ui/StatCard';
import StateBlock from '@/components/ui/StateBlock';
import StatusBadge from '@/components/ui/StatusBadge';
import { formatDate, formatNumber } from '@/lib/format';
import type { SalesAgentRow, SalesFilters, SalesIndexProps } from '@/types/sales';
import { Head, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

const COLUMNS: [string, string | null][] = [
    ['Commercial', 'commercial'],
    ['Contact', null],
    ['Zone', 'zone'],
    ['Ouvriers', null],
    ['Taux de validation', 'taux'],
    ['Objectif', 'objectif'],
    ['Gain hebdomadaire', 'gain'],
    ['Performance', 'performance'],
    ['Disponibilité', null],
    ['Statut du compte', 'statut'],
    ['Dernière activité', 'activite'],
];

const FILTERS: [keyof SalesIndexProps['filterOptions'], string][] = [
    ['statut', 'Statut'],
    ['zone', 'Zone'],
    ['agence', 'Agence'],
    ['disponibilite', 'Disponibilité'],
    ['performance', 'Performance'],
    ['superviseur', 'Superviseur'],
    ['periode', 'Période d’activité'],
];

const QUICK_LABELS: Record<string, string> = { objectifs: 'Objectifs atteints à date', relances: 'Commerciaux à relancer', gains: 'Gains à valider' };

const PRE_ACTIVATION = ['draft', 'invited', 'pending_activation'];

/** Liste des commerciaux : Commercial du mois, KPI, filtres, tri, sélection et suivi des performances. */
export default function SalesAgentsIndex({ agents, filters, filterOptions, kpis, todo, cotm, months, currentMonth }: SalesIndexProps) {
    const actions = useSalesActions();
    const [selected, setSelected] = useState<number[]>([]);
    const [menuFor, setMenuFor] = useState<number | null>(null);
    const rows = agents.data;

    useEffect(() => setSelected([]), [agents]);

    const apply = (changes: SalesFilters, keepPage = false) => {
        const next: SalesFilters = { ...filters, ...changes, ...(keepPage ? {} : { page: undefined }) };
        router.get('/commerciaux', Object.fromEntries(Object.entries(next).filter(([, v]) => v)), { preserveState: true, preserveScroll: true, replace: true });
    };

    const applyQuick = (key: string | null) => {
        apply({ rapide: key ?? undefined });
        if (key) requestAnimationFrame(() => document.querySelector('[data-component="ListPanel"]')?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
    };

    const allSelected = rows.length > 0 && rows.every((r) => selected.includes(r.id));
    const exportUrl = (ids?: number[]) => `/commerciaux/export${ids?.length ? `?ids=${ids.join(',')}` : ''}`;
    const ref = (c: SalesAgentRow): AgentRef => ({ slug: c.slug, name: c.name, code: c.code, email: c.email, phone: c.phone, zone: c.zone, status: c.status });

    const rowMenu = (c: SalesAgentRow): MenuItem[] => {
        const a = ref(c);
        const status = c.status.value;
        const items: MenuItem[] = [
            { icon: 'key', label: 'Voir les accès', onClick: () => router.visit(`/commerciaux/${c.slug}/acces-securite`) },
            { icon: 'admin_panel_settings', label: 'Modifier les accès', onClick: () => router.visit(`/commerciaux/${c.slug}/modifier/4`) },
        ];
        if (PRE_ACTIVATION.includes(status)) items.push({ icon: 'forward_to_inbox', label: status === 'draft' ? 'Envoyer l’invitation' : 'Renvoyer l’invitation', onClick: () => actions.invite(a) });
        if (status === 'active') {
            items.push({ icon: 'event_busy', label: 'Rendre indisponible', onClick: () => router.visit(`/commerciaux/${c.slug}/zones-disponibilites`) });
            items.push({ icon: 'block', label: 'Suspendre', danger: true, onClick: () => actions.suspend(a) });
        }
        if (status === 'suspended' || status === 'deactivated') items.push({ icon: 'settings_backup_restore', label: 'Réactiver', onClick: () => actions.reactivate(a) });
        if (status !== 'deactivated' && status !== 'archived') items.push({ icon: 'person_off', label: 'Désactiver', danger: true, onClick: () => actions.deactivate(a) });
        items.push({ icon: 'delete_forever', label: 'Supprimer le compte', danger: true, onClick: () => actions.destroy(a) });
        return items;
    };

    const filtered = Object.entries(filters).some(([k, v]) => !['tri', 'sens', 'page', 'mois'].includes(k) && v);

    return (
        <>
            <Head title="Commerciaux" />

            <div style={{ display: 'flex', flexWrap: 'wrap', justifyContent: 'flex-end', gap: 'var(--space-2_5)', marginTop: -6 }}>
                <Button label="Classement" icon="leaderboard" variant="secondary" onClick={() => router.visit('/commerciaux/classement')} />
                <Button label="Exporter" icon="download" variant="secondary" onClick={() => (window.location.href = exportUrl())} />
                <Button label="Ajouter un commercial" icon="add" variant="primary" onClick={() => router.visit('/commerciaux/nouveau')} />
            </div>

            <CommercialOfTheMonthCard cotm={cotm} months={months} currentMonth={currentMonth} onMonth={(mois) => apply({ mois: mois === currentMonth ? undefined : mois }, true)} />

            <div data-component="StatGrid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(230px,1fr))', gap: 'var(--space-4)' }}>
                <StatCard label="Total commerciaux" value={String(kpis.total)} icon="groups" tone="g" />
                <StatCard label="Actifs" value={String(kpis.active)} icon="person" tone="g" />
                <StatCard label="Ouvriers enregistrés" value={formatNumber(kpis.recruited)} icon="engineering" tone="a" />
                <StatCard label="Commissions à payer" value={formatNumber(kpis.payable)} unit="FCFA" icon="paid" tone="a" />
            </div>

            <section data-component="ListPanel" style={{ background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-5) var(--space-5) var(--space-4)', minWidth: 0, scrollMarginTop: 'var(--space-4)' }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-3)', flexWrap: 'wrap', marginBottom: 'var(--space-3)' }}>
                    <Icon name="trending_up" size="var(--icon-md)" color="var(--c-primary)" />
                    <h2 style={{ margin: 0, fontSize: 'var(--fs-2xl)', fontWeight: 'var(--fw-bold)', flex: 1 }}>Liste des commerciaux</h2>
                    {filters.rapide && <FilterChip label={QUICK_LABELS[filters.rapide] ?? filters.rapide} onClear={() => applyQuick(null)} />}
                    <span style={{ fontSize: 'var(--fs-base)', color: 'var(--c-ink-2)' }}>
                        <b style={{ color: 'var(--c-ink)' }}>{agents.total}</b> {agents.total > 1 ? 'commerciaux' : 'commercial'}
                    </span>
                </div>

                <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2_5)', marginBottom: 'var(--space-3)' }}>
                    <SearchInput value={filters.q ?? ''} placeholder="Rechercher par nom, téléphone ou zone" onChange={(q) => apply({ q })} />
                    {FILTERS.map(([key, label]) => (
                        <FilterSelect key={key} label={label} value={filters[key] ?? ''} options={filterOptions[key]} onChange={(value) => apply({ [key]: value })} />
                    ))}
                </div>

                {selected.length > 0 && <SelectionBar count={selected.length} onExport={() => (window.location.href = exportUrl(selected))} onClear={() => setSelected([])} />}

                <TableFrame minWidth={1500}>
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
                        {rows.map((c) => {
                            const on = selected.includes(c.id);
                            const open = () => router.visit(`/commerciaux/${c.slug}`);
                            return (
                                <tr key={c.id} onClick={open} className="och-hover-soft" style={{ cursor: 'pointer', background: on ? 'var(--c-primary-50)' : 'var(--c-surface)' }}>
                                    <td style={tdStyle}>
                                        <Checkbox label={`Sélectionner ${c.name}`} checked={on} onToggle={() => setSelected((s) => (s.includes(c.id) ? s.filter((x) => x !== c.id) : [...s, c.id]))} />
                                    </td>
                                    <td style={tdStyle}>
                                        <PersonCell name={c.name} sub={c.code} />
                                    </td>
                                    <td style={tdStyle}>
                                        <StackCell main={c.phone} sub={c.email} />
                                    </td>
                                    <td style={tdStyle}>
                                        <LocationCell value={c.zone} />
                                    </td>
                                    <td style={tdStyle}>{c.registered != null ? <StackCell main={`${c.registered} enregistrés`} sub={`${c.validated} validés`} /> : <TextCell muted>—</TextCell>}</td>
                                    <td style={tdStyle}>
                                        <TextCell muted={c.validationRate == null}>{c.validationRate != null ? `${c.validationRate} %` : '—'}</TextCell>
                                    </td>
                                    <td style={tdStyle}>
                                        <ProgressCell value={c.objective} />
                                    </td>
                                    <td style={tdStyle}>
                                        <TextCell muted={c.weeklyGain == null}>{c.weeklyGain != null ? fcfa(c.weeklyGain) : '—'}</TextCell>
                                    </td>
                                    <td style={tdStyle}>
                                        <StatusBadge label={c.performance.label} tone={c.performance.tone} />
                                    </td>
                                    <td style={tdStyle}>
                                        <StatusBadge label={c.availability.label} tone={c.availability.tone} />
                                    </td>
                                    <td style={tdStyle}>
                                        <StatusBadge label={c.status.label} tone={c.status.tone} icon={c.status.value === 'active' ? 'verified' : undefined} />
                                    </td>
                                    <td style={tdStyle}>
                                        <TextCell muted={!c.lastActivityOn}>{c.lastActivityOn ? formatDate(c.lastActivityOn) : '—'}</TextCell>
                                    </td>
                                    <RowActions
                                        actions={[
                                            { icon: 'visibility', label: 'Voir la fiche', onClick: open },
                                            ...(c.status.value !== 'archived' ? [{ icon: 'edit', label: 'Modifier', onClick: () => router.visit(`/commerciaux/${c.slug}/modifier`) }] : []),
                                        ]}
                                        menu={rowMenu(c)}
                                        menuOpen={menuFor === c.id}
                                        onToggleMenu={() => setMenuFor(menuFor === c.id ? null : c.id)}
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
                            <StateBlock kind="nodata" icon="badge" title="Aucun commercial enregistré" text="Créez un premier commercial pour recruter des ouvriers sur le terrain." actionLabel="Ajouter un commercial" onAction={() => router.visit('/commerciaux/nouveau')} />
                        </div>
                    ))}

                <Pagination page={agents.currentPage} lastPage={agents.lastPage} from={agents.from} to={agents.to} total={agents.total} onPage={(page) => apply({ page: String(page) }, true)} />
            </section>

            <ActionStrip
                title="Suivi des performances"
                icon="schedule"
                active={filters.rapide ?? null}
                activeLabel={filters.rapide ? QUICK_LABELS[filters.rapide] : undefined}
                ctaLabel={filters.rapide ? 'Afficher tous les commerciaux' : 'Voir les performances'}
                onCta={() => (filters.rapide ? applyQuick(null) : router.visit('/commerciaux/classement'))}
                onSelect={applyQuick}
                items={[
                    { key: 'objectifs', count: todo.objective, label: todo.objective > 1 ? 'objectifs atteints à date (prorata du mois)' : 'objectif atteint à date (prorata du mois)', icon: 'emoji_events', tone: 'a' },
                    { key: 'relances', count: todo.followUp, label: 'commerciaux à relancer (inactifs, performance faible ou compte non activé)', icon: 'person_alert', tone: 'r' },
                    { key: 'gains', count: todo.earnings, label: `gains de ${todo.previousMonth.toLowerCase()} à valider · ${fcfa(todo.pending)}`, icon: 'paid', tone: 'a' },
                ]}
            />

            {actions.dialog}
        </>
    );
}
