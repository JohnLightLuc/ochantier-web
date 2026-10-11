import { SearchInput } from '@/components/list/ListToolbar';
import { Checkbox, PersonCell, RowActions, StackCell, TableFrame, TextCell, tdStyle, thStyle, type RowAction } from '@/components/list/Table';
import Pagination from '@/components/list/Pagination';
import Icon from '@/components/ui/Icon';
import type { MenuItem } from '@/components/ui/Menu';
import StateBlock from '@/components/ui/StateBlock';
import StatusBadge from '@/components/ui/StatusBadge';
import { Block } from '@/components/workers/blocks';
import { formatDate } from '@/lib/format';
import type { AnyTone } from '@/types';
import type { AttendanceRow, GridRow, Paginated, PeriodInfo, Section } from '@/types/attendances';
import { Link, router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';

const pill = (on: boolean) => ({
    display: 'inline-flex',
    alignItems: 'center',
    gap: 'var(--space-1_5)',
    height: 'var(--control-sm)',
    padding: '0 var(--space-3)',
    borderRadius: 'var(--radius-pill)',
    border: `1px solid ${on ? 'var(--c-primary-400)' : 'var(--c-line-strong)'}`,
    background: on ? 'var(--c-primary-50)' : 'var(--c-surface)',
    color: on ? 'var(--c-primary)' : 'var(--c-ink-2)',
    fontWeight: 'var(--fw-semibold)',
    fontSize: 'var(--fs-sm)',
    cursor: 'pointer',
    textDecoration: 'none',
    whiteSpace: 'nowrap' as const,
});

/** Navigation entre les sections du module. */
export function SectionNav({ sections }: { sections: Section[] }) {
    return (
        <nav aria-label="Sections du module Pointages" style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2)' }}>
            {sections.map((s) => (
                <Link key={s.href} href={s.href} aria-current={s.active ? 'page' : undefined} className="och-hover-line" style={pill(s.active)}>
                    <Icon name={s.icon} size="var(--icon-xs)" />
                    {s.label}
                </Link>
            ))}
        </nav>
    );
}

type PeriodBarProps = {
    period: PeriodInfo;
    periods: Record<string, string>;
    query?: string;
    onChange: (changes: Record<string, string | undefined>) => void;
};

/** Période (aujourd’hui, hier, semaine, mois, personnalisée), libellé des bornes et recherche. */
export function PeriodBar({ period, periods, query, onChange }: PeriodBarProps) {
    const keys = Object.keys(periods);

    return (
        <div style={{ display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: 'var(--space-2)' }}>
            {keys.map((k) => {
                const on = period.key === k;
                return (
                    <button key={k} type="button" aria-pressed={on} onClick={() => onChange({ periode: k, du: undefined, au: undefined })} style={{ ...pill(false), border: `1px solid ${on ? 'var(--c-primary)' : 'var(--c-line-strong)'}`, background: on ? 'var(--c-primary)' : 'var(--c-surface)', color: on ? 'var(--c-on-dark)' : 'var(--c-ink-2)' }}>
                        {periods[k]}
                    </button>
                );
            })}
            {period.key === 'perso' && (
                <>
                    {(['du', 'au'] as const).map((k) => (
                        <label key={k} style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-1_5)', fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>
                            {k === 'du' ? 'Du' : 'au'}
                            <input type="date" className="och-input" value={k === 'du' ? period.from : period.to} aria-label={k === 'du' ? 'Date de début' : 'Date de fin'} onChange={(e) => onChange({ periode: 'perso', [k]: e.target.value })} style={{ height: 'var(--control-sm)', padding: '0 var(--space-3)', border: '1px solid var(--c-line-strong)', borderRadius: 'var(--radius-md)', background: 'var(--c-surface)', font: 'inherit', fontSize: 'var(--fs-sm)' }} />
                        </label>
                    ))}
                </>
            )}
            <span style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)', fontWeight: 'var(--fw-semibold)' }}>{period.label}</span>
            <span style={{ flex: 1 }} />
            {query !== undefined && (
                <div style={{ minWidth: 'min(100%, 340px)', display: 'flex' }}>
                    <SearchInput value={query} placeholder="Ouvrier, identifiant, téléphone, chantier, promoteur, référence…" onChange={(q) => onChange({ q: q || undefined })} />
                </div>
            )}
        </div>
    );
}

export type ColumnKey = 'select' | 'site' | 'worker';

