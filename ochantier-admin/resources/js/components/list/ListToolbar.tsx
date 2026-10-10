import Icon from '@/components/ui/Icon';
import type { Option } from '@/components/ui/Field';
import { useEffect, useRef, useState } from 'react';

type SearchProps = {
    value: string;
    placeholder: string;
    onChange: (value: string) => void;
    /** Délai avant d’appliquer la recherche (ms). */
    debounce?: number;
};

/** Champ de recherche d’une liste ; la valeur est appliquée après une courte pause de saisie. */
export function SearchInput({ value, placeholder, onChange, debounce = 300 }: SearchProps) {
    const [text, setText] = useState(value);
    const timer = useRef<number | undefined>(undefined);

    useEffect(() => setText(value), [value]);
    useEffect(() => () => window.clearTimeout(timer.current), []);

    return (
        <div style={{ flex: '2 1 280px', display: 'flex', alignItems: 'center', gap: 'var(--space-2_5)', height: 'var(--control-md)', padding: '0 var(--space-3)', border: '1px solid var(--c-line-strong)', borderRadius: 'var(--radius-lg)', background: 'var(--c-surface-2)' }}>
            <Icon name="search" color="var(--c-muted)" />
            <input
                type="search"
                value={text}
                aria-label={placeholder}
                placeholder={placeholder}
                onChange={(e) => {
                    const next = e.target.value;
                    setText(next);
                    window.clearTimeout(timer.current);
                    timer.current = window.setTimeout(() => onChange(next), debounce);
                }}
                style={{ flex: 1, minWidth: 0, border: 0, outline: 0, background: 'transparent', fontSize: 'var(--fs-base)' }}
            />
        </div>
    );
}

type FilterProps = {
    label: string;
    value: string;
    options: Option[];
    onChange: (value: string) => void;
};

/** Filtre à liste déroulante ; mis en évidence quand une valeur est choisie. */
export function FilterSelect({ label, value, options, onChange }: FilterProps) {
    const active = value !== '';

    return (
        <label style={{ position: 'relative', flex: '1 1 140px', maxWidth: 200, display: 'flex' }}>
            <select
                value={value}
                onChange={(e) => onChange(e.target.value)}
                aria-label={label}
                className="och-filter"
                style={{ appearance: 'none', WebkitAppearance: 'none', width: '100%', height: 'var(--control-md)', padding: '0 var(--space-8) 0 var(--space-3)', border: `1px solid ${active ? 'var(--c-primary-400)' : 'var(--c-line-strong)'}`, borderRadius: 'var(--radius-lg)', background: active ? 'var(--c-primary-50)' : 'var(--c-surface)', fontSize: 'var(--fs-base)', color: 'var(--c-ink-2)', cursor: 'pointer', outline: 0 }}
            >
                <option value="">{label}</option>
                {options.map((o) => (
                    <option key={o.value} value={o.value}>
                        {o.label}
                    </option>
                ))}
            </select>
            <Icon name="expand_more" color="var(--c-muted)" style={{ position: 'absolute', right: 'var(--space-2_5)', top: '50%', transform: 'translateY(-50%)', pointerEvents: 'none' }} />
        </label>
    );
}

/** Pastille de filtre actif avec bouton de retrait. */
export function FilterChip({ label, onClear }: { label: string; onClear: () => void }) {
    return (
        <span style={{ display: 'inline-flex', alignItems: 'center', gap: 'var(--space-1)', height: 28, padding: '0 var(--space-2) 0 var(--space-3)', borderRadius: 'var(--radius-pill)', background: 'var(--c-primary-50)', border: '1px solid var(--c-primary-200)', color: 'var(--c-primary)', fontSize: 'var(--fs-sm)', fontWeight: 'var(--fw-semibold)' }}>
            {label}
            <button type="button" onClick={onClear} aria-label="Retirer le filtre" style={{ border: 0, background: 'transparent', padding: 0, color: 'inherit', cursor: 'pointer', display: 'grid', placeItems: 'center' }}>
                <Icon name="close" size="var(--icon-xs)" />
            </button>
        </span>
    );
}

type SelectionProps = {
    count: number;
    onExport: () => void;
    onBulk: () => void;
    bulkLabel: string;
    bulkIcon: string;
    onClear: () => void;
};

/** Barre d’actions groupées sur la sélection. */
export function SelectionBar({ count, onExport, onBulk, bulkLabel, bulkIcon, onClear }: SelectionProps) {
    return (
        <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-3)', flexWrap: 'wrap', padding: 'var(--space-2_5) var(--space-3)', marginBottom: 'var(--space-2_5)', borderRadius: 'var(--radius-lg)', background: 'var(--c-primary)', color: 'var(--c-surface)' }}>
            <span style={{ fontWeight: 'var(--fw-bold)' }}>
                {count} {count > 1 ? 'éléments sélectionnés' : 'élément sélectionné'}
            </span>
            <span style={{ flex: 1 }} />
            <button type="button" onClick={onExport} style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-1_5)', height: 'var(--control-xs)', padding: '0 var(--space-3)', border: '1px solid var(--c-on-dark-line)', borderRadius: 'var(--radius-md)', background: 'transparent', color: 'var(--c-surface)', cursor: 'pointer', fontWeight: 'var(--fw-semibold)' }}>
                <Icon name="download" />
                Exporter
            </button>
            <button type="button" onClick={onBulk} style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-1_5)', height: 'var(--control-xs)', padding: '0 var(--space-3)', border: 0, borderRadius: 'var(--radius-md)', background: 'var(--c-accent)', color: 'var(--c-primary-strong)', cursor: 'pointer', fontWeight: 'var(--fw-bold)' }}>
                <Icon name={bulkIcon} />
                {bulkLabel}
            </button>
            <button type="button" onClick={onClear} aria-label="Désélectionner" style={{ width: 32, height: 32, border: 0, borderRadius: 'var(--radius-md)', background: 'transparent', color: 'var(--c-surface)', cursor: 'pointer', display: 'grid', placeItems: 'center' }}>
                <Icon name="close" />
            </button>
        </div>
    );
}
