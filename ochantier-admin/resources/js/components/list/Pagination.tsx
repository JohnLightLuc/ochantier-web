import Icon from '@/components/ui/Icon';
import type { CSSProperties } from 'react';

type Props = {
    page: number;
    lastPage: number;
    from: number;
    to: number;
    total: number;
    onPage: (page: number) => void;
};

/** Numéros de page affichés : tous jusqu’à 7, sinon première, voisines, dernière et « … ». */
function pageNumbers(page: number, last: number): (number | '…')[] {
    if (last <= 7) return Array.from({ length: last }, (_, i) => i + 1);
    const pages = [...new Set([1, page - 1, page, page + 1, last].filter((n) => n >= 1 && n <= last))].sort((a, b) => a - b);
    return pages.flatMap((n, i) => (i && n - pages[i - 1] > 1 ? ['…' as const, n] : [n]));
}

/** Pied de liste : plage affichée et navigation entre les pages. */
export default function Pagination({ page, lastPage, from, to, total, onPage }: Props) {
    const arrow = (enabled: boolean): CSSProperties => ({ width: 36, height: 36, border: '1px solid var(--c-line-strong)', borderRadius: 'var(--radius-md)', background: 'var(--c-surface)', cursor: enabled ? 'pointer' : 'default', display: 'grid', placeItems: 'center', color: enabled ? 'var(--c-ink)' : 'var(--c-subtle)' });

    return (
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 'var(--space-4)', flexWrap: 'wrap', marginTop: 'var(--space-3)' }}>
            <span style={{ fontSize: 'var(--fs-md)', color: 'var(--c-muted)' }}>
                {from}–{to} sur {total}
            </span>
            <nav aria-label="Pagination" style={{ display: 'flex', gap: 'var(--space-1_5)', alignItems: 'center' }}>
                <button type="button" onClick={() => page > 1 && onPage(page - 1)} disabled={page <= 1} aria-label="Page précédente" style={arrow(page > 1)}>
                    <Icon name="chevron_left" />
                </button>
                {pageNumbers(page, lastPage).map((n, i) =>
                    n === '…' ? (
                        <span key={`gap-${i}`} style={{ minWidth: 36, textAlign: 'center', color: 'var(--c-ink-2)' }}>
                            …
                        </span>
                    ) : (
                        <button
                            key={n}
                            type="button"
                            onClick={() => onPage(n)}
                            aria-current={n === page ? 'page' : undefined}
                            style={{ minWidth: 36, height: 36, padding: '0 var(--space-1_5)', borderRadius: 'var(--radius-md)', cursor: 'pointer', fontWeight: 'var(--fw-semibold)', border: `1px solid ${n === page ? 'var(--c-primary)' : 'var(--c-line-strong)'}`, background: n === page ? 'var(--c-primary)' : 'var(--c-surface)', color: n === page ? 'var(--c-surface)' : 'var(--c-ink-2)' }}
                        >
                            {n}
                        </button>
                    ),
                )}
                <button type="button" onClick={() => page < lastPage && onPage(page + 1)} disabled={page >= lastPage} aria-label="Page suivante" style={arrow(page < lastPage)}>
                    <Icon name="chevron_right" />
                </button>
            </nav>
        </div>
    );
}
