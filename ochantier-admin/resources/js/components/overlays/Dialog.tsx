import Button, { type ButtonVariant } from '@/components/ui/Button';
import Icon from '@/components/ui/Icon';
import { useEffect, type FormEvent, type ReactNode } from 'react';

export type DialogTone = 'primary' | 'danger' | 'warning' | 'success';

type Props = {
    title: string;
    body?: ReactNode;
    icon?: string;
    tone?: DialogTone;
    wide?: boolean;
    okLabel: string;
    okIcon?: string;
    cancelLabel?: string;
    processing?: boolean;
    onSubmit: () => void;
    onClose: () => void;
    children?: ReactNode;
};

const TONES: Record<DialogTone, [string, string]> = {
    primary: ['var(--c-primary-100)', 'var(--c-primary-600)'],
    danger: ['var(--c-danger-bg)', 'var(--c-danger-fg)'],
    warning: ['var(--c-warning-bg)', 'var(--c-warning-fg)'],
    success: ['var(--c-success-bg)', 'var(--c-success-fg)'],
};

/** Fenêtre modale : icône, titre, texte, champs éventuels et boutons Annuler / Confirmer. */
export default function Dialog({ title, body, icon = 'help', tone = 'primary', wide, okLabel, okIcon, cancelLabel = 'Annuler', processing, onSubmit, onClose, children }: Props) {
    const [iconBg, iconFg] = TONES[tone];
    const okVariant: ButtonVariant = tone === 'danger' ? 'danger' : 'primary';

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onClose();
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [onClose]);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (!processing) onSubmit();
    };

    return (
        <div
            data-component="ModalHost"
            role="dialog"
            aria-modal="true"
            aria-label={title}
            onClick={onClose}
            style={{ position: 'fixed', inset: 0, zIndex: 50, background: 'var(--c-scrim)', display: 'grid', placeItems: 'center', padding: 'var(--space-6)', overflowY: 'auto' }}
        >
            <form
                noValidate
                onSubmit={submit}
                onClick={(e) => e.stopPropagation()}
                style={{ width: '100%', maxWidth: wide ? 'var(--modal-w-lg)' : 'var(--modal-w)', background: 'var(--c-surface)', borderRadius: 'var(--radius-2xl)', boxShadow: 'var(--shadow-modal)', padding: 'var(--space-6)', animation: 'och-rise var(--dur) var(--ease)' }}
            >
                <div style={{ display: 'flex', gap: 'var(--space-4)', alignItems: 'flex-start' }}>
                    <span style={{ width: 'var(--control-md)', height: 'var(--control-md)', borderRadius: 'var(--radius-round)', flex: 'none', display: 'grid', placeItems: 'center', background: iconBg }}>
                        <Icon name={icon} size="var(--icon-md)" color={iconFg} />
                    </span>
                    <div style={{ flex: 1, minWidth: 0 }}>
                        <h3 style={{ margin: 0, fontSize: 'var(--fs-2xl)', fontWeight: 'var(--fw-bold)' }}>{title}</h3>
                        {body && <p style={{ margin: 'var(--space-2) 0 0', fontSize: 'var(--fs-base)', color: 'var(--c-muted)', lineHeight: 'var(--lh-normal)' }}>{body}</p>}
                    </div>
                </div>
                {children && <div style={{ marginTop: 'var(--space-5)', display: 'flex', flexDirection: 'column', gap: 'var(--space-3)' }}>{children}</div>}
                <div style={{ display: 'flex', flexWrap: 'wrap', justifyContent: 'flex-end', gap: 'var(--space-2_5)', marginTop: 'var(--space-6)' }}>
                    <Button label={cancelLabel} variant="secondary" onClick={onClose} />
                    <Button type="submit" label={okLabel} icon={okIcon ?? (tone === 'danger' ? undefined : 'check')} variant={okVariant} disabled={processing} autoFocus={!children} />
                </div>
            </form>
        </div>
    );
}
