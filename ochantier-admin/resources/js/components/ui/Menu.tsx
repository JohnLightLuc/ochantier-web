import type { MouseEvent } from 'react';
import Icon from './Icon';

export type MenuItem = {
    icon: string;
    label: string;
    danger?: boolean;
    onClick: () => void;
};

type Props = {
    items: MenuItem[];
    onClose: () => void;
    width?: number;
    /** Position par rapport au parent (positionné en relative). */
    top?: string;
    right?: string;
};

/** Menu contextuel : fond cliquable pour fermer, puis la liste d’actions. */
export default function Menu({ items, onClose, width = 250, top = 'calc(100% - var(--space-1))', right = 'var(--space-2)' }: Props) {
    const stop = (fn: () => void) => (e: MouseEvent) => {
        e.stopPropagation();
        fn();
    };

    return (
        <>
            <div onClick={stop(onClose)} style={{ position: 'fixed', inset: 0, zIndex: 15, cursor: 'default' }} />
            <div role="menu" style={{ position: 'absolute', right, top, zIndex: 20, width, background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-xl)', boxShadow: 'var(--shadow-popover)', padding: 'var(--space-1_5)', textAlign: 'left' }}>
                {items.map((item) => (
                    <button
                        key={item.label}
                        type="button"
                        role="menuitem"
                        onClick={stop(() => {
                            onClose();
                            item.onClick();
                        })}
                        className="och-hover-menu"
                        style={{ width: '100%', display: 'flex', alignItems: 'center', gap: 'var(--space-2_5)', padding: 'var(--space-2) var(--space-3)', border: 0, borderRadius: 'var(--radius-md)', background: 'transparent', cursor: 'pointer', textAlign: 'left', color: item.danger ? 'var(--c-danger-fg)' : 'var(--c-ink)' }}
                    >
                        <Icon name={item.icon} />
                        {item.label}
                    </button>
                ))}
            </div>
        </>
    );
}
