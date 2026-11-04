import type { Option } from '@/components/ui/Field';
import { ReasonDialog } from '@/components/promoters/dialogs';
import { useAdminUi } from '@/contexts/AdminUiContext';
import type { WorkerDocument } from '@/types/workers';
import { router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import { CorrectionsDialog, SuspendDialog } from './dialogs';

type WorkerRef = { slug: string; name: string };

const REJECTION_CATEGORIES = ['Identité non vérifiable', 'Doublon potentiel', 'Expérience non justifiée', 'Documents non conformes', 'Autre'];

const DOCUMENT_CATEGORIES = ['Document illisible', 'Document expiré', 'Incohérence d’identité', 'Mauvais document', 'Autre'];

const EXCLUSION_CATEGORIES = ['Fraude avérée', 'Comportement grave', 'Usurpation d’identité', 'Autre'];

/** Actions sur un ouvrier et leurs fenêtres de saisie. Rendre `dialog` dans la page. */
export function useWorkerActions() {
    const ui = useAdminUi();
    const [dialog, setDialog] = useState<ReactNode>(null);
    const close = () => setDialog(null);
    const base = (w: WorkerRef) => `/ouvriers/${w.slug}`;
    const post = (url: string) => router.post(url, {}, { preserveScroll: true });

    return {
        dialog,
        close,

        validate: (w: WorkerRef) =>
            ui.confirm({
                tone: 'success',
                icon: 'verified',
                title: `Valider le dossier de ${w.name} ?`,
                body: 'L’ouvrier pourra être proposé sur les chantiers et la commission du commercial sera confirmée.',
                ok: 'Valider le dossier',
                onConfirm: () => post(`${base(w)}/validation`),
            }),

        corrections: (w: WorkerRef, documents: WorkerDocument[], fields: Option[]) =>
            setDialog(<CorrectionsDialog workerName={w.name} documents={documents.filter((d) => d.hasFile)} fields={fields} url={`${base(w)}/corrections`} onClose={close} />),

        reject: (w: WorkerRef) =>
            setDialog(
                <ReasonDialog
                    title={`Refuser le dossier de ${w.name} ?`}
                    body="Le refus est définitif : le commercial est notifié du motif et sa commission est annulée."
                    icon="person_cancel"
                    okLabel="Refuser le dossier"
                    reasonLabel="Motif du refus *"
                    categories={REJECTION_CATEGORIES}
                    url={`${base(w)}/rejet`}
                    placeholder="Précisez le motif (communiqué au commercial)."
                    onClose={close}
                />,
            ),

        suspend: (w: WorkerRef) => setDialog(<SuspendDialog workerName={w.name} url={`${base(w)}/suspension`} onClose={close} />),

        reactivate: (w: WorkerRef) =>
            ui.confirm({
                tone: 'success',
                icon: 'settings_backup_restore',
                title: `Réactiver ${w.name} ?`,
                body: 'L’ouvrier redevient validé et peut de nouveau être proposé sur les chantiers.',
                ok: 'Réactiver',
                onConfirm: () => router.delete(`${base(w)}/suspension`, { preserveScroll: true }),
            }),

        exclude: (w: WorkerRef) =>
            setDialog(
                <ReasonDialog
                    title={`Exclure ${w.name} de la plateforme ?`}
                    body="L’exclusion est définitive : l’ouvrier ne sera plus jamais proposé et ne pourra pas être réactivé."
                    icon="person_off"
                    okLabel="Exclure l’ouvrier"
                    reasonLabel="Motif de l’exclusion *"
                    categories={EXCLUSION_CATEGORIES}
                    url={`${base(w)}/exclusion`}
                    placeholder="Précisez le motif (inscrit au journal d’audit)."
                    onClose={close}
                />,
            ),

        validateDocument: (w: WorkerRef, d: WorkerDocument) =>
            ui.confirm({ tone: 'success', icon: 'verified', title: 'Valider ce document ?', body: `« ${d.label} »${d.reference ? ` (${d.reference})` : ''} sera marqué comme vérifié.`, ok: 'Valider le document', onConfirm: () => post(`${base(w)}/documents/${d.id}/validation`) }),

        rejectDocument: (w: WorkerRef, d: WorkerDocument) =>
            setDialog(
                <ReasonDialog
                    title="Rejeter ce document ?"
                    body={`« ${d.label} » sera marqué comme rejeté. Pour que le commercial le remplace, demandez ensuite une correction.`}
                    icon="cancel"
                    okLabel="Rejeter le document"
                    reasonLabel="Motif du rejet *"
                    categories={DOCUMENT_CATEGORIES}
                    url={`${base(w)}/documents/${d.id}/rejet`}
                    placeholder="Précisez le motif (visible dans le dossier)."
                    onClose={close}
                />,
            ),
    };
}

export type WorkerActions = ReturnType<typeof useWorkerActions>;
