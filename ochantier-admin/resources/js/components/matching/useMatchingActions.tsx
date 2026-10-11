import FormDialog from '@/components/overlays/FormDialog';
import type { PanelAction } from '@/components/overlays/SidePanel';
import { useAdminUi } from '@/contexts/AdminUiContext';
import { formatDate, formatNumber } from '@/lib/format';
import type { AnyTone } from '@/types';
import type { MatchingGroup, OfferInfo, Profile, SelectionInfo } from '@/types/matching';
import { router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';

const REJECTION_REASONS = ['Profil non adapté', 'Expérience insuffisante', 'Indisponibilité', 'Distance excessive', 'Coût trop élevé', 'Document manquant', 'Certification absente', 'Conflit de planning', 'Historique insatisfaisant', 'Refus du promoteur', 'Refus de l’ouvrier', 'Doublon', 'Erreur de données', 'Autre motif'];
const DEROGATION_REASONS = ['Urgence chantier', 'Accord écrit du promoteur', 'Document en cours de renouvellement', 'Autre'];
const RECALCULATION_REASONS = ['Disponibilité', 'Métier', 'Expérience', 'Localisation', 'Coût', 'Certification', 'Note', 'Statut du profil', 'Dates du besoin', 'Budget', 'Nombre de postes', 'Paramètres de Matching'];
const CRITERIA = ['Correspondance métier et spécialité', 'Disponibilité et planning', 'Expérience', 'Proximité du chantier', 'Compatibilité du coût', 'Qualité et complétude du profil', 'Note et fiabilité', 'Historique et engagements'];

/** Statuts d’un profil déjà transmis : il ne peut plus être sélectionné depuis le Matching. */
export const SENT = /Transmis|Confirmé|Démarré/;

export const criterionTone = (n: number): AnyTone => (n >= 80 ? 'g' : n >= 55 ? 'a' : 'r');
export const fcfa = (n: number) => `${formatNumber(n)} FCFA`;
export const decimal = (n: number) => String(n).replace('.', ',');

/** Actions du Matching : sélection, panneaux de profil et de validation, décisions sur un profil. Rendre `dialog` dans la page. */
export function useMatchingActions() {
    const ui = useAdminUi();
    const [dialog, setDialog] = useState<ReactNode>(null);
    const close = () => setDialog(null);
    const offerUrl = (offerId: number) => `/matching/offres/${offerId}`;
    const profileUrl = (p: Profile) => `${offerUrl(p.offerId)}/profils/${p.key}`;
    const post = (url: string, data: Record<string, unknown> = {}) => router.post(url, data as never, { preserveScroll: true, onSuccess: () => ui.closePanel() });
    const act = (label: string, icon: string, onClick: () => void, variant?: PanelAction['variant']): PanelAction => ({ label, icon, variant, onClick });

    const actions = {
        dialog,
        close,

        toggle: (p: Profile) => post(`${offerUrl(p.offerId)}/choix`, { key: p.key }),

        replacement: (p: Profile, role: string | null) => post(`${offerUrl(p.offerId)}/remplacant`, { key: p.key, role }),

        /** Panneau de détail d’un profil recommandé. */
        drawer: (p: Profile) =>
            ui.openPanel({
                kicker: `Profil recommandé · ${p.matchId.toUpperCase()}`,
                title: p.name,
                badges: [
                    { label: p.level.label, tone: p.level.tone },
                    { label: p.status.label, tone: p.status.tone },
                ],
                progress: p.score,
                text: `Aide à la décision : aucune sélection ni affectation automatique. Calcul du ${formatDate(p.computedOn)} · ${p.computedBy}.`,
                meta: [
                    ['Identifiant', p.code],
                    ['Métier', `${p.trade} · ${p.specialty ?? '—'}`],
                    ['Commune', p.commune],
                    ['Distance', `${decimal(p.km)} km · ${p.minutes} min`],
                    ['Expérience', `${p.experience} ans`],
                    ['Coût journalier', fcfa(p.cost)],
                    ['Disponibilité', `${p.availability} · dès le ${formatDate(p.availableOn)}`],
                    ['Profil vérifié', p.verified ? 'Oui' : 'Non'],
                    ['Qualité du dossier', `${p.dossier} %`],
                    ['Certifications', p.certs.join(', ') || 'Aucune'],
                    ['Note', `${decimal(p.rating)} / 5 (${p.reviews} avis)`],
                    ['Avertissements', p.warnings.join(' · ') || 'Aucun'],
                ],
                listTitle: 'Détail du score',
                list: p.criteria.map((c) => ({
                    icon: c.n >= 80 ? 'check_circle' : c.n >= 55 ? 'remove_circle' : 'cancel',
                    tone: criterionTone(c.n),
                    name: `${c.label} · ${c.value}`,
                    sub: `${c.n}/100 × ${c.weight} % = ${decimal(c.contribution)} pts · ${c.why}`,
                    badge: c.level,
                    badgeTone: criterionTone(c.n),
                })),
                actions: [
                    act('Voir le calcul complet', 'calculate', () => router.visit(`/matching/${p.matchId}/score`)),
                    ...(p.blocked || SENT.test(p.status.label)
                        ? []
                        : [
                              act(p.selected ? 'Désélectionner' : 'Sélectionner', p.selected ? 'remove' : 'add', () => actions.toggle(p), 'primary'),
                              act('Remplaçant principal', 'swap_horiz', () => actions.replacement(p, 'Remplaçant principal')),
                              act('Remplaçant secondaire', 'swap_horiz', () => actions.replacement(p, 'Remplaçant secondaire')),
                              act('Réserve', 'bookmark', () => actions.replacement(p, 'Réserve')),
                          ]),
                    p.workerSlug ? act('Fiche de l’ouvrier', 'open_in_new', () => router.visit(`/ouvriers/${p.workerSlug}`)) : act('Rechercher dans Ouvriers', 'search', () => router.visit(`/ouvriers?q=${encodeURIComponent(p.name)}`)),
                ],
            }),

        /** Validation de la sélection d’une offre : sursélection à traiter, ou récapitulatif avant soumission ou transmission. */
        validate: (offer: OfferInfo, selection: SelectionInfo) => {
            const main = selection.main;
            if (!main.length) return ui.toast('Sélectionnez au moins un profil.', { tone: 'error' });
            if (main.length > offer.rest) {
                return ui.openPanel({
                    kicker: `Sursélection · ${offer.reference}`,
                    title: `${main.length} profils pour ${offer.rest} poste(s)`,
                    text: 'La validation silencieuse d’une sélection supérieure au nombre de postes est impossible. Choisissez une option.',
                    actions: [
                        act('Réduire la sélection', 'remove', () => post(`${offerUrl(offer.id)}/sur-selection`, { mode: 'reduire' })),
                        act('Conserver les excédentaires comme remplaçants', 'swap_horiz', () => post(`${offerUrl(offer.id)}/sur-selection`, { mode: 'remplacants' }), 'primary'),
                        act('Demander une dérogation', 'gpp_maybe', () => {
                            ui.closePanel();
                            setDialog(<FormDialog title="Dérogation de sursélection" icon="gpp_maybe" tone="danger" okLabel="Confirmer" url={`${offerUrl(offer.id)}/sur-selection`} extra={{ mode: 'derogation' }} onClose={close} fields={[{ name: 'reason', label: 'Justification', type: 'textarea', required: true }]} />);
                        }),
                    ],
                });
            }
            const warnings = main.flatMap((p) => p.warnings.map((w) => `${p.name} : ${w}`));
            const missing = main.filter((p) => !p.verified || p.dossier < 70).map((p) => `${p.name} (dossier ${p.dossier} %)`);
            const b = selection.budget;
            ui.openPanel({
                kicker: `Validation de la sélection · ${offer.reference}`,
                title: `${offer.site.name} · ${offer.trade}`,
                badges: [{ label: selection.status.label, tone: selection.status.tone }],
                meta: [
                    ['Besoin', `${offer.need.reference} · ${offer.reference}`],
                    ['Chantier', `${offer.site.name} · ${offer.site.commune}`],
                    ['Métier', `${offer.trade} · ${offer.specialty ?? '—'}`],
                    ['Ouvriers requis', String(offer.rest)],
                    ['Profils sélectionnés', String(main.length)],
                    ['Remplaçants', selection.replacements.map((r) => `${r.name} (${r.role})`).join(', ') || 'Aucun'],
                    ['Dates', `${formatDate(offer.startsOn)} → ${formatDate(offer.endsOn)}`],
                    ['Rémunération', `${fcfa(offer.cost)}/j · ${offer.payType}${b.negotiable ? ' · négociable' : ''}`],
                    ['Budget estimé', `${fcfa(b.total)} / ${fcfa(b.available)} disponibles${b.over ? ' · DÉPASSÉ' : ''}`],
                    ['Conflits', main.filter((p) => p.conflict).map((p) => p.name).join(', ') || 'Aucun'],
                    ['Avertissements', warnings.join(' · ') || 'Aucun'],
                    ['Documents manquants', missing.join(', ') || 'Aucun'],
                    ['Conséquences', 'Des propositions d’affectation seront créées dans Affectations & Offres. Aucun ouvrier n’est affecté tant qu’il n’a pas confirmé.'],
                ],
                listTitle: 'Profils retenus',
                list: main.map((p) => ({ icon: 'person', tone: p.level.tone, name: `${p.name} · ${p.score} / 100`, sub: `${p.level.label} · ${fcfa(p.cost)}/j · ${decimal(p.km)} km`, badge: p.warnings.length ? `${p.warnings.length} avert.` : 'OK', badgeTone: p.warnings.length ? 'a' : 'g' })),
                actions: [
                    act('Revenir à la sélection', 'arrow_back', () => ui.closePanel()),
                    act('Enregistrer pour plus tard', 'save', () => post(`${offerUrl(offer.id)}/soumission`, { status: 'Brouillon' })),
                    act('Soumettre à validation', 'send', () => post(`${offerUrl(offer.id)}/soumission`, { status: 'En attente de validation' })),
                    act('Valider et transmettre aux Affectations', 'forward', () => post(`${offerUrl(offer.id)}/transmission`), 'primary'),
                ],
            });
        },

        /** Validation groupée depuis l’écran principal. */
        validateAll: (groups: MatchingGroup[]) => {
            const entries = groups
                .map((g) => ({ g, picks: g.picks.filter((p) => p.selected && !p.blocked).slice(0, g.offer.rest) }))
                .filter((e) => e.picks.length);
            if (!entries.length) return ui.toast('Aucun profil matché à valider pour les besoins sélectionnés.', { tone: 'error' });
            const cost = (e: (typeof entries)[number]) => e.picks.reduce((a, p) => a + p.cost * Math.max(1, Math.round((e.g.offer.duration * 6) / 7)), 0);
            const count = entries.reduce((a, e) => a + e.picks.length, 0);
            const needs = new Set(entries.map((e) => e.g.offer.need.reference)).size;
            ui.openPanel({
                kicker: 'Validation groupée · Matching',
                title: `${count} profil(s) matché(s) · ${needs} besoin(s)`,
                text: `Les profils ci-dessous seront validés et transmis aux Affectations & Offres (statut « À contacter »). Aucun ouvrier n’est affecté tant qu’il n’a pas confirmé. Coût estimé : ${fcfa(entries.reduce((a, e) => a + cost(e), 0))}.`,
                listTitle: 'Profils par besoin',
                list: entries.map((e) => ({
                    icon: 'engineering',
                    tone: e.picks.length >= e.g.offer.rest ? 'g' : 'a',
                    name: `${e.g.offer.need.reference} · ${e.g.offer.trade} — ${e.picks.map((p) => `${p.name} (${p.score} %)`).join(', ')}`,
                    sub: `${e.g.offer.site.name} · ${fcfa(cost(e))} estimés`,
                    badge: `${e.picks.length} / ${e.g.offer.rest}`,
                    badgeTone: e.picks.length >= e.g.offer.rest ? 'g' : 'a',
                })),
                actions: [act(`Valider et transmettre les ${count} profil(s)`, 'done_all', () => post('/matching/transmission', { offers: entries.map((e) => e.g.offer.id) }), 'primary'), act('Annuler', 'close', () => ui.closePanel())],
            });
        },

        reject: (p: Profile) =>
            setDialog(
                <FormDialog
                    title={`Rejeter la recommandation · ${p.name}`}
                    body={`Score ${p.score} / 100. Le rejet alimente les statistiques sans modifier les pondérations.`}
                    icon="block"
                    tone="danger"
                    okLabel="Rejeter"
                    url={`${profileUrl(p)}/rejet`}
                    onClose={close}
                    fields={[
                        { name: 'reason', label: 'Motif', type: 'select', required: true, options: REJECTION_REASONS },
                        { name: 'nature', label: 'Caractère', type: 'select', required: true, options: ['Temporaire', 'Définitif'] },
                        { name: 'criterion', label: 'Critère concerné', type: 'select', full: true, options: ['', ...CRITERIA].map((c) => ({ value: c, label: c || 'Aucun' })) },
                        { name: 'comment', label: 'Commentaire', type: 'textarea', required: true },
                        { name: 'corrective_action', label: 'Action corrective éventuelle', full: true },
                    ]}
                />,
            ),

        derogation: (p: Profile) => {
            if (p.absolute) return ui.toast(`Dérogation impossible : interdiction de sécurité absolue (${p.blocking.find((b) => b.absolute)?.criterion.toLowerCase()}).`, { tone: 'error' });
            setDialog(
                <FormDialog
                    title={`Demander une dérogation · ${p.name}`}
                    body={`Critères bloquants : ${p.blocking.map((b) => `${b.criterion} (${b.found})`).join(', ')}. La dérogation sera inscrite au journal d’audit.`}
                    icon="gpp_maybe"
                    tone="danger"
                    okLabel="Confirmer la dérogation"
                    url={`${profileUrl(p)}/derogation`}
                    onClose={close}
                    fields={[
                        { name: 'motive', label: 'Motif', type: 'select', required: true, full: true, options: DEROGATION_REASONS },
                        { name: 'justification', label: 'Justification', type: 'textarea', required: true },
                    ]}
                />,
            );
        },

        recalculate: (p: Profile, weights: string) =>
            setDialog(
                <FormDialog
                    title={`Recalculer le score · ${p.name}`}
                    body={`Score actuel : ${p.score} / 100 · pondérations ${weights}. L’ancien score est conservé dans l’historique.`}
                    icon="calculate"
                    okLabel="Recalculer"
                    url={`${profileUrl(p)}/recalcul`}
                    onClose={close}
                    fields={[{ name: 'reason', label: 'Raison du recalcul', type: 'select', required: true, full: true, options: RECALCULATION_REASONS }]}
                />,
            ),

        archive: (p: Profile) => ui.confirm({ icon: 'archive', title: `Archiver la recommandation ${p.matchId.toUpperCase()} ?`, body: `${p.name} ne sera plus proposé pour ce poste.`, ok: 'Archiver', onConfirm: () => post(`${profileUrl(p)}/archivage`) }),

        verification: (p: Profile) => post(`${profileUrl(p)}/verification`),

        correction: (offer: OfferInfo, selection: SelectionInfo) =>
            setDialog(<FormDialog title="Demander une correction" body={`Sélection ${offer.reference} · ${selection.main.map((p) => p.name).join(', ')}`} icon="rule" tone="warning" okLabel="Envoyer" url={`${offerUrl(offer.id)}/correction`} onClose={close} fields={[{ name: 'reason', label: 'Correction demandée', type: 'textarea', required: true }]} />),

        rejectSelection: (offer: OfferInfo) => setDialog(<FormDialog title="Rejeter la sélection" body={offer.reference} icon="block" tone="danger" okLabel="Rejeter" url={`${offerUrl(offer.id)}/rejet`} onClose={close} fields={[{ name: 'reason', label: 'Motif', type: 'textarea', required: true }]} />),

        reset: (offer: OfferInfo) => ui.confirm({ tone: 'danger', icon: 'restart_alt', title: 'Réinitialiser la sélection ?', body: `${offer.reference} · les profils sélectionnés et les remplaçants seront retirés.`, ok: 'Réinitialiser', onConfirm: () => post(`${offerUrl(offer.id)}/reinitialisation`) }),

        transmit: (offer: OfferInfo) => post(`${offerUrl(offer.id)}/transmission`),

        notifySales: (offer: OfferInfo) => post(`${offerUrl(offer.id)}/commerciaux`),

        activate: (r: Profile) => ui.confirm({ tone: 'warning', icon: 'swap_horiz', title: `Activer ${r.name} ?`, body: 'Le remplaçant rejoindra la sélection principale. Sa disponibilité doit être reconfirmée.', ok: 'Activer', onConfirm: () => post(`${offerUrl(r.offerId)}/choix`, { key: r.key }) }),
    };

    return actions;
}

export type MatchingActions = ReturnType<typeof useMatchingActions>;
