import Button from '@/components/ui/Button';
import Skeleton from '@/components/ui/Skeleton';
import StateBlock from '@/components/ui/StateBlock';
import { smoothPath } from '@/lib/format';
import type { Activity, ActivityRange } from '@/types/dashboard';
import { useState } from 'react';
import Card, { Dot, FooterLink, Segmented, tooltipStyle } from './Card';

export type SeriesKey = 'active' | 'created';

type Props = {
    activity: Activity;
    loading: boolean;
    onRange: (range: ActivityRange, from?: string, to?: string) => void;
    onPoint: (series: SeriesKey, index: number) => void;
    onReport: () => void;
};

export const SERIES: Record<SeriesKey, { name: string; color: string; gradient: string }> = {
    active: { name: 'Chantiers actifs', color: 'var(--c-primary-500)', gradient: 'dbA' },
    created: { name: 'Nouveaux chantiers', color: 'var(--c-accent-600)', gradient: 'dbB' },
};

const RANGES: [ActivityRange, string][] = [
    ['7j', '7 jours'],
    ['30j', '30 jours'],
    ['3m', '3 mois'],
    ['custom', 'Personnalisée'],
];

/** Courbes des chantiers actifs et des nouveaux chantiers. */
export default function ActivityChart({ activity, loading, onRange, onPoint, onReport }: Props) {
    const [hover, setHover] = useState<{ series: SeriesKey; index: number } | null>(null);
    const [pickerOpen, setPickerOpen] = useState(false);

    const n = activity.active.length;
    const max = n ? Math.max(...activity.active, ...activity.created) : 10;
    const yMax = Math.max(5, Math.ceil((max * 1.2) / 5) * 5);
    const x = (i: number) => (n > 1 ? (i / (n - 1)) * 100 : 50);
    const y = (v: number) => 100 - (v / yMax) * 100;
    const path = (values: number[]) => (n > 1 ? smoothPath(values.map((v, i) => [x(i), y(v)])) : '');

    const tip = hover && activity[hover.series][hover.index] !== undefined ? { ...hover, value: activity[hover.series][hover.index], left: x(hover.index) } : null;

    return (
        <Card
            component="ChartCard"
            span={8}
            icon="show_chart"
            title="Activité des chantiers"
            headerGap="0"
            aside={
                <div style={{ position: 'relative' }}>
                    <Segmented
                        label="Période"
                        options={RANGES}
                        value={activity.range}
                        trailingIcon={{ custom: 'calendar_month' }}
                        onChange={(key) => {
                            if (key === 'custom') setPickerOpen(!pickerOpen);
                            else {
                                setPickerOpen(false);
                                onRange(key);
                            }
                        }}
                    />
                    {pickerOpen && (
                        <CustomRangePicker
                            initialFrom={activity.from || '2026-09-01'}
                            initialTo={activity.to || '2026-09-21'}
                            onCancel={() => setPickerOpen(false)}
                            onApply={(from, to) => {
                                setPickerOpen(false);
                                onRange('custom', from, to);
                            }}
                        />
                    )}
                </div>
            }
        >
            <div style={{ margin: 'var(--space-1) 0 var(--space-4)', fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>{activity.caption}</div>

            {loading ? (
                <Skeleton height={230} radius="var(--radius-lg)" />
            ) : n === 0 ? (
                <StateBlock kind="nodata" icon="insights" title="Aucune donnée sur cette période" text="Choisissez une autre période ou revenez plus tard." minHeight="230px" />
            ) : (
                <div style={{ display: 'flex', gap: 'var(--space-2_5)' }}>
                    <div style={{ display: 'flex', flexDirection: 'column', justifyContent: 'space-between', height: 220, margin: '-7px 0', fontSize: 'var(--fs-xs)', color: 'var(--c-muted)', textAlign: 'right', width: 24, flex: 'none' }}>
                        {[1, 0.8, 0.6, 0.4, 0.2, 0].map((k) => (
                            <span key={k}>{Math.round(yMax * k)}</span>
                        ))}
                    </div>
                    <div style={{ flex: 1, minWidth: 0 }}>
                        <div onMouseLeave={() => setHover(null)} style={{ position: 'relative', height: 220, backgroundImage: 'linear-gradient(var(--c-line-soft) 1px,transparent 1px)', backgroundSize: '100% 20%', borderBottom: '1px solid var(--c-line-strong)' }}>
                            <svg viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true" style={{ position: 'absolute', inset: 0, width: '100%', height: '100%', overflow: 'visible' }}>
                                <defs>
                                    {Object.values(SERIES).map((s) => (
                                        <linearGradient key={s.gradient} id={s.gradient} x1="0" y1="0" x2="0" y2="1">
                                            <stop offset="0" style={{ stopColor: s.color, stopOpacity: 0.22 }} />
                                            <stop offset="1" style={{ stopColor: s.color, stopOpacity: 0 }} />
                                        </linearGradient>
                                    ))}
                                </defs>
                                {(Object.keys(SERIES) as SeriesKey[]).map((key) => {
                                    const line = path(activity[key]);
                                    return line ? <path key={`${key}-area`} d={`${line} L100,100 L0,100 Z`} fill={`url(#${SERIES[key].gradient})`} /> : null;
                                })}
                                {(Object.keys(SERIES) as SeriesKey[]).map((key) => (
                                    <path key={`${key}-line`} d={path(activity[key])} fill="none" style={{ stroke: SERIES[key].color }} strokeWidth={2.5} vectorEffect="non-scaling-stroke" />
                                ))}
                            </svg>

                            {(Object.keys(SERIES) as SeriesKey[]).flatMap((key) =>
                                activity[key].map((v, i) => {
                                    const on = hover?.series === key && hover.index === i;
                                    return (
                                        <button
                                            key={`${key}-${i}`}
                                            type="button"
                                            onMouseEnter={() => setHover({ series: key, index: i })}
                                            onFocus={() => setHover({ series: key, index: i })}
                                            onBlur={() => setHover(null)}
                                            onClick={() => onPoint(key, i)}
                                            aria-label={`${activity.dates[i]} · ${SERIES[key].name} : ${v}`}
                                            style={{ position: 'absolute', left: `${x(i)}%`, top: `${y(v)}%`, zIndex: 2, width: 14, height: 14, margin: '-7px 0 0 -7px', padding: 0, borderRadius: 'var(--radius-round)', background: SERIES[key].color, border: '2px solid var(--c-surface)', boxShadow: `0 0 0 1px ${SERIES[key].color}`, cursor: 'pointer', transform: `scale(${on ? 1.35 : 1})`, transition: 'transform var(--dur-fast) var(--ease)' }}
                                        />
                                    );
                                }),
                            )}

                            {tip && (
                                <div
                                    role="tooltip"
                                    style={{ ...tooltipStyle, position: 'absolute', left: `${tip.left}%`, top: `${y(tip.value)}%`, transform: `translate(${tip.left < 12 ? '-12px' : tip.left > 88 ? 'calc(-100% + 12px)' : '-50%'},calc(-100% - 14px))` }}
                                >
                                    <div style={{ fontSize: 'var(--fs-xs)', color: 'var(--c-on-dark-muted)' }}>{activity.dates[tip.index]}</div>
                                    <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-1_5)', fontSize: 'var(--fs-md)', fontWeight: 'var(--fw-bold)' }}>
                                        <Dot color={SERIES[tip.series].color} size={8} />
                                        {SERIES[tip.series].name} : {tip.value}
                                    </div>
                                    <div style={{ fontSize: 'var(--fs-xs)', color: 'var(--c-on-dark-muted)' }}>Cliquez pour voir les chantiers</div>
                                </div>
                            )}
                        </div>
                        <div style={{ position: 'relative', height: 18, marginTop: 'var(--space-2)', fontSize: 'var(--fs-xs)', color: 'var(--c-muted)' }}>
                            {activity.labels.map((label, i) => (
                                <span key={i} style={{ position: 'absolute', left: `${x(i)}%`, transform: `translateX(${i === 0 ? '0' : i === n - 1 ? '-100%' : '-50%'})`, whiteSpace: 'nowrap' }}>
                                    {label}
                                </span>
                            ))}
                        </div>
                    </div>
                </div>
            )}

            <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-3) var(--space-5)', flexWrap: 'wrap', marginTop: 'auto', paddingTop: 'var(--space-4)' }}>
                {Object.values(SERIES).map((s) => (
                    <span key={s.name} style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)', fontSize: 'var(--fs-sm)', color: 'var(--c-ink-2)' }}>
                        <Dot color={s.color} />
                        {s.name}
                    </span>
                ))}
                <span style={{ flex: 1 }} />
                <FooterLink label="Voir le rapport complet" onClick={onReport} />
            </div>
        </Card>
    );
}

