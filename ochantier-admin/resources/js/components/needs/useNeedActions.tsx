import FormDialog from '@/components/overlays/FormDialog';
import { useAdminUi } from '@/contexts/AdminUiContext';
import { formatDate } from '@/lib/format';
import type { NeedActionRef, OfferRef } from '@/types/needs';
import { router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';

const addDays = (iso: string, n: number) => {
    const d = new Date(`${iso}T12:00:00`);
    d.setDate(d.getDate() + n);
    return d.toISOString().slice(0, 10);
};

/** Actions sur un besoin du module Affectations, encore sur ses données locales. Rendre `dialog` dans la page. */
export function useNeedActions() {
    const ui = useAdminUi();
    const [dialog, setDialog] = useState<ReactNode>(null);
    const close = () => setDialog(null);
    const base = (n: { slug: string }) => `/besoins/${n.slug}`;

    const actions = {
        dialog,

        match: (n: NeedActionRef) => (n.canMatch ? router.visit(`/matching?besoin=${n.slug}`) : ui.toast('Aucun poste ouvert à couvrir pour ce besoin. Actualisez d’abord le besoin expiré.', { tone: 'error' })),

        refreshOffer: (n: NeedActionRef, o: OfferRef) =>
            setDialog(
                <FormDialog
                    title={`Actualiser l’offre ${o.reference} ?`}
                    body={`Le besoin sera republié pour les ${o.rest} ouvrier(s) manquant(s) (${o.trade}) avec une nouvelle date limite. Les ${o.selected} ouvrier(s) déjà trouvé(s) restent affectés.`}
                    icon="autorenew"
                    tone="success"
                    okLabel="Actualiser et republier"
                    url={`${base(n)}/offres/${o.id}/actualisation`}
                    onClose={close}
                    initial={{ deadline: addDays(n.today, 5), starts_on: o.startPassed ? addDays(n.today, 7) : '' }}
                    fields={[
                        { name: 'deadline', label: 'Nouvelle date limite de candidature', type: 'date', required: true },
                        ...(o.startPassed ? [{ name: 'starts_on', label: 'Nouvelle date de démarrage', type: 'date' as const, required: true, hint: `Le démarrage initial (${formatDate(o.startsOn)}) est dépassé.` }] : []),
                    ]}
                />,
            ),

        refreshAll: (n: NeedActionRef) => {
            if (n.expiredOffers.length === 1) return actions.refreshOffer(n, n.expiredOffers[0]);
            setDialog(
                <FormDialog
                    title={`Actualiser ${n.expiredOffers.length} offres expirées ?`}
                    body={n.expiredOffers.map((o) => `${o.reference} (${o.trade})`).join(', ')}
                    icon="autorenew"
                    tone="success"
                    okLabel="Actualiser et republier"
                    url={`${base(n)}/actualisation`}
                    onClose={close}
                    initial={{ deadline: addDays(n.today, 5) }}
                    fields={[{ name: 'deadline', label: 'Nouvelle date limite de candidature', type: 'date', required: true, full: true }]}
                />,
            );
        },
    };

    return actions;
}
