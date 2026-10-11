import FormDialog from '@/components/overlays/FormDialog';
import { useAdminUi } from '@/contexts/AdminUiContext';
import { formatDate, formatNumber, todayIso } from '@/lib/format';
import type { Objective, RankingRow, SalesDocument, Session, Weight } from '@/types/sales';
import { router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';

/** Champs d’un commercial utilisés par les actions. */
export type AgentRef = {
    slug: string;
    name: string;
    code: string;
    email?: string;
    phone?: string;
    zone?: string;
    status: { value: string };
    invitation?: { label: string; value: string };
    secondaryZones?: string[];
    access?: { sentAt: string | null; expiresAt: string | null; twoFactor: boolean; role: string; level: string; duration: string; permissions: Record<string, boolean> };
    pay?: { bonusCotm: number };
};

const DOCUMENT_TYPES = [
    { value: 'id_front', label: 'Pièce d’identité (recto)' },
    { value: 'id_back', label: 'Pièce d’identité (verso)' },
    { value: 'photo', label: 'Photo d’identité' },
    { value: 'proof_of_address', label: 'Justificatif de domicile' },
    { value: 'contract', label: 'Contrat ou convention' },
    { value: 'cv', label: 'Curriculum vitae' },
];

const PRE_ACTIVATION = ['draft', 'invited', 'pending_activation'];

const fcfa = (n: number) => `${formatNumber(n)} FCFA`;
const decimal = (n: number) => String(n).replace('.', ',');

/** Actions sur un commercial et leurs fenêtres de saisie. Rendre `dialog` dans la page. */
export function useSalesActions() {
    const ui = useAdminUi();
    const [dialog, setDialog] = useState<ReactNode>(null);
    const close = () => setDialog(null);
    const base = (a: AgentRef) => `/commerciaux/${a.slug}`;
    const post = (url: string) => router.post(url, {}, { preserveScroll: true });
    const del = (url: string, data: Record<string, string> = {}) => router.delete(url, { data, preserveScroll: true });

    const actions = {
        dialog,
        open: setDialog,
        close,

        invite: (a: AgentRef) => {
            if (a.status.value === 'active') return ui.toast('Ce compte est déjà activé. Utilisez « Réinitialiser l’accès ».', { tone: 'error' });
            const first = a.status.value === 'draft';
            ui.confirm({
                icon: 'forward_to_inbox',
                title: `${first ? 'Envoyer' : 'Renvoyer'} l’invitation ?`,
                body: `Un lien d’activation valable 72 heures sera envoyé à ${a.email}. Identifiant de connexion : ${a.phone}. Aucun mot de passe n’est transmis.`,
                ok: first ? 'Envoyer l’invitation' : 'Renvoyer',
                onConfirm: () => post(`${base(a)}/invitation`),
            });
        },

        emailPreview: (a: AgentRef & { firstName?: string }) =>
            ui.openPanel({
                kicker: `Aperçu de l’e-mail · ${a.email}`,
                title: 'Activez votre compte O’CHANTIER Commercial',
                badges: a.invitation ? [{ label: `Invitation · ${a.invitation.label}`, tone: 'b' }] : [],
                text: `Bonjour ${a.firstName ?? a.name}, votre compte commercial O’CHANTIER a été créé par l’administration. Cliquez sur « Activer mon compte » pour créer votre mot de passe personnel. Vous vous connecterez ensuite à l’Application Commercial avec votre numéro de téléphone.`,
                meta: [
                    ['Plateforme', 'O’CHANTIER — Application Commercial'],
                    ['Destinataire', `${a.name} · ${a.email}`],
                    ['Identifiant commercial', a.code],
                    ['Téléphone de connexion', a.phone ?? '—'],
                    ['Validité du lien', `72 heures${a.access?.expiresAt ? ` · jusqu’au ${formatDate(a.access.expiresAt)}` : ''}`],
                    ['Consignes de sécurité', 'Ne transférez pas cet e-mail. O’CHANTIER ne vous demandera jamais votre mot de passe. Choisissez un mot de passe d’au moins 8 caractères, différent de votre nom.'],
                    ['Support', 'support@ochantier.ci · +225 27 22 00 00 00'],
                    ['Mot de passe', 'Aucun mot de passe n’est transmis : il est créé par le commercial.'],
                ],
                actions: PRE_ACTIVATION.includes(a.status.value)
                    ? [
                          { label: 'Copier le lien', icon: 'link', onClick: () => actions.copyLink(a) },
                          { label: a.status.value === 'draft' ? 'Envoyer' : 'Renvoyer', icon: 'forward_to_inbox', variant: 'primary', onClick: () => actions.invite(a) },
                      ]
                    : [],
            }),

        copyLink: (a: AgentRef) => {
            if (!a.access?.expiresAt || ['revoked', 'expired'].includes(a.invitation?.value ?? '')) return ui.toast('Aucun lien valide : générez un nouveau lien.', { tone: 'error' });
            const url = `https://commercial.ochantier.ci/activation/${a.code.toLowerCase()}-${(a.access.sentAt ?? '').replace(/-/g, '')}`;
            navigator.clipboard?.writeText(url).catch(() => undefined);
            post(`${base(a)}/lien/copie`);
        },

        revokeLink: (a: AgentRef) =>
            ui.confirm({ tone: 'danger', icon: 'link_off', title: 'Révoquer le lien d’activation ?', body: 'Le lien actuel ne fonctionnera plus. Vous pourrez en générer un nouveau.', ok: 'Révoquer', onConfirm: () => post(`${base(a)}/lien/revocation`) }),

        newLink: (a: AgentRef) =>
            ui.confirm({ icon: 'add_link', title: 'Générer un nouveau lien ?', body: `Un nouveau lien valable 72 heures sera envoyé à ${a.email}.`, ok: 'Générer et envoyer', onConfirm: () => post(`${base(a)}/lien/nouveau`) }),

        editEmail: (a: AgentRef) =>
            setDialog(
                <FormDialog
                    title="Modifier l’adresse e-mail"
                    body="Possible uniquement avant l’activation du compte. Un nouveau lien sera envoyé."
                    icon="alternate_email"
                    okLabel="Enregistrer et renvoyer"
                    url={`${base(a)}/email`}
                    onClose={close}
                    initial={{ email: a.email ?? '' }}
                    fields={[{ name: 'email', label: 'Adresse e-mail', required: true, full: true }]}
                />,
            ),

        activate: (a: AgentRef) =>
            ui.confirm({
                tone: 'success',
                icon: 'how_to_reg',
                title: `Activer le compte de ${a.name} ?`,
                body: 'À utiliser si le commercial a créé son mot de passe mais que l’activation n’a pas été confirmée. Il sera redirigé vers la connexion de l’Application Commercial.',
                ok: 'Activer le compte',
                onConfirm: () => post(`${base(a)}/activation`),
            }),

        resetAccess: (a: AgentRef) => {
            if (a.status.value !== 'active') return ui.toast('Le compte doit être actif pour réinitialiser l’accès.', { tone: 'error' });
            ui.confirm({
                tone: 'warning',
                icon: 'lock_reset',
                title: 'Réinitialiser l’accès ?',
                body: `Un lien de réinitialisation sera envoyé à ${a.email}. Le commercial choisira un nouveau mot de passe. Toutes ses sessions seront fermées.`,
                ok: 'Envoyer le lien',
                onConfirm: () => post(`${base(a)}/reinitialisation`),
            });
        },

        suspend: (a: AgentRef) =>
            setDialog(
                <FormDialog
                    title={`Suspendre ${a.name} ?`}
                    body="Le commercial ne pourra plus se connecter. Ses ouvriers restent visibles et ses dossiers en cours sont conservés."
                    icon="block"
                    tone="danger"
                    okLabel="Suspendre"
                    url={`${base(a)}/suspension`}
                    onClose={close}
                    fields={[
                        { name: 'category', label: 'Motif', type: 'select', required: true, full: true, options: ['Profils frauduleux ou dupliqués', 'Inactivité prolongée', 'Comportement inapproprié', 'Demande du commercial', 'Autre'] },
                        { name: 'reason', label: 'Précisions', type: 'textarea', required: true },
                    ]}
                />,
            ),

        reactivate: (a: AgentRef) =>
            ui.confirm({ tone: 'success', icon: 'settings_backup_restore', title: `Réactiver ${a.name} ?`, body: 'Le commercial pourra se reconnecter avec son numéro et son mot de passe. Une notification lui sera envoyée par e-mail.', ok: 'Réactiver', onConfirm: () => post(`${base(a)}/reactivation`) }),

        deactivate: (a: AgentRef) =>
            setDialog(
                <FormDialog
                    title={`Désactiver ${a.name} ?`}
                    body="Le compte sera fermé. L’historique, les ouvriers et les gains restent consultables."
                    icon="person_off"
                    tone="danger"
                    okLabel="Désactiver"
                    url={`${base(a)}/desactivation`}
                    onClose={close}
                    fields={[
                        { name: 'category', label: 'Motif', type: 'select', required: true, full: true, options: ['Fin de contrat', 'Démission', 'Faute grave', 'Autre'] },
                        { name: 'reason', label: 'Précisions', type: 'textarea', required: true },
                    ]}
                />,
            ),

        archive: (a: AgentRef) => ui.confirm({ tone: 'danger', icon: 'archive', title: `Archiver ${a.name} ?`, body: 'Le commercial passera en lecture seule.', ok: 'Archiver', onConfirm: () => post(`${base(a)}/archivage`) }),

        destroy: (a: AgentRef) =>
            setDialog(
                <FormDialog
                    title={`Supprimer le compte de ${a.name} ?`}
                    body="Action irréversible : le compte, ses accès, ses sessions et son lien d’activation seront supprimés. Les ouvriers qu’il a enregistrés restent dans le module Ouvriers et la suppression est tracée dans le journal d’audit."
                    icon="delete_forever"
                    tone="danger"
                    okLabel="Supprimer définitivement"
                    method="delete"
                    url={base(a)}
                    onClose={close}
                    fields={[
                        { name: 'category', label: 'Motif', type: 'select', required: true, full: true, options: ['Compte créé par erreur', 'Doublon', 'Fin de collaboration', 'Demande du commercial (RGPD)', 'Autre'] },
                        { name: 'comment', label: 'Commentaire', type: 'textarea', required: true },
                        { name: 'confirm', label: `Saisissez l’identifiant ${a.code} pour confirmer`, required: true, full: true, placeholder: a.code },
                    ]}
                />,
            ),

        unavailability: (a: AgentRef, colleagues: string[]) =>
            setDialog(
                <FormDialog
                    title="Déclarer une indisponibilité"
                    body={`${a.name} · ${a.zone ?? ''}`}
                    icon="event_busy"
                    tone="warning"
                    okLabel="Enregistrer"
                    url={`${base(a)}/indisponibilite`}
                    onClose={close}
                    initial={{ starts_on: todayIso(), transfer: 'Non', replacement: '' }}
                    fields={[
                        { name: 'starts_on', label: 'Du', type: 'date', required: true },
                        { name: 'ends_on', label: 'Au', type: 'date', required: true },
                        { name: 'type', label: 'Type', type: 'select', required: true, options: [{ value: 'leave', label: 'En congé' }, { value: 'unavailable', label: 'Indisponible' }, { value: 'partial', label: 'Partiellement disponible' }, { value: 'field_mission', label: 'En mission terrain' }] },
                        { name: 'reason', label: 'Motif', type: 'select', required: true, options: ['Congé annuel', 'Maladie', 'Formation', 'Raison familiale', 'Mission hors zone', 'Autre'] },
                        { name: 'replacement', label: 'Remplaçant temporaire', type: 'select', options: [{ value: '', label: 'Aucun' }, ...colleagues.map((c) => ({ value: c, label: c }))] },
                        { name: 'transfer', label: 'Transférer temporairement les dossiers', type: 'select', options: ['Non', 'Oui'] },
                        { name: 'comment', label: 'Commentaire', type: 'textarea' },
                    ]}
                />,
            ),

        addZone: (a: AgentRef, communes: string[]) =>
            setDialog(
                <FormDialog
                    title="Attribuer une zone"
                    body={a.name}
                    icon="add_location_alt"
                    okLabel="Attribuer"
                    url={`${base(a)}/zones`}
                    onClose={close}
                    fields={[
                        { name: 'zone', label: 'Commune', type: 'select', required: true, full: true, options: communes.filter((z) => z !== a.zone && !a.secondaryZones?.includes(z)) },
                        { name: 'kind', label: 'Type d’attribution', type: 'select', required: true, full: true, options: [{ value: 'secondary', label: 'Zone secondaire' }, { value: 'primary', label: 'Zone principale (remplace l’actuelle)' }] },
                    ]}
                />,
            ),

        transferZone: (a: AgentRef, colleagues: string[]) => {
            if (!a.secondaryZones?.length) return ui.toast('Aucune zone secondaire à transférer.', { tone: 'error' });
            setDialog(
                <FormDialog
                    title="Transférer une zone"
                    body="Les dossiers en cours de la zone seront confiés au commercial choisi."
                    icon="move_down"
                    okLabel="Transférer"
                    url={`${base(a)}/zones/transfert`}
                    onClose={close}
                    fields={[
                        { name: 'zone', label: 'Zone', type: 'select', required: true, options: a.secondaryZones },
                        { name: 'to', label: 'Vers', type: 'select', required: true, options: colleagues },
                        { name: 'comment', label: 'Justification', type: 'textarea', required: true },
                    ]}
                />,
            );
        },

        removeValue: (a: AgentRef, list: 'zones-secondaires' | 'communes' | 'metiers-prioritaires', value: string, label: string) =>
            ui.confirm({ tone: 'danger', icon: 'wrong_location', title: `Retirer « ${value} » ?`, body: `${label} · les dossiers de la zone resteront attribués jusqu’au transfert.`, ok: 'Retirer', onConfirm: () => del(`${base(a)}/listes/${list}`, { value }) }),

        removePeriod: (a: AgentRef, id: number) => ui.confirm({ tone: 'danger', icon: 'delete', title: 'Supprimer cette période ?', ok: 'Supprimer', onConfirm: () => del(`${base(a)}/periodes/${id}`) }),

        objective: (a: AgentRef, objective?: Objective) =>
            setDialog(
                objective ? (
                    <FormDialog
                        title="Modifier l’objectif"
                        body={objective.label}
                        icon="flag"
                        okLabel="Enregistrer"
                        method="put"
                        url={`${base(a)}/objectifs/${objective.id}`}
                        onClose={close}
                        initial={{ target: String(objective.target) }}
                        fields={[{ name: 'target', label: 'Profils validés à atteindre', type: 'number', required: true, full: true }]}
                    />
                ) : (
                    <FormDialog
                        title="Ajouter un objectif"
                        body={a.name}
                        icon="flag"
                        okLabel="Enregistrer"
                        url={`${base(a)}/objectifs`}
                        onClose={close}
                        fields={[
                            { name: 'type', label: 'Type', type: 'select', required: true, options: ['Hebdomadaire', 'Mensuel', 'Trimestriel'] },
                            { name: 'label', label: 'Libellé de la période', required: true, placeholder: 'Ex. Novembre 2026' },
                            { name: 'starts_on', label: 'Début', type: 'date', required: true },
                            { name: 'ends_on', label: 'Fin', type: 'date', required: true },
                            { name: 'target', label: 'Profils validés à atteindre', type: 'number', required: true, full: true },
                        ]}
                    />
                ),
            ),

        closeObjective: (a: AgentRef, o: Objective) =>
            ui.confirm({ tone: 'warning', icon: 'lock', title: `Clôturer « ${o.label} » ?`, body: `Résultat : ${o.done ?? 0} / ${o.target} (${o.progress} %). L’objectif ne pourra plus être modifié.`, ok: 'Clôturer', onConfirm: () => post(`${base(a)}/objectifs/${o.id}/cloture`) }),

        appreciation: (a: AgentRef) =>
            setDialog(<FormDialog title="Ajouter une appréciation" icon="rate_review" okLabel="Enregistrer" url={`${base(a)}/appreciations`} onClose={close} fields={[{ name: 'text', label: 'Appréciation', type: 'textarea', required: true }]} />),

        /** `month` + `closed` : bonus rattaché à un classement (une autorisation est exigée si le mois est clôturé). */
        bonus: (a: AgentRef, options: { cotm?: boolean; month?: string; monthLabel?: string; closed?: boolean } = {}) =>
            setDialog(
                <FormDialog
                    title={options.cotm ? 'Attribuer le bonus Commercial du mois' : 'Ajouter un bonus'}
                    body={`${a.name}${options.monthLabel ? ` · ${options.monthLabel}` : ''}`}
                    icon="workspace_premium"
                    tone="success"
                    okLabel="Attribuer"
                    url={`${base(a)}/bonus${options.month ? `?month=${options.month}` : ''}`}
                    onClose={close}
                    initial={{ ...(options.cotm ? { type: 'Bonus Commercial du mois', amount: String(a.pay?.bonusCotm ?? '') } : {}) }}
                    fields={[
                        { name: 'type', label: 'Type de bonus', type: 'select', required: true, options: ['Bonus Commercial du mois', 'Bonus d’objectif', 'Prime exceptionnelle'] },
                        { name: 'amount', label: 'Montant (FCFA)', type: 'number', required: true },
                        { name: 'reason', label: 'Motif', type: 'textarea', required: true },
                        ...(options.closed ? [{ name: 'authorization', label: 'Autorisation administrative (classement clôturé)', required: true, full: true, placeholder: 'Référence de la décision ou nom du validateur' }] : []),
                    ]}
                />,
            ),

        deduction: (a: AgentRef) =>
            setDialog(
                <FormDialog
                    title="Ajouter une retenue"
                    body={a.name}
                    icon="money_off"
                    tone="danger"
                    okLabel="Enregistrer la retenue"
                    url={`${base(a)}/retenues`}
                    onClose={close}
                    fields={[
                        { name: 'amount', label: 'Montant (FCFA)', type: 'number', required: true },
                        { name: 'type', label: 'Nature', type: 'select', required: true, options: ['Profils dupliqués', 'Profil frauduleux', 'Avance sur commission', 'Autre'] },
                        { name: 'reason', label: 'Justification', type: 'textarea', required: true },
                    ]}
                />,
            ),

        payout: (a: AgentRef, ref: string, action: 'valider' | 'reprendre') => post(`${base(a)}/gains/${ref}/${action}`),

        suspendPayout: (a: AgentRef, ref: string) =>
            setDialog(<FormDialog title="Suspendre ce paiement ?" icon="pause_circle" tone="danger" okLabel="Suspendre" url={`${base(a)}/gains/${ref}/suspendre`} onClose={close} fields={[{ name: 'reason', label: 'Motif', type: 'textarea', required: true }]} />),

        permission: (a: AgentRef, key: string, label: string) => {
            const on = !!a.access?.permissions[key];
            ui.confirm({ tone: on ? 'warning' : 'primary', icon: 'admin_panel_settings', title: `${on ? 'Retirer' : 'Accorder'} l’autorisation ?`, body: `« ${label} » pour ${a.name}. La modification est enregistrée dans le journal d’audit.`, ok: on ? 'Retirer' : 'Accorder', onConfirm: () => post(`${base(a)}/permissions/${key}`) });
        },

        role: (a: AgentRef, options: { roles: string[]; levels: string[]; durations: string[] }) =>
            setDialog(
                <FormDialog
                    title="Modifier le rôle et le niveau d’accès"
                    body={a.name}
                    icon="manage_accounts"
                    okLabel="Enregistrer"
                    url={`${base(a)}/role`}
                    onClose={close}
                    initial={{ role: a.access?.role ?? '', level: a.access?.level ?? '', duration: a.access?.duration ?? '' }}
                    fields={[
                        { name: 'role', label: 'Rôle', type: 'select', required: true, options: options.roles },
                        { name: 'level', label: 'Niveau d’accès', type: 'select', required: true, options: options.levels },
                        { name: 'duration', label: 'Durée de validité de l’accès', type: 'select', required: true, full: true, options: options.durations },
                    ]}
                />,
            ),

        twoFactor: (a: AgentRef) => {
            const on = !!a.access?.twoFactor;
            ui.confirm({
                tone: on ? 'warning' : 'success',
                icon: 'phonelink_lock',
                title: `${on ? 'Désactiver' : 'Activer'} la double authentification ?`,
                body: on ? 'Le commercial ne recevra plus de code OTP à la connexion. Déconseillé.' : `Un code OTP sera envoyé par SMS au ${a.phone} à chaque nouvelle connexion.`,
                ok: on ? 'Désactiver' : 'Activer',
                onConfirm: () => post(`${base(a)}/double-authentification`),
            });
        },

        revokeSession: (a: AgentRef, session?: Session) =>
            ui.confirm({
                tone: 'danger',
                icon: 'logout',
                title: session ? 'Révoquer cette session ?' : 'Déconnecter tous les appareils ?',
                body: session ? `${session.device} · ${session.location}` : 'Toutes les sessions actives seront fermées immédiatement.',
                ok: session ? 'Révoquer' : 'Tout déconnecter',
                onConfirm: () => del(`${base(a)}/sessions${session ? `/${session.id}` : ''}`),
            }),

        /** Détail du calcul du score, avec correction administrative. */
        scoreDrawer: (a: AgentRef, row: Pick<RankingRow, 'rank' | 'score' | 'performance' | 'metrics' | 'normalized'>, month: { value: string; label: string; closed: boolean; isCurrent: boolean; reference: string }, weights: Weight[], profileLink = true) => {
            const raw: Record<string, string> = {
                validated: `${row.metrics.validated} profils`,
                validation_rate: `${row.metrics.validationRate} %`,
                objective: `${row.metrics.objective} %`,
                quality: `${row.metrics.quality} / 100`,
                activity: `${row.metrics.activity} / 100`,
                delays: `${row.metrics.delays} / 100`,
            };
            ui.openPanel({
                kicker: `Détail du score · ${month.label}`,
                title: `${a.name} · ${decimal(row.score)} / 100`,
                badges: [
                    { label: `Rang ${row.rank}`, tone: row.rank <= 3 ? 'a' : 'n' },
                    { label: row.performance.label, tone: row.performance.tone },
                    { label: month.closed ? 'Classement clôturé' : 'Provisoire', tone: month.closed ? 'n' : 'b' },
                ],
                text: `Chaque indicateur est normalisé sur 100 puis multiplié par sa pondération. Les profils frauduleux, dupliqués, rejetés ou annulés sont exclus (${row.metrics.frauds} exclu(s) ce mois).`,
                listTitle: 'Contribution des indicateurs',
                list: weights.map((w) => ({
                    icon: 'analytics',
                    tone: 'b',
                    name: w.label,
                    sub: `Valeur : ${raw[w.key]} · Normalisé : ${row.normalized[w.key]} / 100 · Pondération : ${w.weight} %`,
                    badge: `+${decimal(Math.round(row.normalized[w.key] * w.weight) / 100)} pts`,
                    badgeTone: 'g',
                })),
                meta: [
                    ['Score global', `${decimal(row.score)} / 100`],
                    ['Dernier calcul', month.isCurrent ? `${formatDate(month.reference)} · 23:00 (automatique)` : 'Clôture du mois'],
                    ['Départage', 'Taux de validation, puis profils validés, qualité, délais, taux de rejet'],
                ],
                actions: [
                    {
                        label: 'Corriger le score',
                        icon: 'edit',
                        onClick: () => {
                            ui.closePanel();
                            setDialog(
                                <FormDialog
                                    title="Correction administrative"
                                    body={`Ajuste le nombre de profils validés retenus pour ${month.label}.`}
                                    icon="edit"
                                    tone="warning"
                                    okLabel="Enregistrer la correction"
                                    url={`${base(a)}/score/${month.value}/correction`}
                                    onClose={close}
                                    fields={[
                                        { name: 'delta', label: 'Ajustement (profils validés, ex. -2)', required: true, full: true },
                                        { name: 'reason', label: 'Justification', type: 'textarea', required: true },
                                        ...(month.closed ? [{ name: 'authorization', label: 'Autorisation administrative', required: true, full: true }] : []),
                                    ]}
                                />,
                            );
                        },
                    },
                    ...(profileLink ? [{ label: 'Voir la fiche', icon: 'open_in_new', variant: 'primary' as const, onClick: () => router.visit(`${base(a)}/objectifs-performances`) }] : []),
                ],
            });
        },

        addDocument: (a: AgentRef, type = 'id_front') =>
            setDialog(
                <FormDialog
                    title="Ajouter ou remplacer un document"
                    body={`Le document sera ajouté au dossier de ${a.name} avec le statut « À vérifier ».`}
                    icon="upload_file"
                    okLabel="Enregistrer le document"
                    url={`${base(a)}/documents`}
                    onClose={close}
                    initial={{ type }}
                    fields={[
                        { name: 'type', label: 'Type de document', type: 'select', required: true, full: true, options: DOCUMENT_TYPES },
                        { name: 'reference', label: 'N° / référence', required: true },
                        { name: 'expires_at', label: 'Date d’expiration', type: 'date' },
                        { name: 'file', label: 'Fichier', type: 'file', required: true },
                    ]}
                />,
            ),

        validateDocument: (a: AgentRef, d: SalesDocument) =>
            ui.confirm({ tone: 'success', icon: 'verified', title: 'Valider ce document ?', body: `« ${d.label} » (${d.reference}) sera marqué comme vérifié.`, ok: 'Valider le document', onConfirm: () => post(`${base(a)}/documents/${d.id}/validation`) }),

        rejectDocument: (a: AgentRef, d: SalesDocument) =>
            setDialog(
                <FormDialog
                    title="Rejeter ce document ?"
                    body={`« ${d.label} » — le commercial sera notifié.`}
                    icon="cancel"
                    tone="danger"
                    okLabel="Rejeter le document"
                    url={`${base(a)}/documents/${d.id}/rejet`}
                    onClose={close}
                    fields={[
                        { name: 'reason', label: 'Motif', type: 'select', required: true, options: ['Document illisible', 'Document expiré', 'Incohérence d’identité', 'Mauvais document', 'Doublon potentiel', 'Autre'] },
                        { name: 'request', label: 'Action demandée', type: 'select', required: true, options: ['Déposer une nouvelle photo nette', 'Fournir un document à jour', 'Contacter le commercial référent', 'Se présenter en agence'] },
                        { name: 'comment', label: 'Commentaire', type: 'textarea', required: true },
                    ]}
                />,
            ),

        commentDocument: (a: AgentRef, d: SalesDocument) =>
            setDialog(
                <FormDialog
                    title="Commentaire interne"
                    body={`« ${d.label} » — visible uniquement par l’administration.`}
                    icon="chat"
                    okLabel="Enregistrer"
                    url={`${base(a)}/documents/${d.id}/commentaire`}
                    onClose={close}
                    initial={{ comment: d.internalComment ?? '' }}
                    fields={[{ name: 'comment', label: 'Commentaire interne', type: 'textarea', required: true }]}
                />,
            ),

        flagActivity: (a: AgentRef, id: number, label: string) =>
            setDialog(<FormDialog title="Signaler une anomalie" body={label} icon="report" tone="warning" okLabel="Signaler" url={`${base(a)}/activites/${id}/anomalie`} onClose={close} fields={[{ name: 'reason', label: 'Description de l’anomalie', type: 'textarea', required: true }]} />),
    };

    return actions;
}

export type SalesActions = ReturnType<typeof useSalesActions>;

export { fcfa, decimal };
