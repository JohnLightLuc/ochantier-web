import { toneColors } from '@/lib/tone';
import type { AnyTone } from '@/types';
import Icon from './Icon';

type Props = {
    label: string;
    tone?: AnyTone;
    icon?: string;
};

/** Badge de statut coloré. */
export default function StatusBadge({ label, tone = 'neutral', icon }: Props) {
    const { bg, fg } = toneColors(tone);

    return (
        <span
            style={{
                display: 'inline-flex',
                alignItems: 'center',
                gap: 'var(--space-1)',
                height: 26,
                padding: '0 var(--space-2_5)',
                borderRadius: 'var(--radius-sm)',
                background: bg,
                color: fg,
                fontFamily: 'var(--font-sans)',
                fontSize: 'var(--fs-sm)',
                fontWeight: 'var(--fw-semibold)',
                whiteSpace: 'nowrap',
                lineHeight: 1,
            }}
        >
            {icon && <Icon name={icon} size="var(--icon-xs)" />}
            {label}
        </span>
    );
}
