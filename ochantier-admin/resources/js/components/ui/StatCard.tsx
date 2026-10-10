import { resolveTone } from '@/lib/tone';
import type { AnyTone } from '@/types';
import Icon from './Icon';

export type StatCardProps = {
    label: string;
    value: string;
    unit?: string;
    icon?: string;
    tone?: AnyTone;
    delta?: string;
    deltaTone?: AnyTone;
    deltaIcon?: string;
    compare?: string;
    hint?: string;
    highlight?: boolean;
};

/** Carte KPI : valeur, unité, icône, variation. */
export default function StatCard({ label, value, unit, icon = 'insights', tone = 'success', delta, deltaTone = 'success', deltaIcon = 'north_east', compare = 'vs mois dernier', hint, highlight }: StatCardProps) {
    const t = resolveTone(tone, 'success');
    const dt = resolveTone(deltaTone, 'success');
    const long = value.length + (unit ? unit.length + 1 : 0) > 12;

    return (
        <div
            style={{
                height: '100%',
                display: 'flex',
                gap: 'var(--space-4)',
                alignItems: 'flex-start',
                padding: 'var(--space-5)',
                borderRadius: 'var(--radius-2xl)',
                background: highlight ? 'var(--c-warning-surface)' : 'var(--c-surface)',
                border: `1px solid ${highlight ? 'var(--c-warning-line)' : 'var(--c-line)'}`,
                boxShadow: 'var(--shadow-xs)',
                color: 'var(--c-ink)',
                minWidth: 0,
            }}
        >
            <div style={{ width: 'var(--control-lg)', height: 'var(--control-lg)', borderRadius: 'var(--radius-xl)', flex: 'none', display: 'grid', placeItems: 'center', background: `var(--c-${t}-bg)` }}>
                <Icon name={icon} size="var(--icon-lg)" color={`var(--c-${t}-fg)`} />
            </div>
            <div style={{ minWidth: 0, flex: 1 }}>
                <div style={{ fontSize: 'var(--fs-base)', fontWeight: 'var(--fw-medium)', color: 'var(--c-ink-2)' }}>{label}</div>
                <div style={{ display: 'flex', alignItems: 'baseline', gap: 'var(--space-1_5)', marginTop: 'var(--space-1)', whiteSpace: 'nowrap', minWidth: 0 }}>
                    <span style={{ fontSize: long ? 'var(--fs-3xl)' : 'var(--fs-4xl)', fontWeight: 'var(--fw-bold)', letterSpacing: '-.5px', lineHeight: 'var(--lh-tight)', fontVariantNumeric: 'tabular-nums' }}>{value}</span>
                    {unit && <span style={{ fontSize: 'var(--fs-lg)', fontWeight: 'var(--fw-bold)' }}>{unit}</span>}
                </div>
                {delta && (
                    <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-1_5)', marginTop: 'var(--space-1_5)', fontSize: 'var(--fs-sm)', flexWrap: 'wrap' }}>
                        <span style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-0_5)', fontWeight: 'var(--fw-bold)', color: `var(--c-${dt}-fg)` }}>
                            <Icon name={deltaIcon} size="var(--icon-xs)" />
                            {delta}
                        </span>
                        <span style={{ color: 'var(--c-muted)' }}>{compare}</span>
                    </div>
                )}
                {hint && <div style={{ marginTop: 'var(--space-1_5)', fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>{hint}</div>}
            </div>
        </div>
    );
}
