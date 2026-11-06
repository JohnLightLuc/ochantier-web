import FormDialog from '@/components/overlays/FormDialog';
import { RelationTable, stack } from '@/components/promoters/RelationTabs';
import Button from '@/components/ui/Button';
import StatusBadge from '@/components/ui/StatusBadge';
import { Block, KeyValues } from '@/components/workers/blocks';
import { fcfa, formatDate } from '@/lib/format';
import type { RefundCan, RefundRow, RegularisationRow } from '@/types/payouts';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { balanceLabel } from './Regularisations';

type Mission = RegularisationRow & { refundable: number; refunds: RefundRow[] };

/** Fiche d’une mission terminée : coût réel, ce qui a été réglé, écart et remboursements. */
export default function PaymentRegularisation({ mission: m, can }: { mission: Mission; can: RefundCan }) {
    const [refunding, setRefunding] = useState(false);
    const base = `/paiements/regularisations/${m.id}`;
    const open = m.state.value === 'a_faire';
    const manual = !open && m.refundable > 0 && can.update;

    return (
        <>
            <Head title={m.reference} />

            <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 'var(--space-2)', marginTop: -6 }}>
                {manual && <Button label="Demander un remboursement" icon="undo" variant="secondary" onClick={() => setRefunding(true)} />}
                {open && can.update && <Button label="Régulariser" icon="balance" disabled={m.blockers.length > 0} onClick={() => router.post(`${base}/calcul`, {}, { preserveScroll: true })} />}
            </div>

            <div style={stack}>
                {m.blockers.length > 0 && (
                    <Block icon="block" title="À traiter avant de régulariser">
                        <ul style={{ margin: 0, paddingLeft: 'var(--space-5)' }}>
                            {m.blockers.map((b) => (
                                <li key={b}>{b}</li>
                            ))}
                        </ul>
                    </Block>
                )}

                <Block icon="balance" title={m.reference}>
                    <KeyValues
                        items={[
                            ['État', <StatusBadge label={m.state.label} tone={m.state.tone as never} />],
                            ['Chantier', <Link href={`/chantiers/${m.site.id}`}>{m.site.label}</Link>],
                            ['Promoteur', m.promoter.full_name],
                            ['Terminée le', m.closedAt],
                            ['Journées validées', String(m.days)],
                            ['Coût des ouvriers', fcfa(m.workers)],
                            [`Commission (${m.yRate} %)`, fcfa(m.commission)],
                            ['Coût réel', fcfa(m.total)],
                            ['Prix convenu', fcfa(m.plannedTotal)],
                            ['Réglé par le promoteur', fcfa(m.paid)],
                            ['Déjà remboursé', fcfa(m.refunded)],
                            ['Écart', balanceLabel(m.balance)],
                        ]}
                    />
                </Block>

                {m.schedule && m.schedule.amount_due > 0 && (
                    <Block icon="event_note" title="Complément à payer">
                        <KeyValues
                            items={[
                                ['Échéance', <Link href={`/paiements/echeances/${m.schedule.id}`}>{m.schedule.reference}</Link>],
                                ['Montant', fcfa(m.schedule.amount_due)],
                                ['Date limite', m.schedule.due_date ? formatDate(m.schedule.due_date) : null],
                                ['Statut', <StatusBadge label={m.schedule.status.label} tone={m.schedule.status.tone as never} />],
                            ]}
                        />
                    </Block>
                )}

                <Block icon="undo" title="Remboursements">
                    <RelationTable
                        columns={['Référence', 'Origine', 'Montant', 'Statut']}
                        empty={{ icon: 'undo', title: 'Aucun remboursement', text: 'Un trop-perçu crée ici une demande à valider.' }}
                        rows={m.refunds.map((r) => ({ key: r.id, cells: [<Link href={`/paiements/remboursements/${r.id}`}>{r.reference}</Link>, r.origin.label, fcfa(r.amount), <StatusBadge label={r.status.label} tone={r.status.tone as never} />] }))}
                    />
                </Block>
            </div>

            {refunding && (
                <FormDialog
                    title="Demander un remboursement"
                    body={`Au plus ${fcfa(m.refundable)}. Une autre personne devra le valider.`}
                    icon="undo"
                    okLabel="Demander"
                    url={`${base}/remboursement`}
                    onClose={() => setRefunding(false)}
                    fields={[
                        { name: 'amount', label: 'Montant (FCFA)', type: 'number', required: true },
                        { name: 'reason', label: 'Motif', type: 'textarea', required: true },
                    ]}
                />
            )}
        </>
    );
}
