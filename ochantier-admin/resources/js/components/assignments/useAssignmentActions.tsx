import Dialog from '@/components/overlays/Dialog';
import FormDialog from '@/components/overlays/FormDialog';
import Field, { SelectInput } from '@/components/ui/Field';
import { useAdminUi } from '@/contexts/AdminUiContext';
import { formatNumber } from '@/lib/format';
import type { AssignmentOptions, ManualPost } from '@/types/assignments';
import { router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';

export const fcfa = (n: number) => `${formatNumber(n)} FCFA`;

const addDays = (iso: string, n: number) => {
    const d = new Date(`${iso}T12:00:00`);
    d.setDate(d.getDate() + n);
    return d.toISOString().slice(0, 10);
};

type Person = { id: number; name: string; phone?: string | null };

/** Choix du poste avant l’ajout manuel d’un ouvrier (quand plusieurs métiers sont ouverts). */
function ChoosePost({ posts, onPick, onClose }: { posts: ManualPost[]; onPick: (post: ManualPost) => void; onClose: () => void }) {
    const [value, setValue] = useState(String(posts[0].offerId));

    return (
        <Dialog title="Ajouter un ouvrier manuellement" body="Choisissez le poste à pourvoir." icon="person_add" okLabel="Continuer" onClose={onClose} onSubmit={() => onPick(posts.find((p) => String(p.offerId) === value) ?? posts[0])}>
            <Field label="Poste" required>
                <SelectInput value={value} onChange={(e) => setValue(e.target.value)} placeholder="" options={posts.map((p) => ({ value: String(p.offerId), label: p.label }))} />
            </Field>
        </Dialog>
    );
}

/**
 * Fenêtres d’action d’Affectations & Offres (besoin suivi ou offre de promoteur) : contact, validation, invalidation,
 * remplacement, acompte, notification, annulation et nouveau besoin. Rendre `dialog` dans la page.
 */
export function useAssignmentActions(options: AssignmentOptions) {
    const ui = useAdminUi();
    const [dialog, setDialog] = useState<ReactNode>(null);
    const close = () => setDialog(null);
    const post = (url: string, data: Record<string, unknown> = {}) => router.post(url, data as Record<string, string>, { preserveScroll: true });
    const reasonFields = (motifs: string[]) => [
        { name: 'motif', label: 'Motif', type: 'select' as const, required: true, full: true, options: motifs },
        { name: 'reason', label: 'Précisions', type: 'textarea' as const, required: true },
    ];

    return {
        dialog,
        close,

        contact: (base: string, list: Person[], intro: string) =>
            setDialog(
                <FormDialog
                    title={list.length > 1 ? `Contacter ${list.length} ouvriers` : `Contacter ${list[0].name}`}
                    body={`${list.map((p) => (p.phone ? `${p.name} · ${p.phone}` : p.name)).join(' — ')}. ${intro}`}
                    icon="call"
                    okLabel="Enregistrer le contact"
                    url={`${base}/contact`}
                    onClose={close}
                    extra={{ ids: list.map((p) => p.id) }}
                    fields={[
                        { name: 'channel', label: 'Canal', type: 'select', required: true, full: true, options: options.channels },
                        { name: 'note', label: 'Note', type: 'textarea' },
                    ]}
                />,
            ),

        validate: (base: string, list: Person[], body: string) =>
            ui.confirm({
                tone: 'success',
                icon: list.length > 1 ? 'done_all' : 'verified',
                title: list.length > 1 ? `Valider les ouvriers (${list.length}) ?` : `Valider ${list[0].name} ?`,
                body,
                ok: list.length > 1 ? 'Valider les ouvriers' : 'Valider',
                onConfirm: () => post(`${base}/validation`, { ids: list.map((p) => p.id) }),
            }),

        decline: (url: string, name: string, motifs: string[]) =>
            setDialog(<FormDialog title={`Invalider ${name} ?`} body="Un matching sera lancé pour trouver un ouvrier alternatif du même métier." icon="person_off" tone="danger" okLabel="Invalider" url={url} onClose={close} fields={reasonFields(motifs)} />),

        requestReplacement: (url: string, name: string, body: string) =>
            setDialog(<FormDialog title={`Demande de remplacement · ${name}`} body={body} icon="swap_horiz" tone="warning" okLabel="Lancer le remplacement" url={url} onClose={close} fields={reasonFields(options.replacementReasons)} />),

        propose: (url: string, key: string, name: string, body: string) =>
            ui.confirm({ tone: 'success', icon: 'swap_horiz', title: `Proposer ${name} en remplacement ?`, body, ok: 'Proposer', onConfirm: () => post(url, { key }) }),

        cancelValidation: (url: string, name: string) =>
            ui.confirm({ tone: 'warning', icon: 'undo', title: `Annuler la validation de ${name} ?`, body: 'L’ouvrier repasse « Contacté » : sa validation devra être reprise.', ok: 'Annuler la validation', onConfirm: () => post(url) }),

        payDeposit: (url: string, body: string) =>
            setDialog(
                <FormDialog
                    title="Valider le paiement de l’acompte"
                    body={body}
                    icon="payments"
                    tone="success"
                    okLabel="Valider le paiement"
                    url={url}
                    onClose={close}
                    fields={[
                        { name: 'method', label: 'Mode de paiement', type: 'select', required: true, options: options.paymentMethods },
                        { name: 'reference', label: 'Référence de la transaction', required: true, placeholder: 'MM-58214' },
                    ]}
                />,
            ),

        notify: (url: string, title: string, recipient: string, subject: string) =>
            setDialog(
                <FormDialog
                    title={title}
                    body={`Destinataire : ${recipient}`}
                    icon="notifications"
                    okLabel="Envoyer"
                    okIcon="send"
                    url={url}
                    onClose={close}
                    initial={{ subject, channel: options.notificationChannels[0] }}
                    fields={[
                        { name: 'channel', label: 'Canal', type: 'select', required: true, full: true, options: options.notificationChannels },
                        { name: 'subject', label: 'Objet', required: true, full: true },
                    ]}
                />,
            ),

        cancelOffer: (url: string, reference: string) =>
            setDialog(
                <FormDialog
                    title={`Annuler l’offre ${reference} ?`}
                    body="Les ouvriers sélectionnés et le promoteur seront notifiés. Les affectations déjà créées restent actives."
                    icon="cancel"
                    tone="danger"
                    okLabel="Annuler l’offre"
                    url={url}
                    onClose={close}
                    fields={[{ name: 'reason', label: 'Motif', type: 'textarea', required: true }]}
                />,
            ),

        complement: (url: string, body: string, today: string) =>
            setDialog(
                <FormDialog
                    title="Nouveau besoin en ouvriers"
                    body={`${body} Le besoin sera publié aussitôt pour le matching.`}
                    icon="group_add"
                    okLabel="Créer le besoin"
                    url={url}
                    onClose={close}
                    initial={{ trade: options.trades[0], quantity: '1', experience: 'Intermédiaire', urgency: 'Élevé', starts_on: addDays(today, 3), duration: '14', daily_cost: '11000', reason: 'Renfort pour le chantier en cours' }}
                    fields={[
                        { name: 'trade', label: 'Métier', type: 'select', required: true, options: options.trades },
                        { name: 'quantity', label: 'Nombre d’ouvriers', type: 'number', required: true },
                        { name: 'experience', label: 'Niveau d’expérience', type: 'select', required: true, options: options.experiences },
                        { name: 'urgency', label: 'Urgence', type: 'select', required: true, options: options.urgencies },
                        { name: 'starts_on', label: 'Date de début', type: 'date', required: true },
                        { name: 'duration', label: 'Durée (jours)', type: 'number', required: true },
                        { name: 'daily_cost', label: 'Coût journalier (FCFA)', type: 'number', required: true },
                        { name: 'reason', label: 'Motif', type: 'textarea' },
                    ]}
                />,
            ),

        manual: (url: string, reference: string, posts: ManualPost[], offerId?: number) => {
            if (!posts.length) return ui.toast('Tous les postes de ce besoin sont déjà pourvus.', { tone: 'error' });
            const open = (p: ManualPost) =>
                setDialog(
                    <FormDialog
                        title={`Ajouter un ${p.trade.toLowerCase()} manuellement`}
                        body={`${reference} · ${p.selected} / ${p.quantity} ouvrier(s) sélectionné(s) · ${p.rest} manquant(s). L’ouvrier sera ajouté à la liste des affectations pour être contacté.`}
                        icon="person_add"
                        okLabel="Ajouter à l’affectation"
                        url={url}
                        onClose={close}
                        extra={{ offer: p.offerId }}
                        fields={[{ name: 'key', label: 'Ouvrier (module Ouvriers)', type: 'select', required: true, full: true, options: p.options }]}
                    />,
                );
            const chosen = posts.find((p) => p.offerId === offerId);
            if (chosen || posts.length === 1) return open(chosen ?? posts[0]);
            setDialog(<ChoosePost posts={posts} onClose={close} onPick={open} />);
        },
    };
}

export type AssignmentActions = ReturnType<typeof useAssignmentActions>;
