import { PaymentLayout, queryOf } from '@/components/payments/blocks';
import { fcfa } from '@/components/payments/usePaymentActions';
import { TextCell } from '@/components/list/Table';
import StatusBadge from '@/components/ui/StatusBadge';
import { Block, DataTable } from '@/components/workers/blocks';
import { HistoryList } from '@/components/workers/tabs';
import { formatDate } from '@/lib/format';
import type { FilterDef } from '@/types/attendances';
import type { PaymentActivity, PaymentPageProps, Rule, Tile } from '@/types/payments';
import { Head } from '@inertiajs/react';

type Props = PaymentPageProps & {
    filterDefs: FilterDef[];
    tiles: Tile[];
    rule: Rule;
    notifications: { id: number; type: string; recipient: string; channel: string; sentAt: string; content: string; status: string }[];
    activities: PaymentActivity[];
};

/** Rapports financiers : indicateurs de commission, exports (PDF imprimable, Excel, CSV), règles, notifications et journal. */
export default function PaymentsReports(props: Props) {
    const { rule, notifications, activities, filters } = props;
    const q = queryOf(filters);
    const download = (url: string) => () => (window.location.href = url);

    return (
        <>
            <Head title="Rapports financiers" />
            <PaymentLayout
                {...props}
                tiles={props.tiles}
                toolbar={[
                    { label: 'Rapport de commissions (PDF)', icon: 'picture_as_pdf', variant: 'primary', onClick: download(`/paiements/rapports/commissions/pdf?${q}`) },
                    { label: 'Rapport de commissions (Excel)', icon: 'table_view', onClick: download(`/paiements/rapports/commissions/excel?${q}`) },
                    { label: 'Détail des opérations (CSV)', icon: 'download', onClick: download(`/paiements/export/rapport-operations?${q}`) },
                ]}
            >
                <DataTable
                    icon="policy"
                    title="Règles de commission utilisées"
                    count="Définies dans Paramètres"
                    minWidth={1100}
                    columns={['Nom', 'Version', 'Type', 'Base', 'Taux promoteur (Y)', 'Taux ouvrier (Z)', 'Minimum', 'Maximum', 'Effet', 'Fin', 'Statut']}
                    empty={{ icon: 'policy', title: 'Aucune règle', text: '' }}
                    rows={[
                        { key: rule.version, cells: [rule.name, rule.version, rule.type, rule.base, `${rule.y} %`, `${rule.z} %`, rule.min ? fcfa(rule.min) : 'Aucun', rule.max ? fcfa(rule.max) : 'Aucun', formatDate(rule.from), rule.to ? formatDate(rule.to) : '—'].map((v, i) => <TextCell key={i} strong={i === 0}>{v}</TextCell>).concat([<StatusBadge key="s" label={rule.status} tone="g" />]), actions: [] },
                        ...rule.previous.map((r) => ({ key: r.version, cells: [r.name, r.version, r.type, '—', '—', '—', '—', '—', formatDate(r.from), formatDate(r.to)].map((v, i) => <TextCell key={i}>{v}</TextCell>).concat([<StatusBadge key="s" label={r.status} tone="n" />]), actions: [] })),
                    ]}
                />
                <DataTable
                    icon="notifications"
                    title="Notifications financières"
                    count={`${notifications.length} notification(s)`}
                    minWidth={1000}
                    columns={['Type', 'Destinataire', 'Canal', 'Date', 'Contenu', 'Statut']}
                    empty={{ icon: 'notifications_off', title: 'Aucune notification', text: '' }}
                    rows={notifications.map((n) => ({ key: String(n.id), cells: [<TextCell key="t" strong>{n.type}</TextCell>, <TextCell key="r">{n.recipient}</TextCell>, <TextCell key="c">{n.channel}</TextCell>, <TextCell key="d">{`${formatDate(n.sentAt)} ${n.sentAt.slice(11)}`}</TextCell>, <span key="ct">{n.content}</span>, <StatusBadge key="s" label={n.status} tone="g" />], actions: [] }))}
                />
                <Block icon="history" title="Journal financier" count="Actions des 40 derniers événements">
                    <HistoryList activities={activities} />
                </Block>
            </PaymentLayout>
        </>
    );
}
