import { TextCell } from '@/components/list/Table';
import StatusBadge from '@/components/ui/StatusBadge';
import { DataTable } from '@/components/workers/blocks';
import { formatDate } from '@/lib/format';
import type { Refund } from '@/types/payments';
import { router } from '@inertiajs/react';
import { fcfa, type PaymentActions } from './usePaymentActions';

/** Remboursements avec leur impact sur la commission ; les demandes s’approuvent ou se rejettent. */
export function RefundTable({ refunds, actions, withPayment = false, reference }: { refunds: Refund[]; actions: PaymentActions; withPayment?: boolean; reference?: string }) {
    return (
        <DataTable
            icon="currency_exchange"
            title="Remboursements"
            count={`${refunds.length} demande(s)`}
            minWidth={withPayment ? 1600 : 1300}
            columns={['Date', ...(withPayment ? ['Opération'] : []), 'Type', 'Montant', 'Motif', 'Bénéficiaire', 'Responsabilité', 'Impact commission', 'Moment', 'Justificatif', 'Demandeur', 'Approbateur', 'Statut']}
            empty={{ icon: 'currency_exchange', title: 'Aucun remboursement', text: withPayment ? 'Aucune demande sur la période.' : 'Aucune demande pour cette opération.' }}
            rows={refunds.map((r) => {
                const ref = r.payment ?? reference ?? '';
                return {
                    key: String(r.id),
                    onOpen: withPayment ? () => router.visit(`/paiements/operations/${ref}/remboursements`) : undefined,
                    cells: [
                        <TextCell key="d">{formatDate(r.date)}</TextCell>,
                        ...(withPayment ? [<TextCell key="op" strong>{ref}</TextCell>] : []),
                        <TextCell key="t" strong={!withPayment}>
                            {r.type}
                        </TextCell>,
                        <TextCell key="a" strong>
                            {fcfa(r.amount)}
                        </TextCell>,
                        <span key="m">{r.motif}</span>,
                        <TextCell key="b">{r.beneficiary}</TextCell>,
                        <TextCell key="rs">{r.responsibility}</TextCell>,
                        <TextCell key="i">{`−${fcfa(r.impact)} · ${r.rule}`}</TextCell>,
                        <TextCell key="tm">{r.timing}</TextCell>,
                        <TextCell key="j" muted={!r.proof}>
                            {r.proof ?? '—'}
                        </TextCell>,
                        <TextCell key="au">{r.author}</TextCell>,
                        <TextCell key="ap" muted={!r.approver}>
                            {r.approver ?? '—'}
                        </TextCell>,
                        <StatusBadge key="s" label={r.status.label} tone={r.status.tone} />,
                    ],
                    actions: r.pending
                        ? [
                              { icon: 'check', label: 'Approuver', onClick: () => actions.decideRefund(ref, r, true) },
                              { icon: 'close', label: 'Rejeter', onClick: () => actions.decideRefund(ref, r, false) },
                          ]
                        : [],
                };
            })}
        />
    );
}
