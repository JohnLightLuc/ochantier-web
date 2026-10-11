import { CandidateTable, CardList, MissingTable, Panels } from '@/components/assignments/blocks';
import { fcfa, useAssignmentActions } from '@/components/assignments/useAssignmentActions';
import { PersonCell, TextCell } from '@/components/list/Table';
import DetailHeader from '@/components/matching/DetailHeader';
import type { MenuItem } from '@/components/ui/Menu';
import StatusBadge from '@/components/ui/StatusBadge';
import { Block, DataTable, KeyValues } from '@/components/workers/blocks';
import { formatDate, formatRating } from '@/lib/format';
import type { AssignmentOfferProps, OfferProfileRow } from '@/types/assignments';
import { Head, router } from '@inertiajs/react';

const SOURCE_TONES: Record<string, 'b' | 'p' | 'n'> = { Candidature: 'b', Matching: 'p', Manuel: 'n' };

/** Offre d’un promoteur : profils choisis à contacter et valider, remplaçants proposés, acompte et historique. */
export default function AssignmentOffer({ offer: o, counts: k, deposit, profiles, replacement, missing, activities, options, today }: AssignmentOfferProps) {
    const actions = useAssignmentActions(options);
    const base = `/affectations/offres/${o.slug}`;
    const open = o.open;
    const status = o.statusLabel;
    const pending = profiles.filter((p) => p.state === 'pending');
    const contacted = profiles.filter((p) => p.state === 'contacted');
    const refused = profiles.find((p) => p.state === 'refused' || p.state === 'refused_by_worker');
    const person = (p: OfferProfileRow) => ({ id: p.id, name: p.name });
    const intro = `Présentez l’offre de ${o.promoter} (${o.site.name}, du ${formatDate(o.startsOn)} au ${formatDate(o.endsOn)}, ${fcfa(o.dailyCost)}/jour).`;
    const accepted = profiles.filter((p) => p.state === 'accepted').length;
    const isLast = (list: OfferProfileRow[]) => profiles.every((p) => list.includes(p) || p.state === 'accepted' || p.state === 'replaced');

    const contact = (list: OfferProfileRow[]) => actions.contact(base, list.map(person), intro);
    const validate = (list: OfferProfileRow[]) =>
        actions.validate(
            base,
            list.map(person),
            list.length > 1
                ? `${list.map((p) => p.name).join(', ')}. Une affectation sera créée pour chacun.${isLast(list) ? ` Le traitement de l’offre sera terminé : ${o.promoter} sera notifié automatiquement pour le paiement de l’acompte.` : ''}`
                : `L’ouvrier a été contacté et accepte l’offre. ${list[0].trade} · ${fcfa(list[0].cost)}/jour · ${o.site.name} du ${formatDate(o.startsOn)} au ${formatDate(o.endsOn)}. Une affectation sera créée.`,
        );
    const payDeposit = () => actions.payDeposit(`${base}/acompte`, `${o.promoter} · acompte de ${fcfa(deposit.deposit)} (${deposit.rate} % · Paramètres) sur un coût global de ${fcfa(deposit.total)}. La demande du promoteur sera validée.`);
    const showReplacements = (p: OfferProfileRow) => router.get(base, { remplacement: p.id }, { preserveScroll: true });

    const primary = [
        open && k.pending ? { label: `Contacter les ouvriers (${k.pending})`, icon: 'call', onClick: () => contact(pending) } : null,
        open && k.contacted ? { label: `Valider les ouvriers (${k.contacted})`, icon: 'done_all', onClick: () => validate(contacted) } : null,
        open && status === 'Promoteur à notifier' ? { label: 'Notifier le promoteur', icon: 'notifications', onClick: () => actions.notify(`${base}/notification`, 'Notifier le promoteur', o.promoter, `Offre ${o.reference} traitée · ${deposit.accepted} ouvrier(s) validé(s) · acompte de ${fcfa(deposit.deposit)} à régler`) } : null,
        open && status === 'Acompte en attente' ? { label: 'Valider le paiement de l’acompte', icon: 'payments', onClick: payDeposit } : null,
        open && status === 'Comblé · chantier en cours' ? { label: 'Nouveau besoin en ouvriers', icon: 'group_add', onClick: () => actions.complement(`${base}/complement`, `${o.promoter} · ${o.site.name} (chantier en cours).`, today) } : null,
        open && refused ? { label: 'Trouver un remplaçant', icon: 'join_inner', onClick: () => showReplacements(refused) } : null,
    ].filter((a): a is { label: string; icon: string; onClick: () => void } => a !== null);

    const menu: MenuItem[] = [
        { icon: 'construction', label: 'Voir le chantier', onClick: () => router.visit(`/chantiers/${o.site.slug}`) },
        { icon: 'apartment', label: 'Voir le promoteur', onClick: () => router.visit(o.promoterSlug ? `/promoteurs/${o.promoterSlug}` : `/promoteurs?q=${encodeURIComponent(o.promoter)}`) },
        { icon: 'join_inner', label: 'Ouvrir la page Matching', onClick: () => router.visit('/matching') },
        ...(open ? [{ icon: 'cancel', label: 'Annuler l’offre', danger: true, onClick: () => actions.cancelOffer(`${base}/annulation`, o.reference) }] : []),
    ];

    const banners: Record<string, [string, string, string, string]> = {
        Annulée: ['neutral', 'cancel', 'Offre annulée.', 'Lecture seule.'],
        'Promoteur à notifier': ['info', 'notifications', 'Traitement terminé.', `Tous les ouvriers ont validé l’offre : notifiez ${o.promoter} pour qu’il règle l’acompte.`],
        'Acompte en attente': ['warning', 'payments', `Promoteur notifié automatiquement le ${formatDate(o.notifiedOn)}.`, `En attente du paiement de l’acompte (${fcfa(deposit.deposit)}) pour valider la demande.`],
        'Comblé · chantier en cours': ['success', 'task_alt', 'Acompte validé · demande comblée.', `Acompte payé (${o.paymentReference}) · chantier en cours.`],
        'Remplacement à trouver': ['danger', 'person_search', `${k.refused} profil(s) refusé(s).`, 'Lancez un matching pour trouver un ouvrier alternatif.'],
    };
    const b = banners[status];

    const rowActions = (p: OfferProfileRow) => {
        if (!open) return [];
        if (p.state === 'pending') return [{ icon: 'call', label: 'Contacter', onClick: () => contact([p]) }];
        if (p.state === 'contacted')
            return [
                { icon: 'check_circle', label: 'Valider', onClick: () => validate([p]) },
                { icon: 'cancel', label: 'Invalider', onClick: () => actions.decline(`${base}/profils/${p.id}/refus`, p.name, options.refuseReasons) },
            ];
        if (p.state === 'refused' || p.state === 'refused_by_worker') return [{ icon: 'join_inner', label: 'Voir les remplaçants proposés', onClick: () => showReplacements(p) }];
        if (p.state === 'accepted' && o.paymentReference)
            return [{ icon: 'swap_horiz', label: 'Demande de remplacement', onClick: () => actions.requestReplacement(`${base}/profils/${p.id}/demande-remplacement`, p.name, `${o.promoter} demande le remplacement de cet ouvrier. Un matching proposera des ouvriers alternatifs ; le remplaçant sera contacté puis validé.`) }];
        return [];
    };

    return (
        <>
            <Head title={`Offre · ${o.reference}`} />
            <DetailHeader
                icon="local_offer"
                title={`${o.reference} · ${o.trade}`}
                badges={[o.status, o.urgency]}
                sub={`Offre du promoteur ${o.promoter} · ${o.site.name}`}
                code={`Reçue le ${formatDate(o.sentOn)}`}
                meta={[
                    ['location_on', 'Localisation', `${o.site.commune}, ${o.site.city}`],
                    ['event', 'Période', `${formatDate(o.startsOn)} → ${formatDate(o.endsOn)}`],
                    ['payments', 'Rémunération', `${fcfa(o.dailyCost)}/jour`],
                    ['groups', 'Profils', String(k.total)],
                ]}
                actions={primary}
                menu={menu}
                banner={b ? { tone: b[0], icon: b[1], title: b[2], text: b[3] } : null}
                kpis={[
                    { label: 'Profils sélectionnés', value: String(k.total), icon: 'groups', tone: 'b', hint: `Choisis par le promoteur${k.replaced ? ` · ${k.replaced} remplacé(s)` : ''}` },
                    { label: 'Validés', value: `${k.accepted} / ${k.total}`, icon: 'how_to_reg', tone: 'g', hint: 'Ouvriers contactés ayant accepté' },
                    { label: 'Refusés', value: String(k.refused), icon: 'person_off', tone: k.refused ? 'r' : 'n', hint: k.refused ? 'Remplacement à trouver' : 'Aucun refus' },
                    { label: 'À contacter / contactés', value: `${k.pending} / ${k.contacted}`, icon: 'call', tone: 'a', hint: 'Réponse de l’ouvrier attendue' },
                ]}
                tabs={[]}
                tab=""
                base={base}
            >
                <DataTable
                    icon="groups"
                    title="Profils sélectionnés par le promoteur"
                    count={`${k.total} ouvrier(s) · ${k.pending} à contacter · ${k.contacted} contacté(s) · ${accepted} validé(s)`}
                    minWidth={1200}
                    columns={['Ouvrier', 'Métier', 'Source', 'Commune', 'Expérience', 'Note', 'Coût journalier', 'Disponibilité', 'Contact', 'Statut', 'Motif / affectation']}
                    empty={{ icon: 'groups', title: 'Aucun profil', text: 'Le promoteur n’a sélectionné aucun ouvrier.' }}
                    rows={profiles.map((p) => ({
                        key: String(p.id),
                        onOpen: p.workerSlug ? () => router.visit(`/ouvriers/${p.workerSlug}`) : undefined,
                        cells: [
                            <PersonCell key="p" name={p.name} sub={p.replaces ? `Remplace ${p.replaces}` : p.workerSlug ? 'Fiche ouvrier' : undefined} />,
                            <TextCell key="t">{p.trade}</TextCell>,
                            <StatusBadge key="s" label={p.source} tone={SOURCE_TONES[p.source] ?? 'b'} />,
                            <TextCell key="c">{p.commune ?? '—'}</TextCell>,
                            <TextCell key="e">{`${p.experience} an${p.experience > 1 ? 's' : ''}`}</TextCell>,
                            <TextCell key="n">{formatRating(p.rating)} / 5</TextCell>,
                            <TextCell key="f">{fcfa(p.cost)}</TextCell>,
                            <TextCell key="d">{p.availability}</TextCell>,
                            <TextCell key="ct" muted={!p.contacted}>
                                {p.contact}
                            </TextCell>,
                            <StatusBadge key="st" label={p.status.label} tone={p.status.tone} />,
                            <TextCell key="m" muted={p.detail === '—'}>
                                {p.detail}
                            </TextCell>,
                        ],
                        actions: rowActions(p),
                        menu: [p.workerSlug ? { icon: 'person', label: 'Fiche de l’ouvrier', onClick: () => router.visit(`/ouvriers/${p.workerSlug}`) } : { icon: 'search', label: 'Rechercher dans Ouvriers', onClick: () => router.visit(`/ouvriers?q=${encodeURIComponent(p.name)}`) }],
                    }))}
                />

                {replacement && (
                    <CandidateTable
                        icon="join_inner"
                        title={`Matching automatique · remplaçants proposés pour ${replacement.profile.name}`}
                        count={`Même métier (${o.trade}) · profils disponibles non affectés · classés par score`}
                        rows={replacement.rows}
                        compareTo={replacement.profile.experience}
                        empty={{ title: 'Aucun profil disponible', text: 'Élargissez la recherche depuis la page Matching.' }}
                        action={(c) => ({
                            icon: 'swap_horiz',
                            label: 'Proposer en remplacement',
                            onClick: () => actions.propose(`${base}/profils/${replacement.profile.id}/remplacant`, c.key, c.name, `Remplace ${replacement.profile.name}. Le promoteur ${o.promoter} sera notifié ; le profil passera « En attente » pour traitement.`),
                        })}
                        menu={() => [{ icon: 'close', label: 'Fermer les suggestions', onClick: () => router.get(base, { remplacement: '0' }, { preserveScroll: true }) }]}
                    />
                )}

                {missing.length > 0 && <MissingTable rows={missing} open={open} />}

                <Panels>
                    <Block icon="info" title="Détails de l’offre">
                        <KeyValues
                            items={[
                                ['Promoteur', o.promoter],
                                ['Chantier', `${o.site.name} · ${o.site.type}`],
                                ['Localisation', `${o.site.commune}, ${o.site.city}`],
                                ['Métier / spécialité', `${o.trade}${o.specialty ? ` · ${o.specialty}` : ''}`],
                                ['Période', `${formatDate(o.startsOn)} → ${formatDate(o.endsOn)} · ${o.duration} j`],
                                ['Rémunération', `${fcfa(o.dailyCost)}/jour`],
                                ['Coût global (validés)', fcfa(deposit.total)],
                                [`Acompte (${deposit.rate} % · Paramètres)`, `${fcfa(deposit.deposit)}${o.paymentReference ? ` · payé (${o.paymentReference})` : o.notifiedOn ? ` · promoteur notifié automatiquement le ${formatDate(o.notifiedOn)}` : ' · notification automatique après validation des ouvriers'}`],
                                ['Message du promoteur', o.message ?? '—'],
                            ]}
                        />
                    </Block>
                    <Block icon="history" title="Historique">
                        <CardList items={activities.map((e) => ({ key: e.id, icon: 'history', kicker: `${formatDate(e.date)} · ${e.time} · ${e.author}`, title: e.action, sub: e.comment, badge: e.badge }))} />
                    </Block>
                </Panels>
            </DetailHeader>

            {actions.dialog}
        </>
    );
}
