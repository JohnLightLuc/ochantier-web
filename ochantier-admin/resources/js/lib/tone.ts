import type { AnyTone, Tone } from '@/types';

const ALIASES: Record<string, Tone> = { g: 'success', a: 'warning', o: 'orange', r: 'danger', b: 'info', p: 'purple', n: 'neutral', R: 'danger-solid' };

export function resolveTone(tone: AnyTone | undefined, fallback: Tone = 'neutral'): Tone {
    if (!tone) return fallback;
    return ALIASES[tone] ?? (tone as Tone);
}

/** Couleurs de fond et de texte d’un ton (tokens --c-<ton>-bg / --c-<ton>-fg). */
export function toneColors(tone: AnyTone | undefined, fallback: Tone = 'neutral'): { bg: string; fg: string } {
    const t = resolveTone(tone, fallback);
    if (t === 'danger-solid') return { bg: 'var(--c-danger)', fg: 'var(--c-on-dark)' };
    if (t === 'primary') return { bg: 'var(--c-primary-100)', fg: 'var(--c-primary-600)' };
    return { bg: `var(--c-${t}-bg)`, fg: `var(--c-${t}-fg)` };
}

export const PRIORITY_TONES: Record<string, AnyTone> = { Urgent: 'r', Élevé: 'o', Moyen: 'a' };

/** Hachage stable d’une chaîne (couleur d’avatar, vignettes). */
export function hash(value: string): number {
    let h = 0;
    for (const ch of value) h = (h * 31 + ch.charCodeAt(0)) >>> 0;
    return h;
}

export function initials(name: string): string {
    return name
        .replace(/[’']/g, ' ')
        .split(' ')
        .filter(Boolean)
        .map((w) => w[0])
        .slice(-2)
        .join('')
        .toUpperCase();
}
