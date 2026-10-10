import Icon from '@/components/ui/Icon';
import type { NavModule } from '@/types';
import { Link } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

type Props = {
    modules: NavModule[];
    currentKey: string | null;
    collapsed: boolean;
    onToggle: () => void;
};

/** Barre de navigation latérale, repliable. */
export default function Sidebar({ modules, currentKey, collapsed, onToggle }: Props) {
    const navRef = useRef<HTMLElement>(null);
    const [tallScreen, setTallScreen] = useState(() => window.innerHeight >= 860);

    useEffect(() => {
        const onResize = () => setTallScreen(window.innerHeight >= 860);
        window.addEventListener('resize', onResize);
        return () => window.removeEventListener('resize', onResize);
    }, []);

    // Garde l’entrée active visible quand la liste défile.
    useEffect(() => {
        const nav = navRef.current;
        const active = nav?.querySelector<HTMLElement>('[aria-current="page"]');
        if (!nav || !active) return;
        const top = active.offsetTop;
        const bottom = top + active.offsetHeight;
        if (top < nav.scrollTop) nav.scrollTop = top - 8;
        else if (bottom > nav.scrollTop + nav.clientHeight) nav.scrollTop = bottom - nav.clientHeight + 8;
    }, [currentKey, collapsed]);

    return (
        <aside
            data-component="Sidebar"
            style={{
                width: collapsed ? 'var(--sidebar-w-collapsed)' : 'var(--sidebar-w)',
                flex: 'none',
                position: 'sticky',
                top: 0,
                height: '100vh',
                background: 'linear-gradient(180deg,var(--c-sidebar-top) 0%,var(--c-sidebar-bottom) 100%)',
                color: 'var(--c-surface)',
                display: 'flex',
                flexDirection: 'column',
                padding: 'var(--space-5) var(--space-3)',
                transition: 'width .2s ease',
                overflow: 'hidden',
            }}
        >
            {collapsed ? (
                <div style={{ textAlign: 'center', fontWeight: 'var(--fw-black)', fontSize: 'var(--fs-4xl)', lineHeight: 1, color: 'var(--c-accent)', padding: 'var(--space-1) 0 var(--space-8)' }}>O’</div>
            ) : (
                <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', padding: 'var(--space-1) 0 var(--space-6)' }}>
                    <div style={{ fontWeight: 'var(--fw-black)', fontSize: 'var(--fs-logo)', lineHeight: 1, letterSpacing: '-.5px', whiteSpace: 'nowrap' }}>
                        <span style={{ color: 'var(--c-accent)' }}>O’</span>
                        <span style={{ color: 'var(--c-brand-green)' }}>CHANTIER</span>
                    </div>
                    <div style={{ fontWeight: 'var(--fw-semibold)', fontSize: 'var(--fs-xs)', letterSpacing: '.45em', marginTop: 'var(--space-1_5)', paddingLeft: '.45em' }}>ADMIN</div>
                </div>
            )}

            <nav ref={navRef} aria-label="Navigation principale" style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-0_5)', flex: 1, overflowY: 'auto', position: 'relative' }}>
                {modules.map((m) => {
                    const active = m.key === currentKey;
                    return (
                        <Link
                            key={m.key}
                            href={m.path}
                            aria-current={active ? 'page' : undefined}
                            title={m.label}
                            className={active ? undefined : 'och-hover-dark'}
                            style={{
                                flex: 'none',
                                display: 'flex',
                                alignItems: 'center',
                                gap: 'var(--space-3)',
                                height: 'var(--control-md)',
                                padding: '0 var(--space-3)',
                                borderRadius: 'var(--radius-lg)',
                                background: active ? 'linear-gradient(90deg,var(--c-primary-500),var(--c-primary-600))' : 'transparent',
                                color: 'var(--c-surface)',
                                textDecoration: 'none',
                                fontSize: 'var(--fs-base)',
                                fontWeight: active ? 600 : 400,
                                whiteSpace: 'nowrap',
                                boxShadow: active ? 'var(--shadow-nav-active)' : 'none',
                            }}
                        >
                            <Icon name={m.icon} size="var(--icon-md)" color={active ? 'var(--c-surface)' : 'var(--c-on-dark-muted)'} style={{ flex: 'none' }} />
                            {!collapsed && <span style={{ flex: 1 }}>{m.label}</span>}
                            {!collapsed && m.badge ? (
                                <span style={{ minWidth: 22, height: 20, padding: '0 var(--space-1_5)', borderRadius: 'var(--radius-lg)', background: 'var(--c-accent)', color: 'var(--c-primary-strong)', fontSize: 'var(--fs-xs)', fontWeight: 'var(--fw-bold)', display: 'grid', placeItems: 'center' }}>{m.badge}</span>
                            ) : null}
                        </Link>
                    );
                })}
            </nav>

            {!collapsed && tallScreen && (
                <div style={{ padding: 'var(--space-4) var(--space-3) var(--space-1_5)' }}>
                    <div style={{ width: 56, height: 2, background: 'var(--c-accent)', marginBottom: 'var(--space-4)' }} />
                    <div style={{ fontSize: 'var(--fs-md)', letterSpacing: '.2em', lineHeight: 1.6, color: 'var(--c-on-dark-muted)' }}>
                        Des talents
                        <br />
                        pour bâtir demain
                    </div>
                </div>
            )}

            <button
                type="button"
                onClick={onToggle}
                title="Réduire / déployer le menu"
                aria-label={collapsed ? 'Déployer le menu' : 'Réduire le menu'}
                className="och-hover-dark"
                style={{ marginTop: 'var(--space-2_5)', height: 'var(--control-sm)', border: '1px solid var(--c-on-dark-line)', borderRadius: 'var(--radius-md)', background: 'transparent', color: 'var(--c-on-dark-muted)', cursor: 'pointer', display: 'grid', placeItems: 'center' }}
            >
                <Icon name={collapsed ? 'keyboard_double_arrow_right' : 'keyboard_double_arrow_left'} />
            </button>
        </aside>
    );
}