type TableProps = {
    icon: string;
    title: string;
    count: string;
    rows: AttendanceRow[];
    page?: Paginated<AttendanceRow>;
    onPage?: (page: number) => void;
    hide?: ColumnKey[];
    selected?: string[];
    onToggle?: (ref: string) => void;
    /** Dernière colonne : indicateurs (par défaut) ou motifs d’incomplétude. */
    lastColumn?: { label: string; value: (r: AttendanceRow) => string };
    actions?: (r: AttendanceRow) => RowAction[];
    menu?: (r: AttendanceRow) => MenuItem[];
    tools?: ReactNode;
    empty: { icon: string; title: string; text: string };
    minWidth?: number;
};

/** Tableau des journées de pointage (colonnes de la maquette), avec sélection, actions et pagination. */
export function AttendanceTable({ icon, title, count, rows, page, onPage, hide = [], selected, onToggle, lastColumn, actions, menu, tools, empty, minWidth = 1600 }: TableProps) {
    const [menuFor, setMenuFor] = useState<string | null>(null);
    const show = (k: ColumnKey) => !hide.includes(k);
    const selectable = !!onToggle && show('select');
    const columns = ['Date', ...(show('worker') ? ['Ouvrier'] : []), ...(show('site') ? ['Chantier · affectation'] : []), 'Arrivée prévue', 'Arrivée enregistrée', 'Départ prévu', 'Départ enregistré', 'Présence nette', 'Retard', 'Heures sup.', 'Journée', lastColumn?.label ?? 'Indicateurs'];
    const open = (r: AttendanceRow) => router.visit(`/pointages/${r.reference}`);

    return (
        <Block icon={icon} title={title} count={count}>
            {tools}
            {rows.length === 0 ? (
                <StateBlock kind="nodata" icon={empty.icon} title={empty.title} text={empty.text} />
            ) : (
                <TableFrame minWidth={minWidth}>
                    <thead>
                        <tr style={{ background: 'var(--c-surface-3)' }}>
                            {selectable && <th style={{ ...thStyle, width: 40 }} />}
                            {columns.map((c) => (
                                <th key={c} style={thStyle}>
                                    {c}
                                </th>
                            ))}
                            <th style={{ ...thStyle, textAlign: 'center' }}>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((r) => {
                            const last = lastColumn ? lastColumn.value(r) : r.indicators.join(' · ');
                            return (
                                <tr key={r.reference} onClick={() => open(r)} className="och-hover-soft" style={{ cursor: 'pointer', background: selected?.includes(r.reference) ? 'var(--c-primary-50)' : 'var(--c-surface)' }}>
                                    {selectable && (
                                        <td style={tdStyle}>
                                            <Checkbox checked={!!selected?.includes(r.reference)} onToggle={() => onToggle!(r.reference)} label={`Sélectionner ${r.reference}`} />
                                        </td>
                                    )}
                                    <td style={tdStyle}>
                                        <TextCell strong>
                                            {formatDate(r.date)}
                                            {r.night && <Icon name="dark_mode" size="var(--icon-xs)" color="var(--c-purple-fg)" style={{ marginLeft: 4, verticalAlign: 'middle' }} />}
                                        </TextCell>
                                    </td>
                                    {show('worker') && (
                                        <td style={tdStyle}>
                                            <PersonCell name={r.worker.name} sub={`${r.worker.code} · ${r.worker.trade}`} />
                                        </td>
                                    )}
                                    {show('site') && (
                                        <td style={tdStyle}>
                                            <StackCell main={r.site.name} sub={`${r.site.promoter} · ${r.assignment}`} />
                                        </td>
                                    )}
                                    <td style={tdStyle}>
                                        <TextCell>{r.plannedStart}</TextCell>
                                    </td>
                                    <td style={tdStyle}>
                                        <StackCell main={r.arrival.time ?? '—'} sub={r.arrival.status} />
                                    </td>
                                    <td style={tdStyle}>
                                        <TextCell>{r.plannedEnd + (r.night ? ' (J+1)' : '')}</TextCell>
                                    </td>
                                    <td style={tdStyle}>
                                        <StackCell main={r.departure.time ?? '—'} sub={r.departure.status} />
                                    </td>
                                    <td style={tdStyle}>
                                        <TextCell strong={r.hasNet}>{r.net}</TextCell>
                                    </td>
                                    <td style={tdStyle}>
                                        <TextCell muted={!r.late}>{r.late ?? '—'}</TextCell>
                                    </td>
                                    <td style={tdStyle}>
                                        <TextCell muted={!r.overtime}>{r.overtime ?? '—'}</TextCell>
                                    </td>
                                    <td style={tdStyle}>
                                        <StatusBadge label={r.status.label} tone={r.status.tone} />
                                    </td>
                                    <td style={{ ...tdStyle, minWidth: 180 }}>
                                        <span style={{ fontSize: 'var(--fs-sm)', color: last ? 'var(--c-ink-2)' : 'var(--c-muted)', fontWeight: lastColumn && last ? 600 : 400 }}>{last || '—'}</span>
                                    </td>
                                    <RowActions
                                        actions={actions ? actions(r) : [{ icon: 'visibility', label: 'Consulter', onClick: () => open(r) }]}
                                        menu={menu?.(r)}
                                        menuOpen={menuFor === r.reference}
                                        onToggleMenu={menu ? () => setMenuFor(menuFor === r.reference ? null : r.reference) : undefined}
                                    />
                                </tr>
                            );
                        })}
                    </tbody>
                </TableFrame>
            )}
            {page && onPage && page.lastPage > 1 && <Pagination page={page.page} lastPage={page.lastPage} from={page.from} to={page.to} total={page.total} onPage={onPage} />}
        </Block>
    );
}

