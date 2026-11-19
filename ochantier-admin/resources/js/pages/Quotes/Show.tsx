import { TextCell } from '@/components/list/Table';
import { useQuoteActions } from '@/components/quotes/useQuoteActions';
import Button from '@/components/ui/Button';
import Icon from '@/components/ui/Icon';
import StatusBadge from '@/components/ui/StatusBadge';
import { Block, DataTable, KeyValues } from '@/components/workers/blocks';
import { HistoryList } from '@/components/workers/tabs';
import { fcfa } from '@/lib/format';
import type { QuoteShowProps } from '@/types/quotes';
import { Head, Link, router } from '@inertiajs/react';

/**
 * Fiche d’une demande de devis (un métier) : la demande, les propositions de visite, la mission de visite, les
 * finances de la visite, les versions du devis et la chronologie, avec prolongation, relances et annulation.
 */
export default function QuoteShow({ quote: q, can }: QuoteShowProps) {
    const actions = useQuoteActions();
    const meta: [string, string, string][] = [
        ['apartment', 'Promoteur', q.promoter],
        ['location_on', 'Lieu', q.place],
        ['event', 'Publiée le', q.submittedAt ?? '—'],
        ...(q.deadline ? ([['hourglass_top', 'Date limite', q.deadline]] as [string, string, string][]) : []),
        ...(q.contractor ? ([['handyman', 'Professionnel choisi', q.contractor]] as [string, string, string][]) : []),
    ];

    return (
        <>
            <Head title={`${q.reference} · ${q.trade}`} />
            <div data-component="QuoteModule" style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-5)' }}>
                <section style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-5)', alignItems: 'flex-start', background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-5)' }}>
                    <span style={{ width: 'var(--avatar-lg)', height: 'var(--avatar-lg)', borderRadius: 'var(--radius-xl)', display: 'grid', placeItems: 'center', background: 'var(--c-primary-100)', flex: 'none' }}>
                        <Icon name="request_quote" size="var(--icon-xl)" color="var(--c-primary)" />
                    </span>
                    <div style={{ flex: '1 1 360px', minWidth: 0 }}>
                        <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2_5)', flexWrap: 'wrap' }}>
                            <h2 style={{ margin: 0, fontSize: 'var(--fs-4xl)', fontWeight: 'var(--fw-bold)', letterSpacing: '-.4px' }}>
                                {q.trade} · {q.site}
                            </h2>
                            <StatusBadge label={q.status.label} tone={q.status.tone} />
                        </div>
                        <div style={{ marginTop: 'var(--space-1)', fontSize: 'var(--fs-lg)', color: 'var(--c-muted)', fontVariantNumeric: 'tabular-nums' }}>
                            {q.reference}
                            {q.tradesCount > 1 ? ` · demande de ${q.tradesCount} métiers` : ''}
                        </div>
                        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2) var(--space-5)', marginTop: 'var(--space-3)' }}>
                            {meta.map(([icon, label, value]) => (
                                <span key={label} style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-1_5)', fontSize: 'var(--fs-md)', color: 'var(--c-muted)' }}>
                                    <Icon name={icon} size="var(--icon-xs)" color="var(--c-primary-600)" />
                                    {label} : <b style={{ color: 'var(--c-ink)', fontWeight: 'var(--fw-semibold)' }}>{value}</b>
                                </span>
                            ))}
                        </div>
                        {q.otherLines.length > 0 && (
                            <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2)', marginTop: 'var(--space-3)', fontSize: 'var(--fs-md)', color: 'var(--c-muted)' }}>
                                Autres métiers de la demande :
                                {q.otherLines.map((l) => (
                                    <Link key={l.id} href={`/demandes-devis/${l.id}`} style={{ color: 'var(--c-primary)', fontWeight: 'var(--fw-semibold)' }}>
                                        {l.trade ?? 'Métier'}
                                    </Link>
                                ))}
                            </div>
                        )}
                    </div>
                    <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2)', alignItems: 'center' }}>
                        {can.update && q.can.remindPromoter && <Button label="Relancer le promoteur" icon="notifications_active" variant="secondary" onClick={() => actions.remind(q, 'promoter')} />}
                        {can.update && q.can.remindContractor && <Button label="Relancer le professionnel" icon="notifications_active" variant="secondary" onClick={() => actions.remind(q, 'contractor')} />}
                        {can.update && q.can.extend && <Button label="Prolonger" icon="event_repeat" variant="secondary" onClick={() => actions.extend(q)} />}
                        {can.validate && q.can.cancel && <Button label="Annuler la demande" icon="cancel" variant="danger-soft" onClick={() => actions.cancel(q)} />}
                    </div>
                </section>

                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,420px),1fr))', gap: 'var(--space-4)', background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-5)' }}>
                    <Block icon="description" title="La demande">
                        <KeyValues
                            items={[
                                ['Chantier', q.site],
                                ['Métier', q.trade],
                                ['Promoteur', q.promoter],
                                ['Téléphone', q.promoterContact.phone],
                                ['Statut de la demande', q.requestStatus],
                                ['Notes du promoteur', q.notes],
                            ]}
                        />
                    </Block>

                    <Block icon="event_available" title="Mission de visite">
                        {q.visitInfo ? (
                            <>
                                <KeyValues
                                    items={[
                                        ['Professionnel', q.contractor],
                                        ['Visite', q.visitInfo.date],
                                        ['Durée prévue', q.visitInfo.duration],
                                        ['Statut', q.visitInfo.status],
                                        ['Réalisée le', q.visitInfo.visitedAt],
                                    ]}
                                />
                                {q.visitInfo.reschedule && (
                                    <div role="note" style={{ marginTop: 'var(--space-3)', padding: 'var(--space-3)', borderRadius: 'var(--radius-lg)', background: 'var(--c-orange-bg)', color: 'var(--c-orange-fg)', fontSize: 'var(--fs-md)' }}>
                                        <b>Report proposé le {q.visitInfo.reschedule.requestedAt}</b> · nouvelle date {q.visitInfo.reschedule.date}
                                        {q.visitInfo.reschedule.reason ? ` · ${q.visitInfo.reschedule.reason}` : ''}. En attente de la réponse du promoteur.
                                    </div>
                                )}
                            </>
                        ) : (
                            <span style={{ fontSize: 'var(--fs-md)', color: 'var(--c-muted)' }}>La visite sera planifiée quand le promoteur aura choisi et payé une proposition.</span>
                        )}
                    </Block>

                    <div style={{ gridColumn: '1 / -1', minWidth: 0 }}>
                        <DataTable
                            icon="forum"
                            title="Propositions de visite"
                            count={`${q.bids.length} reçue${q.bids.length > 1 ? 's' : ''}${q.status.value === 'ouverte' && q.bids.length > 3 ? ' · les 3 premières sont montrées au promoteur' : ''}`}
                            minWidth={900}
                            columns={['Professionnel', 'Visite proposée', 'Frais de visite', 'Frais de service', 'Total promoteur', 'Statut']}
                            empty={{ icon: 'forum', title: 'Aucune proposition', text: 'Aucun professionnel n’a encore proposé de visite.' }}
                            rows={q.bids.map((b) => ({
                                key: b.id,
                                cells: [
                                    <TextCell strong>
                                        {b.contractor}
                                        <span style={{ display: 'block', fontWeight: 'var(--fw-regular)', fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>
                                            {[b.contractorRef, b.rating !== null ? `★ ${String(b.rating).replace('.', ',')}` : null, b.distance].filter(Boolean).join(' · ')}
                                        </span>
                                    </TextCell>,
                                    <TextCell>
                                        {b.visit}
                                        {b.duration ? ` · ${b.duration}` : ''}
                                    </TextCell>,
                                    <TextCell>{fcfa(b.fee)}</TextCell>,
                                    <TextCell muted>{fcfa(b.serviceFee)}</TextCell>,
                                    <TextCell strong>{fcfa(b.total)}</TextCell>,
                                    <span style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-1)' }}>
                                        <StatusBadge label={b.status.label} tone={b.status.tone} />
                                        {b.shown && <StatusBadge label="Montrée au promoteur" tone="n" />}
                                    </span>,
                                ],
                                actions: b.contractorId ? [{ icon: 'person', label: 'Voir le professionnel', onClick: () => router.visit(`/entrepreneurs/${b.contractorId}`) }] : [],
                            }))}
                        />
                    </div>

                    <Block icon="payments" title="Finances · visite">
                        {q.finance.length ? <KeyValues items={q.finance} /> : <span style={{ fontSize: 'var(--fs-md)', color: 'var(--c-muted)' }}>Aucune proposition choisie pour l’instant.</span>}
                        {q.payment && (
                            <div style={{ marginTop: 'var(--space-3)' }}>
                                <KeyValues
                                    items={[
                                        ['Paiement', <StatusBadge label={q.payment.status.label} tone={q.payment.status.tone} />],
                                        ['Référence', q.payment.reference],
                                        ['Montant encaissé', q.payment.amount],
                                        ['Payé le', q.payment.paidAt],
                                        ...(q.payment.shared ? ([['Commande', q.payment.shared]] as [string, string][]) : []),
                                    ]}
                                />
                            </div>
                        )}
                        <p style={{ margin: 'var(--space-3) 0 0', fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>La collecte et le reversement des frais de service seront suivis dans le registre des commissions.</p>
                    </Block>

                    <Block icon="task" title="Versions du devis" count={q.versions.length ? `${q.versions.length} envoyée${q.versions.length > 1 ? 's' : ''}` : undefined}>
                        {q.versions.length === 0 ? (
                            <span style={{ fontSize: 'var(--fs-md)', color: 'var(--c-muted)' }}>Aucun devis envoyé pour l’instant.</span>
                        ) : (
                            q.versions.map((v, i) => (
                                <div key={v.id} style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-3)', flexWrap: 'wrap', padding: 'var(--space-2_5) 0', borderTop: i ? '1px solid var(--c-line-soft)' : undefined }}>
                                    <div style={{ flex: 1, minWidth: 200 }}>
                                        <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)', flexWrap: 'wrap' }}>
                                            <b>Devis {v.label}</b>
                                            <StatusBadge label={v.status.label} tone={v.status.tone} />
                                        </div>
                                        <div style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)', marginTop: 'var(--space-1)' }}>
                                            {[v.totalTtc ? `${v.totalTtc} TTC` : null, v.duration ? `travaux ${v.duration}` : null, v.validity ? `valable ${v.validity}` : null, v.sentAt ? `envoyé le ${v.sentAt}` : null, v.receivedAt ? `reçu le ${v.receivedAt}` : null].filter(Boolean).join(' · ')}
                                        </div>
                                    </div>
                                    {v.pdfUrl && <Button label="PDF" icon="picture_as_pdf" size="sm" variant="secondary" onClick={() => window.open(v.pdfUrl!, '_blank', 'noopener')} />}
                                </div>
                            ))
                        )}
                    </Block>

                    <Block full icon="history" title="Historique">
                        {q.timeline.length ? <HistoryList activities={q.timeline} /> : <span style={{ color: 'var(--c-muted)' }}>Aucun événement.</span>}
                    </Block>
                </div>
            </div>

            {actions.dialog}
        </>
    );
}
