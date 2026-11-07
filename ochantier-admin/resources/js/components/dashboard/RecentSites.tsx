import Button from '@/components/ui/Button';
import Icon from '@/components/ui/Icon';
import StateBlock from '@/components/ui/StateBlock';
import StatusBadge from '@/components/ui/StatusBadge';
import type { Site, SiteDistribution } from '@/types/dashboard';
import { router } from '@inertiajs/react';
import { useState, type MouseEvent } from 'react';
import Card, { Dot } from './Card';

type Props = {
    sites: Site[];
    distribution: SiteDistribution;
    onOpen: (site: Site) => void;
};

const HEADERS = ['Chantier', 'Promoteur', 'Localisation', 'Temps écoulé', 'Ouvriers', 'Statut'];

const thStyle = { padding: 'var(--space-2_5) var(--space-3)', fontSize: 'var(--fs-sm)', fontWeight: 'var(--fw-semibold)', color: 'var(--c-ink-2)' } as const;
const rowIconButton = { width: 'var(--control-xs)', height: 'var(--control-xs)', border: 0, borderRadius: 'var(--radius-md)', background: 'transparent', cursor: 'pointer', display: 'grid', placeItems: 'center', color: 'var(--c-ink-2)' } as const;

/** Tableau des chantiers récents et répartition des chantiers actifs. */
export default function RecentSites({ sites, distribution, onOpen }: Props) {
    const [menuFor, setMenuFor] = useState<string | null>(null);

    const stop = (fn: () => void) => (e: MouseEvent) => {
        e.stopPropagation();
        fn();
    };

    const menuItems = (site: Site): [string, string, () => void][] =>
        [
            ['visibility', 'Voir le détail', () => onOpen(site)],
            site.promoterHref ? ['apartment', 'Voir le promoteur', () => router.visit(site.promoterHref!)] : null,
            ['schedule', 'Consulter les pointages', () => router.visit('/pointages')],
            ['open_in_new', 'Ouvrir dans Chantiers', () => router.visit(`/chantiers?q=${encodeURIComponent(site.name)}`)],
        ].filter((item): item is [string, string, () => void] => item !== null);

    return (
        <Card component="RecentTable" span={8} icon="construction" title="Chantiers récents" aside={<Button label="Voir tous" icon="arrow_forward" size="sm" variant="secondary" onClick={() => router.visit('/chantiers')} />}>
            {sites.length === 0 ? (
                <StateBlock kind="nodata" icon="construction" title="Aucun chantier récent" text="Les chantiers créés ou mis à jour apparaîtront ici." />
            ) : (
                <>
                    <div style={{ overflowX: 'auto', border: '1px solid var(--c-line-soft)', borderRadius: 'var(--radius-lg)' }}>
                        <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 'var(--fs-md)', minWidth: 760 }}>
                            <thead>
                                <tr style={{ background: 'var(--c-surface-3)', textAlign: 'left' }}>
                                    {HEADERS.map((h) => (
                                        <th key={h} style={thStyle}>
                                            {h}
                                        </th>
                                    ))}
                                    <th style={{ ...thStyle, textAlign: 'center' }}>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {sites.map((site) => (
                                    <tr key={site.id} onClick={() => onOpen(site)} className="och-hover-soft" style={{ cursor: 'pointer', borderTop: '1px solid var(--c-line-soft)', background: 'var(--c-surface)' }}>
                                        <td style={{ padding: 'var(--space-3)', fontWeight: 'var(--fw-semibold)', whiteSpace: 'nowrap' }}>{site.name}</td>
                                        <td style={{ padding: 'var(--space-3)', color: 'var(--c-ink-2)', whiteSpace: 'nowrap' }}>{site.promoter}</td>
                                        <td style={{ padding: 'var(--space-3)', whiteSpace: 'nowrap' }}>
                                            <span style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-1)' }}>
                                                <Icon name="location_on" size="var(--icon-xs)" color="var(--c-primary-600)" />
                                                {site.location}
                                            </span>
                                        </td>
                                        <td style={{ padding: 'var(--space-3)' }}>
                                            <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2_5)' }}>
                                                <span style={{ width: 38, fontVariantNumeric: 'tabular-nums' }}>{site.progress} %</span>
                                                <div style={{ width: 90, height: 7, borderRadius: 'var(--radius-xs)', background: 'var(--c-line-soft)', overflow: 'hidden' }}>
                                                    <div style={{ height: '100%', borderRadius: 'var(--radius-xs)', width: `${site.progress}%`, background: site.progress < 50 ? 'var(--c-accent-600)' : 'var(--c-primary-500)' }} />
                                                </div>
                                            </div>
                                        </td>
                                        <td style={{ padding: 'var(--space-3)', fontVariantNumeric: 'tabular-nums', whiteSpace: 'nowrap' }}>{site.workers}</td>
                                        <td style={{ padding: 'var(--space-3)' }}>
                                            <StatusBadge label={site.status} tone={site.tone} />
                                        </td>
                                        <td style={{ position: 'relative', padding: 'var(--space-2)' }}>
                                            <div style={{ display: 'flex', justifyContent: 'center', gap: 'var(--space-0_5)' }}>
                                                <button type="button" onClick={stop(() => onOpen(site))} title="Voir le détail" aria-label="Voir le détail" className="och-hover-icon" style={rowIconButton}>
                                                    <Icon name="visibility" outlined />
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={stop(() => setMenuFor(menuFor === site.id ? null : site.id))}
                                                    title="Plus d’actions"
                                                    aria-label="Plus d’actions"
                                                    aria-expanded={menuFor === site.id}
                                                    className="och-hover-icon"
                                                    style={rowIconButton}
                                                >
                                                    <Icon name="more_vert" />
                                                </button>
                                            </div>
                                            {menuFor === site.id && (
                                                <>
                                                    <div onClick={stop(() => setMenuFor(null))} style={{ position: 'fixed', inset: 0, zIndex: 15, cursor: 'default' }} />
                                                    <div role="menu" style={{ position: 'absolute', right: 'var(--space-2)', top: 'calc(100% - var(--space-1))', zIndex: 20, width: 240, background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-xl)', boxShadow: 'var(--shadow-popover)', padding: 'var(--space-1_5)', textAlign: 'left' }}>
                                                        {menuItems(site).map(([icon, label, fn]) => (
                                                            <button
                                                                key={label}
                                                                type="button"
                                                                role="menuitem"
                                                                onClick={stop(() => {
                                                                    setMenuFor(null);
                                                                    fn();
                                                                })}
                                                                className="och-hover-menu"
                                                                style={{ width: '100%', display: 'flex', alignItems: 'center', gap: 'var(--space-2_5)', padding: 'var(--space-2) var(--space-3)', border: 0, borderRadius: 'var(--radius-md)', background: 'transparent', cursor: 'pointer', textAlign: 'left', color: 'var(--c-ink)' }}
                                                            >
                                                                <Icon name={icon} />
                                                                {label}
                                                            </button>
                                                        ))}
                                                    </div>
                                                </>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <div style={{ marginTop: 'auto', paddingTop: 'var(--space-4)' }}>
                        <div style={{ fontSize: 'var(--fs-md)', fontWeight: 'var(--fw-semibold)', marginBottom: 'var(--space-2)' }}>Répartition des {distribution.total} chantiers actifs</div>
                        <div style={{ display: 'flex', height: 10, borderRadius: 'var(--radius-pill)', overflow: 'hidden', gap: 2, background: 'var(--c-line-soft)' }}>
                            {distribution.segments.map((d) => (
                                <div key={d.label} style={{ height: '100%', width: `${(d.count / (distribution.total || 1)) * 100}%`, background: d.color }} />
                            ))}
                        </div>
                        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2) var(--space-5)', marginTop: 'var(--space-2_5)' }}>
                            {distribution.segments.map((d) => (
                                <button
                                    key={d.label}
                                    type="button"
                                    onClick={() => router.visit(d.href)}
                                    className="och-hover-ink"
                                    style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)', border: 0, background: 'transparent', padding: 0, cursor: 'pointer', fontSize: 'var(--fs-md)', color: 'var(--c-ink-2)' }}
                                >
                                    <Dot color={d.color} />
                                    {d.label}
                                    <b style={{ color: 'var(--c-ink)' }}>{d.count}</b>
                                </button>
                            ))}
                        </div>
                    </div>
                </>
            )}
        </Card>
    );
}
