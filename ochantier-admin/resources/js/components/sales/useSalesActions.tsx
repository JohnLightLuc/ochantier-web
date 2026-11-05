import FormDialog from '@/components/overlays/FormDialog';
import { ReasonDialog } from '@/components/promoters/dialogs';
import { useAdminUi } from '@/contexts/AdminUiContext';
import { formatNumber } from '@/lib/format';
import type { SalesAccess } from '@/types/sales';
import type { WorkerDocument } from '@/types/workers';
import { router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';

/** Champs d’un commercial utilisés par les actions. */
export type AgentRef = { id: string; name: string; phone?: string | null; email?: string | null; status: { value: string } };

export const fcfa = (n: number) => `${formatNumber(n)} FCFA`;

/** Décisions sur le compte d’un commercial et leurs fenêtres de saisie. Rendre `dialog` dans la page. */
export function useSalesActions() {
    const ui = useAdminUi();
    const [dialog, setDialog] = useState<ReactNode>(null);
    const close = () => setDialog(null);
    const base = (a: AgentRef) => `/commerciaux/${a.id}`;

    const post = (url: string) => router.post(url, {}, { preserveScroll: true });
    const del = (url: string) => router.delete(url, { preserveScroll: true });

    return {
        dialog,

        sendLink: (a: AgentRef) => {
            const active = a.status.value === 'actif';
            ui.confirm({
                tone: active ? 'warning' : 'primary',
                icon: active ? 'lock_reset' : 'forward_to_inbox',
                title: active ? 'Réinitialiser l’accès ?' : 'Envoyer le lien d’activation ?',
                body: `Un lien valable 72 heures sera envoyé au ${a.phone ?? 'numéro du commercial'}${a.email ? ` et à ${a.email}` : ''}. Le lien précédent ne fonctionnera plus.${active ? ' Toutes ses sessions seront fermées.' : ''}`,
                ok: 'Envoyer le lien',
                onConfirm: () => post(`${base(a)}/lien-acces`),
            });
        },

        revokeLink: (a: AgentRef) => ui.confirm({ tone: 'danger', icon: 'link_off', title: 'Révoquer le lien ?', body: 'Le lien actuel ne fonctionnera plus. Vous pourrez en envoyer un nouveau.', ok: 'Révoquer', onConfirm: () => del(`${base(a)}/lien-acces`) }),

        revokeSession: (a: AgentRef, session?: SalesAccess['sessions'][number]) =>
            ui.confirm({
                tone: 'danger',
                icon: 'logout',
                title: session ? 'Fermer cette session ?' : 'Déconnecter tous les appareils ?',
                body: session ? session.device : 'Toutes les sessions ouvertes seront fermées immédiatement.',
                ok: session ? 'Fermer' : 'Tout déconnecter',
                onConfirm: () => del(`${base(a)}/sessions${session ? `/${session.id}` : ''}`),
            }),

        validateDocument: (a: AgentRef, d: WorkerDocument) => ui.confirm({ tone: 'success', icon: 'verified', title: 'Valider cette pièce ?', body: `« ${d.label} » sera marquée comme vérifiée.`, ok: 'Valider la pièce', onConfirm: () => post(`${base(a)}/documents/${d.id}/validation`) }),

        rejectDocument: (a: AgentRef, d: WorkerDocument) =>
            setDialog(<ReasonDialog title="Rejeter cette pièce ?" body={`« ${d.label} » devra être remplacée.`} icon="cancel" okLabel="Rejeter la pièce" reasonLabel="Motif du rejet *" categories={['Document illisible', 'Document expiré', 'Incohérence d’identité', 'Mauvais document', 'Autre']} url={`${base(a)}/documents/${d.id}/rejet`} onClose={close} />),

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
