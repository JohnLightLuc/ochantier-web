import { PaymentLayout } from '@/components/payments/blocks';
import OperationList from '@/components/payments/OperationList';
import { usePaymentActions } from '@/components/payments/usePaymentActions';
import type { ListProps, PaymentPageProps } from '@/types/payments';
import { Head, router, usePage } from '@inertiajs/react';

type Props = PaymentPageProps & ListProps & { title: string; selectable: boolean; payoutNav: boolean };

const PAYOUT_NAV: [string, string, string][] = [
    ['À reverser', '/paiements/a-reverser', 'savings'],
    ['Historique des reversements', '/paiements/reversements', 'history'],
    ['Lots', '/paiements/lots', 'stacks'],
];

/** Listes d’opérations : toutes les opérations, montants à reverser, historique des reversements. */
export default function PaymentsOperations(props: Props) {
    const { url } = usePage();
    const actions = usePaymentActions(props.options);
    const path = url.split('?')[0];
    const exportView = path.endsWith('a-reverser') ? 'a-reverser' : path.endsWith('reversements') ? 'reversements' : 'operations';

    return (
        <>
            <Head title={props.title} />
            <PaymentLayout {...props}>
                <OperationList
                    {...props}
                    periodLabel={props.period.label}
                    actions={actions}
                    exportView={exportView}
                    selectable={props.selectable}
                    extraTools={props.payoutNav ? PAYOUT_NAV.map(([label, href, icon]) => ({ label, icon, variant: path === href ? ('primary' as const) : ('secondary' as const), onClick: () => router.visit(href) })) : []}
                />
            </PaymentLayout>
            {actions.dialog}
        </>
    );
}
