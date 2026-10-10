import Button from '@/components/ui/Button';
import Icon from '@/components/ui/Icon';
import { toneColors } from '@/lib/tone';
import type { AnyTone } from '@/types';
import { FilterChip } from './ListToolbar';

export type ActionStripItem = {
    key: string;
    count: number;
    label: string;
    icon: string;
    tone: AnyTone;
};

type Props = {
    title: string;
    icon: string;
    items: ActionStripItem[];
    active: string | null;
    activeLabel?: string;
    ctaLabel: string;
    onCta: () => void;
    onSelect: (key: string | null) => void;
};

/** Bandeau « À traiter » : raccourcis de filtrage avec leur compteur. */
export default function ActionStrip({ title, icon, items, active, activeLabel, ctaLabel, onCta, onSelect }: Props) {
    return (
        <section data-component="ActionStrip" style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)', background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-4) var(--space-5) var(--space-5)' }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-3)', flexWrap: 'wrap' }}>
                <Icon name={icon} size="var(--icon-md)" color="var(--c-primary)" />
                <h2 style={{ margin: 0, fontSize: 'var(--fs-2xl)', fontWeight: 'var(--fw-bold)', flex: 1, minWidth: 200 }}>{title}</h2>
                {active && activeLabel && <FilterChip label={`Filtre : ${activeLabel}`} onClear={() => onSelect(null)} />}
                <Button label={ctaLabel} iconRight="arrow_forward" variant="primary" onClick={onCta} />
            </div>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,240px),1fr))', gap: 'var(--space-3)' }}>
                {items.map((item) => {
                    const on = active === item.key;
                    const off = item.count === 0;
                    const { bg, fg } = toneColors(item.tone);
                    return (
                        <button
                            key={item.key}
                            type="button"
                            onClick={() => !off && onSelect(on ? null : item.key)}
                            aria-pressed={on}
                            disabled={off}
                            className={off ? undefined : 'och-hover-card'}
                            style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-3)', padding: 'var(--space-3)', border: `1.5px solid ${on ? 'var(--c-primary-400)' : 'var(--c-line)'}`, borderRadius: 'var(--radius-xl)', background: on ? 'var(--c-primary-50)' : 'var(--c-surface)', cursor: off ? 'default' : 'pointer', opacity: off ? 0.55 : 1, textAlign: 'left', minWidth: 0 }}
                        >
                            <span style={{ width: 46, height: 46, borderRadius: 'var(--radius-round)', flex: 'none', display: 'grid', placeItems: 'center', background: bg }}>
                                <Icon name={item.icon} size="var(--icon-md)" color={fg} />
                            </span>
                            <span style={{ flex: 1, minWidth: 0 }}>
                                <span style={{ display: 'block', fontSize: 'var(--fs-3xl)', fontWeight: 'var(--fw-bold)', lineHeight: 1.1 }}>{item.count}</span>
                                <span style={{ display: 'block', fontSize: 'var(--fs-md)', color: 'var(--c-muted)' }}>{item.label}</span>
                            </span>
                            <Icon name={on ? 'close' : 'arrow_forward'} color={on ? 'var(--c-primary)' : 'var(--c-ink-2)'} />
                        </button>
                    );
                })}
            </div>
        </section>
    );
}
