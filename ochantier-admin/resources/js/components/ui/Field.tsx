import type { CSSProperties, InputHTMLAttributes, ReactNode, SelectHTMLAttributes, TextareaHTMLAttributes } from 'react';
import Icon from './Icon';

export type Option = { value: string; label: string };

type FieldProps = {
    label: string;
    required?: boolean;
    error?: string;
    hint?: string;
    span?: 'full';
    children: ReactNode;
};

/** Libellé, champ et message d’erreur. */
export default function Field({ label, required, error, hint, span, children }: FieldProps) {
    return (
        <label style={{ display: 'block', gridColumn: span === 'full' ? '1 / -1' : 'auto', minWidth: 0 }}>
            <span style={{ display: 'block', fontWeight: 'var(--fw-semibold)', fontSize: 'var(--fs-md)', marginBottom: 'var(--space-1_5)' }}>
                {label}
                {required && <span style={{ color: 'var(--c-danger-fg)' }}> *</span>}
            </span>
            {children}
            {hint && !error && <span style={{ display: 'block', marginTop: 'var(--space-1)', fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>{hint}</span>}
            {error && (
                <span role="alert" style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-1)', marginTop: 'var(--space-1)', fontSize: 'var(--fs-sm)', color: 'var(--c-danger-fg)' }}>
                    <Icon name="error" size="var(--icon-xs)" />
                    {error}
                </span>
            )}
        </label>
    );
}

export function controlStyle(invalid?: boolean): CSSProperties {
    return {
        width: '100%',
        height: 'var(--control-md)',
        padding: '0 var(--space-3)',
        border: `1.5px solid ${invalid ? 'var(--c-danger)' : 'var(--c-line-strong)'}`,
        borderRadius: 'var(--radius-lg)',
        background: 'var(--c-surface)',
        fontSize: 'var(--fs-base)',
        outline: 0,
    };
}

export function TextInput({ invalid, style, ...rest }: InputHTMLAttributes<HTMLInputElement> & { invalid?: boolean }) {
    return <input className="och-input" aria-invalid={invalid} style={{ ...controlStyle(invalid), ...style }} {...rest} />;
}

export function SelectInput({ invalid, options, placeholder = 'Sélectionner…', style, ...rest }: SelectHTMLAttributes<HTMLSelectElement> & { invalid?: boolean; options: Option[]; placeholder?: string }) {
    return (
        <select className="och-input" aria-invalid={invalid} style={{ ...controlStyle(invalid), cursor: 'pointer', ...style }} {...rest}>
            {placeholder !== '' && <option value="">{placeholder}</option>}
            {options.map((o) => (
                <option key={o.value} value={o.value}>
                    {o.label}
                </option>
            ))}
        </select>
    );
}

export function TextArea({ invalid, style, ...rest }: TextareaHTMLAttributes<HTMLTextAreaElement> & { invalid?: boolean }) {
    return <textarea className="och-input" aria-invalid={invalid} style={{ ...controlStyle(invalid), height: 'auto', padding: 'var(--space-2_5) var(--space-3)', resize: 'vertical', fontFamily: 'var(--font-sans)', ...style }} {...rest} />;
}

/** Les 10 chiffres du numéro, sans l’indicatif +225. */
export function phoneDigits(value: string): string {
    return value.replace(/^\s*\+?\s*225\s*/, '').replace(/\D/g, '').slice(0, 10);
}

const groupByTwo = (digits: string) => (digits.match(/.{1,2}/g) ?? []).join(' ');

/** Téléphone ivoirien : indicatif +225 fixe, 10 chiffres groupés par deux. Valeur stockée : « +225 07 08 25 14 63 ». */
export function PhoneInput({ value, onChange, invalid, label }: { value: string; onChange: (value: string) => void; invalid?: boolean; label: string }) {
    return (
        <div data-component="PhoneField" style={{ display: 'flex', alignItems: 'stretch', height: 'var(--control-md)', border: `1.5px solid ${invalid ? 'var(--c-danger)' : 'var(--c-line-strong)'}`, borderRadius: 'var(--radius-lg)', background: 'var(--c-surface)', overflow: 'hidden' }}>
            <span title="Côte d’Ivoire (+225)" style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)', padding: '0 var(--space-3)', borderRight: '1px solid var(--c-line)', background: 'var(--c-surface-2)', flex: 'none' }}>
                <span role="img" aria-label="Drapeau de la Côte d’Ivoire" style={{ display: 'flex', flex: 'none', width: 24, height: 16, borderRadius: 2, overflow: 'hidden', border: '1px solid var(--c-line-strong)' }}>
                    <span style={{ width: 8, height: '100%', background: '#F77F00' }} />
                    <span style={{ width: 8, height: '100%', background: '#FFFFFF' }} />
                    <span style={{ width: 8, height: '100%', background: '#009E60' }} />
                </span>
                <b style={{ fontSize: 'var(--fs-base)', fontWeight: 'var(--fw-semibold)', fontVariantNumeric: 'tabular-nums' }}>+225</b>
            </span>
            <input
                type="tel"
                inputMode="numeric"
                autoComplete="tel-national"
                maxLength={14}
                value={groupByTwo(phoneDigits(value))}
                onChange={(e) => {
                    const digits = e.target.value.replace(/\D/g, '').slice(0, 10);
                    onChange(digits ? `+225 ${groupByTwo(digits)}` : '');
                }}
                placeholder="07 08 25 14 63"
                aria-label={`${label} — 10 chiffres après l’indicatif +225`}
                aria-invalid={invalid}
                style={{ flex: 1, minWidth: 0, border: 0, outline: 0, padding: '0 var(--space-3)', fontSize: 'var(--fs-base)', fontVariantNumeric: 'tabular-nums', letterSpacing: '.02em', background: 'transparent' }}
            />
        </div>
    );
}
