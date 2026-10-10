import Skeleton from '@/components/ui/Skeleton';
import StateBlock from '@/components/ui/StateBlock';
import { formatAxisMillions, formatMillions, niceStep } from '@/lib/format';
import type { Finance, FinanceRange } from '@/types/dashboard';
import { useState } from 'react';
import Card, { Dot, FooterLink, Segmented, tooltipStyle } from './Card';

type Props = {
    finance: Finance;
    loading: boolean;
    onRange: (range: FinanceRange) => void;
    onPayments: () => void;
};

const RANGES: [FinanceRange, string][] = [
    ['auj', 'Aujourd’hui'],
    ['7j', '7 jours'],
    ['30j', '30 jours'],
    ['3m', '3 mois'],
];

/** Part des encaissements reversée à O’CHANTIER. */
const COMMISSION_RATE = 0.08;

const BARS = [
    { key: 'collected', label: 'Encaissements promoteurs', short: 'Encaissements', color: 'var(--c-primary-600)' },
    { key: 'paidOut', label: 'Reversements aux ouvriers', short: 'Reversements', color: 'var(--c-accent-600)' },
    { key: 'commission', label: 'Commissions O’CHANTIER', short: 'Commissions', color: 'var(--c-info)' },
] as const;

const sum = (values: number[]) => values.reduce((a, b) => a + b, 0);

