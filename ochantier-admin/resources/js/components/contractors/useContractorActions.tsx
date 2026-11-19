import { ReasonDialog } from '@/components/promoters/dialogs';
import { useAdminUi } from '@/contexts/AdminUiContext';
import type { ContractorDocument } from '@/types/contractors';
import { router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import { ComplementDialog } from './dialogs';

type ContractorRef = { id: string; name: string };

const REJECTION_CATEGORIES = ['Identité non vérifiable', 'Entreprise non immatriculée', 'Doublon potentiel', 'Pièces non conformes', 'Autre'];

const SUSPENSION_CATEGORIES = ['Documents frauduleux', 'Comportement signalé', 'Litige en cours', 'Autre'];

const DOCUMENT_REJECTION_CATEGORIES = ['Document non conforme', 'Document expiré', 'Incohérence avec le dossier', 'Mauvais document', 'Autre'];

const DOCUMENT_COMPLEMENT_CATEGORIES = ['Document illisible', 'Page ou verso manquant', 'Document incomplet', 'Autre'];

/** Actions sur un entrepreneur et leurs fenêtres de saisie. Rendre `dialog` dans la page. */
export function useContractorActions() {
    const ui = useAdminUi();
    const [dialog, setDialog] = useState<ReactNode>(null);
    const close = () => setDialog(null);
    const base = (c: ContractorRef) => `/entrepreneurs/${c.id}`;

    return {
        dialog,
        close,

        validate: (c: ContractorRef) =>
            ui.confirm({
                tone: 'success',
                icon: 'verified',
                title: `Valider le dossier de ${c.name} ?`,
                body: 'Le profil passe « Vérifié » : le professionnel peut répondre aux demandes de devis et aux offres contractuelles. Les pièces encore en attente sont validées avec le dossier.',
                ok: 'Valider le dossier',
                onConfirm: () => router.post(`${base(c)}/validation`, {}, { preserveScroll: true }),
            }),

        complement: (c: ContractorRef, documents: ContractorDocument[]) => setDialog(<ComplementDialog contractorName={c.name} documents={documents} url={`${base(c)}/complement`} onClose={close} />),

        reject: (c: ContractorRef) =>
            setDialog(
                <ReasonDialog
                    title={`Rejeter le dossier de ${c.name} ?`}
                    body="Le professionnel est notifié du motif. Il pourra corriger son dossier et le soumettre de nouveau."
                    icon="person_cancel"
                    okLabel="Rejeter le dossier"
                    reasonLabel="Motif du rejet *"
                    categories={REJECTION_CATEGORIES}
                    url={`${base(c)}/rejet`}
                    placeholder="Précisez le motif (communiqué au professionnel)."
                    onClose={close}
                />,
            ),

        suspend: (c: ContractorRef) =>
            setDialog(
                <ReasonDialog
                    title={`Suspendre le compte de ${c.name} ?`}
                    body="Le professionnel garde l’accès en consultation, mais ne peut plus répondre aux opportunités ni modifier son dossier."
                    icon="block"
                    okLabel="Suspendre le compte"
                    reasonLabel="Motif de la suspension *"
                    categories={SUSPENSION_CATEGORIES}
                    url={`${base(c)}/suspension`}
                    placeholder="Précisez le motif (communiqué au professionnel)."
                    onClose={close}
                />,
            ),

        reactivate: (c: ContractorRef) =>
            ui.confirm({
                tone: 'success',
                icon: 'settings_backup_restore',
                title: `Réactiver le compte de ${c.name} ?`,
                body: 'Le professionnel peut de nouveau répondre aux opportunités.',
                ok: 'Réactiver',
                onConfirm: () => router.delete(`${base(c)}/suspension`, { preserveScroll: true }),
            }),

        confirmDocument: (c: ContractorRef, d: ContractorDocument) => router.post(`${base(c)}/pieces/${d.id}/confirmation`, {}, { preserveScroll: true, preserveState: true }),

        askDocument: (c: ContractorRef, d: ContractorDocument) =>
            setDialog(
                <ReasonDialog
                    title="Demander un complément pour cette pièce ?"
                    body={`« ${d.label} » sera à remplacer. Le professionnel en sera prévenu avec la demande de complément sur le dossier.`}
                    icon="edit_document"
                    okLabel="Marquer la pièce à compléter"
                    reasonLabel="Ce qu’il faut fournir *"
                    categories={DOCUMENT_COMPLEMENT_CATEGORIES}
                    url={`${base(c)}/pieces/${d.id}/rejet`}
                    placeholder="Ex. Le verso de la pièce est illisible."
                    onClose={close}
                />,
            ),

        rejectDocument: (c: ContractorRef, d: ContractorDocument) =>
            setDialog(
                <ReasonDialog
                    title="Rejeter cette pièce ?"
                    body={`« ${d.label} » sera marquée comme rejetée. Le professionnel en sera prévenu avec la demande de complément sur le dossier.`}
                    icon="cancel"
                    okLabel="Rejeter la pièce"
                    reasonLabel="Motif du rejet *"
                    categories={DOCUMENT_REJECTION_CATEGORIES}
                    url={`${base(c)}/pieces/${d.id}/rejet`}
                    placeholder="Précisez le motif (visible dans le dossier)."
                    onClose={close}
                />,
            ),
    };
}

export type ContractorActions = ReturnType<typeof useContractorActions>;
