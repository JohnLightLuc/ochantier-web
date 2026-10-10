import { RowActions, TableFrame, tdStyle, thStyle, type RowAction } from '@/components/list/Table';
import Button, { type ButtonVariant } from '@/components/ui/Button';
import Icon from '@/components/ui/Icon';
import type { MenuItem } from '@/components/ui/Menu';
import StateBlock from '@/components/ui/StateBlock';
import { router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';

export type ToolbarAction = { label: string; icon: string; variant?: ButtonVariant; onClick: () => void };

type AlertItem = { tone: string; icon: string; title: string; text: string; cta?: string | null; onAction?: () => void };

/** Bandeaux d’alerte en tête d’onglet. */
export function AlertList({ alerts }: { alerts: AlertItem[] }) {
    if (!alerts.length) return null;

    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-2)' }}>
            {alerts.map((a, i) => (
                <div key={i} role="alert" style={{ display: 'flex', gap: 'var(--space-3)', alignItems: 'center', flexWrap: 'wrap', padding: 'var(--space-3) var(--space-4)', borderRadius: 'var(--radius-lg)', background: `var(--c-${a.tone}-bg)`, color: `var(--c-${a.tone}-fg)` }}>
                    <Icon name={a.icon} />
                    <span style={{ flex: 1, minWidth: 200, fontSize: 'var(--fs-md)' }}>
                        <b>{a.title}</b> · {a.text}
                    </span>
                    {a.cta && a.onAction && (
                        <button type="button" onClick={a.onAction} style={{ border: 0, background: 'transparent', padding: 0, color: 'inherit', fontWeight: 'var(--fw-bold)', cursor: 'pointer', textDecoration: 'underline', textUnderlineOffset: 2 }}>
                            {a.cta}
                        </button>
                    )}
                </div>
            ))}
        </div>
    );
}

export type Tile = { label: string; value: string; icon: string; tone: string; bar?: number; barColor?: string; sub?: string };

/** Grille de petits indicateurs. */
export function TileGrid({ tiles }: { tiles: Tile[] }) {
    return (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,180px),1fr))', gap: 'var(--space-3)' }}>
            {tiles.map((t) => (
                <div key={t.label} style={{ padding: 'var(--space-3) var(--space-4)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-lg)', minWidth: 0, display: 'flex', flexDirection: 'column', gap: 'var(--space-1)', background: 'var(--c-surface)' }}>
                    <span style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-1_5)', fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>
                        <Icon name={t.icon} size="var(--icon-xs)" color={`var(--c-${t.tone}-fg)`} />
                        {t.label}
                    </span>
                    <b style={{ fontSize: 'var(--fs-xl)', fontVariantNumeric: 'tabular-nums', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{t.value}</b>
                    {t.bar != null && (
                        <span style={{ display: 'block', height: 6, borderRadius: 'var(--radius-pill)', background: 'var(--c-line-soft)', overflow: 'hidden' }}>
                            <span style={{ display: 'block', height: '100%', width: `${Math.min(100, t.bar)}%`, background: t.barColor ?? 'var(--c-primary-500)' }} />
                        </span>
                    )}
                    {t.sub && <span style={{ fontSize: 'var(--fs-xs)', color: 'var(--c-muted)' }}>{t.sub}</span>}
                </div>
            ))}
        </div>
    );
}

type BlockProps = {
    icon: string;
    title: string;
    count?: string;
    action?: ToolbarAction | null;
    tools?: ToolbarAction[];
    full?: boolean;
    children: ReactNode;
};

/** Bloc encadré d’un onglet : icône, titre, compteur, actions. */
export function Block({ icon, title, count, action, tools, full, children }: BlockProps) {
    return (
        <div style={{ gridColumn: full ? '1 / -1' : 'auto', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-xl)', padding: 'var(--space-4)', minWidth: 0 }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2) var(--space-2_5)', marginBottom: 'var(--space-3)', flexWrap: 'wrap' }}>
                <Icon name={icon} size="var(--icon-md)" color="var(--c-primary)" />
                <h3 style={{ margin: 0, fontSize: 'var(--fs-xl)', flex: count ? undefined : 1, minWidth: 0 }}>{title}</h3>
                {count && <span style={{ fontSize: 'var(--fs-md)', color: 'var(--c-muted)', flex: 1 }}>{count}</span>}
                {action && <Button label={action.label} icon={action.icon} size="sm" variant={action.variant ?? 'secondary'} onClick={action.onClick} />}
                {tools?.map((t) => (
                    <Button key={t.label} label={t.label} icon={t.icon} size="sm" variant={t.variant ?? 'secondary'} onClick={t.onClick} />
                ))}
            </div>
            {children}
        </div>
    );
}

