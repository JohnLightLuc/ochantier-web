import { AttendanceTable, PeriodBar, SectionNav, rowMenu } from '@/components/attendances/blocks';
import { useAttendanceActions } from '@/components/attendances/useAttendanceActions';
import { FilterSelect } from '@/components/list/ListToolbar';
import Dialog from '@/components/overlays/Dialog';
import Button from '@/components/ui/Button';
import Field, { TextInput } from '@/components/ui/Field';
import Icon from '@/components/ui/Icon';
import StatCard from '@/components/ui/StatCard';
import StateBlock from '@/components/ui/StateBlock';
import { Toolbar } from '@/components/workers/blocks';
import { useAdminUi } from '@/contexts/AdminUiContext';
import { currentTime } from '@/lib/format';
import type { AnyTone } from '@/types';
import type { AttendanceFilters, AttendanceIndexProps } from '@/types/attendances';
import { Head, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

type Saved = { name: string; query: AttendanceFilters };

const SAVED_KEY = 'och_pt_saved';

const DEFAULT_SAVED: Saved[] = [
    { name: 'Anomalies à traiter', query: { raccourci: 'anom', periode: 'semaine' } },
    { name: 'Retards de la semaine', query: { raccourci: 'late', periode: 'semaine' } },
];

function readSaved(): Saved[] {
    try {
        return JSON.parse(localStorage.getItem(SAVED_KEY) ?? 'null') ?? DEFAULT_SAVED;
    } catch {
        return DEFAULT_SAVED;
    }
}

/** Liste des pointages : période, raccourcis, filtres, tri, filtres enregistrés, sélection et actions groupées. */
export default function AttendancesIndex({ sections, period, periods, filters, shortcuts, filterDefs, filterCount, sorts, attendances, hasData, options }: AttendanceIndexProps) {
    const ui = useAdminUi();
    const { url } = usePage();
    const actions = useAttendanceActions(options);
    const base = url.split('?')[0];
    const [selected, setSelected] = useState<string[]>([]);
    const [saved, setSaved] = useState<Saved[]>(DEFAULT_SAVED);
    const [naming, setNaming] = useState<string | null>(null);
    const more = !!filters.plus;
    const active = Object.entries(filters).filter(([k, v]) => v && !['page', 'periode', 'du', 'au', 'tri', 'plus'].includes(k)).length;
    const rows = attendances.data;
    const shortcut = shortcuts.find((s) => s.key === period.shortcut);
    const chosen = rows.filter((r) => selected.includes(r.reference));

    useEffect(() => setSaved(readSaved()), []);

    const apply = (changes: AttendanceFilters, keepPage = false) => {
        const next: AttendanceFilters = { ...filters, ...changes, ...(keepPage ? {} : { page: undefined }) };
        router.get(base, Object.fromEntries(Object.entries(next).filter(([, v]) => v)), { preserveState: true, preserveScroll: true, replace: true });
    };

    const saveCurrent = () => {
        const name = (naming ?? '').trim();
        if (!name) return;
        const next = [...saved, { name, query: Object.fromEntries(Object.entries(filters).filter(([k, v]) => v && k !== 'page')) }];
        setSaved(next);
        try {
            localStorage.setItem(SAVED_KEY, JSON.stringify(next));
        } catch {
            // Stockage indisponible : le filtre reste disponible pour la session.
        }
        setNaming(null);
        ui.toast(`Filtre « ${name} » enregistré.`);
    };

    const toggle = (ref: string) => setSelected((s) => (s.includes(ref) ? s.filter((x) => x !== ref) : [...s, ref]));

    return (
        <>
            <Head title={period.key === 'jour' && !Object.keys(periods).length ? 'Pointages du jour' : 'Pointages'} />

            <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: -6 }}>
                <Button label="Actualiser" icon="refresh" variant="secondary" onClick={() => router.reload({ onSuccess: () => ui.toast(`Pointages actualisés (${currentTime()}).`) })} />
            </div>

            <div data-component="PointagesTop" style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)' }}>
                <SectionNav sections={sections} />
                <PeriodBar period={period} periods={periods} query={filters.q ?? ''} onChange={(c) => apply(c)} />
                <div data-component="StatGrid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(230px,1fr))', gap: 'var(--space-3)' }}>
                    {shortcuts
                        .filter((s) => s.kpi)
                        .map((s) => {
                            const on = period.shortcut === s.key;
                            return (
                                <button key={s.key} type="button" aria-pressed={on} onClick={() => apply({ raccourci: on ? undefined : s.key })} className="och-hover-card" style={{ display: 'block', minWidth: 0, padding: 0, border: 0, background: 'transparent', textAlign: 'left', borderRadius: 'var(--radius-2xl)', cursor: 'pointer' }}>
                                    <StatCard label={s.label} value={String(s.count)} icon={s.icon} tone={s.tone as AnyTone} hint={on ? 'Filtre actif · cliquer pour retirer' : 'Cliquer pour filtrer'} highlight={on} />
                                </button>
                            );
                        })}
                </div>
                <div role="group" aria-label="Filtres rapides" style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2)' }}>
                    {shortcuts
                        .filter((s) => !s.kpi)
                        .map((s) => {
                            const on = period.shortcut === s.key;
                            return (
                                <button key={s.key} type="button" aria-pressed={on} onClick={() => apply({ raccourci: on ? undefined : s.key })} style={{ display: 'inline-flex', alignItems: 'center', gap: 'var(--space-1_5)', height: 'var(--control-sm)', padding: '0 var(--space-1) 0 var(--space-3)', borderRadius: 'var(--radius-pill)', border: `1px solid ${on ? 'var(--c-primary)' : 'var(--c-line-strong)'}`, background: on ? 'var(--c-primary)' : 'var(--c-surface)', color: on ? 'var(--c-on-dark)' : 'var(--c-ink-2)', fontWeight: 'var(--fw-semibold)', fontSize: 'var(--fs-sm)', cursor: 'pointer' }}>
                                    <Icon name={s.icon} size="var(--icon-xs)" />
                                    {s.label}
                                    <span style={{ minWidth: 22, height: 22, padding: '0 var(--space-1_5)', borderRadius: 'var(--radius-pill)', display: 'inline-grid', placeItems: 'center', background: on ? 'var(--c-surface)' : 'var(--c-surface-3)', color: on ? 'var(--c-primary)' : 'var(--c-ink-2)', fontSize: 'var(--fs-xs)' }}>{s.count}</span>
                                </button>
                            );
                        })}
                </div>
            </div>

            <section style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)', background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-5)', minWidth: 0 }}>
                <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2)' }}>
                    {filterDefs.map((f) => (
                        <FilterSelect key={f.key} label={f.label} value={filters[f.key] ?? ''} options={f.options.map((o) => ({ value: o, label: o }))} onChange={(v) => apply({ [f.key]: v || undefined })} />
                    ))}
                    <FilterSelect label="Trier : date" value={filters.tri && filters.tri !== 'date' ? filters.tri : ''} options={Object.entries(sorts).filter(([k]) => k !== 'date').map(([value, label]) => ({ value, label: `Trier : ${label.toLowerCase()}` }))} onChange={(v) => apply({ tri: v || undefined }, true)} />
                </div>
                <Toolbar
                    actions={[
                        { label: more ? 'Moins de filtres' : `Plus de filtres (${filterCount})`, icon: 'tune', onClick: () => apply({ plus: more ? undefined : '1' }, true) },
                        { label: `Réinitialiser les filtres${active ? ` (${active})` : ''}`, icon: 'filter_alt_off', onClick: () => router.get(base, period.key !== 'jour' ? { periode: period.key } : {}, { preserveScroll: true }) },
                        { label: 'Enregistrer le filtre', icon: 'bookmark_add', onClick: () => (active ? setNaming('') : ui.toast('Aucun filtre actif à enregistrer.', { tone: 'error' })) },
                        ...saved.map((s) => ({ label: `★ ${s.name}`, icon: 'bookmark', onClick: () => router.get(base, s.query as Record<string, string>, { preserveScroll: true }) })),
                        { label: 'Exporter', icon: 'download', onClick: () => (window.location.href = `/pointages/export${url.includes('?') ? url.slice(url.indexOf('?')) : ''}`) },
                        ...(chosen.length
                            ? [
                                  { label: `Relancer les promoteurs (${chosen.length})`, icon: 'notifications_active', variant: 'primary' as const, onClick: () => actions.remind(chosen.map((r) => r.reference)) },
                                  { label: `Clôturer (${chosen.length})`, icon: 'lock', onClick: () => actions.closeDays(chosen) },
                                  { label: 'Exporter la sélection', icon: 'download', onClick: () => (window.location.href = `/pointages/export?refs=${chosen.map((r) => r.reference).join(',')}`) },
                                  { label: 'Vider la sélection', icon: 'close', onClick: () => setSelected([]) },
                              ]
                            : [{ label: 'Sélectionner la page', icon: 'select_all', onClick: () => setSelected(rows.map((r) => r.reference)) }]),
                    ]}
                />

                {!hasData ? (
                    <StateBlock kind="nodata" icon="info" title="Aucune donnée" text="Aucun pointage n’a encore été enregistré par les promoteurs." />
                ) : (
                    <AttendanceTable
                        icon="event_note"
                        title={`Pointages · ${period.label}${shortcut ? ` · ${shortcut.label.toLowerCase()}` : ''}`}
                        count={`${attendances.from}–${attendances.to} sur ${attendances.total}${chosen.length ? ` · ${chosen.length} sélectionné(s)` : ''}`}
                        rows={rows}
                        page={attendances}
                        onPage={(p) => apply({ page: String(p) }, true)}
                        selected={selected}
                        onToggle={toggle}
                        menu={(r) => rowMenu(r, actions)}
                        empty={{ icon: 'search_off', title: 'Aucun résultat', text: 'Aucun pointage ne correspond à la recherche ou aux filtres.' }}
                        minWidth={1680}
                    />
                )}
            </section>

            {naming !== null && (
                <Dialog title="Enregistrer le filtre" icon="bookmark_add" okLabel="Enregistrer" onClose={() => setNaming(null)} onSubmit={saveCurrent}>
                    <Field label="Nom du filtre" required>
                        <TextInput value={naming} onChange={(e) => setNaming(e.target.value)} autoFocus />
                    </Field>
                </Dialog>
            )}
            {actions.dialog}
        </>
    );
}
