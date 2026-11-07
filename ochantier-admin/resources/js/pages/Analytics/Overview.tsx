import { LinesBlock } from '@/components/blocks/blocks';
import Button from '@/components/ui/Button';
import StatCard from '@/components/ui/StatCard';
import { fcfa } from '@/lib/format';
import { Head, router } from '@inertiajs/react';

type Month = { month: string; label: string; received: number; paid_out: number; collected: number; revenue: number };

type Props = {
    report: {
        kinds: { kind: string; label: string }[];
        indicators: { sites_active: number; sites_done: number; occupation: number; rating: number | null };
        months: Month[];
    };
};

/** Vue d’ensemble des rapports : activité du moment et évolution financière sur douze mois. */
export default function ReportsOverview({ report: r }: Props) {
    const i = r.indicators;
    const money = (values: number[]) => values.map(fcfa);

    return (
        <>
            <Head title="Rapports" />

            <div style={{ display: 'flex', justifyContent: 'flex-end', flexWrap: 'wrap', gap: 'var(--space-2)', marginTop: -6 }}>
                {r.kinds.map((k) => (
                    <Button key={k.kind} label={k.label} icon="description" variant="secondary" onClick={() => router.visit(`/rapports/${k.kind}`)} />
                ))}
            </div>

            <div data-component="StatGrid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(220px,1fr))', gap: 'var(--space-4)' }}>
                <StatCard label="Chantiers en cours" value={String(i.sites_active)} icon="construction" tone="b" />
                <StatCard label="Chantiers terminés" value={String(i.sites_done)} icon="verified" tone="g" />
                <StatCard label="Taux d’occupation" value={`${i.occupation} %`} icon="pie_chart" tone="a" hint="Ouvriers validés ayant travaillé ces 7 derniers jours" />
                <StatCard label="Note moyenne" value={i.rating !== null ? `${String(i.rating).replace('.', ',')} / 5` : '—'} icon="star" tone="a" hint="Sur les avis publiés" />
            </div>

            {r.months.length > 0 && (
                <LinesBlock
                    chart={{
                        title: 'Évolution financière',
                        sub: 'Reçu et décaissé sur douze mois · FCFA',
                        labels: r.months.map((m) => m.label),
                        series: [
                            { label: 'Reçu', color: 'var(--c-primary-500)', values: r.months.map((m) => m.received), texts: money(r.months.map((m) => m.received)) },
                            { label: 'Décaissé', color: 'var(--c-accent-600)', values: r.months.map((m) => m.paid_out), texts: money(r.months.map((m) => m.paid_out)) },
                        ],
                        extra: [
                            { label: 'Commissions encaissées', color: 'var(--c-info)', texts: money(r.months.map((m) => m.collected)) },
                            { label: 'Revenu', color: 'var(--c-neutral)', texts: money(r.months.map((m) => m.revenue)) },
                        ],
                        note: { label: 'Revenu sur 12 mois', value: fcfa(r.months.reduce((total, m) => total + m.revenue, 0)) },
                    }}
                />
            )}
        </>
    );
}
