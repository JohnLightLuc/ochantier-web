import FormDialog from '@/components/overlays/FormDialog';
import { useAdminUi } from '@/contexts/AdminUiContext';
import type { SiteDocument, SiteIncident, SiteInfo } from '@/types/sites';
import { router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';

/** Ce qu’il faut d’un chantier pour proposer ses actions (ligne de liste ou fiche). */
export type SiteRef = { slug: string; name: string; status: { value: string }; startsOn: string; endsOn: string; info: SiteInfo; acceptanceVerified: boolean };

export type SiteAction = { icon: string; label: string; onClick: () => void; primary?: boolean; danger?: boolean };

const PRIORITIES = [
    { value: 'high', label: 'Haute' },
    { value: 'medium', label: 'Moyenne' },
    { value: 'normal', label: 'Normale' },
];

const INCIDENT_TYPES = ['Accident', 'Retard d’approvisionnement', 'Absence massive', 'Intempéries', 'Litige', 'Vol ou dégradation', 'Arrêt administratif'];

const SEVERITIES = [
    { value: 'low', label: 'Faible' },
    { value: 'medium', label: 'Moyenne' },
    { value: 'high', label: 'Élevée' },
    { value: 'critical', label: 'Critique' },
];

const CORRECTION_FIELDS = ['Adresse / localisation', 'Dates', 'Type de construction', 'Documents d’autorisation', 'Effectif prévu', 'Autre'];

const DOCUMENT_TYPES = [
    { value: 'building_permit', label: 'Permis de construire' },
    { value: 'execution_plan', label: 'Plan d’exécution' },
    { value: 'setting_out_report', label: 'PV d’implantation' },
    { value: 'insurance', label: 'Attestation d’assurance chantier' },
    { value: 'safety_plan', label: 'Plan de prévention HSE' },
    { value: 'acceptance_report', label: 'PV de réception' },
];

const FINAL = ['closed', 'cancelled', 'archived'];
const OPERATIONAL = ['in_progress', 'late', 'recruiting', 'ready'];

/** Statuts en lecture seule : ni modification ni ajout de document. */
export const READ_ONLY = ['cancelled', 'archived'];

/** Actions sur un chantier et leurs fenêtres de saisie. Rendre `dialog` dans la page. */
export function useSiteActions() {
    const ui = useAdminUi();
    const [dialog, setDialog] = useState<ReactNode>(null);
    const close = () => setDialog(null);
    const base = (s: { slug: string }) => `/chantiers/${s.slug}`;
    const post = (url: string) => router.post(url, {}, { preserveScroll: true });

    const reasonDialog = (s: SiteRef, title: string, ok: string, url: string, body: string) =>
        setDialog(<FormDialog title={title} body={body} icon="edit_note" tone="danger" okLabel={ok} url={`${base(s)}/${url}`} onClose={close} fields={[{ name: 'reason', label: 'Motif', type: 'textarea', required: true, placeholder: 'Précisez le motif (visible dans l’historique).' }]} />);

    const actions = {
        dialog,
        close,

        edit: (s: SiteRef) =>
            setDialog(
                <FormDialog
                    title="Modifier les informations autorisées"
                    body="Les informations contractuelles (promoteur, type, adresse) ne sont modifiables que par le promoteur ou via une demande de correction."
                    icon="edit"
                    okLabel="Enregistrer"
                    url={`${base(s)}/informations`}
                    method="put"
                    onClose={close}
                    initial={s.info}
                    fields={[
                        { name: 'name', label: 'Libellé', required: true, full: true },
                        { name: 'priority', label: 'Niveau de priorité', type: 'select', required: true, options: PRIORITIES },
                        { name: 'working_hours', label: 'Horaires prévisionnels' },
                        { name: 'instructions', label: 'Consignes générales', type: 'textarea' },
                        { name: 'access_conditions', label: 'Conditions d’accès', type: 'textarea' },
                        { name: 'safety_equipment', label: 'Équipements de sécurité obligatoires', full: true },
                    ]}
                />,
            ),

        correction: (s: SiteRef & { promoter: string }) =>
            setDialog(
                <FormDialog
                    title="Demander une correction au promoteur"
                    body={`${s.promoter} sera notifié dans l’Application Promoteur.`}
                    icon="rule"
                    tone="warning"
                    okLabel="Envoyer la demande"
                    url={`${base(s)}/correction`}
                    onClose={close}
                    fields={[
                        { name: 'field', label: 'Élément à corriger', type: 'select', required: true, full: true, options: CORRECTION_FIELDS },
                        { name: 'message', label: 'Message', type: 'textarea', required: true },
                    ]}
                />,
            ),

        plan: (s: SiteRef) =>
            setDialog(
                <FormDialog
                    title="Planifier le chantier"
                    icon="event"
                    okLabel="Enregistrer"
                    url={`${base(s)}/planification`}
                    onClose={close}
                    initial={{ starts_on: s.startsOn, ends_on: s.endsOn }}
                    fields={[
                        { name: 'starts_on', label: 'Date de début', type: 'date', required: true },
                        { name: 'ends_on', label: 'Date de fin prévisionnelle', type: 'date', required: true },
                    ]}
                />,
            ),

        incident: (s: SiteRef) =>
            setDialog(
                <FormDialog
                    title="Signaler un incident"
                    body={s.name}
                    icon="report"
                    tone="danger"
                    okLabel="Signaler"
                    url={`${base(s)}/incidents`}
                    onClose={close}
                    fields={[
                        { name: 'type', label: 'Type', type: 'select', required: true, options: INCIDENT_TYPES },
                        { name: 'severity', label: 'Gravité', type: 'select', required: true, options: SEVERITIES },
                        { name: 'description', label: 'Description', type: 'textarea', required: true },
                    ]}
                />,
            ),

        resolveIncident: (s: SiteRef, incident: SiteIncident) =>
            setDialog(
                <FormDialog
                    title={`Résoudre l’incident ${incident.reference} ?`}
                    body={`${incident.type} · ${incident.description}`}
                    icon="task_alt"
                    tone="success"
                    okLabel="Résoudre"
                    url={`${base(s)}/incidents/${incident.id}/resolution`}
                    onClose={close}
                    fields={[{ name: 'measures', label: 'Mesures prises', type: 'textarea', required: true }]}
                />,
            ),

        suspend: (s: SiteRef) => reasonDialog(s, `Suspendre ${s.name} ?`, 'Suspendre', 'suspension', 'Les pointages et paiements seront gelés jusqu’à la reprise.'),

        cancel: (s: SiteRef) => reasonDialog(s, `Annuler ${s.name} ?`, 'Annuler le chantier', 'annulation', 'Le chantier passera en lecture seule.'),

        resume: (s: SiteRef) => ui.confirm({ tone: 'success', icon: 'play_circle', title: 'Reprendre le chantier ?', body: `${s.name} reprendra son exécution.`, ok: 'Reprendre', onConfirm: () => post(`${base(s)}/reprise`) }),

        closeSite: (s: SiteRef) =>
            ui.confirm({
                tone: s.acceptanceVerified ? 'success' : 'warning',
                icon: 'task_alt',
                title: `Clôturer ${s.name} ?`,
                body: (s.acceptanceVerified ? '' : 'Attention : le PV de réception n’est pas vérifié. ') + 'Les affectations seront terminées et les derniers paiements déclenchés.',
                ok: 'Clôturer',
                onConfirm: () => post(`${base(s)}/cloture`),
            }),

        archive: (s: SiteRef) => ui.confirm({ icon: 'archive', title: 'Archiver ce chantier ?', body: 'Le chantier passera en lecture seule. Les données restent conservées pour l’historique.', ok: 'Archiver', onConfirm: () => post(`${base(s)}/archivage`) }),

        create: (promoters: string[], types: string[], communes: string[]) =>
            setDialog(
                <FormDialog
                    title="Création administrative exceptionnelle"
                    body="Réservée aux administrateurs autorisés. Les chantiers sont normalement créés par les promoteurs depuis l’Application Promoteur. Cette création sera tracée dans le journal d’audit."
                    icon="add_business"
                    tone="warning"
                    okLabel="Créer le chantier"
                    url="/chantiers"
                    onClose={close}
                    fields={[
                        { name: 'name', label: 'Libellé', required: true, full: true },
                        { name: 'promoter', label: 'Promoteur', type: 'select', required: true, options: promoters },
                        { name: 'type', label: 'Type de construction', type: 'select', required: true, options: types },
                        { name: 'commune', label: 'Commune', type: 'select', required: true, options: communes },
                        { name: 'required_workers', label: 'Ouvriers requis', type: 'number', required: true },
                        { name: 'starts_on', label: 'Date de début', type: 'date', required: true },
                        { name: 'ends_on', label: 'Date de fin prévisionnelle', type: 'date', required: true },
                        { name: 'justification', label: 'Justification (journal d’audit)', type: 'textarea', required: true },
                    ]}
                />,
            ),

        addDocument: (s: SiteRef, type = 'building_permit') =>
            setDialog(
                <FormDialog
                    title="Ajouter ou remplacer un document"
                    body={`Le document sera ajouté au dossier de ${s.name} avec le statut « À vérifier ».`}
                    icon="upload_file"
                    okLabel="Enregistrer le document"
                    url={`${base(s)}/documents`}
                    onClose={close}
                    initial={{ type }}
                    fields={[
                        { name: 'type', label: 'Type de document', type: 'select', required: true, full: true, options: DOCUMENT_TYPES },
                        { name: 'reference', label: 'N° / référence', required: true, placeholder: 'Ex. PER-2026001' },
                        { name: 'expires_at', label: 'Date d’expiration', type: 'date' },
                        { name: 'file', label: 'Fichier', type: 'file', required: true },
                    ]}
                />,
            ),

        validateDocument: (s: SiteRef, d: SiteDocument) =>
            ui.confirm({ tone: 'success', icon: 'verified', title: 'Valider ce document ?', body: `« ${d.label} » (${d.reference ?? '—'}) sera marqué comme vérifié.`, ok: 'Valider le document', onConfirm: () => post(`${base(s)}/documents/${d.id}/validation`) }),

        rejectDocument: (s: SiteRef, d: SiteDocument) =>
            setDialog(
                <FormDialog
                    title="Rejeter ce document ?"
                    body={`« ${d.label} » — le promoteur sera notifié.`}
                    icon="cancel"
                    tone="danger"
                    okLabel="Rejeter le document"
                    url={`${base(s)}/documents/${d.id}/rejet`}
                    onClose={close}
                    fields={[
                        { name: 'reason', label: 'Motif', type: 'select', required: true, full: true, options: ['Document illisible', 'Document expiré', 'Mauvais document', 'Informations incohérentes', 'Autre'] },
                        { name: 'comment', label: 'Commentaire', type: 'textarea', required: true },
                    ]}
                />,
            ),

        commentDocument: (s: SiteRef, d: SiteDocument) =>
            setDialog(
                <FormDialog
                    title="Commentaire interne"
                    body={`« ${d.label} » — visible uniquement par l’administration.`}
                    icon="chat"
                    okLabel="Enregistrer"
                    url={`${base(s)}/documents/${d.id}/commentaire`}
                    onClose={close}
                    initial={{ comment: d.internalComment ?? '' }}
                    fields={[{ name: 'comment', label: 'Commentaire interne', type: 'textarea', required: true }]}
                />,
            ),
    };

    /** Actions disponibles selon le statut (les principales sont mises en avant dans l’en-tête). */
    const available = (s: SiteRef & { promoter: string }): SiteAction[] => {
        const status = s.status.value;
        const list: SiteAction[] = [];
        if (!FINAL.includes(status)) list.push({ icon: 'rule', label: 'Demander une correction au promoteur', onClick: () => actions.correction(s) });
        if (['draft', 'planned'].includes(status)) list.push({ icon: 'event', label: 'Planifier', primary: true, onClick: () => actions.plan(s) });
        if (OPERATIONAL.includes(status)) {
            list.push({ icon: 'report', label: 'Signaler un incident', primary: status !== 'ready', onClick: () => actions.incident(s) });
            list.push({ icon: 'pause_circle', label: 'Suspendre', danger: true, onClick: () => actions.suspend(s) });
        }
        if (['suspended', 'interrupted'].includes(status)) list.push({ icon: 'play_circle', label: 'Reprendre', primary: true, onClick: () => actions.resume(s) });
        if (['completed', 'pending_closure'].includes(status)) list.push({ icon: 'task_alt', label: 'Clôturer', primary: true, onClick: () => actions.closeSite(s) });
        if (!FINAL.includes(status)) list.push({ icon: 'cancel', label: 'Annuler le chantier', danger: true, onClick: () => actions.cancel(s) });
        if (['closed', 'cancelled'].includes(status)) list.push({ icon: 'archive', label: 'Archiver', onClick: () => actions.archive(s) });
        return list;
    };

    return { ...actions, available };
}

export type SiteActions = ReturnType<typeof useSiteActions>;
