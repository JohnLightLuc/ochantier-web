import FormDialog from '@/components/overlays/FormDialog';
import { ReasonDialog } from '@/components/promoters/dialogs';
import { useAdminUi } from '@/contexts/AdminUiContext';
import type { ContractOffer } from '@/types/contractOffers';
import { router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';

const CANCEL_CATEGORIES = ['Chantier reporté ou abandonné', 'Demande du promoteur', 'Offre non conforme', 'Autre'];

/** Décisions sur une offre contractuelle et leurs fenêtres de saisie. Rendre `dialog` dans la page. */
export function useContractOfferActions() {
    const ui = useAdminUi();
    const [dialog, setDialog] = useState<ReactNode>(null);
    const close = () => setDialog(null);
    const base = (o: ContractOffer) => `/offres-contractuelles/${o.id}`;

    return {
        dialog,

        extend: (o: ContractOffer) =>
            setDialog(
                <FormDialog
                    title="Prolonger la date limite"
                    body={`${o.reference} · les professionnels peuvent répondre jusqu’à la nouvelle date.`}
                    icon="event_repeat"
                    okLabel="Prolonger"
                    url={`${base(o)}/prolongation`}
                    onClose={close}
                    initial={{ deadline: o.deadlineIso ?? '' }}
                    fields={[{ name: 'deadline', label: 'Nouvelle date limite', type: 'date', required: true, full: true }]}
                />,
            ),

        remindPromoter: (o: ContractOffer) =>
            setDialog(
                <FormDialog
                    title={`Relancer ${o.promoter}`}
                    body="Un rappel est envoyé dans l’application (notification et push) : comparer et choisir une offre, ou payer l’acompte. Sans message, un texte adapté est utilisé. Une relance par jour au plus."
                    icon="notifications_active"
                    okLabel="Envoyer la relance"
                    okIcon="send"
                    url={`${base(o)}/relance`}
                    extra={{ target: 'promoter' }}
                    onClose={close}
                    fields={[{ name: 'message', label: 'Message (facultatif)', type: 'textarea', full: true, placeholder: 'Ex. Trois professionnels ont répondu : merci de choisir avant vendredi.' }]}
                />,
            ),

        remindContractors: (o: ContractOffer) =>
            ui.confirm({
                tone: 'primary',
                icon: 'campaign',
                title: 'Relancer les professionnels ?',
                body: 'Les professionnels du métier et de la zone qui n’ont pas encore répondu reçoivent de nouveau l’offre (une fois par jour au plus).',
                ok: 'Relancer',
                onConfirm: () => router.post(`${base(o)}/relance`, { target: 'contractors' }, { preserveScroll: true }),
            }),

        cancel: (o: ContractOffer) =>
            setDialog(
                <ReasonDialog
                    title={`Annuler l’offre ${o.reference} ?`}
                    body="Les réponses sont annulées et le chantier revient au brouillon : le promoteur pourra modifier et republier son offre. Le promoteur et les professionnels qui ont répondu sont prévenus avec le motif."
                    icon="cancel"
                    okLabel="Annuler l’offre"
                    reasonLabel="Motif de l’annulation *"
                    categories={CANCEL_CATEGORIES}
                    url={`${base(o)}/annulation`}
                    placeholder="Précisez le motif (communiqué au promoteur et aux professionnels)."
                    onClose={close}
                />,
            ),
    };
}
