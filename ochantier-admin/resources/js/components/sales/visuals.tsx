import { useState } from 'react';


const decimal = (n: number) => String(n).replace('.', ',');

export type Bar = { label: string; value: number; tip?: string };

/** Histogramme simple avec info-bulle au survol ou au focus. */
export function BarChart({ title, sub, bars, color = 'var(--c-primary-500)' }: { title: string; sub: string; bars: Bar[]; color?: string }) {
    const [hover, setHover] = useState<number | null>(null);
    const max = Math.max(1, ...bars.map((b) => b.value));

    return (
        <div style={{ border: '1px solid var(--c-line)', borderRadius: 'var(--radius-xl)', padding: 'var(--space-4)', minWidth: 0 }}>
            <div style={{ fontSize: 'var(--fs-lg)', fontWeight: 'var(--fw-bold)' }}>{title}</div>
            <div style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)', marginBottom: 'var(--space-3)' }}>{sub}</div>
            <div style={{ height: 150, display: 'flex', alignItems: 'flex-end', gap: 'var(--space-2)', borderBottom: '1px solid var(--c-line-strong)' }}>
                {bars.map((b, i) => {
                    const h = Math.max(4, (b.value / max) * 110);
                    const on = hover === i;
                    const tip = b.tip ?? `${b.label} : ${b.value}`;
                    const sep = tip.indexOf(' : ');
                    return (
                        <div
                            key={b.label}
                            tabIndex={0}
                            aria-label={tip}
                            onMouseEnter={() => setHover(i)}
                            onMouseLeave={() => setHover(null)}
                            onFocus={() => setHover(i)}
                            onBlur={() => setHover(null)}
                            style={{ position: 'relative', flex: 1, minWidth: 0, display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'flex-end', gap: 'var(--space-1)', height: '100%', borderRadius: 'var(--radius-sm)', background: on ? 'var(--c-surface-3)' : 'transparent', outline: 0, cursor: 'pointer' }}
                        >
                            {on && (
                                <div role="tooltip" style={{ position: 'absolute', left: '50%', bottom: h + 28, transform: `translateX(${i === 0 ? '-20%' : i === bars.length - 1 ? '-80%' : '-50%'})`, zIndex: 3, pointerEvents: 'none', padding: 'var(--space-2) var(--space-3)', borderRadius: 'var(--radius-md)', background: 'var(--c-primary-strong)', color: 'var(--c-on-dark)', boxShadow: 'var(--shadow-popover)', whiteSpace: 'nowrap' }}>
                                    <div style={{ fontSize: 'var(--fs-xs)', color: 'var(--c-on-dark-muted)' }}>{sep > 0 ? tip.slice(0, sep) : b.label}</div>
                                    <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-1_5)', fontSize: 'var(--fs-md)', fontWeight: 'var(--fw-bold)' }}>
                                        <span style={{ width: 8, height: 8, borderRadius: 'var(--radius-round)', background: color }} />
                                        {sep > 0 ? tip.slice(sep + 3) : b.value}
                                    </div>
                                </div>
                            )}
                            <span style={{ fontSize: 'var(--fs-xs)', color: 'var(--c-ink-2)', fontWeight: 'var(--fw-semibold)' }}>{decimal(b.value)}</span>
                            <span style={{ width: '100%', maxWidth: 34, borderRadius: 'var(--radius-xs) var(--radius-xs) 0 0', background: color, height: h }} />
                        </div>
                    );
                })}
            </div>
            <div style={{ display: 'flex', gap: 'var(--space-2)', marginTop: 'var(--space-1_5)' }}>
                {bars.map((b) => (
                    <span key={b.label} style={{ flex: 1, minWidth: 0, textAlign: 'center', fontSize: 'var(--fs-2xs)', color: 'var(--c-muted)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                        {b.label}
                    </span>
                ))}
            </div>
        </div>
    );
}

export function ChartGrid({ children }: { children: React.ReactNode }) {
    return <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,320px),1fr))', gap: 'var(--space-4)' }}>{children}</div>;
}
