import Avatar from '@/components/ui/Avatar';
import Icon from '@/components/ui/Icon';
import Menu, { type MenuItem } from '@/components/ui/Menu';
import { hash } from '@/lib/tone';
import type { CSSProperties, MouseEvent, ReactNode } from 'react';

export const thStyle: CSSProperties = { padding: 'var(--space-2_5) var(--space-3)', fontWeight: 'var(--fw-semibold)', fontSize: 'var(--fs-sm)', color: 'var(--c-ink-2)', whiteSpace: 'nowrap', textAlign: 'left' };
export const tdStyle: CSSProperties = { padding: 'var(--space-3)', borderTop: '1px solid var(--c-line-soft)', verticalAlign: 'middle' };

/** Conteneur de tableau à défilement horizontal. */
export function TableFrame({ children, minWidth }: { children: ReactNode; minWidth?: number }) {
    return (
        <div style={{ overflowX: 'auto', border: '1px solid var(--c-line-soft)', borderRadius: 'var(--radius-lg)' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 'var(--fs-md)', minWidth }}>{children}</table>
        </div>
    );
}

export type SortDirection = 'asc' | 'desc';

type SortableProps = {
    label: string;
    /** null : colonne non triable. */
    sortKey: string | null;
    current: { key?: string; direction?: SortDirection };
    onSort: (key: string | null, direction: SortDirection) => void;
};

/** En-tête de colonne triable : croissant, décroissant, puis aucun tri. */
export function SortableTh({ label, sortKey, current, onSort }: SortableProps) {
    const on = !!sortKey && current.key === sortKey;
    const direction = on ? current.direction ?? 'asc' : null;

    if (!sortKey) return <th style={thStyle}>{label}</th>;

    return (
        <th aria-sort={direction === 'asc' ? 'ascending' : direction === 'desc' ? 'descending' : 'none'} style={thStyle}>
            <button
                type="button"
                onClick={() => (direction === 'asc' ? onSort(sortKey, 'desc') : direction === 'desc' ? onSort(null, 'asc') : onSort(sortKey, 'asc'))}
                style={{ border: 0, background: 'transparent', padding: 0, display: 'inline-flex', alignItems: 'center', gap: 'var(--space-0_5)', font: 'inherit', color: 'inherit', cursor: 'pointer' }}
            >
                {label}
                <Icon name={direction === 'asc' ? 'arrow_upward' : direction === 'desc' ? 'arrow_downward' : 'unfold_more'} size="var(--icon-xs)" color={on ? 'var(--c-primary)' : 'var(--c-subtle)'} />
            </button>
        </th>
    );
}

/** Case à cocher carrée de la maquette. */
export function Checkbox({ checked, onToggle, label }: { checked: boolean; onToggle: () => void; label: string }) {
    return (
        <button
            type="button"
            role="checkbox"
            aria-checked={checked}
            aria-label={label}
            onClick={(e) => {
                e.stopPropagation();
                onToggle();
            }}
            style={{ width: 18, height: 18, borderRadius: 'var(--radius-sm)', padding: 0, display: 'grid', placeItems: 'center', cursor: 'pointer', border: `1.5px solid ${checked ? 'var(--c-primary)' : 'var(--c-line-hover)'}`, background: checked ? 'var(--c-primary)' : 'var(--c-surface)' }}
        >
            {checked && <Icon name="check" size="var(--icon-xs)" color="var(--c-surface)" />}
        </button>
    );
}

export type RowAction = { icon: string; label: string; onClick: () => void };

type RowActionsProps = {
    actions: RowAction[];
    menu?: MenuItem[];
    menuOpen?: boolean;
    onToggleMenu?: () => void;
};

