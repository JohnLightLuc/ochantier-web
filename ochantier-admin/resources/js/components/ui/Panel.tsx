import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import Icon from './Icon';

type Props = {
    icon: string;
    iconOutlined?: boolean;
    title: string;
    /** Lien ou bouton à droite du titre. */
    aside?: ReactNode;
    /** « section » : carte de page ; « inner » : bloc encadré à l’intérieur d’une carte. */
    variant?: 'section' | 'inner';
    children: ReactNode;
};

/** Bloc titré (icône, titre, action) utilisé dans les fiches. */
export default function Panel({ icon, iconOutlined, title, aside, variant = 'inner', children }: Props) {
    const section = variant === 'section';

    return (
        <section
            style={{
                background: section ? 'var(--c-surface)' : undefined,
                border: '1px solid var(--c-line)',
                borderRadius: section ? 'var(--radius-2xl)' : 'var(--radius-xl)',
                padding: section ? 'var(--space-5)' : 'var(--space-4)',
                minWidth: 0,
            }}
        >
            <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)', marginBottom: 'var(--space-3)', flexWrap: 'wrap' }}>
                <Icon name={icon} size="var(--icon-md)" color="var(--c-primary)" outlined={iconOutlined} />
                <h3 style={{ margin: 0, fontSize: 'var(--fs-xl)', flex: 1 }}>{title}</h3>
                {aside}
            </div>
            {children}
        </section>
    );
}

/** Lien souligné d’en-tête de bloc (« Voir tous… »). */
export function PanelLink({ href, label }: { href: string; label: string }) {
    return (
        <Link href={href} style={{ color: 'var(--c-primary-600)', fontWeight: 'var(--fw-semibold)' }}>
            {label}
        </Link>
    );
}

/** Ligne « icône · libellé · valeur ». */
export function InfoRow({ icon, label, value, labelWidth = 110 }: { icon: string; label: string; value: ReactNode; labelWidth?: number }) {
    return (
        <div style={{ display: 'flex', gap: 'var(--space-2_5)', padding: 'var(--space-2) 0', borderTop: '1px solid var(--c-line-soft)' }}>
            <Icon name={icon} color="var(--c-primary-600)" />
            <span style={{ width: labelWidth, flex: 'none', color: 'var(--c-muted)', fontSize: 'var(--fs-md)' }}>{label}</span>
            <span style={{ flex: 1, minWidth: 0, fontWeight: 'var(--fw-semibold)', overflowWrap: 'anywhere' }}>{value || '—'}</span>
        </div>
    );
}

/** Élément de liste cliquable : titre, sous-titre, badge à droite. */
export function LinkRow({ href, title, sub, aside, preserveScroll }: { href: string; title: ReactNode; sub?: ReactNode; aside?: ReactNode; preserveScroll?: boolean }) {
    return (
        <Link
            href={href}
            preserveScroll={preserveScroll}
            className="och-hover-soft"
            style={{ width: '100%', display: 'flex', alignItems: 'center', gap: 'var(--space-3)', padding: 'var(--space-2_5) var(--space-1)', borderTop: '1px solid var(--c-line-soft)', background: 'transparent', color: 'var(--c-ink)', textDecoration: 'none', borderRadius: 'var(--radius-md)' }}
        >
            <span style={{ flex: 1, minWidth: 0 }}>
                <span style={{ display: 'block', fontWeight: 'var(--fw-semibold)' }}>{title}</span>
                {sub && <span style={{ display: 'block', fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>{sub}</span>}
            </span>
            {aside}
        </Link>
    );
}
