import FormDialog from '@/components/overlays/FormDialog';
import { useAdminUi } from '@/contexts/AdminUiContext';
import { router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';

type OfferRef = { id: string; reference: string; deadline: string | null };

/** Décisions sur un besoin et leurs fenêtres de saisie. Rendre `dialog` dans la page. */
export function useOfferActions() {
    const ui = useAdminUi();
    const [dialog, setDialog] = useState<ReactNode>(null);
    const close = () => setDialog(null);
    const base = (o: OfferRef) => `/besoins/${o.id}`;
    const reasonFields = (options: string[]) => [
        { name: 'category', label: 'Motif', type: 'select' as const, required: true, full: true, options },
        { name: 'reason', label: 'Précisions', type: 'textarea' as const, required: true },
    ];

    return {
        dialog,

        suspend: (o: OfferRef) =>
            setDialog(<FormDialog title={`Suspendre ${o.reference} ?`} body="La recherche s’arrête et la proposition en attente est retirée. Le promoteur sera notifié." icon="pause_circle" tone="danger" okLabel="Suspendre" url={`${base(o)}/suspension`} onClose={close} fields={reasonFields(['Vérification du besoin', 'Demande du promoteur', 'Litige', 'Autre'])} />),

        resume: (o: OfferRef) => ui.confirm({ tone: 'success', icon: 'play_circle', title: `Reprendre ${o.reference} ?`, body: 'La recherche d’ouvriers reprend et le promoteur sera notifié.', ok: 'Reprendre', onConfirm: () => router.delete(`${base(o)}/suspension`, { preserveScroll: true }) }),

        extend: (o: OfferRef) =>
            setDialog(<FormDialog title="Prolonger la date limite" body={o.reference} icon="event_repeat" okLabel="Prolonger" url={`${base(o)}/prolongation`} onClose={close} initial={{ deadline: o.deadline ?? '' }} fields={[{ name: 'deadline', label: 'Nouvelle date limite', type: 'date', required: true, full: true }]} />),

        cancel: (o: OfferRef) =>
            setDialog(<FormDialog title={`Annuler ${o.reference} ?`} body="Le besoin et sa proposition en attente seront annulés. Cette action est définitive." icon="cancel" tone="danger" okLabel="Annuler le besoin" url={`${base(o)}/annulation`} onClose={close} fields={reasonFields(['Demande du promoteur', 'Doublon', 'Informations erronées', 'Autre'])} />),

        close: (o: OfferRef) =>
            setDialog(<FormDialog title={`Clôturer ${o.reference} ?`} body="La recherche s’arrête et les postes restants sont annulés. L’équipe déjà affectée n’est pas touchée." icon="task_alt" tone="warning" okLabel="Clôturer" url={`${base(o)}/cloture`} onClose={close} fields={reasonFields(['Équipe suffisante', 'Demande du promoteur', 'Autre'])} />),
    };
}

export type OfferActions = ReturnType<typeof useOfferActions>;
