import FormDialog from '@/components/overlays/FormDialog';
import { useAdminUi } from '@/contexts/AdminUiContext';
import { todayIso } from '@/lib/format';
import type { Assignment, WorkerDocument } from '@/types/workers';
import { router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';

type WorkerRef = { slug: string; name: string; trade: string; dailyRate: number; status: { value: string } };

const DOCUMENT_TYPES = [
    { value: 'id_front', label: 'Pièce d’identité (recto)' },
    { value: 'id_back', label: 'Pièce d’identité (verso)' },
    { value: 'proof_of_address', label: 'Justificatif de domicile' },
    { value: 'cv', label: 'Curriculum vitae' },
    { value: 'diploma', label: 'Certificat ou diplôme' },
    { value: 'professional_certificate', label: 'Attestation professionnelle' },
];

const UNAVAILABILITY_ACTIVE = ['En cours', 'Confirmée', 'Acceptée', 'En attente d’acceptation', 'Proposée'];

/** Actions sur un ouvrier et leurs fenêtres de saisie. Rendre `dialog` dans la page. */
export function useWorkerActions() {
    const ui = useAdminUi();
    const [dialog, setDialog] = useState<ReactNode>(null);
    const close = () => setDialog(null);
    const base = (w: WorkerRef) => `/ouvriers/${w.slug}`;
    const post = (url: string) => router.post(url, {}, { preserveScroll: true });

    return {
        dialog,
        open: setDialog,
        close,

        /** `pending` : pièces pas encore vérifiées, signalées dans la confirmation. */
        validate: (w: WorkerRef, pending = 0) =>
            ui.confirm({
                tone: 'success',
                icon: 'verified',
                title: `Valider le profil de ${w.name} ?`,
                body: (pending ? `Attention : ${pending} document${pending > 1 ? 's' : ''} ne ${pending > 1 ? 'sont' : 'est'} pas encore vérifié${pending > 1 ? 's' : ''}. ` : 'Tous les documents obligatoires sont vérifiés. ') + 'L’ouvrier pourra recevoir des propositions d’affectation.',
                ok: 'Valider le profil',
                onConfirm: () => post(`${base(w)}/validation`),
            }),

        reject: (w: WorkerRef) =>
            setDialog(
                <FormDialog
                    title={`Rejeter le profil de ${w.name} ?`}
                    body="Le commercial référent et l’ouvrier seront notifiés du motif et de l’action attendue."
                    icon="person_cancel"
                    tone="danger"
                    okLabel="Rejeter le profil"
                    url={`${base(w)}/rejet`}
                    onClose={close}
                    fields={[
                        { name: 'reason', label: 'Motif', type: 'select', required: true, options: ['Document illisible', 'Document expiré', 'Incohérence d’identité', 'Doublon potentiel', 'Expérience non justifiée', 'Autre'] },
                        { name: 'request', label: 'Action demandée', type: 'select', required: true, options: ['Fournir une nouvelle pièce d’identité lisible', 'Mettre à jour les documents expirés', 'Justifier les expériences déclarées', 'Contacter le commercial référent', 'Compléter le profil'] },
                        { name: 'comment', label: 'Commentaire', type: 'textarea', required: true, placeholder: 'Précisez les éléments à corriger' },
                    ]}
                />,
            ),

        suspend: (w: WorkerRef) =>
            setDialog(
                <FormDialog
                    title={`Suspendre ${w.name} ?`}
                    body="L’ouvrier ne recevra plus de proposition et ses affectations futures seront signalées pour remplacement."
                    icon="block"
                    tone="danger"
                    okLabel="Suspendre"
                    url={`${base(w)}/suspension`}
                    onClose={close}
                    fields={[
                        { name: 'category', label: 'Catégorie', type: 'select', required: true, full: true, options: ['Absences répétées', 'Comportement inapproprié', 'Documents non conformes', 'Litige en cours', 'Demande de l’ouvrier', 'Autre'] },
                        { name: 'reason', label: 'Motif de la suspension', type: 'textarea', required: true, placeholder: 'Précisez le motif (visible dans l’historique).' },
                    ]}
                />,
            ),

        reactivate: (w: WorkerRef) =>
            ui.confirm({ tone: 'success', icon: 'settings_backup_restore', title: `Réactiver ${w.name} ?`, body: 'Le profil redeviendra visible pour le matching et l’ouvrier sera notifié.', ok: 'Réactiver', onConfirm: () => post(`${base(w)}/reactivation`) }),

        archive: (w: WorkerRef) =>
            ui.confirm({ tone: 'danger', icon: 'archive', title: `Archiver ${w.name} ?`, body: 'Le profil passera en lecture seule et ne sera plus proposé. Les données restent conservées pour l’historique.', ok: 'Archiver le profil', onConfirm: () => post(`${base(w)}/archivage`) }),

        assign: (w: WorkerRef, sites: string[]) => {
            if (w.status.value !== 'validated') return ui.toast('Le profil doit être validé avant toute affectation.', { tone: 'error' });
            setDialog(
                <FormDialog
                    title="Proposer une affectation"
                    body={`${w.name} · ${w.trade} · ${new Intl.NumberFormat('fr-FR').format(w.dailyRate).replace(/ /g, ' ')} FCFA/jour`}
                    icon="assignment_ind"
                    okLabel="Proposer l’affectation"
                    okIcon="send"
                    url={`${base(w)}/affectations`}
                    onClose={close}
                    initial={{ offer: `Renfort ${w.trade.toLowerCase()}`, amount: String(w.dailyRate) }}
                    fields={[
                        { name: 'site', label: 'Chantier', type: 'select', required: true, full: true, options: sites },
                        { name: 'offer', label: 'Besoin ou offre', required: true, full: true },
                        { name: 'type', label: 'Type de mission', type: 'select', required: true, options: ['Ponctuelle', 'Plusieurs jours', 'Contractuelle'] },
                        { name: 'pay', label: 'Mode de paiement', type: 'select', required: true, options: ['Journalier', 'Hebdomadaire', 'Contractuel'] },
                        { name: 'start', label: 'Date de début', type: 'date', required: true },
                        { name: 'end', label: 'Date de fin', type: 'date', required: true },
                        { name: 'amount', label: 'Coût journalier ou montant contractuel (FCFA)', type: 'number', required: true, full: true },
                    ]}
                />,
            );
        },

        /** `replacement` : la déclaration vise à lancer un remplacement sur une affectation. */
        unavailability: (w: WorkerRef, assignments: Assignment[], replacement = false) => {
            const active = assignments.filter((a) => UNAVAILABILITY_ACTIVE.includes(a.status));
            setDialog(
                <FormDialog
                    title={replacement ? 'Demander un remplacement' : 'Déclarer une indisponibilité'}
                    body={`${w.name} · ${w.trade}`}
                    icon="event_busy"
                    tone="warning"
                    okLabel={replacement ? 'Lancer le remplacement' : 'Enregistrer l’indisponibilité'}
                    url={`${base(w)}/indisponibilite`}
                    onClose={close}
                    initial={{ moment: replacement ? 'Pendant l’exécution du chantier' : '', assignment: active[0]?.id ?? '', starts_on: todayIso() }}
                    fields={[
                        { name: 'moment', label: 'Moment', type: 'select', required: true, options: ['Pendant l’exécution du chantier', 'Avant le début du chantier'] },
                        { name: 'assignment', label: 'Chantier / affectation concernés', type: 'select', options: [...active.map((a) => ({ value: a.id, label: `${a.site} · ${a.status}` })), { value: '', label: 'Aucune affectation concernée' }] },
                        { name: 'starts_on', label: 'Date de début', type: 'date', required: true },
                        { name: 'ends_on', label: 'Date de fin estimée', type: 'date', required: true },
                        { name: 'reason', label: 'Motif', type: 'select', required: true, options: ['Maladie', 'Blessure', 'Raison familiale', 'Problème de transport', 'Autre engagement', 'Autre'] },
                        { name: 'urgency', label: 'Urgence', type: 'select', required: true, options: ['Faible', 'Moyenne', 'Élevée', 'Critique'] },
                        { name: 'recovery', label: 'Capacité de reprise', type: 'select', full: true, options: ['Oui — après la date de fin', 'Partielle — mi-temps', 'Non'] },
                        { name: 'comment', label: 'Commentaire', type: 'textarea', required: true },
                        { name: 'file', label: 'Justificatif', type: 'file' },
                    ]}
                />,
            );
        },

        addDocument: (w: WorkerRef, type = 'id_front') =>
            setDialog(
                <FormDialog
                    title="Ajouter ou remplacer un document"
                    body={`Le document sera ajouté au dossier de ${w.name} avec le statut « À vérifier ».`}
                    icon="upload_file"
                    okLabel="Enregistrer le document"
                    url={`${base(w)}/documents`}
                    onClose={close}
                    initial={{ type }}
                    fields={[
                        { name: 'type', label: 'Type de document', type: 'select', required: true, full: true, options: DOCUMENT_TYPES },
                        { name: 'reference', label: 'N° / référence', required: true, placeholder: 'Ex. CI1234567' },
                        { name: 'expires_at', label: 'Date d’expiration', type: 'date' },
                        { name: 'file', label: 'Fichier', type: 'file', required: true },
                    ]}
                />,
            ),

        validateDocument: (w: WorkerRef, d: WorkerDocument) =>
            ui.confirm({ tone: 'success', icon: 'verified', title: 'Valider ce document ?', body: `« ${d.label} » (${d.reference}) sera marqué comme vérifié.`, ok: 'Valider le document', onConfirm: () => post(`${base(w)}/documents/${d.id}/validation`) }),

        rejectDocument: (w: WorkerRef, d: WorkerDocument) =>
            setDialog(
                <FormDialog
                    title="Rejeter ce document ?"
                    body={`« ${d.label} » — le commercial et l’ouvrier seront notifiés.`}
                    icon="cancel"
                    tone="danger"
                    okLabel="Rejeter le document"
                    url={`${base(w)}/documents/${d.id}/rejet`}
                    onClose={close}
                    fields={[
                        { name: 'reason', label: 'Motif', type: 'select', required: true, options: ['Document illisible', 'Document expiré', 'Incohérence d’identité', 'Mauvais document', 'Doublon potentiel', 'Autre'] },
                        { name: 'request', label: 'Action demandée', type: 'select', required: true, options: ['Déposer une nouvelle photo nette', 'Fournir un document à jour', 'Contacter le commercial référent', 'Se présenter en agence'] },
                        { name: 'comment', label: 'Commentaire', type: 'textarea', required: true },
                    ]}
                />,
            ),

        commentDocument: (w: WorkerRef, d: WorkerDocument) =>
            setDialog(
                <FormDialog
                    title="Commentaire interne"
                    body={`« ${d.label} » — visible uniquement par l’administration.`}
                    icon="chat"
                    okLabel="Enregistrer"
                    url={`${base(w)}/documents/${d.id}/commentaire`}
                    onClose={close}
                    initial={{ comment: d.internalComment ?? '' }}
                    fields={[{ name: 'comment', label: 'Commentaire interne', type: 'textarea', required: true }]}
                />,
            ),
    };
}

export type WorkerActions = ReturnType<typeof useWorkerActions>;
