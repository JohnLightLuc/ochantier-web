import FormDialog from '@/components/overlays/FormDialog';
import { useAdminUi } from '@/contexts/AdminUiContext';
import type { AttendanceOptions, Correction, Decision, Proof } from '@/types/attendances';
import { router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';

const NEEDS_COMMENT = ['Confirmée', 'Fausse alerte', 'Corrigée', 'Clôturée', 'Justification demandée'];

/** Journée visée par une action : référence et éléments du libellé. */
export type Target = { reference: string; closable?: boolean; closed?: boolean; blockers?: string[] };

/**
 * Fenêtres d’action du Back-office sur les pointages (relance, note, correction, anomalie, régularisation,
 * clôture, réouverture, transmission, preuves, décisions). Rendre `dialog` dans la page.
 */
export function useAttendanceActions(options: AttendanceOptions) {
    const ui = useAdminUi();
    const [dialog, setDialog] = useState<ReactNode>(null);
    const close = () => setDialog(null);
    const url = (ref: string, path: string) => `/pointages/${ref}/${path}`;
    const post = (to: string, data: Record<string, unknown> = {}) => router.post(to, data as Record<string, string>, { preserveScroll: true });
    const reason = (title: string, body: string, icon: string, ok: string, to: string, label = 'Motif', tone: 'danger' | 'warning' | undefined = undefined, extra?: Record<string, unknown>) =>
        setDialog(<FormDialog title={title} body={body} icon={icon} tone={tone} okLabel={ok} url={to} onClose={close} extra={extra} fields={[{ name: 'reason', label, type: 'textarea', required: true }]} />);

    return {
        dialog,
        close,

        remind: (refs: string[]) => post('/pointages/relance', { refs }),

        /** Clôture normale (journées clôturables) ou exceptionnelle (justification obligatoire). */
        closeDays: (targets: Target[], exceptional = false) => {
            const refs = targets.map((t) => t.reference);
            if (exceptional) {
                return reason('Clôture exceptionnelle', `Points bloquants : ${targets[0]?.blockers?.join(', ') || 'aucun'}. La justification est inscrite au journal d’audit.`, 'lock_clock', 'Clôturer', '/pointages/cloture', 'Justification', 'danger', { refs, exceptionnelle: 1 });
            }
            const ok = targets.filter((t) => t.closable);
            if (!ok.length) return ui.toast(`Aucune journée clôturable : ${targets[0]?.blockers?.join(', ') || 'déjà clôturée'}.`, { tone: 'error' });
            const skipped = targets.filter((t) => !t.closable && !t.closed).length;
            ui.confirm({
                icon: 'lock',
                title: `Clôturer ${ok.length} journée(s) ?`,
                body: `Les données validées deviendront disponibles pour la progression et la préparation des paiements (après clôture de la journée).${skipped ? ` ${skipped} journée(s) non clôturable(s) seront ignorées.` : ''}`,
                ok: 'Clôturer',
                onConfirm: () => post('/pointages/cloture', { refs }),
            });
        },

        note: (ref: string) => reason(`Note interne · ${ref}`, 'Visible uniquement dans le Back-office.', 'sticky_note_2', 'Ajouter', url(ref, 'notes'), 'Note'),

        correction: (ref: string, field?: string) =>
            setDialog(
                <FormDialog
                    title={`Demande de correction · ${ref}`}
                    body="Un pointage validé ne se modifie jamais directement : la demande sera soumise au promoteur puis approuvée. L’ancienne valeur est conservée."
                    icon="edit_note"
                    okLabel="Soumettre la demande"
                    url={url(ref, 'corrections')}
                    onClose={close}
                    initial={field ? { field } : {}}
                    fields={[
                        { name: 'field', label: 'Donnée incorrecte', type: 'select', required: true, options: options.correctionFields },
                        { name: 'new_value', label: 'Nouvelle valeur demandée', required: true, placeholder: 'ex. 17:45' },
                        { name: 'motif', label: 'Motif', type: 'select', required: true, options: options.correctionMotifs },
                        { name: 'proof', label: 'Justificatif (référence)' },
                        { name: 'comment', label: 'Commentaire', type: 'textarea' },
                    ]}
                />,
            ),

        approveCorrection: (ref: string, c: Correction) =>
            ui.confirm({
                tone: 'warning',
                icon: 'published_with_changes',
                title: 'Approuver et appliquer la correction ?',
                body: `${c.field} : ${c.oldValue} → ${c.newValue}. L’ancienne valeur est conservée, les durées sont recalculées et l’action est auditée.`,
                ok: 'Approuver et appliquer',
                onConfirm: () => post(url(ref, `corrections/${c.id}/approbation`)),
            }),

        rejectCorrection: (ref: string, c: Correction) => reason('Rejeter la correction', `${c.field} : ${c.oldValue} → ${c.newValue}`, 'block', 'Rejeter', url(ref, `corrections/${c.id}/rejet`), 'Motif du rejet', 'danger'),

        reportAnomaly: (ref: string) =>
            setDialog(
                <FormDialog
                    title={`Signaler une anomalie · ${ref}`}
                    icon="report"
                    tone="warning"
                    okLabel="Signaler"
                    url={url(ref, 'anomalies')}
                    onClose={close}
                    fields={[
                        { name: 'type', label: 'Type', type: 'select', required: true, options: options.anomalyTypes },
                        { name: 'level', label: 'Niveau', type: 'select', required: true, options: options.levels },
                        { name: 'note', label: 'Description', type: 'textarea', required: true },
                    ]}
                />,
            ),

        setAnomaly: (ref: string, anomaly: { id: number; type: string; level: string }, status: string) =>
            NEEDS_COMMENT.includes(status)
                ? reason(`${status} · ${anomaly.type}`, `${ref} · niveau ${anomaly.level.toLowerCase()}`, 'rule', 'Confirmer', url(ref, `anomalies/${anomaly.id}/statut`), 'Commentaire', undefined, { status })
                : post(url(ref, `anomalies/${anomaly.id}/statut`), { status }),

        regularize: (ref: string) =>
            setDialog(
                <FormDialog
                    title={`Régulariser exceptionnellement · ${ref}`}
                    body="Action réservée aux administrateurs autorisés. Elle ne remplace pas le promoteur : la régularisation est tracée et signalée au promoteur."
                    icon="gavel"
                    tone="danger"
                    okLabel="Régulariser"
                    url={url(ref, 'regularisation')}
                    onClose={close}
                    fields={[
                        { name: 'object', label: 'Objet', type: 'select', required: true, options: ['Retard', 'Départ anticipé', 'Absence', 'Journée incomplète', 'Anomalie'] },
                        { name: 'decision', label: 'Décision', type: 'select', required: true, options: ['Régularisé', 'Justifié', 'Non justifié'] },
                        { name: 'reason', label: 'Justification', type: 'textarea', required: true },
                    ]}
                />,
            ),

        reopen: (ref: string) =>
            reason(`Rouvrir la journée · ${ref}`, 'Impact : la journée sort du calcul de progression et la transmission financière est suspendue. La version précédente est conservée.', 'lock_open', 'Rouvrir avec autorisation', url(ref, 'reouverture'), 'Motif', 'danger'),

        transmit: (ref: string) => post(url(ref, 'transmission')),

        proof: (ref: string, event: 'arrivee' | 'depart', proof: Proof, action: 'verification' | 'rejet' | 'remplacement' | 'commentaire') => {
            const to = url(ref, `preuves/${event}/${proof.id}/${action}`);
            if (action === 'verification') return post(to);
            if (action === 'commentaire') return reason('Commentaire interne', `${proof.type} · ${proof.file}`, 'sticky_note_2', 'Enregistrer', to, 'Commentaire');
            reason(action === 'rejet' ? 'Rejeter la preuve' : 'Demander le remplacement de la preuve', `${proof.type} · ${proof.file}`, 'image_not_supported', 'Confirmer', to, 'Motif', 'warning');
        },

        decide: (ref: string, worker: string, decision: Decision, status: string) =>
            reason(`${status} · ${decision.label.toLowerCase()}`, `${ref} · ${worker}. Aucun impact financier automatique : les règles de Paramètres s’appliquent au calcul.`, 'rule', 'Confirmer', url(ref, `decisions/${decision.key}`), 'Commentaire', undefined, { status }),
    };
}

export type AttendanceActions = ReturnType<typeof useAttendanceActions>;
