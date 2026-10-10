import Icon from '@/components/ui/Icon';
import StateBlock from '@/components/ui/StateBlock';
import type { FeedEntry } from '@/types/dashboard';
import { Link } from '@inertiajs/react';
import Card, { FooterLink } from './Card';

type Props = {
    feed: FeedEntry[];
    onSeeAll: () => void;
};

/** Dernières actions réalisées sur la plateforme. */
export default function ActivityFeed({ feed, onSeeAll }: Props) {
    return (
        <Card component="ActivityFeed" span={5} icon="history" iconOutlined title="Activité récente" headerGap="var(--space-3)">
            {feed.length === 0 ? (
                <StateBlock kind="nodata" icon="history" title="Aucune activité récente" text="Les actions réalisées sur la plateforme apparaîtront ici." />
            ) : (
                <div style={{ display: 'flex', flexDirection: 'column' }}>
                    {feed.slice(0, 6).map((f) => (
                        <div key={f.title + f.when} style={{ display: 'grid', gridTemplateColumns: '36px minmax(0,1fr) auto', gap: 'var(--space-3)', alignItems: 'center', padding: 'var(--space-2_5) 0', borderTop: '1px solid var(--c-line-soft)' }}>
                            <span style={{ width: 'var(--control-sm)', height: 'var(--control-sm)', borderRadius: 'var(--radius-round)', display: 'grid', placeItems: 'center', background: `var(--c-${f.tone}-bg)` }}>
                                <Icon name={f.icon} color={`var(--c-${f.tone}-fg)`} />
                            </span>
                            <div style={{ minWidth: 0 }}>
                                <div style={{ fontSize: 'var(--fs-md)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                                    <b style={{ fontWeight: 'var(--fw-semibold)' }}>{f.title}</b> · {f.target}
                                </div>
                                <div style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                                    {f.user} · {f.when}
                                </div>
                            </div>
                            <Link href={f.href} aria-label={`Ouvrir : ${f.title} · ${f.target}`} style={{ fontWeight: 'var(--fw-semibold)', fontSize: 'var(--fs-sm)' }}>
                                Ouvrir
                            </Link>
                        </div>
                    ))}
                </div>
            )}
            <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: 'auto', paddingTop: 'var(--space-4)' }}>
                <FooterLink label="Voir toute l’activité" onClick={onSeeAll} />
            </div>
        </Card>
    );
}
