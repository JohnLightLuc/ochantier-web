import { useAdminUi } from '@/contexts/AdminUiContext';
import type { PromoterDocument } from '@/types/promoters';
import { router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import { CorrectionsDialog, NotifyDialog, ReasonDialog } from './dialogs';

type PromoterRef = { slug: string; name: string; representative: string };

const SUSPENSION_CATEGORIES = ['Retards de paiement', 'Documents KYC non conformes', 'Signalements répétés', 'Demande du promoteur', 'Autre'];

const REFUSAL_CATEGORIES = ['Document falsifié', 'Identité non vérifiable', 'Entreprise inexistante', 'Doublon de compte', 'Autre'];

/** Actions sur un compte promoteur, avec leurs fenêtres de confirmation. Rendre `dialog` dans la page. */
export function usePromoterActions() {
    const ui = useAdminUi();
    const [dialog, setDialog] = useState<ReactNode>(null);
    const close = () => setDialog(null);
    const base = (p: PromoterRef) => `/promoteurs/${p.slug}`;

    return {
        dialog,

        suspend: (p: PromoterRef) =>
            setDialog(
                <ReasonDialog
                    title={`Suspendre ${p.name} ?`}
                    body="Le promoteur est déconnecté et ne peut plus se connecter. Son dossier de vérification est conservé tel quel."
                    icon="block"
                    okLabel="Suspendre le compte"
                    reasonLabel="Motif de la suspension *"
                    categories={SUSPENSION_CATEGORIES}
                    url={`${base(p)}/suspension`}
                    onClose={close}
                />,
            ),

        reactivate: (p: PromoterRef) =>
            ui.confirm({
                tone: 'success',
                icon: 'settings_backup_restore',
                title: `Réactiver ${p.name} ?`,
                body: 'Le promoteur pourra de nouveau se connecter et sera notifié. Son dossier retrouve le statut qu’il avait avant la suspension.',
                ok: 'Réactiver le compte',
                onConfirm: () => router.delete(`${base(p)}/suspension`, { preserveScroll: true }),
            }),

        validate: (p: PromoterRef) =>
            ui.confirm({
                tone: 'success',
                icon: 'verified',
                title: 'Valider le compte ?',
                body: `Le dossier de ${p.name} passera au statut « Validé » et le promoteur sera notifié.`,
                ok: 'Valider le compte',
                onConfirm: () => router.post(`${base(p)}/validation`, {}, { preserveScroll: true }),
            }),

        corrections: (p: PromoterRef, documents: PromoterDocument[]) => setDialog(<CorrectionsDialog promoterName={p.name} documents={documents.filter((d) => d.hasFile)} url={`${base(p)}/corrections`} onClose={close} />),

        refuse: (p: PromoterRef) =>
            setDialog(
                <ReasonDialog
                    title={`Refuser le dossier de ${p.name} ?`}
                    body="Le promoteur est notifié du refus et du motif. Il devra contacter le support pour reprendre sa vérification."
                    icon="gpp_bad"
                    okLabel="Refuser le dossier"
                    reasonLabel="Motif du refus *"
                    categories={REFUSAL_CATEGORIES}
                    url={`${base(p)}/refus`}
                    onClose={close}
                />,
            ),

        notify: (p: PromoterRef, subject?: string) => setDialog(<NotifyDialog recipient={`${p.representative} · ${p.name}`} subject={subject} url={`${base(p)}/notifications`} onClose={close} />),
    };
}
