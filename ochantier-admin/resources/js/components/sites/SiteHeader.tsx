import Button from '@/components/ui/Button';
import Icon from '@/components/ui/Icon';
import Menu, { type MenuItem } from '@/components/ui/Menu';
import StatusBadge from '@/components/ui/StatusBadge';
import { useAdminUi } from '@/contexts/AdminUiContext';
import { formatDate } from '@/lib/format';
import type { SiteShowProps } from '@/types/sites';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import type { SiteActions } from './useSiteActions';

type Props = Pick<SiteShowProps, 'site' | 'banner' | 'can'> & { actions: SiteActions };

/** En-tête de la fiche chantier : identité, statut, décisions possibles et bandeau d’état. */
export default function SiteHeader({ site: s, banner, can, actions }: Props) {
    const ui = useAdminUi();
    const [moreOpen, setMoreOpen] = useState(false);

    const meta: [string, string, string][] = [
        ['location_on', 'Localisation', [s.neighborhood, s.commune].filter(Boolean).join(', ') || '—'],
        ['domain', 'Type', s.type ?? '—'],
        ['date_range', 'Période', `${s.startsOn ? formatDate(s.startsOn) : '—'} → ${s.endsOn ? formatDate(s.endsOn) : '—'}`],
    ];

    const moreItems: MenuItem[] = [
        { icon: 'apartment', label: 'Voir le promoteur', onClick: () => router.visit(`/promoteurs/${s.promoterId}`) },
        {
            icon: 'content_copy',
            label: 'Copier la référence',
            onClick: () => {
                navigator.clipboard?.writeText(s.reference).catch(() => undefined);
                ui.toast(`Référence ${s.reference} copiée.`);
            },
        },
        ...(can.validate && s.can.cancel ? [{ icon: 'cancel', label: 'Annuler le chantier', danger: true, onClick: () => actions.cancel(s) }] : []),
        ...(can.validate && s.can.archive ? [{ icon: 'archive', label: 'Archiver', onClick: () => actions.archive(s) }] : []),
    ];

    return (
        <>
            <section data-component="SiteHeader" style={{ position: 'relative', display: 'flex', flexWrap: 'wrap', gap: 'var(--space-5)', alignItems: 'flex-start', background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-5)' }}>
                <div style={{ width: 64, height: 64, flex: 'none', borderRadius: 'var(--radius-xl)', display: 'grid', placeItems: 'center', background: 'var(--c-primary-50)' }}>
                    <Icon name="construction" size="var(--icon-lg)" color="var(--c-primary-600)" />
                </div>
                <div style={{ flex: '1 1 360px', minWidth: 0 }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2_5)', flexWrap: 'wrap' }}>
                        <h2 style={{ margin: 0, fontSize: 'var(--fs-4xl)', fontWeight: 'var(--fw-bold)', letterSpacing: '-.4px' }}>{s.name}</h2>
                        <StatusBadge label={s.status.label} tone={s.status.tone} />
                        {s.priority && <StatusBadge label={`Priorité ${s.priority.label.toLowerCase()}`} tone={s.priority.tone} />}
                    </div>
                    <div style={{ marginTop: 'var(--space-1)', fontSize: 'var(--fs-lg)', color: 'var(--c-ink-2)' }}>
                        {s.promoter} · <span style={{ fontVariantNumeric: 'tabular-nums', color: 'var(--c-muted)' }}>{s.reference}</span>
                    </div>
                    <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2) var(--space-5)', marginTop: 'var(--space-3)' }}>
                        {meta.map(([icon, label, value]) => (
                            <span key={label} style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-1_5)', fontSize: 'var(--fs-md)', color: 'var(--c-muted)' }}>
                                <Icon name={icon} size="var(--icon-xs)" color="var(--c-primary-600)" />
                                {label} : <b style={{ color: 'var(--c-ink)', fontWeight: 'var(--fw-semibold)' }}>{value}</b>
                            </span>
                        ))}
                    </div>
                </div>
                <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2)', alignItems: 'center', position: 'relative' }}>
                    {can.update && s.can.resume && <Button label="Reprendre" icon="play_circle" variant="primary" onClick={() => actions.resume(s)} />}
                    {can.update && s.can.suspend && <Button label="Suspendre" icon="block" variant="danger-soft" onClick={() => actions.suspend(s)} />}
                    {can.validate && s.can.close && <Button label="Clôturer" icon="task_alt" variant="primary" onClick={() => actions.close(s)} />}
                    <Button icon="more_horiz" variant="secondary" aria-label="Plus d’actions" aria-expanded={moreOpen} onClick={() => setMoreOpen(!moreOpen)} />
                    {moreOpen && <Menu items={moreItems} width={260} top="52px" right="0" onClose={() => setMoreOpen(false)} />}
                </div>
            </section>

            {banner && (
                <div role="status" style={{ display: 'flex', gap: 'var(--space-3)', alignItems: 'center', flexWrap: 'wrap', padding: 'var(--space-3) var(--space-4)', borderRadius: 'var(--radius-lg)', background: `var(--c-${banner.tone}-bg)`, color: `var(--c-${banner.tone}-fg)` }}>
                    <Icon name={banner.icon} />
                    <span style={{ flex: 1, minWidth: 200, fontSize: 'var(--fs-md)' }}>
                        <b>{banner.title}</b> {banner.text}
                    </span>
                </div>
            )}
        </>
    );
}
