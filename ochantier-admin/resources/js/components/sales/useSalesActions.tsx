import FormDialog from '@/components/overlays/FormDialog';
import { useAdminUi } from '@/contexts/AdminUiContext';
import { formatNumber } from '@/lib/format';
import { router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';

/** Champs d’un commercial utilisés par les actions. */
export type AgentRef = { id: string; name: string; status: { value: string } };

export const fcfa = (n: number) => `${formatNumber(n)} FCFA`;

/** Décisions sur le compte d’un commercial et leurs fenêtres de saisie. Rendre `dialog` dans la page. */
export function useSalesActions() {
    const ui = useAdminUi();
    const [dialog, setDialog] = useState<ReactNode>(null);
    const close = () => setDialog(null);
    const base = (a: AgentRef) => `/commerciaux/${a.id}`;

    return {
        dialog,

        suspend: (a: AgentRef) =>
            setDialog(
                <FormDialog
                    title={`Suspendre ${a.name} ?`}
                    body="Le commercial ne pourra plus se connecter et ses sessions seront fermées. Ses ouvriers, ses dossiers et ses gains sont conservés."
                    icon="block"
                    tone="danger"
                    okLabel="Suspendre"
                    url={`${base(a)}/suspension`}
                    onClose={close}
                    fields={[
                        { name: 'category', label: 'Motif', type: 'select', required: true, full: true, options: ['Profils frauduleux ou dupliqués', 'Inactivité prolongée', 'Comportement inapproprié', 'Demande du commercial', 'Autre'] },
                        { name: 'reason', label: 'Précisions', type: 'textarea', required: true },
                    ]}
                />,
            ),

        reactivate: (a: AgentRef) =>
            ui.confirm({
                tone: 'success',
                icon: 'settings_backup_restore',
                title: `Réactiver ${a.name} ?`,
                body: a.status.value === 'termine' ? 'Le contrat reprend : le commercial pourra se reconnecter avec son numéro et son mot de passe.' : 'Le commercial pourra se reconnecter avec son numéro et son mot de passe.',
                ok: 'Réactiver',
                onConfirm: () => router.post(`${base(a)}/reactivation`, {}, { preserveScroll: true }),
            }),

        terminate: (a: AgentRef) =>
            setDialog(
                <FormDialog
                    title={`Mettre fin au contrat de ${a.name} ?`}
                    body="Le compte sera fermé et ses sessions déconnectées. Les ouvriers restent rattachés à ce commercial, et ses gains restent dus. La fiche pourra être réactivée."
                    icon="person_off"
                    tone="danger"
                    okLabel="Mettre fin au contrat"
                    url={`${base(a)}/fin-de-contrat`}
                    onClose={close}
                    fields={[
                        { name: 'category', label: 'Motif', type: 'select', required: true, full: true, options: ['Fin de contrat', 'Démission', 'Faute grave', 'Compte créé par erreur', 'Autre'] },
                        { name: 'reason', label: 'Précisions', type: 'textarea', required: true },
                    ]}
                />,
            ),
    };
}

export type SalesActions = ReturnType<typeof useSalesActions>;
