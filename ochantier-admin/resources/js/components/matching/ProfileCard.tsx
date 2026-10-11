import Avatar from '@/components/ui/Avatar';
import Icon from '@/components/ui/Icon';
import { toneColors } from '@/lib/tone';
import { formatDate } from '@/lib/format';
import type { AnyTone } from '@/types';
import type { Profile } from '@/types/matching';
import { decimal, fcfa } from './useMatchingActions';

const LEVEL_COLORS: Record<string, string> = { 'Excellent match': 'var(--c-success-fg)', 'Très bon match': 'var(--c-success-fg)', 'Bon match': 'var(--c-info-fg)', 'Match acceptable': 'var(--c-warning-fg)', 'Match faible': 'var(--c-warning-fg)', 'Non recommandé': 'var(--c-danger-fg)' };

/** Anneau de score sur 100. */
export function ScoreRing({ score, size = 62 }: { score: number; size?: number }) {
    return (
        <div style={{ width: size, height: size, borderRadius: 'var(--radius-round)', display: 'grid', placeItems: 'center', background: `conic-gradient(var(--c-primary-500) 0 ${score}%,var(--c-primary-200) ${score}% 100%)` }}>
            <div style={{ width: size - 12, height: size - 12, borderRadius: 'var(--radius-round)', background: 'var(--c-surface)', display: 'grid', placeItems: 'center', fontWeight: 'var(--fw-bold)', fontSize: 'var(--fs-lg)' }}>{score} %</div>
        </div>
    );
}

function Tag({ label, tone }: { label: string; tone: AnyTone }) {
    const { bg, fg } = toneColors(tone);
    return <span style={{ padding: '2px var(--space-2)', borderRadius: 'var(--radius-pill)', background: bg, color: fg, fontSize: 'var(--fs-xs)', fontWeight: 'var(--fw-semibold)' }}>{label}</span>;
}

type Props = {
    p: Profile;
    /** Poste complet : le profil ne peut pas être ajouté. */
    locked: boolean;
    onToggle: () => void;
    onView: () => void;
};

