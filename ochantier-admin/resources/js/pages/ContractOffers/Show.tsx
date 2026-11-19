import { useContractOfferActions } from '@/components/contract-offers/useContractOfferActions';
import { TextCell } from '@/components/list/Table';
import Button from '@/components/ui/Button';
import Icon from '@/components/ui/Icon';
import StatusBadge from '@/components/ui/StatusBadge';
import { Block, DataTable, KeyValues } from '@/components/workers/blocks';
import { HistoryList } from '@/components/workers/tabs';
import { fcfa } from '@/lib/format';
import type { ContractOfferShowProps, ContractResponse } from '@/types/contractOffers';
import { Head, Link, router } from '@inertiajs/react';

/** Écart au prix fixé par le promoteur, signé (« +150 000 FCFA »). */
const gapLabel = (gap: number) => (gap === 0 ? 'au prix fixé' : `${gap > 0 ? '+' : '−'}${fcfa(Math.abs(gap))}`);

/**
 * Fiche d’une offre contractuelle : l’offre, les réponses des professionnels au prix promoteur, le comparatif,
 * les finances (commission Y, acompte X), l’attribution et la chronologie, avec prolongation, relances et annulation.
 */
export default function ContractOfferShow({ offer: o, can }: ContractOfferShowProps) {
    const actions = useContractOfferActions();
    const compared = o.responsesList.filter((r) => r.competing);
    const best = [...compared].sort((a, b) => a.total - b.total)[0];
    const meta: [string, string, string][] = [
        ['apartment', 'Promoteur', o.promoter],
        ['location_on', 'Lieu', o.place],
        ['date_range', 'Période', o.period],
        ['payments', 'Paiement du solde', o.paymentMode ?? '—'],
        ...(o.deadline ? ([['hourglass_top', 'Date limite', o.deadline]] as [string, string, string][]) : []),
    ];
    const contractorLink = (r: ContractResponse) => (r.contractorId ? [{ icon: 'person', label: 'Voir le professionnel', onClick: () => router.visit(`/entrepreneurs/${r.contractorId}`) }] : []);

    return (
        <>
            <Head title={`${o.reference} · ${o.site}`} />
            <div data-component="ContractOfferModule" style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-5)' }}>
                <section style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-5)', alignItems: 'flex-start', background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-5)' }}>
                    <span style={{ width: 'var(--avatar-lg)', height: 'var(--avatar-lg)', borderRadius: 'var(--radius-xl)', display: 'grid', placeItems: 'center', background: 'var(--c-primary-100)', flex: 'none' }}>
                        <Icon name="handshake" size="var(--icon-xl)" color="var(--c-primary)" />
                    </span>
                    <div style={{ flex: '1 1 360px', minWidth: 0 }}>
                        <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2_5)', flexWrap: 'wrap' }}>
                            <h2 style={{ margin: 0, fontSize: 'var(--fs-4xl)', fontWeight: 'var(--fw-bold)', letterSpacing: '-.4px' }}>{o.site}</h2>
                            <StatusBadge label={o.status.label} tone={o.status.tone} />
                        </div>
                        <div style={{ marginTop: 'var(--space-1)', fontSize: 'var(--fs-lg)', color: 'var(--c-muted)', fontVariantNumeric: 'tabular-nums' }}>
                            {o.reference} · prix fixé {fcfa(o.price)}
                            {o.quoteReference && o.quoteLineId && (
                                <>
                                    {' · issue de '}
                                    <Link href={`/demandes-devis/${o.quoteLineId}`} style={{ color: 'var(--c-primary)', fontWeight: 'var(--fw-semibold)' }}>
                                        {o.quoteReference}
                                    </Link>
                                </>
                            )}
                        </div>
                        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2) var(--space-5)', marginTop: 'var(--space-3)' }}>
                            {meta.map(([icon, label, value]) => (
                                <span key={label} style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-1_5)', fontSize: 'var(--fs-md)', color: 'var(--c-muted)' }}>
                                    <Icon name={icon} size="var(--icon-xs)" color="var(--c-primary-600)" />
                                    {label} : <b style={{ color: 'var(--c-ink)', fontWeight: 'var(--fw-semibold)' }}>{value}</b>
                                </span>
                            ))}
                        </div>
                    </div>
                    <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2)', alignItems: 'center' }}>
                        {can.update && o.can.remindContractors && <Button label="Relancer les professionnels" icon="campaign" variant="secondary" onClick={() => actions.remindContractors(o)} />}
                        {can.update && o.can.remindPromoter && <Button label="Relancer le promoteur" icon="notifications_active" variant="secondary" onClick={() => actions.remindPromoter(o)} />}
                        {can.update && o.can.extend && <Button label="Prolonger" icon="event_repeat" variant="secondary" onClick={() => actions.extend(o)} />}
                        {can.validate && o.can.cancel && <Button label="Annuler l’offre" icon="cancel" variant="danger-soft" onClick={() => actions.cancel(o)} />}
                    </div>
                </section>

                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,420px),1fr))', gap: 'var(--space-4)', background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-5)' }}>
                    <Block icon="description" title="L’offre">
                        <KeyValues
                            items={[
                                ['Prix fixé (promoteur)', fcfa(o.price)],
                                ['Période', o.period],
                                ['Paiement du solde', o.paymentMode],
                                ['Promoteur', o.promoter],
                                ['Téléphone', o.promoterContact.phone],
                                ['Prolongations', o.extensions ? String(o.extensions) : null],
                                ['Motif d’annulation', o.cancellationReason],
                                ['Description', o.description],
                            ]}
                        />
                    </Block>

                    <Block icon="payments" title="Finances & commission">
                        <KeyValues
                            items={[
                                ['Commission O’CHANTIER (Y)', `${o.rates.commission} du montant du professionnel`],
                                ['Acompte à l’attribution (X)', `${o.rates.deposit} du total promoteur`],
                                ...((o.retained
                                    ? [
                                          ['Total retenu (promoteur)', fcfa(o.retained.total)],
                                          ['Écart au prix fixé', gapLabel(o.retained.total - o.price)],
                                      ]
                                    : []) as [string, string][]),
                            ]}
                        />
                        <p style={{ margin: 'var(--space-3) 0 0', fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>La collecte et le reversement de la commission seront suivis dans le registre des commissions.</p>
                    </Block>

                    <div style={{ gridColumn: '1 / -1', minWidth: 0 }}>
                        <DataTable
                            icon="forum"
                            title="Réponses des professionnels"
                            count={`${o.responses} reçue${o.responses > 1 ? 's' : ''}${o.counterProposals ? ` · dont ${o.counterProposals} contre-proposition${o.counterProposals > 1 ? 's' : ''}` : ''}`}
                            minWidth={1000}
                            columns={['Professionnel', 'Réponse', 'Montant du professionnel', 'Commission', 'Total promoteur', 'Durée · équipe', 'Statut']}
                            empty={{ icon: 'forum', title: 'Aucune réponse', text: 'Aucun professionnel n’a encore répondu à cette offre.' }}
                            rows={o.responsesList.map((r) => ({
                                key: r.id,
                                cells: [
                                    <TextCell strong>
                                        {r.contractor}
                                        <span style={{ display: 'block', fontWeight: 'var(--fw-regular)', fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>
                                            {[r.contractorRef, r.rating !== null ? `★ ${String(r.rating).replace('.', ',')}` : null, r.distance].filter(Boolean).join(' · ')}
                                        </span>
                                    </TextCell>,
                                    <span style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-1)' }}>
                                        <StatusBadge label={r.mode.label} tone={r.mode.tone} />
                                        {r.quoteAuthor && <StatusBadge label="Auteur du devis" tone="p" icon="star" />}
                                    </span>,
                                    <TextCell>{fcfa(r.amount)}</TextCell>,
                                    <TextCell muted>{fcfa(r.commission)}</TextCell>,
                                    <TextCell strong>
                                        {fcfa(r.total)}
                                        <span style={{ display: 'block', fontWeight: 'var(--fw-regular)', fontSize: 'var(--fs-sm)', color: r.gap > 0 ? 'var(--c-danger-fg)' : 'var(--c-muted)' }}>{gapLabel(r.gap)}</span>
                                    </TextCell>,
                                    <TextCell>
                                        {r.duration} · {r.team}
                                        {r.dates && <span style={{ display: 'block', fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>{r.dates}</span>}
                                    </TextCell>,
                                    <span style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-1)' }}>
                                        <StatusBadge label={r.status.label} tone={r.status.tone} />
                                        {r.shown && <StatusBadge label="Montrée au promoteur" tone="n" />}
                                    </span>,
                                ],
                                actions: contractorLink(r),
                            }))}
                        />
                    </div>

                    <Block icon="compare_arrows" title="Comparatif" count={compared.length ? `${compared.length} offre${compared.length > 1 ? 's' : ''} en lice` : undefined}>
                        {compared.length === 0 ? (
                            <span style={{ fontSize: 'var(--fs-md)', color: 'var(--c-muted)' }}>Aucune offre à comparer pour l’instant.</span>
                        ) : (
                            compared.map((r, i) => (
                                <div key={r.id} style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-3)', flexWrap: 'wrap', padding: 'var(--space-2_5) 0', borderTop: i ? '1px solid var(--c-line-soft)' : undefined }}>
                                    <div style={{ flex: 1, minWidth: 180 }}>
                                        <b>{r.contractor}</b>
                                        {r === best && <span style={{ marginLeft: 'var(--space-2)' }}><StatusBadge label="Moins chère" tone="g" /></span>}
                                        <div style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>
                                            {r.duration} · {r.team} · acompte {fcfa(r.deposit)}
                                        </div>
                                    </div>
                                    <b style={{ fontVariantNumeric: 'tabular-nums' }}>{fcfa(r.total)}</b>
                                </div>
                            ))
                        )}
                    </Block>

                    <Block icon="construction" title="Attribution">
                        {o.attribution ? (
                            <KeyValues
                                items={[
                                    ['Professionnel retenu', o.retained?.contractor ?? '—'],
                                    ['Mission', o.attribution.mission],
                                    ['Période', o.attribution.period],
                                    ['Acompte', o.attribution.deposit ? `${o.attribution.deposit.paid} payés sur ${o.attribution.deposit.amount} · ${o.attribution.deposit.status}` : null],
                                    ['Référence de l’acompte', o.attribution.deposit?.reference ?? null],
                                ]}
                            />
                        ) : (
                            <span style={{ fontSize: 'var(--fs-md)', color: 'var(--c-muted)' }}>
                                {o.status.value === 'acompte_attendu' ? 'Le promoteur a choisi une offre : le chantier sera attribué au paiement de l’acompte.' : 'Le chantier sera attribué quand le promoteur aura choisi une offre et payé l’acompte.'}
                            </span>
                        )}
                    </Block>

                    <Block full icon="history" title="Historique">
                        {o.timeline.length ? <HistoryList activities={o.timeline} /> : <span style={{ color: 'var(--c-muted)' }}>Aucun événement.</span>}
                    </Block>
                </div>
            </div>

            {actions.dialog}
        </>
    );
}
