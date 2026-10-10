import type { ButtonHTMLAttributes, CSSProperties } from 'react';
import Icon from './Icon';

export type ButtonVariant = 'primary' | 'secondary' | 'ghost' | 'accent' | 'danger' | 'danger-soft';
type Size = 'sm' | 'md' | 'lg';

type Props = Omit<ButtonHTMLAttributes<HTMLButtonElement>, 'style'> & {
    label?: string;
    icon?: string;
    iconRight?: string;
    variant?: ButtonVariant;
    size?: Size;
    full?: boolean;
    style?: CSSProperties;
};

const VARIANTS: Record<ButtonVariant, [string, string, string]> = {
    primary: ['var(--c-primary)', 'var(--c-on-dark)', 'var(--c-primary)'],
    secondary: ['var(--c-surface)', 'var(--c-ink)', 'var(--c-line-strong)'],
    ghost: ['transparent', 'var(--c-primary-600)', 'transparent'],
    accent: ['var(--c-accent)', 'var(--c-primary-strong)', 'var(--c-accent)'],
    danger: ['var(--c-danger)', 'var(--c-on-dark)', 'var(--c-danger)'],
    'danger-soft': ['var(--c-danger-bg)', 'var(--c-danger-fg)', 'var(--c-danger-bg)'],
};

const HEIGHTS: Record<Size, string> = { sm: 'var(--control-xs)', md: 'var(--control-md)', lg: 'var(--control-lg)' };
const PADDINGS: Record<Size, string> = { sm: 'var(--space-3)', md: 'var(--space-4)', lg: 'var(--space-6)' };
const FONT_SIZES: Record<Size, string> = { sm: 'var(--fs-sm)', md: 'var(--fs-base)', lg: 'var(--fs-xl)' };

/** Bouton : variantes primaire, secondaire, fantôme, accent, danger. */
export default function Button({ label, icon, iconRight, variant = 'secondary', size = 'md', full, type = 'button', disabled, style, ...rest }: Props) {
    const [bg, fg, border] = VARIANTS[variant];
    const iconOnly = !label;

    return (
        <button
            type={type}
            disabled={disabled}
            aria-label={iconOnly ? rest['aria-label'] ?? rest.title : undefined}
            className="och-btn"
            style={{
                display: 'inline-flex',
                alignItems: 'center',
                justifyContent: 'center',
                gap: 'var(--space-2)',
                height: HEIGHTS[size],
                padding: iconOnly ? 0 : `0 ${PADDINGS[size]}`,
                width: full ? '100%' : iconOnly ? HEIGHTS[size] : 'auto',
                borderRadius: 'var(--radius-lg)',
                border: `1px solid ${border}`,
                background: bg,
                color: fg,
                fontFamily: 'var(--font-sans)',
                fontSize: FONT_SIZES[size],
                fontWeight: 'var(--fw-semibold)' as CSSProperties['fontWeight'],
                whiteSpace: 'nowrap',
                cursor: disabled ? 'not-allowed' : 'pointer',
                opacity: disabled ? 0.5 : 1,
                boxShadow: variant === 'primary' && size === 'lg' ? 'var(--shadow-primary)' : 'none',
                transition: 'background var(--dur-fast),border-color var(--dur-fast),filter var(--dur-fast)',
                ...style,
            }}
            {...rest}
        >
            {icon && <Icon name={icon} />}
            {label && <span>{label}</span>}
            {iconRight && <Icon name={iconRight} />}
        </button>
    );
}
