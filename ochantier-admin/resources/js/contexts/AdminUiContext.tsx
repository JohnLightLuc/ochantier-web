import ConfirmModal, { type ConfirmData } from '@/components/overlays/ConfirmModal';
import SidePanel, { type PanelData } from '@/components/overlays/SidePanel';
import Toast, { type ToastData, type ToastTone } from '@/components/overlays/Toast';
import { router } from '@inertiajs/react';
import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';

type AdminUi = {
    toast: (message: string, options?: { tone?: ToastTone; onRetry?: () => void }) => void;
    openPanel: (panel: PanelData) => void;
    closePanel: () => void;
    confirm: (modal: ConfirmData) => void;
};

const AdminUiContext = createContext<AdminUi | null>(null);

/** Toasts, panneau latéral et modale de confirmation partagés par toutes les pages du back-office. */
export function AdminUiProvider({ children }: { children: ReactNode }) {
    const [toastData, setToastData] = useState<ToastData | null>(null);
    const [panel, setPanel] = useState<PanelData | null>(null);
    const [modal, setModal] = useState<ConfirmData | null>(null);
    const timer = useRef<number | undefined>(undefined);

    const toast = useCallback<AdminUi['toast']>((message, options = {}) => {
        const tone = options.tone ?? 'success';
        window.clearTimeout(timer.current);
        setToastData({ message, tone, onRetry: options.onRetry });
        timer.current = window.setTimeout(() => setToastData(null), tone === 'error' ? 7000 : 3200);
    }, []);

    const closePanel = useCallback(() => setPanel(null), []);

    useEffect(() => () => window.clearTimeout(timer.current), []);

    // Toute navigation ferme le panneau ouvert.
    useEffect(() => router.on('navigate', () => setPanel(null)), []);

    // Échap ferme la modale, sinon le panneau.
    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (e.key !== 'Escape') return;
            if (modal) setModal(null);
            else setPanel(null);
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [modal]);

    const value = useMemo(() => ({ toast, openPanel: setPanel, closePanel, confirm: setModal }), [toast, closePanel]);

    return (
        <AdminUiContext.Provider value={value}>
            {children}
            {panel && <SidePanel panel={panel} onClose={closePanel} />}
            {modal && <ConfirmModal modal={modal} onClose={() => setModal(null)} />}
            {toastData && <Toast toast={toastData} onClose={() => setToastData(null)} />}
        </AdminUiContext.Provider>
    );
}

export function useAdminUi(): AdminUi {
    const ui = useContext(AdminUiContext);
    if (!ui) throw new Error('useAdminUi doit être utilisé dans AdminLayout.');
    return ui;
}
