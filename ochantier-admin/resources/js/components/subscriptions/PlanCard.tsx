import Icon from '@/components/ui/Icon';
import type { Plan } from '@/types/subscriptions';
import type { ReactNode } from 'react';

type Props = { plan: Plan; selected?: boolean; current?: boolean; onSelect?: () => void; children?: ReactNode };

/** Carte d’une formule : prix, avantages et mise en avant « Le plus choisi ». */
export default function PlanCard({ plan, selected, current, onSelect, children }: Props) {
    const strong = selected ?? plan.popular;
    const body = (
        <>
            {plan.popular && <span style={{ position: 'absolute', top: -11, left: 'var(--space-4)', padding: '2px var(--space-2)', borderRadius: 'var(--radius-pill)', background: 'var(--c-primary)', color: 'var(--c-on-dark)', fontSize: 'var(--fs-xs)', fontWeight: 'var(--fw-bold)' }}>Le plus choisi</span>}
            <div style={{ display: 'flex', justifyContent: 'space-between', gap: 'var(--space-3)', alignItems: 'flex-start' }}>
                <div>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)', fontSize: 'var(--fs-xl)', fontWeight: 'var(--fw-bold)' }}>
                        {onSelect && (
                            <span style={{ width: 20, height: 20, borderRadius: 'var(--radius-round)', border: `2px solid ${selected ? 'var(--c-primary)' : 'var(--c-line-hover)'}`, background: selected ? 'var(--c-primary)' : 'var(--c-surface)', display: 'grid', placeItems: 'center', color: 'var(--c-on-dark)' }}>{selected && <Icon name="check" size="14px" />}</span>
                        )}
                        {plan.name}
                        {current && <span style={{ fontSize: 'var(--fs-xs)', padding: '1px var(--space-2)', borderRadius: 'var(--radius-pill)', background: 'var(--c-primary-50)', color: 'var(--c-primary)' }}>Formule actuelle</span>}
                    </div>
                    <div style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>{plan.subtitle}</div>
                </div>
                <div style={{ textAlign: 'right' }}>
                    <div style={{ fontSize: 'var(--fs-xl)', fontWeight: 'var(--fw-bold)', whiteSpace: 'nowrap' }}>{plan.priceLabel}</div>
                    <div style={{ fontSize: 'var(--fs-xs)', color: 'var(--c-muted)' }}>{plan.period}</div>
                </div>
            </div>
            <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-1_5)' }}>
                {plan.features.map((f) => (
                    <div key={f} style={{ display: 'flex', gap: 'var(--space-2)', fontSize: 'var(--fs-sm)' }}>
                        <Icon name="check" size="var(--icon-xs)" color="var(--c-primary)" />
                        {f}
                    </div>
                ))}
            </div>
            {children}
        </>
    );
    const style = { position: 'relative' as const, display: 'flex', flexDirection: 'column' as const, gap: 'var(--space-3)', padding: 'var(--space-5)', border: `${strong ? 2 : 1}px solid ${strong ? 'var(--c-primary)' : 'var(--c-line)'}`, borderRadius: 'var(--radius-xl)', background: 'var(--c-surface)', textAlign: 'left' as const, font: 'inherit', color: 'inherit' };

    return onSelect ? (
        <button type="button" role="radio" aria-checked={!!selected} onClick={onSelect} style={{ ...style, cursor: 'pointer' }}>
            {body}
        </button>
    ) : (
        <section style={style}>{body}</section>
    );
}