/** Lignes « libellé · valeur » sur deux colonnes. */
export function KeyValues({ items }: { items: [string, ReactNode][] }) {
    return (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,260px),1fr))', gap: '0 var(--space-6)' }}>
            {items.map(([k, v]) => (
                <div key={k} style={{ display: 'flex', gap: 'var(--space-3)', padding: 'var(--space-2_5) 0', borderTop: '1px solid var(--c-line-soft)' }}>
                    <span style={{ width: 160, flex: 'none', color: 'var(--c-muted)', fontSize: 'var(--fs-md)' }}>{k}</span>
                    <span style={{ flex: 1, minWidth: 0, fontWeight: 'var(--fw-semibold)', overflowWrap: 'anywhere' }}>{v || '—'}</span>
                </div>
            ))}
        </div>
    );
}

/** Pastilles supprimables. */
export function Chips({ values, onRemove, empty = 'Aucune valeur renseignée.' }: { values: string[]; onRemove?: (value: string) => void; empty?: string }) {
    if (!values.length) return <span style={{ fontSize: 'var(--fs-md)', color: 'var(--c-muted)' }}>{empty}</span>;

    return (
        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2)' }}>
            {values.map((v) => (
                <span key={v} style={{ display: 'inline-flex', alignItems: 'center', gap: 'var(--space-1)', height: 30, padding: `0 ${onRemove ? 'var(--space-1)' : 'var(--space-3)'} 0 var(--space-3)`, borderRadius: 'var(--radius-pill)', background: 'var(--c-primary-50)', border: '1px solid var(--c-primary-200)', color: 'var(--c-primary)', fontSize: 'var(--fs-sm)', fontWeight: 'var(--fw-semibold)' }}>
                    {v}
                    {onRemove && (
                        <button type="button" onClick={() => onRemove(v)} aria-label={`Supprimer ${v}`} style={{ width: 24, height: 24, border: 0, borderRadius: 'var(--radius-round)', background: 'transparent', color: 'inherit', cursor: 'pointer', display: 'grid', placeItems: 'center' }}>
                            <Icon name="close" size="var(--icon-xs)" />
                        </button>
                    )}
                </span>
            ))}
        </div>
    );
}

export type TableRow = { key: string; cells: ReactNode[]; onOpen?: () => void; actions: RowAction[]; menu?: MenuItem[] };

type DataTableProps = {
    icon: string;
    title: string;
    count: string;
    columns: string[];
    rows: TableRow[];
    minWidth?: number;
    tools?: ToolbarAction[];
    empty: { icon: string; title: string; text: string };
};

/** Tableau titré d’un onglet, avec actions par ligne. */
export function DataTable({ icon, title, count, columns, rows, minWidth = 760, tools, empty }: DataTableProps) {
    const [menuFor, setMenuFor] = useState<string | null>(null);

    return (
        <Block icon={icon} title={title} count={count} tools={tools}>
            {rows.length === 0 ? (
                <StateBlock kind="nodata" icon={empty.icon} title={empty.title} text={empty.text} />
            ) : (
                <TableFrame minWidth={minWidth}>
                    <thead>
                        <tr style={{ background: 'var(--c-surface-3)' }}>
                            {columns.map((c) => (
                                <th key={c} style={thStyle}>
                                    {c}
                                </th>
                            ))}
                            <th style={{ ...thStyle, textAlign: 'center' }}>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((r) => (
                            <tr key={r.key} onClick={r.onOpen} className="och-hover-soft" style={{ cursor: r.onOpen ? 'pointer' : 'default', background: 'var(--c-surface)' }}>
                                {r.cells.map((cell, i) => (
                                    <td key={i} style={tdStyle}>
                                        {cell}
                                    </td>
                                ))}
                                <RowActions actions={r.actions} menu={r.menu?.length ? r.menu : undefined} menuOpen={menuFor === r.key} onToggleMenu={r.menu?.length ? () => setMenuFor(menuFor === r.key ? null : r.key) : undefined} />
                            </tr>
                        ))}
                    </tbody>
                </TableFrame>
            )}
        </Block>
    );
}

/** Rangée de boutons d’action alignés à droite. */
export function Toolbar({ actions }: { actions: ToolbarAction[] }) {
    if (!actions.length) return null;

    return (
        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2)', justifyContent: 'flex-end' }}>
            {actions.map((a) => (
                <Button key={a.label} label={a.label} icon={a.icon} size="sm" variant={a.variant ?? 'secondary'} onClick={a.onClick} />
            ))}
        </div>
    );
}

/** Envoi POST sans perdre la position de défilement. */
export const postAction = (url: string, data: Record<string, unknown> = {}) => router.post(url, data as Record<string, string>, { preserveScroll: true });
