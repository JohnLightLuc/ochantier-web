import StatusBadge from '@/components/ui/StatusBadge';
import StateBlock from '@/components/ui/StateBlock';
import Icon from '@/components/ui/Icon';
import { PRIORITY_TONES } from '@/lib/tone';
import type { AdminNotification } from '@/types';

type Tab = 'unread' | 'read' | 'all';

type Props = {
    notifications: AdminNotification[];
    readIds: string[];
    tab: Tab;
    onTab: (tab: Tab) => void;
    onOpen: (n: AdminNotification) => void;
    onMarkAll: () => void;
    onSeeAll: () => void;
};

/** Panneau déroulant des notifications de la topbar. */
export default function NotificationPanel({ notifications, readIds, tab, onTab, onOpen, onMarkAll, onSeeAll }: Props) {
    const unread = notifications.filter((n) => !readIds.includes(n.id)).length;
    const list = notifications.filter((n) => tab === 'all' || (tab === 'unread' ? !readIds.includes(n.id) : readIds.includes(n.id)));
    const tabs: [Tab, string, number][] = [
        ['unread', 'Non lues', unread],
        ['read', 'Lues', notifications.length - unread],
        ['all', 'Toutes', notifications.length],
    ];

    return (
        <div
            data-component="NotificationPanel"
            role="dialog"
            aria-label="Notifications"
            style={{ position: 'absolute', right: 0, top: 52, zIndex: 30, width: 'var(--notif-w)', maxWidth: '90vw', background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-xl)', boxShadow: 'var(--shadow-popover)', overflow: 'hidden', display: 'flex', flexDirection: 'column' }}
        >
            <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)', padding: 'var(--space-3) var(--space-4) var(--space-2)' }}>
                <b style={{ fontSize: 'var(--fs-lg)', flex: 1 }}>Notifications</b>
                <button
                    type="button"
                    onClick={onMarkAll}
                    disabled={!unread}
                    style={{ border: 0, background: 'transparent', padding: 0, color: unread ? 'var(--c-primary-600)' : 'var(--c-subtle)', fontWeight: 'var(--fw-semibold)', cursor: unread ? 'pointer' : 'default', fontSize: 'var(--fs-sm)' }}
                >
                    Tout marquer comme lu
                </button>
            </div>

            <div role="tablist" style={{ display: 'flex', gap: 'var(--space-1)', padding: '0 var(--space-4)', borderBottom: '1px solid var(--c-line)' }}>
                {tabs.map(([key, label, count]) => {
                    const on = key === tab;
                    return (
                        <button
                            key={key}
                            type="button"
                            role="tab"
                            aria-selected={on}
                            onClick={() => onTab(key)}
                            style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-1_5)', height: 'var(--control-sm)', padding: '0 var(--space-2_5)', border: 0, borderBottom: `2.5px solid ${on ? 'var(--c-primary)' : 'transparent'}`, marginBottom: -1, background: 'transparent', cursor: 'pointer', fontWeight: 'var(--fw-semibold)', fontSize: 'var(--fs-md)', color: on ? 'var(--c-primary)' : 'var(--c-muted)' }}
                        >
                            {label}
                            <span style={{ fontSize: 'var(--fs-xs)', color: 'var(--c-muted)' }}>{count}</span>
                        </button>
                    );
                })}
            </div>

            <div style={{ maxHeight: 400, overflowY: 'auto' }}>
                {list.length === 0 ? (
                    <div style={{ padding: 'var(--space-3)' }}>
                        <StateBlock
                            kind="nodata"
                            compact
                            icon="notifications_off"
                            title={tab === 'unread' ? 'Aucune notification non lue' : 'Aucune notification'}
                            text={tab === 'unread' ? 'Vous êtes à jour.' : 'Les notifications apparaîtront ici.'}
                        />
                    </div>
                ) : (
                    list.map((n) => {
                        const isUnread = !readIds.includes(n.id);
                        return (
                            <button
                                key={n.id}
                                type="button"
                                onClick={() => onOpen(n)}
                                className="och-hover-soft"
                                style={{ width: '100%', display: 'flex', gap: 'var(--space-3)', alignItems: 'flex-start', padding: 'var(--space-3) var(--space-4)', border: 0, borderBottom: '1px solid var(--c-line-soft)', background: isUnread ? 'var(--c-primary-50)' : 'var(--c-surface)', cursor: 'pointer', textAlign: 'left' }}
                            >
                                <span style={{ width: 'var(--control-xs)', height: 'var(--control-xs)', borderRadius: 'var(--radius-round)', flex: 'none', display: 'grid', placeItems: 'center', background: `var(--c-${n.tone}-bg)` }}>
                                    <Icon name={n.icon} size="var(--icon-xs)" color={`var(--c-${n.tone}-fg)`} />
                                </span>
                                <span style={{ flex: 1, minWidth: 0 }}>
                                    <span style={{ display: 'block', fontSize: 'var(--fs-md)', fontWeight: isUnread ? 700 : 500 }}>{n.title}</span>
                                    <span style={{ display: 'block', fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>{n.text}</span>
                                    <span style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)', marginTop: 'var(--space-1)' }}>
                                        <StatusBadge label={n.priority} tone={PRIORITY_TONES[n.priority]} />
                                        <span style={{ fontSize: 'var(--fs-xs)', color: 'var(--c-muted)' }}>{n.when}</span>
                                    </span>
                                </span>
                                {isUnread && <span style={{ width: 8, height: 8, marginTop: 'var(--space-1_5)', borderRadius: 'var(--radius-round)', background: 'var(--c-primary-500)', flex: 'none' }} />}
                            </button>
                        );
                    })
                )}
            </div>

            <div style={{ padding: 'var(--space-2_5)', borderTop: '1px solid var(--c-line-soft)', textAlign: 'center' }}>
                <button type="button" onClick={onSeeAll} style={{ border: 0, background: 'transparent', padding: 0, color: 'var(--c-primary-600)', fontWeight: 'var(--fw-semibold)', cursor: 'pointer', fontSize: 'var(--fs-sm)', textDecoration: 'underline', textUnderlineOffset: 2 }}>
                    Voir toutes les notifications
                </button>
            </div>
        </div>
    );
}
