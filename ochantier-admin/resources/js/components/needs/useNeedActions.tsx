import FormDialog from '@/components/overlays/FormDialog';
import { useAdminUi } from '@/contexts/AdminUiContext';
import { formatDate } from '@/lib/format';
import type { NeedActionRef, OfferRef } from '@/types/needs';
import { router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';

export type NeedAction = { icon: string; label: string; onClick: () => void; primary?: boolean; danger?: boolean };

const addDays = (iso: string, n: number) => {
    const d = new Date(`${iso}T12:00:00`);
    d.setDate(d.getDate() + n);
    return d.toISOString().slice(0, 10);
};

/** Actions sur un besoin et ses offres, avec leurs fenêtres de saisie. Rendre `dialog` dans la page. */
export function useNeedActions() {
    const ui = useAdminUi();
    const [dialog, setDialog] = useState<ReactNode>(null);
    const close = () => setDialog(null);
    const base = (n: { slug: string }) => `/besoins/${n.slug}`;
    const post = (url: string) => router.post(url, {}, { preserveScroll: true });

    const reasonDialog = (n: NeedActionRef, title: string, body: string, ok: string, url: string, icon: string) =>
        setDialog(<FormDialog title={title} body={body} icon={icon} tone="danger" okLabel={ok} url={`${base(n)}/${url}`} onClose={close} fields={[{ name: 'reason', label: 'Motif', type: 'textarea', required: true }]} />);

    const actions = {
        dialog,
        close,

        match: (n: NeedActionRef) => (n.canMatch ? router.visit(`/matching?besoin=${n.slug}`) : ui.toast('Aucun poste ouvert à couvrir pour ce besoin. Actualisez d’abord le besoin expiré.', { tone: 'error' })),

        follow: (n: NeedActionRef) => router.visit(`/affectations/besoins/${n.slug}`),

        suspend: (n: NeedActionRef) => reasonDialog(n, `Suspendre le besoin ${n.reference} ?`, 'Les offres ouvertes seront suspendues ; les candidatures restent consultables.', 'Suspendre', 'suspension', 'pause_circle'),

        cancel: (n: NeedActionRef) => reasonDialog(n, `Annuler le besoin ${n.reference} ?`, 'Les offres non couvertes seront annulées et les candidats notifiés. Les affectations confirmées restent gérées dans le module Affectations.', 'Annuler le besoin', 'annulation', 'cancel'),

        reactivate: (n: NeedActionRef) =>
            ui.confirm({ tone: 'success', icon: 'play_circle', title: `Réactiver le besoin ${n.reference} ?`, body: 'Les offres suspendues seront rouvertes aux candidatures (les offres dont la date limite est dépassée passeront en « Expirée »).', ok: 'Réactiver', onConfirm: () => post(`${base(n)}/reactivation`) }),

        closeNeed: (n: NeedActionRef) =>
            ui.confirm({ tone: 'success', icon: 'task_alt', title: `Clôturer le besoin ${n.reference} ?`, body: `Couverture finale : ${n.selected} / ${n.need} (${n.percent} %). Les offres liées seront clôturées.`, ok: 'Clôturer', onConfirm: () => post(`${base(n)}/cloture`) }),

        archive: (n: NeedActionRef) => ui.confirm({ icon: 'archive', title: `Archiver le besoin ${n.reference} ?`, body: 'Le besoin et ses offres passeront en lecture seule.', ok: 'Archiver', onConfirm: () => post(`${base(n)}/archivage`) }),

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

        refreshMany: (ids: number[], today: string) =>
            setDialog(
                <FormDialog
                    title={`Actualiser ${ids.length} besoin${ids.length > 1 ? 's' : ''} expiré${ids.length > 1 ? 's' : ''} ?`}
                    body="Les besoins seront republiés avec une nouvelle date limite ; les ouvriers ciblés seront notifiés."
                    icon="autorenew"
                    tone="success"
                    okLabel="Actualiser"
                    url="/besoins/actualisation-groupee"
                    onClose={close}
                    initial={{ deadline: addDays(today, 5) }}
                    fields={[{ name: 'deadline', label: 'Nouvelle date limite de candidature', type: 'date', required: true, full: true }]}
                    extra={{ ids }}
                />,
            ),

        extend: (n: NeedActionRef, o: OfferRef, edit = false) =>
            setDialog(
                <FormDialog
                    title={edit ? 'Modifier la date limite' : 'Prolonger l’offre'}
                    body={`${o.reference} · date limite actuelle : ${formatDate(o.deadline)} · démarrage : ${formatDate(o.startsOn)}`}
                    icon={edit ? 'edit_calendar' : 'more_time'}
                    okLabel={edit ? 'Enregistrer' : 'Prolonger'}
                    url={`${base(n)}/offres/${o.id}/prolongation`}
                    onClose={close}
                    initial={{ deadline: addDays(o.deadline < n.today ? n.today : o.deadline, 3) }}
                    fields={[
                        { name: 'deadline', label: 'Nouvelle date limite de candidature', type: 'date', required: true, full: true },
                        { name: 'reason', label: 'Motif', type: 'textarea', required: !edit },
                    ]}
                    extra={{ mode: edit ? 'modifier' : '' }}
                />,
            ),

        closeApplications: (n: NeedActionRef, o: OfferRef) =>
            ui.confirm({ tone: 'warning', icon: 'lock', title: `Clôturer les candidatures de ${o.reference} ?`, body: `${o.selected} / ${o.quantity} affectation(s) confirmée(s).`, ok: 'Clôturer les candidatures', onConfirm: () => post(`${base(n)}/offres/${o.id}/cloture-candidatures`) }),

        addDocument: (n: { slug: string; reference: string }, types: string[], type?: string) =>
            setDialog(
                <FormDialog
                    title="Ajouter un document"
                    body={n.reference}
                    icon="upload_file"
                    okLabel="Ajouter"
                    url={`${base(n)}/documents`}
                    onClose={close}
                    initial={{ type: type ?? types[0] }}
                    fields={[
                        { name: 'type', label: 'Type de document', type: 'select', required: true, full: true, options: types },
                        { name: 'file', label: 'Fichier', type: 'file', required: true },
                    ]}
                />,
            ),
    };

    /** Actions disponibles selon le statut (les principales sont mises en avant). */
    const available = (n: NeedActionRef): NeedAction[] => {
        const list: NeedAction[] = [];
        if (n.expiredOffers.length) list.push({ icon: 'autorenew', label: 'Actualiser le besoin', primary: true, onClick: () => actions.refreshAll(n) });
        if (!n.manual && !n.covered && n.canMatch) list.push({ icon: 'join_inner', label: 'Lancer le matching', primary: true, onClick: () => actions.match(n) });
        if (n.selected > 0 && n.manual !== 'archived') list.push({ icon: 'event_available', label: n.covered ? 'Suivre dans Affectations' : `Suivre les ouvriers trouvés (${n.selected})`, primary: n.covered, onClick: () => actions.follow(n) });
        if (n.manual === 'suspended') list.push({ icon: 'play_circle', label: 'Réactiver', primary: true, onClick: () => actions.reactivate(n) });
        else if (!n.manual && !n.done) list.push({ icon: 'pause_circle', label: 'Suspendre', onClick: () => actions.suspend(n) });
        if (n.done) list.push({ icon: 'task_alt', label: 'Clôturer', onClick: () => actions.closeNeed(n) });
        if (!n.manual && !n.done) list.push({ icon: 'cancel', label: 'Annuler le besoin', danger: true, onClick: () => actions.cancel(n) });
        if (n.manual === 'closed' || n.manual === 'cancelled') list.push({ icon: 'archive', label: 'Archiver', onClick: () => actions.archive(n) });
        return list;
    };

    return { ...actions, available };
}

export type NeedActions = ReturnType<typeof useNeedActions>;
