import { PaymentLayout } from '@/components/payments/blocks';
import { paymentMenu } from '@/components/payments/blocks';
import { usePaymentActions } from '@/components/payments/usePaymentActions';
import { TextCell } from '@/components/list/Table';
import StatusBadge from '@/components/ui/StatusBadge';
import { DataTable } from '@/components/workers/blocks';
import type { PaymentAnomaly, PaymentPageProps, PaymentRow, Tile } from '@/types/payments';
import type { FilterDef } from '@/types/attendances';
import type { AnyTone } from '@/types';
import { Head, router } from '@inertiajs/react';

const LEVEL_TONES: Record<string, AnyTone> = { Critique: 'r', Élevée: 'o', Modérée: 'a', Faible: 'n', Information: 'b' };

/** Anomalies financières détectées (doublons, écarts, échecs répétés…) et signalées. */
export default function PaymentsAnomalies(props: PaymentPageProps & { tiles: Tile[]; filterDefs: FilterDef[]; anomalies: (PaymentAnomaly & { payment: PaymentRow })[] }) {
    const actions = usePaymentActions(props.options);

    return (
        <>
            <Head title="Anomalies financières" />
            <PaymentLayout
                {...props}
                tiles={props.tiles}
                toolbar={[
                    { label: 'Anomalies', icon: 'report', variant: 'primary', onClick: () => undefined },
                    { label: 'Rapprochements', icon: 'rule', onClick: () => router.visit('/paiements/rapprochements') },
                ]}
            >
                <DataTable
                    icon="report"
                    title="Anomalies financières"
                    count={`${props.anomalies.length} anomalie(s) détectée(s)`}
                    minWidth={1200}
                    columns={['Opération', 'Promoteur', 'Ouvrier', 'Type', 'Niveau', 'Description', 'Paiement']}
                    empty={{ icon: 'verified', title: 'Aucune anomalie', text: 'Aucune anomalie sur la période.' }}
                    rows={props.anomalies.map((a, i) => ({
                        key: `${a.payment.reference}-${i}`,
                        onOpen: () => router.visit(`/paiements/operations/${a.payment.reference}`),
                        cells: [
                            <TextCell key="r" strong>
                                {a.payment.reference}
                            </TextCell>,
                            <TextCell key="p">{a.payment.promoter}</TextCell>,
                            <TextCell key="w">{a.payment.worker?.name ?? '—'}</TextCell>,
                            <TextCell key="t" strong>
                                {a.type}
                            </TextCell>,
                            <StatusBadge key="l" label={a.level} tone={LEVEL_TONES[a.level] ?? 'n'} />,
                            <span key="d" style={{ fontSize: 'var(--fs-sm)' }}>
                                {a.detail}
                            </span>,
                            <StatusBadge key="s" label={a.payment.payStatus.label} tone={a.payment.payStatus.tone} />,
                        ],
                        actions: [{ icon: 'visibility', label: 'Consulter', onClick: () => router.visit(`/paiements/operations/${a.payment.reference}`) }],
                        menu: paymentMenu(a.payment, actions),
                    }))}
                />
            </PaymentLayout>
            {actions.dialog}
        </>
    );
}
