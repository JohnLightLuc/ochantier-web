import FormDialog from '@/components/overlays/FormDialog';
import { useAdminUi } from '@/contexts/AdminUiContext';
import type { DialogTone } from '@/components/overlays/Dialog';
import type { DialogField } from '@/components/overlays/FormDialog';
import type { BlockAction, KpiSheet } from '@/types/blocks';
import { router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';

type Form = { title: string; body: string; icon: string; ok: string; url: string; fields: DialogField[]; tone: DialogTone; extra: Record<string, unknown>; initial?: Record<string, string> };
type Confirm = { title: string; body: string; icon: string; ok: string; url: string; tone: DialogTone };

/**
 * Exécution des actions déclarées par le serveur : navigation, téléchargement, envoi, formulaire, confirmation,
 * fiche d’indicateur et message d’erreur. Un module peut traiter ses propres types d’action avec `custom`
 * (qui renvoie true quand il a pris l’action en charge). Rendre `dialog` dans la page.
 */
export function useBlockActions(sheets: Record<string, KpiSheet>, custom?: (action: BlockAction, show: (dialog: ReactNode) => void, close: () => void) => boolean) {
    const ui = useAdminUi();
    const [dialog, setDialog] = useState<ReactNode>(null);
    const close = () => setDialog(null);
    const post = (url: string) =>
        router.post(url, {}, { preserveScroll: true, onError: (errors) => ui.toast(Object.values(errors)[0] ?? 'Action impossible.', { tone: 'error' }) });

    const kpi = (id: string) => {
        const sheet = sheets[id];
        if (!sheet) return;
        ui.openPanel({
            kicker: sheet.kicker,
            title: sheet.title,
            badges: [sheet.badge],
            meta: sheet.meta,
            actions: [{ label: 'Fermer', icon: 'close', onClick: () => {} }, ...(sheet.drill ? [{ label: 'Voir le détail filtré', icon: 'filter_alt', variant: 'primary' as const, onClick: () => router.visit(sheet.drill as string) }] : [])],
        });
    };

    const run = (action: BlockAction | null | undefined) => {
        if (!action) return;
        if (custom?.(action, setDialog, close)) return;
        switch (action.type) {
            case 'link':
                return router.visit(action.href as string);
            case 'download':
                window.location.href = action.href as string;
                return;
            case 'post':
                return post(action.url as string);
            case 'form': {
                const f = action as Form;
                return setDialog(<FormDialog title={f.title} body={f.body} icon={f.icon} tone={f.tone} okLabel={f.ok} url={f.url} fields={f.fields} extra={f.extra} initial={f.initial} onClose={close} />);
            }
            case 'confirm': {
                const c = action as Confirm;
                return ui.confirm({ title: c.title, body: c.body, icon: c.icon, tone: c.tone, ok: c.ok, onConfirm: () => post(c.url) });
            }
            case 'kpi':
                return kpi(action.id as string);
            case 'error':
                return ui.toast(action.message as string, { tone: 'error' });
        }
    };

    return { run, dialog };
}
