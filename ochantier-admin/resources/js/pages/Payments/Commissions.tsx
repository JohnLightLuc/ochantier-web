import { PaymentLayout, queryOf, useApply } from '@/components/payments/blocks';
import { fcfa, usePaymentActions } from '@/components/payments/usePaymentActions';
import { FilterSelect } from '@/components/list/ListToolbar';
import { TextCell } from '@/components/list/Table';
import { AlertList, Bars, DataTable } from '@/components/workers/blocks';
import type { PaymentPageProps, Rule, Tile } from '@/types/payments';
import type { FilterDef } from '@/types/attendances';
import { Head, router } from '@inertiajs/react';

type Aggregate = { n: number; gross: number; calc: number; validated: number; collected: number; refunded: number; reversed: number; net: number };

type Props = PaymentPageProps & {
    tiles: Tile[];
    dimension: string;
    dimensions: Record<string, string>;
    filterDefs: FilterDef[];
    breakdown: { rows: (Aggregate & { label: string })[]; total: Aggregate };
    charts: { title: string; sub: string; accent?: boolean; bars: [string, number][] }[];
    rule: Rule;
};

/** Total des commissions O’CHANTIER : indicateurs, ventilation (le total des lignes égale le total général) et graphiques. */
export default function PaymentsCommissions(props: Props) {
    const { tiles, dimension, dimensions, breakdown, charts, filters, rule, options } = props;
    const actions = usePaymentActions(options);
    const apply = useApply(filters);
    const line = (label: string, m: Aggregate, strong = false) => ({
        key: label,
        cells: [label, String(m.n), fcfa(m.gross), fcfa(m.calc), fcfa(m.validated), fcfa(m.collected), fcfa(m.refunded), fcfa(m.reversed), fcfa(m.net)].map((v, i) => (
            <TextCell key={i} strong={strong || i === 0 || i === 8}>
                {v}
            </TextCell>
        )),
        actions: [],
    });
    const sum = breakdown.rows.reduce((a, r) => a + r.net, 0);

    return (
        <>
            <Head title="Commissions O’CHANTIER" />
            <PaymentLayout
                {...props}
                tiles={tiles}
                toolbar={[
                    { label: 'Commissions par opération', icon: 'list', variant: 'primary', onClick: () => router.visit(`/paiements/commissions/operations?${queryOf(filters)}`) },
                    { label: 'Consulter la règle', icon: 'policy', onClick: () => actions.rule(rule) },
                    { label: 'Exporter', icon: 'download', onClick: () => (window.location.href = `/paiements/export/commissions?${queryOf(filters)}`) },
                ]}
                before={
                    <>
                        <AlertList alerts={[{ tone: 'info', icon: 'function', title: 'Formule du total net', text: 'Somme des commissions encaissées − commissions remboursées − commissions extournées − corrections débitrices. Exclus : estimations, commissions en attente, paiements échoués ou annulés, frais des prestataires, taxes et montants des ouvriers.' }]} />
                        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2)' }}>
                            <FilterSelect label="Par mois" value={dimension === 'mois' ? '' : dimension} options={Object.entries(dimensions).filter(([k]) => k !== 'mois').map(([value, label]) => ({ value, label: `Par ${label.toLowerCase()}` }))} onChange={(v) => apply({ ventilation: v || undefined }, true)} />
                        </div>
                    </>
                }
            >
                <DataTable
                    icon="table_chart"
                    title={`Ventilation par ${dimensions[dimension].toLowerCase()}`}
                    count={`Total identique au total général pour les mêmes filtres : ${fcfa(sum)} = ${fcfa(breakdown.total.net)}`}
                    minWidth={1200}
                    columns={[dimensions[dimension], 'Opérations', 'Volume brut', 'Calculées', 'Validées', 'Encaissées', 'Remboursées', 'Extournées', 'Nettes']}
                    empty={{ icon: 'table_chart', title: 'Aucune opération', text: 'Aucune opération sur la période.' }}
                    rows={[...breakdown.rows.map((r) => line(r.label, r)), ...(breakdown.rows.length ? [line('TOTAL', breakdown.total, true)] : [])]}
                />
                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,420px),1fr))', gap: 'var(--space-4)' }}>
                    {charts.map((c) => (
                        <Bars key={c.title} title={c.title} sub={c.sub} data={c.bars} color={c.accent ? 'var(--c-accent-600)' : undefined} />
                    ))}
                </div>
            </PaymentLayout>
            {actions.dialog}
        </>
    );
}
