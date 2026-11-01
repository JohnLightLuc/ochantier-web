import Sidebar from '@/components/layout/Sidebar';
import Topbar from '@/components/layout/Topbar';
import { AdminUiProvider, useAdminUi } from '@/contexts/AdminUiContext';
import type { NavModule } from '@/types';
import { usePage } from '@inertiajs/react';
import { useEffect, useState, type ReactNode } from 'react';

const SIDE_KEY = 'och_side';

function readCollapsed(): boolean {
    try {
        return localStorage.getItem(SIDE_KEY) === '1';
    } catch {
        return false;
    }
}

/** Module correspondant à l’URL courante (« / » ne correspond qu’à la vue d’ensemble). */
function currentModule(modules: NavModule[], url: string): NavModule | undefined {
    const path = url.split(/[?#]/)[0];
    return modules.find((m) => (m.path === '/' ? path === '/' : path === m.path || path.startsWith(m.path + '/')));
}

/** Gabarit du back-office : sidebar, topbar et overlays partagés. Persistant entre les pages. */
export default function AdminLayout({ children }: { children: ReactNode }) {
    return (
        <AdminUiProvider>
            <AdminShell>{children}</AdminShell>
        </AdminUiProvider>
    );
}

function AdminShell({ children }: { children: ReactNode }) {
    const { url, props, flash } = usePage();
    const ui = useAdminUi();
    const [collapsed, setCollapsed] = useState(readCollapsed);
    const module = currentModule(props.navigation, url);
    const user = props.auth.user;

    useEffect(() => {
        if (flash.toast) ui.toast(flash.toast);
        if (flash.toastError) ui.toast(flash.toastError, { tone: 'error' });
    }, [flash, ui]);

    const toggleSidebar = () => {
        setCollapsed((c) => {
            try {
                localStorage.setItem(SIDE_KEY, c ? '0' : '1');
            } catch {
                // Stockage indisponible (navigation privée) : l’état reste en mémoire.
            }
            return !c;
        });
    };

    if (!user) return <>{children}</>;

    return (
        <div data-component="AdminLayout" style={{ display: 'flex', minHeight: '100vh', background: 'var(--c-bg)' }}>
            <Sidebar modules={props.navigation} currentKey={module?.key ?? null} collapsed={collapsed} onToggle={toggleSidebar} />
            <main
                style={{ width: '100%', maxWidth: 'var(--content-max)', margin: '0 auto', flex: 1, minWidth: 0, display: 'flex', flexDirection: 'column', gap: 'var(--space-5)', padding: 'var(--space-6) var(--space-8) var(--space-8)' }}
            >
                <Topbar
                    title={props.header?.title ?? module?.title ?? ''}
                    subtitle={props.header?.subtitle ?? module?.subtitle ?? ''}
                    crumbs={props.header?.crumbs ?? []}
                    user={user}
                    notifications={props.notifications}
                />
                {children}
            </main>
        </div>
    );
}
