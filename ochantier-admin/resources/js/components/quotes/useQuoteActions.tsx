import FormDialog from '@/components/overlays/FormDialog';
import { ReasonDialog } from '@/components/promoters/dialogs';
import type { Quote } from '@/types/quotes';
import { useState, type ReactNode } from 'react';

const CANCEL_CATEGORIES = ['Demande en double', 'Demande du promoteur', 'Informations du chantier incomplètes', 'Autre'];

/** Décisions sur une demande de devis et leurs fenêtres de saisie. Rendre `dialog` dans la page. */
export function useQuoteActions() {
    const [dialog, setDialog] = useState<ReactNode>(null);
    const close = () => setDialog(null);
    const base = (q: Quote) => `/demandes-devis/${q.id}`;

    return {
        dialog,

        extend: (q: Quote) =>
            setDialog(
                <FormDialog
                    title="Prolonger la date limite"
                    body={`${q.reference} · la nouvelle date s’applique à tous les métiers de la demande. Les professionnels peuvent de nouveau proposer une visite.`}
                    icon="event_repeat"
                    okLabel="Prolonger"
                    url={`${base(q)}/prolongation`}
                    onClose={close}
                    initial={{ deadline: q.deadlineIso ?? '' }}
                    fields={[{ name: 'deadline', label: 'Nouvelle date limite', type: 'date', required: true, full: true }]}
                />,
            ),

        remind: (q: Quote, target: 'promoter' | 'contractor') =>
            setDialog(
                <FormDialog
                    title={target === 'promoter' ? `Relancer ${q.promoter}` : `Relancer ${q.contractor ?? 'le professionnel'}`}
                    body="Un rappel est envoyé dans l’application (notification et push). Sans message, un texte adapté à l’étape est utilisé. Une relance par jour au plus."
                    icon="notifications_active"
                    okLabel="Envoyer la relance"
                    okIcon="send"
                    url={`${base(q)}/relance`}
                    extra={{ target }}
                    onClose={close}
                    fields={[{ name: 'message', label: 'Message (facultatif)', type: 'textarea', full: true, placeholder: 'Ex. Merci de confirmer la réception du devis.' }]}
                />,
            ),

        decideVisit: (q: Quote, decision: 'confirmer' | 'annuler') =>
            setDialog(
                <FormDialog
                    title={decision === 'confirmer' ? 'Confirmer la visite ?' : 'Annuler la visite ?'}
                    body={
                        decision === 'confirmer'
                            ? `La visite de ${q.contractor ?? 'le professionnel'} est confirmée malgré le signalement du promoteur : ses frais de visite deviennent dus. Le promoteur et le professionnel sont prévenus avec le motif.`
                            : `La visite est considérée comme non réalisée : ${q.contractor ?? 'le professionnel'} doit la refaire puis la déclarer de nouveau, ses frais restent bloqués. Le promoteur et le professionnel sont prévenus avec le motif.`
                    }
                    icon={decision === 'confirmer' ? 'task_alt' : 'event_busy'}
                    tone={decision === 'confirmer' ? undefined : 'danger'}
                    okLabel={decision === 'confirmer' ? 'Confirmer la visite' : 'Annuler la visite'}
                    url={`${base(q)}/visite`}
                    extra={{ decision }}
                    onClose={close}
                    fields={[{ name: 'reason', label: 'Motif de la décision', type: 'textarea', required: true, full: true, placeholder: 'Ex. Photos de la visite fournies, échange avec le promoteur…' }]}
                />,
            ),

        cancel: (q: Quote) =>
            setDialog(
                <ReasonDialog
                    title={`Annuler la demande ${q.reference} ?`}
                    body={`Toute la demande est annulée${q.tradesCount > 1 ? ` (ses ${q.tradesCount} métiers)` : ''} : le chantier revient au brouillon, les propositions ne sont pas retenues, le promoteur et les professionnels sont prévenus avec le motif.`}
                    icon="cancel"
                    okLabel="Annuler la demande"
                    reasonLabel="Motif de l’annulation *"
                    categories={CANCEL_CATEGORIES}
                    url={`${base(q)}/annulation`}
                    placeholder="Précisez le motif (communiqué au promoteur et aux professionnels)."
                    onClose={close}
                />,
            ),
    };
}
