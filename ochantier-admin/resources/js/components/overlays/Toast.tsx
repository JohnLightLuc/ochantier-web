import Icon from '@/components/ui/Icon';

export type ToastTone = 'success' | 'info' | 'error';

export type ToastData = {
    message: string;
    tone: ToastTone;
    onRetry?: () => void;
};

type Props = {
    toast: ToastData;
    onClose: () => void;
};

const ICONS: Record<ToastTone, [string, string]> = {
    success: ['check_circle', 'var(--c-brand-green)'],
    info: ['hourglass_top', 'var(--c-on-dark-muted)'],
    error: ['error', 'var(--c-danger-bg)'],
};

/** Notification éphémère en bas à droite. */
export default function Toast({ toast, onClose }: Props) {
    const [icon, color] = ICONS[toast.tone];

    return (
        <div
            data-component="Toast"
            role="status"
            style={{ position: 'fixed', right: 'var(--space-6)', bottom: 'var(--space-6)', zIndex: 60, display: 'flex', alignItems: 'center', gap: 'var(--space-3)', padding: 'var(--space-3) var(--space-4)', minWidth: 280, maxWidth: 420, background: 'var(--c-primary-strong)', color: 'var(--c-on-dark)', borderRadius: 'var(--radius-xl)', boxShadow: 'var(--shadow-popover)', animation: 'och-rise var(--dur) var(--ease)' }}
        >
            <Icon name={icon} color={color} />
            <span style={{ flex: 1, fontSize: 'var(--fs-md)', fontWeight: 'var(--fw-medium)' }}>{toast.message}</span>
            {toast.onRetry && (
                <button
                    type="button"
                    onClick={() => {
                        onClose();
                        toast.onRetry?.();
                    }}
                    style={{ border: '1px solid var(--c-on-dark-line)', background: 'transparent', color: 'var(--c-on-dark)', borderRadius: 'var(--radius-md)', padding: 'var(--space-1) var(--space-2_5)', cursor: 'pointer', fontWeight: 'var(--fw-semibold)', fontSize: 'var(--fs-sm)' }}
                >
                    Réessayer
                </button>
            )}
            <button type="button" onClick={onClose} aria-label="Fermer" style={{ border: 0, background: 'transparent', color: 'var(--c-on-dark-muted)', cursor: 'pointer', display: 'grid', placeItems: 'center' }}>
                <Icon name="close" />
            </button>
        </div>
    );
}
