import { kbdStyle } from '@/components/overlays/SidePanel';
import Icon from '@/components/ui/Icon';
import { useAdminUi } from '@/contexts/AdminUiContext';
import { PRIORITY_TONES } from '@/lib/tone';
import type { AdminNotification, AuthUser, PageHeader } from '@/types';
import { Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import NotificationPanel from './NotificationPanel';

type Props = {
    title: string;
    subtitle: string;
    crumbs: PageHeader['crumbs'];
    user: AuthUser;
    notifications: AdminNotification[];
};

type Menu = 'notifications' | 'profile' | null;

const iconButton = { position: 'relative', width: 42, height: 42, border: 0, borderRadius: 'var(--radius-lg)', background: 'transparent', cursor: 'pointer', display: 'grid', placeItems: 'center' } as const;

/** En-tête de page : titre, recherche, notifications, aide et menu du profil. */
export default function Topbar({ title, subtitle, crumbs, user, notifications }: Props) {
    const ui = useAdminUi();
    const [menu, setMenu] = useState<Menu>(null);
    const [notifTab, setNotifTab] = useState<'unread' | 'read' | 'all'>('unread');
    const [readIds, setReadIds] = useState(() => notifications.filter((n) => n.read).map((n) => n.id));
    const unread = notifications.filter((n) => !readIds.includes(n.id)).length;

    useEffect(() => {
        if (!menu) return;
        const onKey = (e: KeyboardEvent) => e.key === 'Escape' && setMenu(null);
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [menu]);

    const openNotification = (n: AdminNotification) => {
        setReadIds((ids) => (ids.includes(n.id) ? ids : [...ids, n.id]));
        setMenu(null);
        router.visit(n.href);
    };

    const openSearch = () => ui.toast('La recherche globale sera disponible avec l’intégration des modules.', { tone: 'info' });

    const openHelp = () =>
        ui.openPanel({
            kicker: 'Aide',
            title: 'Centre d’aide',
            text: 'Raccourcis et ressources pour utiliser le Back-office O’CHANTIER ADMIN.',
            listTitle: 'Raccourcis et ressources',
            list: [
                { icon: 'search', name: 'Recherche globale', sub: 'Chantiers, ouvriers, promoteurs, commerciaux, offres, transactions', kbd: '⌘ K', onClick: openSearch },
                { icon: 'close', tone: 'n', name: 'Fermer une fenêtre ou un panneau', sub: 'Recherche, notifications, panneaux latéraux', kbd: 'Échap' },
                { icon: 'menu_book', tone: 'b', name: 'Guide de l’administrateur', sub: 'Documentation des modules du Back-office', onClick: () => ui.toast('Guide de l’administrateur : lien à connecter.') },
                { icon: 'support_agent', tone: 'a', name: 'Contacter le support', sub: 'support@ochantier.ci · +225 27 22 00 00 00', onClick: () => ui.toast('Demande envoyée au support O’CHANTIER.') },
            ],
        });

    const confirmLogout = () =>
        ui.confirm({
            tone: 'danger',
            icon: 'logout',
            title: 'Voulez-vous vraiment vous déconnecter ?',
            body: 'Votre session sera fermée sur cet appareil.',
            ok: 'Se déconnecter',
            onConfirm: () => router.post('/deconnexion'),
        });

    const profileItems: { icon: string; label: string; danger?: boolean; onClick: () => void }[] = [
        {
            icon: 'person',
            label: 'Mon profil',
            onClick: () =>
                ui.openPanel({
                    kicker: 'Compte administrateur',
                    title: user.name,
                    badges: [
                        { label: user.role, tone: 'p' },
                        { label: 'Actif', tone: 'g' },
                    ],
                    meta: [
                        ['E-mail', user.email],
                        ['Authentification', 'Double facteur (OTP) activée'],
                    ],
                    actions: [{ label: 'Modifier dans Paramètres', icon: 'edit', variant: 'primary', onClick: () => router.visit('/parametres') }],
                }),
        },
        { icon: 'tune', label: 'Préférences', onClick: () => router.visit('/parametres') },
        { icon: 'shield_lock', label: 'Sécurité', onClick: () => router.visit('/parametres') },
        { icon: 'help', label: 'Aide', onClick: openHelp },
        { icon: 'logout', label: 'Déconnexion', danger: true, onClick: confirmLogout },
    ];

    return (
        <header data-component="Topbar" style={{ display: 'flex', flexWrap: 'wrap', alignItems: 'flex-start', gap: 'var(--space-4) var(--space-6)' }}>
            <div style={{ flex: '1 1 360px', minWidth: 0 }}>
                {crumbs.length > 0 && (
                    <nav aria-label="Fil d’Ariane" style={{ display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: 'var(--space-1_5)', marginBottom: 'var(--space-1_5)', fontSize: 'var(--fs-md)' }}>
                        {crumbs.map((c, i) => (
                            <span key={i} style={{ display: 'contents' }}>
                                {i > 0 && <span style={{ color: 'var(--c-subtle)' }}>/</span>}
                                {c.href ? (
                                    <Link href={c.href} className="och-hover-underline" style={{ color: 'var(--c-primary-600)', fontWeight: 'var(--fw-semibold)', textDecoration: 'none' }}>
                                        {c.label}
                                    </Link>
                                ) : (
                                    <span aria-current="page" style={{ color: 'var(--c-muted)' }}>
                                        {c.label}
                                    </span>
                                )}
                            </span>
                        ))}
                    </nav>
                )}
                <h1 style={{ margin: 0, fontWeight: 'var(--fw-bold)', fontSize: 'var(--fs-4xl)', lineHeight: 1.15, letterSpacing: '-.5px', color: 'var(--c-ink)' }}>{title}</h1>
                <p style={{ margin: 'var(--space-1) 0 0', fontSize: 'var(--fs-lg)', color: 'var(--c-muted)', textWrap: 'pretty' }}>{subtitle}</p>
            </div>

            <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2_5)', position: 'relative' }}>
                <button
                    type="button"
                    onClick={openSearch}
                    aria-label="Recherche globale (⌘ K)"
                    aria-haspopup="dialog"
                    className="och-hover-line"
                    style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)', height: 'var(--control-md)', width: 'var(--search-w)', maxWidth: '36vw', padding: '0 var(--space-2) 0 var(--space-3)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-lg)', background: 'var(--c-surface)', cursor: 'text', textAlign: 'left' }}
                >
                    <Icon name="search" color="var(--c-muted)" />
                    <span style={{ flex: 1, minWidth: 0, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', fontSize: 'var(--fs-md)', color: 'var(--c-muted)' }}>Rechercher un chantier, un ouvrier, un promoteur…</span>
                    <kbd style={kbdStyle}>⌘ K</kbd>
                </button>

                <button type="button" onClick={() => setMenu(menu === 'notifications' ? null : 'notifications')} aria-label="Notifications" aria-expanded={menu === 'notifications'} className="och-hover" style={iconButton}>
                    <Icon name="notifications" size="var(--icon-md)" color="var(--c-ink)" outlined />
                    {unread > 0 && (
                        <span style={{ position: 'absolute', top: 5, right: 5, minWidth: 17, height: 17, borderRadius: 'var(--radius-md)', background: 'var(--c-danger)', color: 'var(--c-surface)', fontSize: 'var(--fs-2xs)', fontWeight: 'var(--fw-bold)', display: 'grid', placeItems: 'center', border: '2px solid var(--c-bg)' }}>{unread}</span>
                    )}
                </button>

                <button type="button" onClick={openHelp} aria-label="Aide" className="och-hover" style={iconButton}>
                    <Icon name="help" size="var(--icon-md)" color="var(--c-ink)" outlined />
                </button>

                <button
                    type="button"
                    onClick={() => setMenu(menu === 'profile' ? null : 'profile')}
                    aria-label="Menu du profil"
                    aria-expanded={menu === 'profile'}
                    className="och-hover"
                    style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-1_5)', border: 0, background: 'transparent', cursor: 'pointer', padding: 'var(--space-0_5)', borderRadius: 'var(--radius-pill)' }}
                >
                    <span style={{ width: 42, height: 42, borderRadius: 'var(--radius-round)', background: 'var(--c-primary)', color: 'var(--c-surface)', fontWeight: 'var(--fw-bold)', display: 'grid', placeItems: 'center' }}>{user.initials}</span>
                    <Icon name="expand_more" size="var(--icon-md)" color="var(--c-ink)" />
                </button>

                {menu && <div onClick={() => setMenu(null)} style={{ position: 'fixed', inset: 0, zIndex: 25 }} />}

                {menu === 'notifications' && (
                    <NotificationPanel
                        notifications={notifications}
                        readIds={readIds}
                        tab={notifTab}
                        onTab={setNotifTab}
                        onOpen={openNotification}
                        onMarkAll={() => {
                            setReadIds(notifications.map((n) => n.id));
                            ui.toast('Toutes les notifications ont été marquées comme lues.');
                        }}
                        onSeeAll={() => {
                            setMenu(null);
                            ui.openPanel({
                                kicker: 'Centre de notifications',
                                title: 'Toutes les notifications',
                                text: `${unread} non lue${unread > 1 ? 's' : ''} sur ${notifications.length}.`,
                                list: notifications.map((n) => ({
                                    icon: n.icon,
                                    tone: n.tone,
                                    name: (readIds.includes(n.id) ? '' : '● ') + n.title,
                                    sub: `${n.text} · ${n.when}`,
                                    badge: n.priority,
                                    badgeTone: PRIORITY_TONES[n.priority],
                                    onClick: () => openNotification(n),
                                })),
                            });
                        }}
                    />
                )}

                {menu === 'profile' && (
                    <div role="menu" data-component="ProfileMenu" style={{ position: 'absolute', right: 0, top: 52, zIndex: 30, width: 250, background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-xl)', boxShadow: 'var(--shadow-popover)', padding: 'var(--space-1_5)' }}>
                        <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2_5)', padding: 'var(--space-2_5) var(--space-3) var(--space-3)', borderBottom: '1px solid var(--c-line-soft)', marginBottom: 'var(--space-1)' }}>
                            <span style={{ width: 'var(--avatar-md)', height: 'var(--avatar-md)', borderRadius: 'var(--radius-round)', background: 'var(--c-primary)', color: 'var(--c-surface)', fontWeight: 'var(--fw-bold)', fontSize: 'var(--fs-sm)', display: 'grid', placeItems: 'center', flex: 'none' }}>{user.initials}</span>
                            <div style={{ minWidth: 0 }}>
                                <div style={{ fontWeight: 'var(--fw-bold)' }}>{user.name}</div>
                                <div style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>{user.role}</div>
                            </div>
                        </div>
                        {profileItems.map((item) => (
                            <div key={item.label}>
                                {item.danger && <div style={{ height: 1, background: 'var(--c-line-soft)', margin: 'var(--space-1) 0' }} />}
                                <button
                                    type="button"
                                    role="menuitem"
                                    onClick={() => {
                                        setMenu(null);
                                        item.onClick();
                                    }}
                                    className="och-hover-menu"
                                    style={{ width: '100%', display: 'flex', alignItems: 'center', gap: 'var(--space-2_5)', padding: 'var(--space-2) var(--space-3)', border: 0, background: 'transparent', borderRadius: 'var(--radius-md)', cursor: 'pointer', textAlign: 'left', color: item.danger ? 'var(--c-danger-fg)' : 'var(--c-ink)' }}
                                >
                                    <Icon name={item.icon} color={item.danger ? 'var(--c-danger-fg)' : 'var(--c-muted)'} />
                                    {item.label}
                                </button>
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </header>
    );
}
