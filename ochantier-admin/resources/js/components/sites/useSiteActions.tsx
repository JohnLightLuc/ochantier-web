import FormDialog from '@/components/overlays/FormDialog';
import { useAdminUi } from '@/contexts/AdminUiContext';
import { router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';

/** Champs d’un chantier utilisés par les actions. */
export type SiteRef = { id: string; name: string; pendingDays: number };

/** Décisions sur un chantier et leurs fenêtres de saisie. Rendre `dialog` dans la page. */
export function useSiteActions() {
    const ui = useAdminUi();
    const [dialog, setDialog] = useState<ReactNode>(null);
    const close = () => setDialog(null);
    const base = (s: SiteRef) => `/chantiers/${s.id}`;
    const post = (url: string) => router.post(url, {}, { preserveScroll: true });

    return {
        dialog,

        suspend: (s: SiteRef) =>
            setDialog(
                <FormDialog
                    title={`Suspendre ${s.name} ?`}
                    body="Les pointages et les nouvelles réservations seront bloqués. L’équipe reste affectée, les journées déjà pointées et les paiements ne changent pas. Le promoteur sera notifié."
                    icon="block"
                    tone="danger"
                    okLabel="Suspendre"
                    url={`${base(s)}/suspension`}
                    onClose={close}
                    fields={[
                        { name: 'category', label: 'Motif', type: 'select', required: true, full: true, options: ['Sécurité', 'Litige', 'Impayé', 'Demande du promoteur', 'Autre'] },
                        { name: 'reason', label: 'Précisions', type: 'textarea', required: true },
                    ]}
                />,
            ),

        resume: (s: SiteRef) => ui.confirm({ tone: 'success', icon: 'play_circle', title: `Reprendre ${s.name} ?`, body: 'Les pointages seront rouverts et le promoteur sera notifié.', ok: 'Reprendre', onConfirm: () => router.delete(`${base(s)}/suspension`, { preserveScroll: true }) }),

        cancel: (s: SiteRef) =>
            setDialog(
                <FormDialog
                    title={`Annuler ${s.name} ?`}
                    body="Le chantier, ses offres et les propositions en attente seront annulés. Cette action est définitive."
                    icon="cancel"
                    tone="danger"
                    okLabel="Annuler le chantier"
                    url={`${base(s)}/annulation`}
                    onClose={close}
                    fields={[
                        { name: 'category', label: 'Motif', type: 'select', required: true, full: true, options: ['Demande du promoteur', 'Doublon', 'Informations erronées', 'Autre'] },
                        { name: 'reason', label: 'Précisions', type: 'textarea', required: true },
                    ]}
                />,
            ),

        close: (s: SiteRef) => {
            if (s.pendingDays > 0) return ui.toast(`${s.pendingDays} journée(s) de pointage à valider avant la clôture.`, { tone: 'error' });
            ui.confirm({ tone: 'warning', icon: 'task_alt', title: `Clôturer ${s.name} ?`, body: 'Les missions et les affectations seront terminées. Le promoteur pourra noter son équipe. Cette action est définitive.', ok: 'Clôturer', onConfirm: () => post(`${base(s)}/cloture`) });
        },

        archive: (s: SiteRef) => ui.confirm({ icon: 'archive', title: `Archiver ${s.name} ?`, body: 'Le chantier sort de la liste courante et reste consultable dans les archivés.', ok: 'Archiver', onConfirm: () => post(`${base(s)}/archivage`) }),
    };
}

export type SiteActions = ReturnType<typeof useSiteActions>;
