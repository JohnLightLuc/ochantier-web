import { useAdminUi } from '@/contexts/AdminUiContext';
import { router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import { DocumentDialog, NotifyDialog, ReasonDialog } from './dialogs';

type PromoterRef = { slug: string; name: string; representative: string };

const SUSPENSION_CATEGORIES = ['Retards de paiement', 'Documents KYC non conformes', 'Signalements répétés', 'Demande du promoteur', 'Autre'];

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
                    body="Le promoteur ne pourra plus publier de besoin ni initier de paiement. Les chantiers en cours restent visibles par les ouvriers affectés."
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
                body: 'Le promoteur retrouvera l’accès complet à la plateforme et sera notifié par e-mail.',
                ok: 'Réactiver le compte',
                onConfirm: () => router.delete(`${base(p)}/suspension`, { preserveScroll: true }),
            }),

        /** `unverified` : nombre de pièces obligatoires pas encore vérifiées, signalé dans la confirmation. */
        validate: (p: PromoterRef, unverified = 0) =>
            ui.confirm({
                tone: 'success',
                icon: 'verified',
                title: 'Valider le compte ?',
                body:
                    (unverified ? `Attention : ${unverified} document${unverified > 1 ? 's' : ''} KYC obligatoire${unverified > 1 ? 's ne sont' : ' n’est'} pas encore vérifié${unverified > 1 ? 's' : ''}. ` : '') +
                    `Le compte ${p.name} passera au statut « Actif ».`,
                ok: 'Valider le compte',
                onConfirm: () => router.post(`${base(p)}/validation`, {}, { preserveScroll: true }),
            }),

        notify: (p: PromoterRef, subject?: string) => setDialog(<NotifyDialog recipient={`${p.representative} · ${p.name}`} subject={subject} url={`${base(p)}/notifications`} onClose={close} />),

        addDocument: (p: PromoterRef, type = 'tax_certificate') => setDialog(<DocumentDialog promoterName={p.name} type={type} url={`${base(p)}/documents`} onClose={close} />),
    };
}
