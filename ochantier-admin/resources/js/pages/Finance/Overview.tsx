import PeriodFilter from '@/components/finance/PeriodFilter';
import Button from '@/components/ui/Button';
import StatCard from '@/components/ui/StatCard';
import { Block, KeyValues } from '@/components/workers/blocks';
import { fcfa } from '@/lib/format';
import { Head, router } from '@inertiajs/react';

type Report = {
    inflows: { missions: number; subscriptions: number; total: number; operator_fees: number };
    outflows: { workers: number; commercials: number; refunds: number; total: number };
    net_cash: number;
    commissions: { promoter_earned: number; worker_earned: number; earned: number; collected: number; remaining: number };
    revenue: number;
    receivables: { late: number; open: number };
};

type Props = { report: Report; filters: { from: string; to: string }; can: { export: boolean } };

/** Vue financière d’une période, calculée sur les opérations réussies et les journées validées. */
export default function FinanceOverview({ report: r, filters }: Props) {
    const c = r.commissions;

    return (
        <>
            <Head title="Finance" />

            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 'var(--space-2)', marginTop: -6 }}>
                <PeriodFilter from={filters.from} to={filters.to} onChange={(period) => router.get('/finance-reporting', period, { preserveState: true, preserveScroll: true, replace: true })} />
                <Button label="Journal des commissions" icon="receipt_long" variant="secondary" onClick={() => router.get('/finance-reporting/commissions', { du: filters.from, au: filters.to })} />
            </div>

            <div data-component="StatGrid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(220px,1fr))', gap: 'var(--space-4)' }}>
                <StatCard label="Revenu O’Chantier" value={fcfa(r.revenue)} icon="trending_up" tone="g" hint="Commissions encaissées et abonnements" />
                <StatCard label="Commissions acquises" value={fcfa(c.earned)} icon="workspace_premium" tone="b" hint="Sur les journées validées de la période" />
                <StatCard label="Commissions encaissées" value={fcfa(c.collected)} icon="payments" tone="g" hint="Part de commission de l’argent reçu" />
                <StatCard label="Solde de trésorerie" value={fcfa(r.net_cash)} icon="account_balance" tone={r.net_cash < 0 ? 'r' : 'b'} hint="Reçu moins décaissé sur la période" />
                <StatCard label="Créances en retard" value={fcfa(r.receivables.late)} icon="error" tone="r" highlight={r.receivables.late > 0} hint="À ce jour" />
            </div>

            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,340px),1fr))', gap: 'var(--space-4)' }}>
                <Block icon="south_west" title="Reçu" count={fcfa(r.inflows.total)}>
                    <KeyValues
                        items={[
                            ['Paiements des missions', fcfa(r.inflows.missions)],
                            ['Abonnements', fcfa(r.inflows.subscriptions)],
                            ['Frais d’opérateur payés par les promoteurs', fcfa(r.inflows.operator_fees)],
                        ]}
                    />
                </Block>
                <Block icon="north_east" title="Décaissé" count={fcfa(r.outflows.total)}>
                    <KeyValues
                        items={[
                            ['Reversements aux ouvriers', fcfa(r.outflows.workers)],
                            ['Versements aux commerciaux', fcfa(r.outflows.commercials)],
                            ['Remboursements', fcfa(r.outflows.refunds)],
                        ]}
                    />
                </Block>
                <Block icon="workspace_premium" title="Commissions" count={fcfa(c.earned)}>
                    <KeyValues
                        items={[
                            ['Commission promoteur (Y)', fcfa(c.promoter_earned)],
                            ['Commission ouvrier (Z)', fcfa(c.worker_earned)],
                            ['Reste à recevoir sur ces missions', fcfa(c.remaining)],
                        ]}
                    />
                </Block>
                <Block icon="event_note" title="Créances" count={fcfa(r.receivables.open)}>
                    <KeyValues
                        items={[
                            ['Échéances dues à ce jour', fcfa(r.receivables.open)],
                            ['Dont en retard', fcfa(r.receivables.late)],
                        ]}
                    />
                </Block>
            </div>
        </>
    );
}
