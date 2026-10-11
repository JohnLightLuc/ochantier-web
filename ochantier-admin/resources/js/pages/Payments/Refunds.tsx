import { PaymentLayout } from '@/components/payments/blocks';
import { RefundTable } from '@/components/payments/RefundTable';
import { usePaymentActions } from '@/components/payments/usePaymentActions';
import type { PaymentPageProps, Refund, Tile } from '@/types/payments';
import { Head } from '@inertiajs/react';

/** Remboursements : demandes à approuver et effectués, avec leur impact sur la commission. */
export default function PaymentsRefunds(props: PaymentPageProps & { tiles: Tile[]; refunds: Refund[] }) {
    const actions = usePaymentActions(props.options);

    return (
        <>
            <Head title="Remboursements" />
            <PaymentLayout {...props} tiles={props.tiles}>
                <RefundTable refunds={props.refunds} actions={actions} withPayment />
            </PaymentLayout>
            {actions.dialog}
        </>
    );
}