type PickerProps = {
    initialFrom: string;
    initialTo: string;
    onCancel: () => void;
    onApply: (from: string, to: string) => void;
};

function CustomRangePicker({ initialFrom, initialTo, onCancel, onApply }: PickerProps) {
    const [from, setFrom] = useState(initialFrom);
    const [to, setTo] = useState(initialTo);
    const [error, setError] = useState('');

    const apply = () => {
        if (!from || !to) return setError('Renseignez les deux dates.');
        if (from > to) return setError('La date de début doit précéder la date de fin.');
        onApply(from, to);
    };

    const input = (label: string, value: string, onChange: (v: string) => void) => (
        <label style={{ display: 'block' }}>
            <span style={{ display: 'block', fontSize: 'var(--fs-sm)', fontWeight: 'var(--fw-semibold)', marginBottom: 'var(--space-1)' }}>{label}</span>
            <input
                type="date"
                value={value}
                onChange={(e) => {
                    onChange(e.target.value);
                    setError('');
                }}
                style={{ width: '100%', height: 'var(--control-sm)', padding: '0 var(--space-2)', border: '1.5px solid var(--c-line-strong)', borderRadius: 'var(--radius-md)', fontSize: 'var(--fs-sm)' }}
            />
        </label>
    );

    return (
        <>
            <div onClick={onCancel} style={{ position: 'fixed', inset: 0, zIndex: 15 }} />
            <div role="dialog" aria-label="Période personnalisée" style={{ position: 'absolute', right: 0, top: 'calc(100% + var(--space-2))', zIndex: 20, width: 300, background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-xl)', boxShadow: 'var(--shadow-popover)', padding: 'var(--space-4)', display: 'flex', flexDirection: 'column', gap: 'var(--space-3)' }}>
                <b style={{ fontSize: 'var(--fs-md)' }}>Période personnalisée</b>
                <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 'var(--space-2)' }}>
                    {input('Du', from, setFrom)}
                    {input('Au', to, setTo)}
                </div>
                {error && (
                    <span role="alert" style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-danger-fg)' }}>
                        {error}
                    </span>
                )}
                <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 'var(--space-2)' }}>
                    <Button label="Annuler" size="sm" variant="secondary" onClick={onCancel} />
                    <Button label="Appliquer" size="sm" variant="primary" onClick={apply} />
                </div>
            </div>
        </>
    );
}
