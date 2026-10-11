import { CandidateTable, CardList, MissingTable, Panels } from '@/components/assignments/blocks';
import { fcfa, useAssignmentActions } from '@/components/assignments/useAssignmentActions';
import { PersonCell, TextCell } from '@/components/list/Table';
import DetailHeader from '@/components/matching/DetailHeader';
import { useNeedActions } from '@/components/needs/useNeedActions';
import type { MenuItem } from '@/components/ui/Menu';
import StatusBadge from '@/components/ui/StatusBadge';
import { Block, DataTable, KeyValues } from '@/components/workers/blocks';
import { formatDate } from '@/lib/format';
import type { AssignmentNeedProps, AssignmentRow } from '@/types/assignments';
import { Head, router } from '@inertiajs/react';
import { useEffect } from 'react';

/** Affectation d’un besoin : ouvriers trouvés à contacter et valider, propositions du Matching, remplaçants, acompte. */
export default function AssignmentNeed(props: AssignmentNeedProps) {
    const { need: n, actionRef, coverage: c, assignments, proposals, replacement, schedule, progress, missing, manualPosts, autoAdd, options } = props;
    const actions = useAssignmentActions(options);
    const needActions = useNeedActions();
    const base = `/affectations/besoins/${n.slug}`;
    const open = n.open;
    const toContact = assignments.filter((a) => a.state === 'to_contact');
    const contacted = assignments.filter((a) => a.state === 'contacted');
    const person = (a: AssignmentRow) => ({ id: a.id, name: a.worker, phone: a.phone });
    const intro = `Présentez le besoin ${n.reference} (${n.site.name}, début le ${formatDate(n.startsOn)}) et recueillez la réponse de l’ouvrier.`;
    const allValidated = c.selected >= c.need && c.validated >= c.need;
    const expired = actionRef.expiredOffers;

    const contact = (list: AssignmentRow[]) => actions.contact(base, list.map(person), intro);
    const validate = (list: AssignmentRow[]) => actions.validate(base, list.map(person), `L’ouvrier a confirmé sa disponibilité et accepte les conditions du besoin ${n.reference}. L’affectation est validée.`);
    const refresh = () => needActions.refreshAll(actionRef);
    const match = () => needActions.match(actionRef);
    const payDeposit = () => actions.payDeposit(`${base}/acompte`, `${n.promoter} · ${fcfa(schedule.deposit)} sur un coût global de ${fcfa(schedule.total)} (${schedule.mode.toLowerCase()}).`);
    const complement = () => actions.complement(`${base}/complement`, `${n.promoter} · ${n.site.name} (chantier en cours).`, actionRef.today);
    const manual = (offerId?: number) => actions.manual(`${base}/ouvriers`, n.reference, manualPosts, offerId);

    useEffect(() => {
        if (autoAdd && open) manual(Number(autoAdd.split(':')[1]) || undefined);
        // Ouverture unique à l’arrivée depuis « Créer une affectation ».
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const primary = [
        open && toContact.length ? { label: `Contacter les ouvriers (${toContact.length})`, icon: 'call', onClick: () => contact(toContact) } : null,
        open && contacted.length ? { label: `Valider les ouvriers (${contacted.length})`, icon: 'verified', onClick: () => validate(contacted) } : null,
        open && n.statusLabel === 'Validé · acompte en attente' ? { label: 'Valider le paiement de l’acompte', icon: 'payments', onClick: payDeposit } : null,
        open && n.statusLabel === 'Comblé · chantier en cours' ? { label: 'Nouveau besoin en ouvriers', icon: 'group_add', onClick: complement } : null,
        open && c.rest ? (n.statusLabel === 'Expiré' ? { label: `Actualiser pour les manquants (${c.rest})`, icon: 'autorenew', onClick: refresh } : { label: `Lancer le matching (${c.rest} manquant${c.rest > 1 ? 's' : ''})`, icon: 'join_inner', onClick: match }) : null,
    ].filter((a): a is { label: string; icon: string; onClick: () => void } => a !== null);

    const menu: MenuItem[] = [
        ...primary.slice(2),
        ...(open && manualPosts.length ? [{ icon: 'person_add', label: 'Ajouter un ouvrier manuellement', onClick: () => manual() }] : []),
        { icon: 'assignment', label: 'Voir la fiche du besoin', onClick: () => router.visit(`/besoins/${n.slug}`) },
        { icon: 'notifications', label: 'Relancer le promoteur', onClick: () => actions.notify(`${base}/relance`, 'Relancer le promoteur', `${n.contact} · ${n.promoter}`, `Validation des affectations · ${n.reference}`) },
        { icon: 'construction', label: 'Voir le chantier', onClick: () => router.visit(`/chantiers/${n.site.slug}`) },
    ];

    const banners: Record<string, [string, string, string, string]> = {
        'Comblé · remplacement en cours': ['warning', 'swap_horiz', 'Remplacement en cours.', 'Le chantier continue ; le remplaçant doit être contacté puis validé (acompte déjà réglé).'],
        Expiré: ['warning', 'timer_off', 'Besoin expiré.', `${c.selected} ouvrier(s) trouvé(s) restent affectés et peuvent être contactés et validés. Les ${c.rest} manquant(s) seront recherchés si le promoteur actualise le besoin.`],
        'Partiellement couvert': ['info', 'pie_chart', 'Besoin partiellement couvert.', `Contactez déjà les ouvriers trouvés ; ${c.rest} ouvrier(s) manquant(s) restent à rechercher par le matching ou manuellement.`],
        'Couvert · validation des ouvriers': ['info', 'event_available', 'Besoin entièrement couvert.', 'Contactez et validez chaque ouvrier pour valider le besoin.'],
        'Validé · acompte en attente': ['warning', 'payments', `Promoteur notifié automatiquement${n.notifiedOn ? ` le ${formatDate(n.notifiedOn)}` : ''}.`, `En attente du paiement de l’acompte (${fcfa(schedule.deposit)}) pour valider la demande.`],
        'Comblé · chantier en cours': ['success', 'task_alt', 'Demande validée.', `Acompte payé${n.paymentReference ? ` (${n.paymentReference})` : ''} · chantier en cours.`],
    };
    const b = banners[n.statusLabel];
    const banner = b ? { tone: b[0], icon: b[1], title: b[2], text: b[3], ...(open && c.rest ? { cta: n.statusLabel === 'Expiré' ? 'Actualiser le besoin' : 'Voir le Matching', onCta: n.statusLabel === 'Expiré' ? refresh : match } : {}) } : null;

    const rowActions = (a: AssignmentRow) => {
        if (!open) return [];
        if (a.state === 'to_contact') return [{ icon: 'call', label: 'Contacter', onClick: () => contact([a]) }];
        if (a.state === 'contacted')
            return [
                { icon: 'check_circle', label: 'Valider', onClick: () => validate([a]) },
                { icon: 'cancel', label: 'Invalider', onClick: () => actions.decline(`${base}/ouvriers/${a.id}/invalidation`, a.worker, options.declineReasons) },
            ];
        if (a.state === 'refused') return [{ icon: 'join_inner', label: 'Voir les remplaçants proposés', onClick: () => router.get(base, { remplacement: a.reference }, { preserveScroll: true }) }];
        if (a.state === 'validated' && a.depositPaid)
            return [{ icon: 'swap_horiz', label: 'Demande de remplacement', onClick: () => actions.requestReplacement(`${base}/ouvriers/${a.id}/demande-remplacement`, a.worker, `${n.promoter} demande le remplacement de cet ouvrier sur ${n.site.name}. Un matching proposera des ouvriers alternatifs du même métier ; le remplaçant sera contacté puis validé (acompte déjà réglé).`) }];
        return [];
    };

    const rowMenu = (a: AssignmentRow): MenuItem[] => [
        a.workerSlug ? { icon: 'person', label: 'Fiche de l’ouvrier', onClick: () => router.visit(`/ouvriers/${a.workerSlug}`) } : { icon: 'search', label: 'Rechercher dans Ouvriers', onClick: () => router.visit(`/ouvriers?q=${encodeURIComponent(a.worker)}`) },
        ...(open && a.state === 'to_contact' ? [{ icon: 'cancel', label: 'Invalider', danger: true, onClick: () => actions.decline(`${base}/ouvriers/${a.id}/invalidation`, a.worker, options.declineReasons) }] : []),
        ...(open && a.state === 'validated' && !a.depositPaid ? [{ icon: 'undo', label: 'Annuler la validation', onClick: () => actions.cancelValidation(`${base}/ouvriers/${a.id}/annulation-validation`, a.worker) }] : []),
    ];

    const openMatching = () => router.visit(`/matching?besoin=${n.slug}`);

    return (
        <>
            <Head title={`Affectation · ${n.reference}`} />
            <DetailHeader
                icon="event_available"
                title={`${n.reference} · ${n.site.name}`}
                badges={[n.status, n.urgency]}
                sub={`Besoin du promoteur ${n.promoter} · ${n.site.type}`}
                code={`Créé le ${formatDate(n.createdOn)}`}
                meta={[
                    ['location_on', 'Localisation', `${n.site.commune}, ${n.site.city}`],
                    ['event', 'Période', `${formatDate(n.startsOn)} → ${formatDate(n.endsOn)}`],
                    ['groups', 'Sélectionnés / recherchés', `${c.selected} / ${c.need}`],
                    ['payments', 'Coût global', fcfa(schedule.total)],
                ]}
                actions={primary.slice(0, 2)}
                menu={menu}
                banner={banner}
                kpis={[
                    { label: 'Sélectionnés / recherchés', value: `${c.selected} / ${c.need}`, icon: 'groups', tone: c.rest ? 'a' : 'g', hint: c.rest ? `${c.rest} ouvrier(s) manquant(s)` : 'Besoin entièrement couvert' },
                    { label: 'À contacter', value: String(c.toContact), icon: 'call', tone: 'a', hint: 'Ouvriers trouvés à joindre' },
                    { label: 'Contactés', value: String(c.contacted), icon: 'mark_chat_read', tone: 'b', hint: 'Réponse de l’ouvrier attendue' },
                    { label: 'Validés', value: `${c.validated} / ${c.need}`, icon: 'verified', tone: 'g', hint: allValidated ? 'Tous les ouvriers ont validé' : 'Progression de la validation' },
                ]}
                tabs={[]}
                tab=""
                base={base}
            >
                <DataTable
                    icon="groups"
                    title="Ouvriers sélectionnés pour ce besoin"
                    count={`${c.selected} / ${c.need} sélectionné(s) · ${c.validated} validé(s)`}
                    minWidth={1250}
                    columns={['Ouvrier', 'Poste', 'Téléphone', 'Commune', 'Coût journalier', 'Source', 'Sélectionné le', 'Contact', 'Statut']}
                    empty={{ icon: 'person_search', title: 'Aucun ouvrier sélectionné', text: 'Ajoutez les propositions automatiques du Matching ou un ouvrier manuellement.' }}
                    rows={assignments.map((a) => ({
                        key: String(a.id),
                        onOpen: a.workerSlug ? () => router.visit(`/ouvriers/${a.workerSlug}`) : undefined,
                        cells: [
                            <PersonCell key="p" name={a.worker} sub={a.replaces ? `${a.reference} · remplace ${a.replaces}` : a.reference} />,
                            <TextCell key="t">{a.post}</TextCell>,
                            <TextCell key="ph">{a.phone ?? '—'}</TextCell>,
                            <TextCell key="c">{a.commune ?? '—'}</TextCell>,
                            <TextCell key="f">{fcfa(a.cost)}</TextCell>,
                            <StatusBadge key="s" label={a.source} tone={a.source === 'Manuel' ? 'n' : 'p'} />,
                            <TextCell key="d">{formatDate(a.selectedOn)}</TextCell>,
                            <TextCell key="ct" muted={!a.contacted}>
                                {a.contact}
                            </TextCell>,
                            <StatusBadge key="st" label={a.status.label} tone={a.status.tone} />,
                        ],
                        actions: rowActions(a),
                        menu: rowMenu(a),
                    }))}
                />

                {replacement && (
                    <CandidateTable
                        icon="join_inner"
                        title={`Matching automatique · remplaçants proposés pour ${replacement.assignment.name}`}
                        count={`Même métier (${replacement.assignment.trade}) · profils disponibles non affectés · classés par score`}
                        rows={replacement.rows}
                        empty={{ title: 'Aucun profil disponible', text: 'Élargissez la recherche depuis la page Matching.' }}
                        action={(p) => ({
                            icon: 'swap_horiz',
                            label: 'Proposer en remplacement',
                            onClick: () => actions.propose(`${base}/ouvriers/${replacement.assignment.id}/remplacant`, p.key, p.name, `Remplace ${replacement.assignment.name} (invalidé). L’ouvrier passera « À contacter » pour être contacté puis validé.`),
                        })}
                        menu={() => [{ icon: 'close', label: 'Fermer les suggestions', onClick: () => router.get(base, { remplacement: '0' }, { preserveScroll: true }) }]}
                    />
                )}

                {proposals && (
                    <CandidateTable
                        icon="join_inner"
                        title="Propositions automatiques du Matching"
                        count={`${proposals.rows.length} profil(s) pour ${proposals.rest} poste(s) manquant(s) · même métier · un ouvrier par poste`}
                        rows={proposals.rows}
                        withTrade
                        tools={
                            proposals.rows.length
                                ? [
                                      { label: `Ajouter toutes les propositions (${proposals.rows.length})`, icon: 'done_all', variant: 'primary', onClick: () => router.post(`${base}/propositions`, {}, { preserveScroll: true }) },
                                      { label: 'Ouvrir dans Matching', icon: 'open_in_new', onClick: openMatching },
                                  ]
                                : []
                        }
                        empty={{ title: 'Aucun profil compatible', text: 'Aucun ouvrier disponible du même métier ne satisfait les critères du Matching (Paramètres › Matching).' }}
                        action={(p) => ({ icon: 'person_add', label: 'Ajouter à l’affectation', onClick: () => router.post(`${base}/propositions`, { picks: [`${p.offerId}:${p.key}`] }, { preserveScroll: true }) })}
                        menu={(p) => [{ icon: 'join_inner', label: 'Voir les autres profils (Matching)', onClick: () => router.visit(p.offerId ? `/matching/besoins/${p.offerId}` : '/matching') }]}
                    />
                )}

                <MissingTable rows={missing} open={open} />

                <Panels>
                    <Block icon="payments" title="Coût global et échéancier">
                        <KeyValues
                            items={[
                                ['Mode de rémunération', `${schedule.mode} · ${schedule.days} jours ouvrés`],
                                ['Coût global estimé', fcfa(schedule.total)],
                                ...schedule.entries.map((e): [string, string] => [e.label, `${fcfa(e.amount)} · ${formatDate(e.date)} · ${e.status}`]),
                                ...(n.paymentReference ? [['Paiement de l’acompte', n.paymentReference] as [string, string]] : []),
                            ]}
                        />
                    </Block>
                    <Block icon="timeline" title="Progression du besoin">
                        <CardList
                            items={progress.map((s) => ({
                                key: s.label,
                                icon: s.state === 'done' ? 'check_circle' : s.state === 'current' ? 'pending' : 'radio_button_unchecked',
                                kicker: s.sub,
                                title: s.label,
                                badge: { label: s.state === 'done' ? 'Réalisé' : s.state === 'current' ? 'En cours' : 'À venir', tone: s.state === 'done' ? 'g' : s.state === 'current' ? 'a' : 'n' },
                            }))}
                        />
                    </Block>
                </Panels>
            </DetailHeader>

            {actions.dialog}
            {needActions.dialog}
        </>
    );
}