/** Cellule d’actions : boutons-icônes et menu « Plus d’actions ». */
export function RowActions({ actions, menu, menuOpen, onToggleMenu }: RowActionsProps) {
    const stop = (fn: () => void) => (e: MouseEvent) => {
        e.stopPropagation();
        fn();
    };
    const button: CSSProperties = { width: 'var(--control-xs)', height: 'var(--control-xs)', border: 0, borderRadius: 'var(--radius-md)', background: 'transparent', cursor: 'pointer', display: 'grid', placeItems: 'center', color: 'var(--c-ink-2)' };

    return (
        <td style={{ ...tdStyle, position: 'relative', padding: 'var(--space-2)' }}>
            <div style={{ display: 'flex', justifyContent: 'center', gap: 'var(--space-0_5)' }}>
                {actions.map((a) => (
                    <button key={a.label} type="button" onClick={stop(a.onClick)} title={a.label} aria-label={a.label} className="och-hover-icon" style={button}>
                        <Icon name={a.icon} outlined />
                    </button>
                ))}
                {menu && onToggleMenu && (
                    <button type="button" onClick={stop(onToggleMenu)} title="Plus d’actions" aria-label="Plus d’actions" aria-expanded={menuOpen} className="och-hover-icon" style={button}>
                        <Icon name="more_vert" />
                    </button>
                )}
            </div>
            {menu && menuOpen && onToggleMenu && <Menu items={menu} onClose={onToggleMenu} />}
        </td>
    );
}

/** Personne : avatar à initiales, nom et sous-titre. */
export function PersonCell({ name, sub, kind }: { name: string; sub?: string; kind?: 'person' | 'team' }) {
    return (
        <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2_5)' }}>
            <Avatar name={name} kind={kind} />
            <div>
                <div style={{ fontWeight: 'var(--fw-semibold)', whiteSpace: 'nowrap' }}>{name}</div>
                {sub && <div style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)', whiteSpace: 'nowrap' }}>{sub}</div>}
            </div>
        </div>
    );
}

/** Texte principal et ligne secondaire. */
export function StackCell({ main, sub, strong, color }: { main: string; sub?: string; strong?: boolean; color?: string }) {
    return (
        <div>
            <div style={{ whiteSpace: 'nowrap', fontWeight: strong ? 600 : 500, color: color ?? 'var(--c-ink)' }}>{main}</div>
            {sub && <div style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)', whiteSpace: 'nowrap' }}>{sub}</div>}
        </div>
    );
}

export function LocationCell({ value }: { value: string }) {
    return (
        <span style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-1)', whiteSpace: 'nowrap' }}>
            <Icon name="location_on" size="var(--icon-xs)" color="var(--c-primary-600)" />
            {value}
        </span>
    );
}

export function ProgressCell({ value }: { value: number }) {
    return (
        <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2_5)' }}>
            <span style={{ width: 38, fontWeight: 'var(--fw-semibold)', fontVariantNumeric: 'tabular-nums' }}>{value} %</span>
            <div style={{ width: 96, height: 7, borderRadius: 'var(--radius-xs)', background: 'var(--c-line-soft)', overflow: 'hidden' }}>
                <div style={{ height: '100%', borderRadius: 'var(--radius-xs)', width: `${value}%`, background: value < 50 ? 'var(--c-accent-600)' : 'var(--c-primary-500)' }} />
            </div>
        </div>
    );
}

export function RatingCell({ value }: { value: string }) {
    return (
        <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-1_5)', whiteSpace: 'nowrap' }}>
            <Icon name="star" color="var(--c-accent-600)" />
            <b>{value}</b>
        </div>
    );
}

const THUMBS = [
    ['var(--c-primary-100)', 'var(--c-primary-600)'],
    ['var(--c-warning-bg)', 'var(--c-warning-fg)'],
    ['var(--c-info-bg)', 'var(--c-info-fg)'],
    ['var(--c-purple-bg)', 'var(--c-purple-fg)'],
];

/** Vignette colorée (chantier) et nom. */
export function ThumbCell({ value }: { value: string }) {
    const [bg, fg] = THUMBS[hash(value) % THUMBS.length];
    return (
        <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2_5)' }}>
            <div style={{ width: 44, height: 36, flex: 'none', borderRadius: 'var(--radius-sm)', display: 'grid', placeItems: 'center', background: bg }}>
                <Icon name="apartment" color={fg} />
            </div>
            <span style={{ fontWeight: 'var(--fw-semibold)', whiteSpace: 'nowrap' }}>{value}</span>
        </div>
    );
}

/** Texte de cellule (gras, discret, lien). */
export function TextCell({ children, strong, muted, link }: { children: ReactNode; strong?: boolean; muted?: boolean; link?: boolean }) {
    return (
        <span style={{ whiteSpace: 'nowrap', fontVariantNumeric: 'tabular-nums', fontWeight: strong ? 700 : 400, color: link ? 'var(--c-primary-600)' : muted ? 'var(--c-muted)' : 'var(--c-ink)', textDecoration: link ? 'underline' : 'none', textUnderlineOffset: 2 }}>
            {children}
        </span>
    );
}
