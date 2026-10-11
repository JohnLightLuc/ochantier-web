import { PaymentLayout, queryOf, useApply } from '@/components/payments/blocks';
import { fcfa, usePaymentActions } from '@/components/payments/usePaymentActions';
import { FilterSelect } from '@/components/list/ListToolbar';
import Pagination from '@/components/list/Pagination';
import { TextCell } from '@/components/list/Table';
import StatusBadge from '@/components/ui/StatusBadge';
import { DataTable } from '@/components/workers/blocks';
import { formatDate } from '@/lib/format';
import type { FilterDef, Paginated } from '@/types/attendances';
import type { PaymentPageProps, PaymentRow, Rule } from '@/types/payments';
import { Head, router } from '@inertiajs/react';

type Props = PaymentPageProps & { filterDefs: FilterDef[]; payments: Paginated<PaymentRow & { ruleVersion: string }>; netTotal: number; rule: Rule };

/** Commissions par opération : base, règle, taux, parts promoteur et ouvrier, encaissé, remboursé et net. */
export default function PaymentsCommissionOperations(props: Props) {
    const { payments, netTotal, filters, rule, options } = props;
    const actions = usePaymentActions(options);
    const apply = useApply(filters);

    return (
        <>
            <Head title="Commissions par opération" />
            <PaymentLayout
                {...props}
                toolbar={[
                    { label: 'Exporter', icon: 'download', onClick: () => (window.location.href = `/paiements/export/commissions-operations?${queryOf(filters)}`) },
                    { label: 'Consulter la règle', icon: 'policy', onClick: () => actions.rule(rule) },
                ]}
                before={<FilterSelect label="Trier : date" value={filters.tri === 'commission' ? 'commission' : ''} options={[{ value: 'commission', label: 'Trier : commission' }]} onChange={(v) => apply({ tri: v || undefined }, true)} />}
            >
                <DataTable
                    icon="percent"
                    title="Commissions par opération"
                    count={`${payments.from}–${payments.to} sur ${payments.total} · nettes ${fcfa(netTotal)}`}
                    minWidth={2000}
                    columns={['Référence', 'Date', 'Promoteur', 'Chantier', 'Ouvrier', 'Type', 'Montant brut', 'Base', 'Règle', 'Taux', 'Fixe', 'Commission', 'Part promoteur', 'Part ouvrier', 'Encaissé', 'Remboursé', 'Nette', 'Statut']}
                    empty={{ icon: 'search_off', title: 'Aucune commission', text: 'Aucune opération sur la période.' }}
                    rows={payments.data.map((r) => ({
                        key: r.reference,
                        onOpen: () => router.visit(`/paiements/operations/${r.reference}/commission`),
                        cells: [
                            <TextCell key="r" strong>
                                {r.reference}
                            </TextCell>,
                            <TextCell key="d">{formatDate(r.date)}</TextCell>,
                            <TextCell key="p">{r.promoter}</TextCell>,
                            <TextCell key="s">{r.site}</TextCell>,
                            <TextCell key="w">{r.worker?.name ?? '—'}</TextCell>,
                            <TextCell key="t">{r.type}</TextCell>,
                            <TextCell key="g">{fcfa(r.gross)}</TextCell>,
                            <TextCell key="b">{fcfa(r.gross)}</TextCell>,
                            <TextCell key="v">{r.ruleVersion}</TextCell>,
                            <TextCell key="rate">{`${r.rate} %`}</TextCell>,
                            <TextCell key="f" muted>
                                —
                            </TextCell>,
                            <TextCell key="c" strong>
                                {fcfa(r.com)}
                            </TextCell>,
                            <TextCell key="cp">{fcfa(r.comP)}</TextCell>,
                            <TextCell key="co">{fcfa(r.comO)}</TextCell>,
                            <TextCell key="e">{fcfa(r.comCollected)}</TextCell>,
                            <TextCell key="rf" muted={!r.comRefunded}>
                                {r.comRefunded ? fcfa(r.comRefunded) : '—'}
                            </TextCell>,
                            <TextCell key="n" strong>
                                {fcfa(r.comNet)}
                            </TextCell>,
                            <StatusBadge key="st" label={r.comStatus.label} tone={r.comStatus.tone} />,
                        ],
                        actions: [
                            { icon: 'percent', label: 'Détail', onClick: () => router.visit(`/paiements/operations/${r.reference}/commission`) },
                            { icon: 'receipt_long', label: 'Opération d’origine', onClick: () => router.visit(`/paiements/operations/${r.reference}`) },
                        ],
                    }))}
                />
                {payments.lastPage > 1 && <Pagination page={payments.page} lastPage={payments.lastPage} from={payments.from} to={payments.to} total={payments.total} onPage={(p) => apply({ page: String(p) }, true)} />}
            </PaymentLayout>
            {actions.dialog}
        </>
    );
}
