import { PaymentLayout, queryOf, useApply } from '@/components/payments/blocks';
import { fcfa, usePaymentActions } from '@/components/payments/usePaymentActions';
import Pagination from '@/components/list/Pagination';
import { StackCell, TextCell } from '@/components/list/Table';
import StatusBadge from '@/components/ui/StatusBadge';
import { DataTable } from '@/components/workers/blocks';
import { formatDate } from '@/lib/format';
import type { FilterDef, Paginated } from '@/types/attendances';
import type { PaymentPageProps, PaymentRow, Tile } from '@/types/payments';
import { Head, router } from '@inertiajs/react';

type Row = PaymentRow & { externalReference: string | null; duplicate: boolean; provider: string | null; received: number; gap: number; paidAt: string; canReconcile: boolean };

/** Rapprochements : montants internes face aux confirmations des prestataires ; rapprochement manuel justifié. */
export default function PaymentsReconciliations(props: PaymentPageProps & { tiles: Tile[]; filterDefs: FilterDef[]; payments: Paginated<Row> }) {
    const { payments, filters, options } = props;
    const actions = usePaymentActions(options);
    const apply = useApply(filters);

    return (
        <>
            <Head title="Rapprochements financiers" />
            <PaymentLayout
                {...props}
                tiles={props.tiles}
                toolbar={[
                    { label: 'Anomalies', icon: 'report', onClick: () => router.visit('/paiements/anomalies') },
                    { label: 'Rapprochements', icon: 'rule', variant: 'primary', onClick: () => undefined },
                    { label: 'Exporter', icon: 'download', onClick: () => (window.location.href = `/paiements/export/rapprochements?${queryOf(filters)}`) },
                ]}
            >
                <DataTable
                    icon="rule"
                    title="Rapprochements financiers"
                    count={`${payments.from}–${payments.to} sur ${payments.total}`}
                    minWidth={1600}
                    columns={['Référence interne', 'Référence externe', 'Prestataire', 'Montant attendu', 'Montant reçu', 'Commission attendue', 'Commission comptabilisée', 'Frais externes', 'Date', 'Écart', 'Statut']}
                    empty={{ icon: 'rule', title: 'Aucun encaissement', text: 'Aucun encaissement à rapprocher.' }}
                    rows={payments.data.map((r) => ({
                        key: r.reference,
                        onOpen: () => router.visit(`/paiements/operations/${r.reference}/rapprochement`),
                        cells: [
                            <TextCell key="r" strong>
                                {r.reference}
                            </TextCell>,
                            <StackCell key="e" main={r.externalReference ?? '—'} sub={r.duplicate ? 'Référence dupliquée' : undefined} color={r.duplicate ? 'var(--c-danger-fg)' : undefined} />,
                            <TextCell key="p">{r.provider ?? '—'}</TextCell>,
                            <TextCell key="a">{fcfa(r.promoterTotal)}</TextCell>,
                            <TextCell key="rc">{fcfa(r.received)}</TextCell>,
                            <TextCell key="c">{fcfa(r.com)}</TextCell>,
                            <TextCell key="cc">{fcfa(r.comCollected)}</TextCell>,
                            <TextCell key="f">{fcfa(r.fees)}</TextCell>,
                            <TextCell key="d">{formatDate(r.paidAt)}</TextCell>,
                            <TextCell key="g" strong={!!r.gap}>
                                {r.gap ? fcfa(r.gap) : '0'}
                            </TextCell>,
                            <StatusBadge key="s" label={r.reconciliation.label} tone={r.reconciliation.tone} />,
                        ],
                        actions: r.canReconcile ? [{ icon: 'rule', label: 'Rapprocher manuellement', onClick: () => actions.reconcile(r.reference, `Attendu ${fcfa(r.promoterTotal)} · reçu ${fcfa(r.received)} · écart ${fcfa(r.gap)} · référence externe ${r.externalReference ?? '—'}.`) }] : [],
                    }))}
                />
                {payments.lastPage > 1 && <Pagination page={payments.page} lastPage={payments.lastPage} from={payments.from} to={payments.to} total={payments.total} onPage={(p) => apply({ page: String(p) }, true)} />}
            </PaymentLayout>
            {actions.dialog}
        </>
    );
}
