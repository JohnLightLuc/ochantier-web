import Icon from '@/components/ui/Icon';
import type { ReactNode } from 'react';

type CardProps = {
    component: string;
    span: 4 | 5 | 7 | 8;
    icon: string;
    iconOutlined?: boolean;
    title: string;
    aside?: ReactNode;
    headerGap?: string;
    children: ReactNode;
};

/** Carte du tableau de bord : en-tête (icône, titre, actions) et contenu. */
export default function Card({ component, span, icon, iconOutlined, title, aside, headerGap = 'var(--space-4)', children }: CardProps) {
    return (
        <section
            data-component={component}
            className={`och-span-${span}`}
            style={{ minWidth: 0, display: 'flex', flexDirection: 'column', background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-5)' }}
        >
            <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2_5) var(--space-3)', flexWrap: 'wrap', marginBottom: headerGap }}>
                <Icon name={icon} size="var(--icon-md)" color="var(--c-primary)" outlined={iconOutlined} />
                <h2 style={{ margin: 0, fontSize: 'var(--fs-2xl)', fontWeight: 'var(--fw-bold)', flex: 1, minWidth: 0 }}>{title}</h2>
                {aside}
            </div>
            {children}
        </section>
    );
}

type SegmentedProps<K extends string> = {
    label: string;
    options: [K, string][];
    value: K;
    onChange: (key: K) => void;
    trailingIcon?: Partial<Record<K, string>>;
};

/** Sélecteur de période (onglets accolés). */
export function Segmented<K extends string>({ label, options, value, onChange, trailingIcon }: SegmentedProps<K>) {
    return (
        <div role="tablist" aria-label={label} style={{ display: 'flex', border: '1px solid var(--c-line-strong)', borderRadius: 'var(--radius-md)', overflow: 'hidden' }}>
            {options.map(([key, text]) => {
                const on = key === value;
                return (
                    <button
                        key={key}
                        type="button"
                        role="tab"
                        aria-selected={on}
                        onClick={() => onChange(key)}
                        style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-1)', height: 'var(--control-sm)', padding: '0 var(--space-3)', border: 0, background: on ? 'var(--c-primary)' : 'var(--c-surface)', color: on ? 'var(--c-on-dark)' : 'var(--c-ink-2)', fontWeight: 'var(--fw-semibold)', fontSize: 'var(--fs-sm)', cursor: 'pointer', whiteSpace: 'nowrap' }}
                    >
                        {text}
                        {trailingIcon?.[key] && <Icon name={trailingIcon[key]} size="var(--icon-xs)" />}
                    </button>
                );
            })}
        </div>
    );
}

/** Lien d’action souligné en pied de carte (« Voir… → »). */
export function FooterLink({ label, icon = 'arrow_forward', onClick }: { label: string; icon?: string; onClick: () => void }) {
    return (
        <button
            type="button"
            onClick={onClick}
            style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-1)', border: 0, background: 'transparent', padding: 0, color: 'var(--c-primary-600)', fontWeight: 'var(--fw-semibold)', fontSize: 'var(--fs-md)', cursor: 'pointer', textDecoration: 'underline', textUnderlineOffset: 2 }}
        >
            {label}
            <Icon name={icon} size="var(--icon-xs)" />
        </button>
    );
}

/** Pastille de couleur de légende. */
export function Dot({ color, size = 10 }: { color: string; size?: number }) {
    return <span style={{ width: size, height: size, flex: 'none', borderRadius: 'var(--radius-round)', background: color }} />;
}

export const tooltipStyle = {
    pointerEvents: 'none',
    padding: 'var(--space-2) var(--space-3)',
    borderRadius: 'var(--radius-md)',
    background: 'var(--c-primary-strong)',
    color: 'var(--c-on-dark)',
    boxShadow: 'var(--shadow-popover)',
    whiteSpace: 'nowrap',
    zIndex: 3,
} as const;