/** Menu standard d’une ligne de pointage. */
export function rowMenu(r: AttendanceRow, actions: { remind: (refs: string[]) => void; correction: (ref: string) => void; note: (ref: string) => void; reportAnomaly: (ref: string) => void; closeDays: (t: { reference: string; closable: boolean; closed: boolean }[], exceptional?: boolean) => void }, extra: MenuItem[] = []): MenuItem[] {
    return [
        { icon: 'notifications_active', label: 'Relancer le promoteur', onClick: () => actions.remind([r.reference]) },
        { icon: 'edit_note', label: 'Demander une correction', onClick: () => actions.correction(r.reference) },
        { icon: 'sticky_note_2', label: 'Ajouter une note interne', onClick: () => actions.note(r.reference) },
        { icon: 'report', label: 'Signaler une anomalie', onClick: () => actions.reportAnomaly(r.reference) },
        ...extra,
        ...(r.closed ? [] : [{ icon: 'lock', label: r.closable ? 'Clôturer' : 'Clôturer exceptionnellement', danger: !r.closable, onClick: () => actions.closeDays([r], !r.closable) }]),
        { icon: 'person', label: 'Pointages de l’ouvrier', onClick: () => router.visit(`/pointages/ouvriers/${r.worker.slug}`) },
        { icon: 'construction', label: 'Pointages du chantier', onClick: () => router.visit(`/pointages/chantiers/${r.site.slug}`) },
    ];
}

type GridProps = { title: string; days: string[]; grid: GridRow[]; legend: string; minWidth?: number };

/** Grille de présence : un ouvrier par ligne, un code par jour (P, P…, A, ?, R, ·) et le total d’heures nettes. */
export function PresenceGrid({ title, days, grid, legend }: GridProps) {
    return (
        <Block icon="calendar_month" title={title} count={legend}>
            {grid.length === 0 ? (
                <StateBlock kind="nodata" icon="event_busy" title="Aucune donnée" text="Aucun pointage sur la période." />
            ) : (
                <TableFrame minWidth={280 + days.length * 92}>
                    <thead>
                        <tr style={{ background: 'var(--c-surface-3)' }}>
                            <th style={thStyle}>Ouvrier</th>
                            {days.map((d) => (
                                <th key={d} style={{ ...thStyle, textAlign: 'center' }}>
                                    {d.slice(8, 10)}/{d.slice(5, 7)}
                                </th>
                            ))}
                            <th style={thStyle}>Heures nettes</th>
                        </tr>
                    </thead>
                    <tbody>
                        {grid.map((r) => (
                            <tr key={r.worker.slug} onClick={() => router.visit(`/pointages/ouvriers/${r.worker.slug}`)} className="och-hover-soft" style={{ cursor: 'pointer', background: 'var(--c-surface)' }}>
                                <td style={tdStyle}>
                                    <PersonCell name={r.worker.name} sub={r.worker.sub} />
                                </td>
                                {r.cells.map((c, i) => (
                                    <td key={days[i]} style={{ ...tdStyle, textAlign: 'center' }}>
                                        {c ? (
                                            <span onClick={(e) => { e.stopPropagation(); router.visit(`/pointages/${c.reference}`); }} title={c.reference} style={{ cursor: 'pointer' }}>
                                                <StatusBadge label={c.code} tone={c.tone as AnyTone} />
                                            </span>
                                        ) : (
                                            <TextCell muted>—</TextCell>
                                        )}
                                    </td>
                                ))}
                                <td style={tdStyle}>
                                    <TextCell strong>{r.net}</TextCell>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </TableFrame>
            )}
        </Block>
    );
}
