import { FilterSelect } from '@/components/list/ListToolbar';
import { TileGrid, Toolbar, type ToolbarAction } from '@/components/workers/blocks';
import type { AttendanceFilters, SectionProps } from '@/types/attendances';
import { router, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { PeriodBar, SectionNav } from './blocks';

type Props = SectionProps & { toolbar?: ToolbarAction[]; before?: ReactNode; children: ReactNode; search?: boolean };

/** Applique des filtres à la page courante (la page revient à 1). */
export function useApply(filters: AttendanceFilters) {
    const { url } = usePage();
    const base = url.split('?')[0];

    return (changes: AttendanceFilters, keepPage = false) => {
        const next: AttendanceFilters = { ...filters, ...changes, ...(keepPage ? {} : { page: undefined }) };
        router.get(base, Object.fromEntries(Object.entries(next).filter(([, v]) => v)), { preserveState: true, preserveScroll: true, replace: true });
    };
}

/** Requête courante (filtres actifs) à reprendre dans un lien d’export. */
export const queryOf = (filters: AttendanceFilters) => new URLSearchParams(Object.entries(filters).filter(([k, v]) => v && k !== 'page') as [string, string][]).toString();

/** Mise en page d’une sous-page du module : navigation, période et recherche, tuiles, filtres, actions puis contenu. */
export default function SectionLayout({ sections, period, periods, filters, filterDefs = [], tiles, toolbar, before, children, search = true }: Props) {
    const apply = useApply(filters);

    return (
        <>
            <div data-component="PointagesTop" style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)' }}>
                <SectionNav sections={sections} />
                <PeriodBar period={period} periods={periods} query={search ? (filters.q ?? '') : undefined} onChange={(c) => apply(c)} />
            </div>
            {tiles && <TileGrid tiles={tiles} />}
            <section style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)', background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-5)', minWidth: 0 }}>
                {(filterDefs.length > 0 || toolbar?.length) && (
                    <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2)', alignItems: 'center' }}>
                        {filterDefs.map((f) => (
                            <FilterSelect key={f.key} label={f.label} value={filters[f.key] ?? ''} options={f.options.map((o) => ({ value: o, label: o }))} onChange={(v) => apply({ [f.key]: v || undefined })} />
                        ))}
                        <span style={{ flex: 1 }} />
                        {toolbar && <Toolbar actions={toolbar} />}
                    </div>
                )}
                {before}
                {children}
            </section>
        </>
    );
}
