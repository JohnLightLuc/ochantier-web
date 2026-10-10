import Icon from '@/components/ui/Icon';
import StateBlock from '@/components/ui/StateBlock';
import StatusBadge from '@/components/ui/StatusBadge';
import type { PromoterActivity } from '@/types/promoters';
import { Link } from '@inertiajs/react';
import { useState } from 'react';

const FILTERS: [string, PromoterActivity['kind']['value'] | null][] = [
    ['Tous', null],
    ['Administrateur', 'admin'],
    ['Promoteur', 'promoter'],
    ['Système', 'system'],
];

/** Journal d’activité groupé par jour, filtrable par auteur. */
export default function ActivityTab({ activities }: { activities: PromoterActivity[] }) {
    const [filter, setFilter] = useState<PromoterActivity['kind']['value'] | null>(null);
    const list = activities.filter((e) => !filter || e.kind.value === filter);
    const groups = list.reduce<{ day: string; items: PromoterActivity[] }[]>((acc, e) => {
        const last = acc[acc.length - 1];
        if (last && last.day === e.day) last.items.push(e);
        else acc.push({ day: e.day, items: [e] });
        return acc;
    }, []);

    return (
        <>
            <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2)', marginBottom: 'var(--space-4)' }}>
                {FILTERS.map(([label, value]) => {
                    const on = value === filter;
                    return (
                        <button
                            key={label}
                            type="button"
                            aria-pressed={on}
                            onClick={() => setFilter(value)}
                            style={{ height: 'var(--control-xs)', padding: '0 var(--space-3)', borderRadius: 'var(--radius-pill)', border: `1px solid ${on ? 'var(--c-primary)' : 'var(--c-line-strong)'}`, background: on ? 'var(--c-primary)' : 'var(--c-surface)', color: on ? 'var(--c-on-dark)' : 'var(--c-ink-2)', fontWeight: 'var(--fw-semibold)', fontSize: 'var(--fs-sm)', cursor: 'pointer' }}
                        >
                            {label}
                        </button>
                    );
                })}
            </div>

            {groups.map((g) => (
                <div key={g.day} style={{ marginBottom: 'var(--space-4)' }}>
                    <div style={{ fontSize: 'var(--fs-sm)', fontWeight: 'var(--fw-bold)', color: 'var(--c-muted)', textTransform: 'uppercase', letterSpacing: '.06em', marginBottom: 'var(--space-2)' }}>{g.day}</div>
                    {g.items.map((e) => (
                        <div key={e.id} style={{ display: 'grid', gridTemplateColumns: '52px 36px 1fr', gap: 'var(--space-3)', alignItems: 'start', padding: 'var(--space-2_5) 0', borderTop: '1px solid var(--c-line-soft)' }}>
                            <span style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)', fontVariantNumeric: 'tabular-nums', paddingTop: 'var(--space-1_5)' }}>{e.time}</span>
                            <span style={{ width: 'var(--control-sm)', height: 'var(--control-sm)', borderRadius: 'var(--radius-round)', display: 'grid', placeItems: 'center', background: `var(--c-${e.tone}-bg)` }}>
                                <Icon name={e.icon} color={`var(--c-${e.tone}-fg)`} />
                            </span>
                            <div style={{ minWidth: 0 }}>
                                <div style={{ fontSize: 'var(--fs-base)' }}>
                                    <b>{e.author}</b> {e.action}
                                </div>
                                <div style={{ display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: 'var(--space-2)', marginTop: 'var(--space-1)', fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>
                                    <StatusBadge label={e.kind.label} tone={e.kind.tone} />
                                    {e.target && <span>{e.target}</span>}
                                    {e.link && (
                                        <Link href={e.link} style={{ fontWeight: 'var(--fw-semibold)' }}>
                                            Ouvrir
                                        </Link>
                                    )}
                                </div>
                            </div>
                        </div>
                    ))}
                </div>
            ))}

            {groups.length === 0 && <StateBlock kind="empty" title="Aucun événement" text="Aucun événement ne correspond à ce filtre." />}
        </>
    );
}