/** Histogramme des encaissements, reversements et commissions. */
export default function FinanceChart({ finance, loading, onRange, onPayments }: Props) {
    const [hover, setHover] = useState<number | null>(null);
    const series = { collected: finance.collected, paidOut: finance.paidOut, commission: finance.collected.map((v) => v * COMMISSION_RATE) };
    const empty = finance.labels.length === 0;
    const step = empty ? 1 : niceStep((Math.max(...finance.collected) * 1.1) / 4);
    const yMax = step * 4;
    const last = finance.labels.length - 1;

    const periodLabel = (label: string) => ({ auj: `Aujourd’hui · ${label}`, '7j': `${label} · semaine en cours`, '30j': `Semaine du ${label}`, '3m': `${label} 2026` })[finance.range];

    const totals = [
        { label: 'Total encaissé', value: sum(series.collected), color: 'var(--c-primary-600)' },
        { label: 'Total reversé', value: sum(series.paidOut), color: 'var(--c-accent-600)' },
        { label: 'Total des commissions', value: sum(series.commission), color: 'var(--c-info)' },
        { label: 'Montant en attente', value: finance.pending, color: 'var(--c-orange)' },
    ];

    return (
        <Card component="FinanceChart" span={7} icon="account_balance" title="Flux financier" aside={<Segmented label="Période" options={RANGES} value={finance.range} onChange={onRange} />}>
            {loading ? (
                <Skeleton height={300} radius="var(--radius-lg)" />
            ) : empty ? (
                <StateBlock kind="nodata" icon="payments" title="Aucun mouvement financier" text="Aucune transaction n’a été enregistrée sur cette période." minHeight="260px" />
            ) : (
                <>
                    <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2) var(--space-5)', marginBottom: 'var(--space-3)' }}>
                        {BARS.map((b) => (
                            <span key={b.key} style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)', fontSize: 'var(--fs-sm)', color: 'var(--c-ink-2)' }}>
                                <Dot color={b.color} />
                                {b.label}
                            </span>
                        ))}
                    </div>
                    <div style={{ display: 'flex', gap: 'var(--space-2_5)' }}>
                        <div style={{ display: 'flex', flexDirection: 'column', justifyContent: 'space-between', height: 170, margin: '-7px 0', fontSize: 'var(--fs-xs)', color: 'var(--c-muted)', textAlign: 'right', width: 40, flex: 'none' }}>
                            {[4, 3, 2, 1, 0].map((k) => (
                                <span key={k}>{formatAxisMillions(step * k)}</span>
                            ))}
                        </div>
                        <div style={{ flex: 1, minWidth: 0 }}>
                            <div style={{ height: 170, display: 'flex', alignItems: 'flex-end', justifyContent: 'space-around', gap: 'var(--space-2)', backgroundImage: 'linear-gradient(var(--c-line-soft) 1px,transparent 1px)', backgroundSize: '100% 25%', borderBottom: '1px solid var(--c-line-strong)' }}>
                                {finance.labels.map((label, i) => {
                                    const on = hover === i;
                                    const values = BARS.map((b) => series[b.key][i]);
                                    return (
                                        <div
                                            key={label}
                                            tabIndex={0}
                                            onMouseEnter={() => setHover(i)}
                                            onMouseLeave={() => setHover(null)}
                                            onFocus={() => setHover(i)}
                                            onBlur={() => setHover(null)}
                                            aria-label={`${label} : encaissements ${formatMillions(values[0])} FCFA, reversements ${formatMillions(values[1])} FCFA, commissions ${formatMillions(values[2])} FCFA`}
                                            style={{ position: 'relative', flex: 1, maxWidth: 56, display: 'flex', alignItems: 'flex-end', justifyContent: 'center', gap: 3, height: '100%', cursor: 'pointer', borderRadius: 'var(--radius-sm)', background: on ? 'var(--c-surface-3)' : 'transparent', outline: 0 }}
                                        >
                                            {on && (
                                                <div role="tooltip" style={{ ...tooltipStyle, position: 'absolute', left: '50%', bottom: `calc(${(values[0] / yMax) * 100}% + var(--space-2))`, transform: `translateX(${i === 0 ? '-20%' : i === last ? '-80%' : '-50%'})`, display: 'flex', flexDirection: 'column', gap: 'var(--space-0_5)' }}>
                                                    <div style={{ fontSize: 'var(--fs-xs)', color: 'var(--c-on-dark-muted)' }}>{periodLabel(label)}</div>
                                                    {BARS.map((b, j) => (
                                                        <div key={b.key} style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)', fontSize: 'var(--fs-sm)' }}>
                                                            <Dot color={b.color} size={8} />
                                                            <span style={{ flex: 1 }}>{b.short}</span>
                                                            <b style={{ fontVariantNumeric: 'tabular-nums', marginLeft: 'var(--space-3)' }}>{formatMillions(values[j])} FCFA</b>
                                                        </div>
                                                    ))}
                                                </div>
                                            )}
                                            {BARS.map((b, j) => (
                                                <div key={b.key} style={{ flex: 1, maxWidth: 14, borderRadius: 'var(--radius-xs) var(--radius-xs) 0 0', background: b.color, height: `${(values[j] / yMax) * 100}%` }} />
                                            ))}
                                        </div>
                                    );
                                })}
                            </div>
                            <div style={{ display: 'flex', justifyContent: 'space-around', gap: 'var(--space-2)', marginTop: 'var(--space-2)', fontSize: 'var(--fs-xs)', color: 'var(--c-muted)' }}>
                                {finance.labels.map((label) => (
                                    <span key={label} style={{ flex: 1, maxWidth: 56, textAlign: 'center', whiteSpace: 'nowrap' }}>
                                        {label}
                                    </span>
                                ))}
                            </div>
                        </div>
                    </div>
                    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(2,minmax(0,1fr))', gap: 'var(--space-3)', marginTop: 'var(--space-4)' }}>
                        {totals.map((t) => (
                            <div key={t.label} style={{ padding: 'var(--space-3)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-lg)', minWidth: 0 }}>
                                <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-1_5)', fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>
                                    <Dot color={t.color} size={8} />
                                    {t.label}
                                </div>
                                <div style={{ marginTop: 'var(--space-1)', fontSize: 'var(--fs-xl)', fontWeight: 'var(--fw-bold)', fontVariantNumeric: 'tabular-nums', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>
                                    {formatMillions(t.value)} <span style={{ fontSize: 'var(--fs-sm)' }}>FCFA</span>
                                </div>
                            </div>
                        ))}
                    </div>
                </>
            )}
            <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: 'auto', paddingTop: 'var(--space-4)' }}>
                <FooterLink label="Voir les paiements" onClick={onPayments} />
            </div>
        </Card>
    );
}