/** Carte d’un profil proposé pour un poste : identité, critères principaux, score et sélection. */
export default function ProfileCard({ p, locked, onToggle, onView }: Props) {
    const on = p.selected;
    const crit = (key: string) => p.criteria.find((c) => c.key === key)?.n ?? 0;
    const tags: [string, AnyTone][] = [
        [p.verified ? 'Profil vérifié' : 'Non vérifié', p.verified ? 'g' : 'a'],
        [`Dossier ${p.dossier} %`, p.dossier >= 80 ? 'g' : 'a'],
        ...(p.certs.length ? ([[p.certs[0], 'b']] as [string, AnyTone][]) : []),
        ...(p.warnings.length ? ([[`${p.warnings.length} avertissement${p.warnings.length > 1 ? 's' : ''}`, 'o']] as [string, AnyTone][]) : []),
        ...(p.replacement ? ([[p.replacement, 'n']] as [string, AnyTone][]) : on ? ([['Sélectionné', 'p']] as [string, AnyTone][]) : []),
    ];
    const button = locked ? ['Complet', 'var(--c-neutral-bg)', 'var(--c-muted)'] : on ? ['Désélectionner', 'var(--c-success-bg)', 'var(--c-success-fg)'] : p.replacement ? [p.replacement, 'var(--c-neutral-bg)', 'var(--c-neutral-fg)'] : ['Sélectionner', 'var(--c-primary-strong)', 'var(--c-surface)'];

    return (
        <div data-component="ProfileCard" style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-4)', flexWrap: 'wrap', padding: 'var(--space-4)', borderRadius: 'var(--radius-xl)', border: `1.5px solid ${on ? 'var(--c-primary-400)' : 'var(--c-line)'}`, background: on ? 'var(--c-primary-50)' : 'var(--c-surface)', opacity: locked ? 0.5 : 1 }}>
            <button type="button" onClick={onToggle} role="checkbox" aria-checked={on} aria-label={`Sélectionner ${p.name}`} style={{ width: 22, height: 22, borderRadius: 'var(--radius-sm)', padding: 0, display: 'grid', placeItems: 'center', cursor: 'pointer', border: `1.5px solid ${locked ? 'var(--c-line)' : on ? 'var(--c-primary)' : 'var(--c-line-hover)'}`, background: locked ? 'var(--c-surface-3)' : on ? 'var(--c-primary)' : 'var(--c-surface)' }}>
                {(on || locked) && <Icon name={locked ? 'lock' : 'check'} size="var(--icon-xs)" color={locked ? 'var(--c-muted)' : 'var(--c-surface)'} />}
            </button>
            <Avatar name={p.name} size="lg" />
            <div style={{ flex: '1 1 280px', minWidth: 0, display: 'flex', flexDirection: 'column', gap: 'var(--space-0_5)' }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2_5)', flexWrap: 'wrap' }}>
                    <span style={{ fontWeight: 'var(--fw-bold)', fontSize: 'var(--fs-xl)' }}>{p.name}</span>
                    <span style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-0_5)', fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>
                        <Icon name="star" size="var(--icon-xs)" color="var(--c-accent-600)" />
                        <b style={{ color: 'var(--c-ink)' }}>{decimal(p.rating)}</b>({p.reviews} avis)
                    </span>
                </div>
                <div style={{ fontSize: 'var(--fs-md)', color: 'var(--c-primary-600)', fontWeight: 'var(--fw-semibold)' }}>
                    {p.trade} · {p.specialty} · {p.code}
                </div>
                <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-1) var(--space-3)', fontSize: 'var(--fs-sm)', color: 'var(--c-ink-2)', marginTop: 'var(--space-0_5)' }}>
                    {(
                        [
                            ['location_on', `${p.commune} · ${decimal(p.km)} km · ${p.minutes} min`],
                            ['work_history', `${p.experience} an${p.experience > 1 ? 's' : ''} d’expérience`],
                            ['payments', `${fcfa(p.cost)}/j`],
                            ['event_available', `Disponible dès le ${formatDate(p.availableOn)} (${p.availability.toLowerCase()})`],
                        ] as const
                    ).map(([icon, text]) => (
                        <span key={icon} style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-1)' }}>
                            <Icon name={icon} size="var(--icon-xs)" color="var(--c-primary-600)" />
                            {text}
                        </span>
                    ))}
                </div>
                <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-1_5)', marginTop: 'var(--space-1_5)' }}>
                    {tags.map(([label, tone]) => (
                        <Tag key={label} label={label} tone={tone} />
                    ))}
                </div>
            </div>
            <div style={{ flex: '1 1 170px', minWidth: 160, maxWidth: 220, display: 'grid', gridTemplateColumns: 'auto 1fr', gap: 'var(--space-1) var(--space-3)', alignItems: 'center', fontSize: 'var(--fs-sm)', color: 'var(--c-ink-2)' }}>
                {(
                    [
                        ['Métier', 'metier'],
                        ['Disponibilité', 'dispo'],
                        ['Expérience', 'exp'],
                        ['Proximité', 'prox'],
                        ['Coût', 'cout'],
                    ] as const
                ).map(([label, key]) => (
                    <span key={key} style={{ display: 'contents' }}>
                        <span>{label}</span>
                        <div style={{ height: 6, borderRadius: 'var(--radius-xs)', background: 'var(--c-line-soft)', overflow: 'hidden' }}>
                            <div style={{ height: '100%', borderRadius: 'var(--radius-xs)', background: 'var(--c-primary-500)', width: `${crit(key)}%` }} />
                        </div>
                    </span>
                ))}
            </div>
            <div style={{ flex: 'none', display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 'var(--space-2)', minWidth: 190 }}>
                <ScoreRing score={p.score} />
                <span style={{ fontSize: 'var(--fs-sm)', fontWeight: 'var(--fw-semibold)', color: LEVEL_COLORS[p.level.label] ?? 'var(--c-muted)' }}>{p.level.label}</span>
                <div style={{ display: 'flex', gap: 'var(--space-1_5)' }}>
                    <button type="button" onClick={onView} style={{ height: 'var(--control-xs)', padding: '0 var(--space-2_5)', border: '1px solid var(--c-line-strong)', borderRadius: 'var(--radius-md)', background: 'var(--c-surface)', cursor: 'pointer', fontSize: 'var(--fs-sm)', fontWeight: 'var(--fw-semibold)', whiteSpace: 'nowrap' }}>
                        Voir le profil
                    </button>
                    <button type="button" onClick={onToggle} style={{ height: 'var(--control-xs)', padding: '0 var(--space-2_5)', border: 0, borderRadius: 'var(--radius-md)', cursor: 'pointer', fontSize: 'var(--fs-sm)', fontWeight: 'var(--fw-semibold)', whiteSpace: 'nowrap', background: button[1], color: button[2] }}>
                        {button[0]}
                    </button>
                </div>
            </div>
        </div>
    );
}
