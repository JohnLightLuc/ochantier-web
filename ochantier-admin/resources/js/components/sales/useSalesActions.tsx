import FormDialog from '@/components/overlays/FormDialog';
import { ReasonDialog } from '@/components/promoters/dialogs';
import { useAdminUi } from '@/contexts/AdminUiContext';
import { formatNumber } from '@/lib/format';
import type { Option } from '@/components/ui/Field';
import type { SalesAccess, SalesObjective, SalesZoneRequest } from '@/types/sales';
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

        /** Sans `worker`, tout le portefeuille part, avec les prospects non transmis. */
        transfer: (a: AgentRef, colleagues: Option[], worker?: { id: string; name: string }) =>
            setDialog(
                <FormDialog
                    title={worker ? `Transférer ${worker.name}` : 'Transférer le portefeuille'}
                    body={worker ? 'Le nouveau commercial suit l’ouvrier et corrige son dossier. La commission et les résultats restent au recruteur.' : `Tous les ouvriers suivis par ${a.name} et ses prospects non transmis passent au commercial choisi. Les commissions déjà engagées restent au recruteur ; celles des prospects iront au nouveau commercial.`}
                    icon="move_down"
                    okLabel="Transférer"
                    url={`${base(a)}/ouvriers/transfert`}
                    onClose={close}
                    extra={worker ? { worker_ids: [worker.id] } : undefined}
                    fields={[
                        { name: 'to_commercial_id', label: 'Nouveau commercial', type: 'select', required: true, full: true, options: colleagues },
                        { name: 'reason', label: 'Motif', type: 'textarea', required: true },
                    ]}
                />,
            ),

        addZone: (a: AgentRef & { zoneList: { id: string }[] }, communes: Option[]) =>
            setDialog(
                <FormDialog
                    title="Attribuer une zone"
                    body={a.name}
                    icon="add_location_alt"
                    okLabel="Attribuer"
                    url={`${base(a)}/zones`}
                    onClose={close}
                    fields={[
                        { name: 'commune_id', label: 'Commune', type: 'select', required: true, full: true, options: communes.filter((c) => !a.zoneList.some((z) => z.id === c.value)) },
                        { name: 'kind', label: 'Type d’attribution', type: 'select', required: true, full: true, options: [{ value: 'secondary', label: 'Zone secondaire' }, { value: 'primary', label: 'Zone principale (l’actuelle devient secondaire)' }] },
                    ]}
                />,
            ),

        removeZone: (a: AgentRef, zone: { id: string; name: string }) => ui.confirm({ tone: 'danger', icon: 'wrong_location', title: `Retirer ${zone.name} ?`, body: 'Les ouvriers de cette commune restent suivis par ce commercial.', ok: 'Retirer', onConfirm: () => del(`${base(a)}/zones/${zone.id}`) }),

        acceptZoneRequest: (a: AgentRef, r: SalesZoneRequest) =>
            setDialog(
                <FormDialog
                    title="Accepter le changement de zone"
                    body={`Les zones de ${a.name} seront remplacées par : ${r.communes.map((c) => c.name).join(', ')}.`}
                    icon="check_circle"
                    tone="success"
                    okLabel="Accepter"
                    url={`${base(a)}/demandes-zone/${r.id}/acceptation`}
                    onClose={close}
                    fields={[
                        { name: 'primary_commune_id', label: 'Zone principale', type: 'select', required: true, full: true, options: r.communes.map((c) => ({ value: c.id, label: c.name })) },
                        { name: 'comment', label: 'Commentaire', type: 'textarea' },
                    ]}
                />,
            ),

        refuseZoneRequest: (a: AgentRef, r: SalesZoneRequest) =>
            setDialog(<ReasonDialog title="Refuser le changement de zone ?" body="Le commercial verra ce motif dans ses notifications." icon="cancel" okLabel="Refuser la demande" reasonLabel="Motif du refus *" categories={['Zone déjà couverte', 'Besoin non justifié', 'Résultats insuffisants', 'Autre']} url={`${base(a)}/demandes-zone/${r.id}/refus`} onClose={close} />),

        objective: (a: AgentRef, months: Option[], objective?: SalesObjective) =>
            setDialog(
                <FormDialog
                    title={objective ? `Modifier l’objectif · ${objective.label}` : 'Fixer un objectif'}
                    body="Nombre d’ouvriers validés à atteindre sur le mois. Le commercial le voit dans son application."
                    icon="flag"
                    okLabel="Enregistrer"
                    url={`${base(a)}/objectifs`}
                    onClose={close}
                    initial={objective ? { month: objective.month, target: String(objective.target), comment: objective.comment ?? '' } : {}}
                    fields={[
                        { name: 'month', label: 'Mois', type: 'select', required: true, options: objective ? [{ value: objective.month, label: objective.label }] : months },
                        { name: 'target', label: 'Ouvriers validés visés', type: 'number', required: true },
                        { name: 'comment', label: 'Commentaire', type: 'textarea' },
                    ]}
                />,
            ),

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
