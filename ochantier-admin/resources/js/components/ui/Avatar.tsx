import { hash, initials } from '@/lib/tone';
import Icon from './Icon';

type Props = {
    name: string;
    kind?: 'person' | 'team' | 'org' | 'brand';
    size?: 'sm' | 'md' | 'lg';
};

const SIZES = {
    sm: ['var(--avatar-sm)', 'var(--fs-xs)', 'var(--icon-xs)'],
    md: ['var(--avatar-md)', 'var(--fs-sm)', 'var(--icon-sm)'],
    lg: ['var(--avatar-lg)', 'var(--fs-3xl)', 'var(--icon-lg)'],
} as const;

const PERSON_TONES = ['success', 'warning', 'danger', 'info', 'purple'];

/** Avatar : initiales colorées, équipe, organisation ou marque. */
export default function Avatar({ name, kind = 'person', size = 'md' }: Props) {
    const [box, fontSize, iconSize] = SIZES[size];
    const tone = PERSON_TONES[hash(name) % PERSON_TONES.length];

    const [bg, fg, radius, text, icon] = {
        person: [`var(--c-${tone}-bg)`, `var(--c-${tone}-fg)`, 'var(--radius-round)', initials(name), ''],
        team: ['var(--c-primary-100)', 'var(--c-primary-600)', 'var(--radius-lg)', '', 'groups'],
        org: ['var(--c-neutral-bg)', 'var(--c-ink-2)', 'var(--radius-lg)', '', 'apartment'],
        brand: ['var(--c-primary-strong)', 'var(--c-accent)', 'var(--radius-round)', 'O’', ''],
    }[kind];

    return (
        <span
            title={name}
            style={{ width: box, height: box, flex: 'none', display: 'inline-grid', placeItems: 'center', borderRadius: radius, background: bg, color: fg, fontSize, fontWeight: 'var(--fw-bold)', lineHeight: 1 }}
        >
            {text}
            {icon && <Icon name={icon} size={iconSize} />}
        </span>
    );
}
