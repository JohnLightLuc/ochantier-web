import Avatar from '@/components/ui/Avatar';
import Button from '@/components/ui/Button';
import Icon from '@/components/ui/Icon';
import StateBlock from '@/components/ui/StateBlock';
import StatusBadge from '@/components/ui/StatusBadge';
import type { Option } from '@/components/ui/Field';
import type { CommercialOfTheMonth, RankingRow } from '@/types/sales';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { decimal } from './useSalesActions';

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

const MEDALS = ['var(--c-accent)', 'var(--c-neutral)', 'var(--c-orange)'];

/** Podium des trois premiers commerciaux éligibles (le premier au centre). */
export function Podium({ rows }: { rows: RankingRow[] }) {
    return (
        <div data-component="CommercialRankingPodium" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,240px),1fr))', gap: 'var(--space-4)', alignItems: 'end' }}>
            {rows.slice(0, 3).map((r, i) => (
                <button
                    key={r.slug}
                    type="button"
                    onClick={() => router.visit(`/commerciaux/${r.slug}`)}
                    className="och-hover-lift"
                    style={{ order: [2, 1, 3][i], display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 'var(--space-2)', padding: `${['var(--space-6)', 'var(--space-4)', 'var(--space-3)'][i]} var(--space-4) var(--space-4)`, borderRadius: 'var(--radius-2xl)', border: `1px solid ${i === 0 ? 'var(--c-warning-line)' : 'var(--c-line)'}`, background: i === 0 ? 'var(--c-warning-surface)' : 'var(--c-surface)', cursor: 'pointer', fontFamily: 'var(--font-sans)', textAlign: 'center' }}
                >
                    <Medal name={r.name} rank={r.rank} color={MEDALS[i]} />
                    {i === 0 && <TitleChip icon="emoji_events" />}
                    <b style={{ fontSize: 'var(--fs-lg)', color: 'var(--c-ink)' }}>{r.name}</b>
                    <span style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>{r.zone}</span>
                    <b style={{ fontSize: 'var(--fs-3xl)', color: 'var(--c-ink)' }}>{decimal(r.score)}</b>
                    <span style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-ink-2)' }}>
                        {r.metrics.validated} profils validés · objectif {r.metrics.objective} %
                    </span>
                    <StatusBadge label={r.performance.label} tone={r.performance.tone} />
                </button>
            ))}
        </div>
    );
}

function Medal({ name, rank, color }: { name: string; rank: number; color: string }) {
    return (
        <span style={{ position: 'relative', flex: 'none' }}>
            <Avatar name={name} size="lg" />
            <span style={{ position: 'absolute', right: -6, bottom: -6, width: 30, height: 30, borderRadius: 'var(--radius-round)', display: 'grid', placeItems: 'center', background: color, color: 'var(--c-primary-strong)', fontWeight: 'var(--fw-bold)', fontSize: 'var(--fs-sm)', border: '2px solid var(--c-surface)' }}>{rank}</span>
        </span>
    );
}

function TitleChip({ icon }: { icon: string }) {
    return (
        <span style={{ display: 'inline-flex', alignItems: 'center', gap: 'var(--space-1)', padding: '2px var(--space-2_5)', borderRadius: 'var(--radius-pill)', background: 'var(--c-accent)', color: 'var(--c-primary-strong)', fontSize: 'var(--fs-xs)', fontWeight: 'var(--fw-bold)' }}>
            <Icon name={icon} size="var(--icon-xs)" />
            Commercial du mois
        </span>
    );
}

/** Carte « Commercial du mois » de la liste, avec choix du mois. */
export function CommercialOfTheMonthCard({ cotm, months, currentMonth, onMonth }: { cotm: CommercialOfTheMonth; months: Option[]; currentMonth: string; onMonth: (month: string) => void }) {
    const previousMonth = months[1]?.value;
    const top = cotm.top;
    const ranges: [string, string][] = [[currentMonth, 'Mois en cours'], ...(previousMonth ? [[previousMonth, 'Mois précédent'] as [string, string]] : [])];

    return (
        <section data-component="CommercialOfTheMonthCard" style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-5)', alignItems: 'center', padding: 'var(--space-5)', borderRadius: 'var(--radius-2xl)', background: 'var(--c-warning-surface)', border: '1px solid var(--c-warning-line)' }}>
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 'var(--space-3)', flexWrap: 'wrap', width: '100%' }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2_5)' }}>
                    <Icon name="emoji_events" size="var(--icon-lg)" color="var(--c-accent-600)" />
                    <div>
                        <h2 style={{ margin: 0, fontSize: 'var(--fs-2xl)', fontWeight: 'var(--fw-bold)' }}>Commercial du mois</h2>
                        <div style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>
                            {cotm.label} · {cotm.closed ? 'clôturé' : 'en cours, provisoire'}
                        </div>
                    </div>
                </div>
                <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)', flexWrap: 'wrap' }}>
                    <div role="tablist" style={{ display: 'flex', border: '1px solid var(--c-line-strong)', borderRadius: 'var(--radius-md)', overflow: 'hidden' }}>
                        {ranges.map(([value, label]) => {
                            const on = cotm.month === value;
                            return (
                                <button key={value} type="button" role="tab" aria-selected={on} onClick={() => onMonth(value)} style={{ height: 'var(--control-sm)', padding: '0 var(--space-3)', border: 0, background: on ? 'var(--c-primary)' : 'var(--c-surface)', color: on ? 'var(--c-on-dark)' : 'var(--c-ink-2)', fontWeight: 'var(--fw-semibold)', fontSize: 'var(--fs-sm)', cursor: 'pointer' }}>
                                    {label}
                                </button>
                            );
                        })}
                    </div>
                    <select value={cotm.month} onChange={(e) => onMonth(e.target.value)} aria-label="Choisir un mois" style={{ height: 'var(--control-sm)', padding: '0 var(--space-2)', border: '1px solid var(--c-line-strong)', borderRadius: 'var(--radius-md)', background: 'var(--c-surface)', fontSize: 'var(--fs-sm)', fontFamily: 'var(--font-sans)' }}>
                        {months.map((o) => (
                            <option key={o.value} value={o.value}>
                                {o.label}
                            </option>
                        ))}
                    </select>
                </div>
            </div>

            {!top ? (
                <div style={{ width: '100%' }}>
                    <StateBlock kind="nodata" icon="emoji_events" title="Aucun commercial éligible" text="Aucun commercial actif n’a été classé sur cette période." compact />
                </div>
            ) : (
                <>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-4)', flex: '1 1 320px', minWidth: 0 }}>
                        <Medal name={top.name} rank={1} color="var(--c-accent)" />
                        <div style={{ minWidth: 0 }}>
                            <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)', flexWrap: 'wrap' }}>
                                <b style={{ fontSize: 'var(--fs-2xl)' }}>{top.name}</b>
                                <TitleChip icon="workspace_premium" />
                            </div>
                            <div style={{ fontSize: 'var(--fs-md)', color: 'var(--c-muted)' }}>
                                {top.code} · {top.zone}
                            </div>
                            <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-1)', marginTop: 'var(--space-1)', fontSize: 'var(--fs-sm)', fontWeight: 'var(--fw-bold)', color: (top.evolution ?? 0) >= 0 ? 'var(--c-success-fg)' : 'var(--c-danger-fg)' }}>
                                <Icon name={(top.evolution ?? 0) >= 0 ? 'north_east' : 'south_east'} size="var(--icon-xs)" />
                                {top.evolution == null ? 'Premier classement' : `${top.evolution >= 0 ? '+' : ''}${decimal(top.evolution)} pts vs ${top.previousLabel}`}
                            </div>
                        </div>
                    </div>
                    <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', padding: 'var(--space-3) var(--space-5)', borderRadius: 'var(--radius-xl)', background: 'var(--c-surface)', border: '1px solid var(--c-warning-line)' }}>
                        <span style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>Score global</span>
                        <b style={{ fontSize: 'var(--fs-5xl)', lineHeight: 1, color: 'var(--c-ink)' }}>{decimal(top.score)}</b>
                        <span style={{ fontSize: 'var(--fs-xs)', color: 'var(--c-muted)' }}>sur 100 · rang 1</span>
                    </div>
                    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(130px,1fr))', gap: 'var(--space-2)', flex: '2 1 420px' }}>
                        {top.stats.map(([l, v]) => (
                            <div key={l} style={{ padding: 'var(--space-2_5) var(--space-3)', borderRadius: 'var(--radius-lg)', background: 'var(--c-surface)', border: '1px solid var(--c-line)' }}>
                                <div style={{ fontSize: 'var(--fs-xs)', color: 'var(--c-muted)' }}>{l}</div>
                                <b style={{ fontSize: 'var(--fs-lg)', whiteSpace: 'nowrap' }}>{v}</b>
                            </div>
                        ))}
                    </div>
                    <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-2)' }}>
                        <Button label="Voir le profil" icon="open_in_new" variant="primary" onClick={() => router.visit(`/commerciaux/${top.slug}`)} />
                        <Button label="Voir le classement" icon="leaderboard" variant="secondary" onClick={() => router.visit(`/commerciaux/classement/${cotm.month.replace('-', '/')}`)} />
                    </div>
                </>
            )}
        </section>
    );
}
