import Avatar from '@/components/ui/Avatar';
import Button from '@/components/ui/Button';
import Icon from '@/components/ui/Icon';
import StateBlock from '@/components/ui/StateBlock';
import type { Matching, WorkerProfile } from '@/types/dashboard';
import Card from './Card';

type Props = {
    matching: Matching;
    onWorker: (worker: WorkerProfile) => void;
    onSeeAll: () => void;
    onLaunch: () => void;
};

/** Synthèse du matching intelligent et profils récemment suggérés. */
export default function MatchingCard({ matching, onWorker, onSeeAll, onLaunch }: Props) {
    const { relevance, pending, delta, profiles } = matching;

    return (
        <Card component="MatchingCard" span={4} icon="join_inner" title="Matching intelligent">
            {profiles.length === 0 ? (
                <StateBlock kind="nodata" icon="person_search" title="Aucune suggestion" text="Aucun besoin n’est en attente de matching." />
            ) : (
                <>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-4)', padding: 'var(--space-3)', borderRadius: 'var(--radius-xl)', background: 'var(--c-surface-2)', marginBottom: 'var(--space-3)' }}>
                        <div style={{ width: 88, height: 88, flex: 'none', borderRadius: 'var(--radius-round)', background: `conic-gradient(var(--c-primary-600) 0 ${relevance}%,var(--c-primary-200) ${relevance}% 100%)`, display: 'grid', placeItems: 'center' }}>
                            <div style={{ width: 70, height: 70, borderRadius: 'var(--radius-round)', background: 'var(--c-surface-2)', display: 'grid', placeItems: 'center', fontSize: 'var(--fs-2xl)', fontWeight: 'var(--fw-bold)', letterSpacing: '-.4px' }}>{relevance} %</div>
                        </div>
                        <div style={{ flex: 1, minWidth: 0 }}>
                            <div style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>Taux moyen de pertinence</div>
                            <div style={{ display: 'flex', alignItems: 'baseline', gap: 'var(--space-1_5)', flexWrap: 'wrap', marginTop: 'var(--space-1)' }}>
                                <b style={{ fontSize: 'var(--fs-3xl)', lineHeight: 1 }}>{pending}</b>
                                <span style={{ fontSize: 'var(--fs-md)' }}>besoins en attente</span>
                            </div>
                            <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-0_5)', marginTop: 'var(--space-1)', fontSize: 'var(--fs-sm)', fontWeight: 'var(--fw-bold)', color: 'var(--c-success-fg)' }}>
                                <Icon name="north_east" size="var(--icon-xs)" />
                                {delta}
                                <span style={{ fontWeight: 'var(--fw-regular)', color: 'var(--c-muted)', marginLeft: 'var(--space-1)' }}>vs mois dernier</span>
                            </div>
                        </div>
                    </div>

                    <div style={{ fontSize: 'var(--fs-xs)', fontWeight: 'var(--fw-bold)', color: 'var(--c-muted)', textTransform: 'uppercase', letterSpacing: '.06em', marginBottom: 'var(--space-1)' }}>Profils récemment suggérés</div>
                    {profiles.slice(0, 3).map((w) => (
                        <button
                            key={w.name}
                            type="button"
                            onClick={() => onWorker(w)}
                            className="och-hover-soft"
                            style={{ width: '100%', display: 'flex', alignItems: 'center', gap: 'var(--space-3)', padding: 'var(--space-2) var(--space-1_5)', border: 0, borderRadius: 'var(--radius-md)', background: 'transparent', cursor: 'pointer', textAlign: 'left' }}
                        >
                            <Avatar name={w.name} />
                            <span style={{ flex: 1, minWidth: 0 }}>
                                <b style={{ display: 'block', fontSize: 'var(--fs-md)', fontWeight: 'var(--fw-semibold)' }}>{w.name}</b>
                                <span style={{ display: 'block', fontSize: 'var(--fs-sm)', color: 'var(--c-muted)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                                    {w.job} · pour {w.site}
                                </span>
                            </span>
                            <span style={{ display: 'flex', flexDirection: 'column', alignItems: 'flex-end', gap: 'var(--space-1)', width: 72, flex: 'none' }}>
                                <b style={{ fontSize: 'var(--fs-md)', color: 'var(--c-success-fg)' }}>{w.score} %</b>
                                <span style={{ width: '100%', height: 6, borderRadius: 'var(--radius-pill)', background: 'var(--c-line-soft)', overflow: 'hidden' }}>
                                    <span style={{ display: 'block', height: '100%', width: `${w.score}%`, background: 'var(--c-primary-500)' }} />
                                </span>
                            </span>
                        </button>
                    ))}
                </>
            )}
            <div style={{ marginTop: 'auto', paddingTop: 'var(--space-3)', display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2)' }}>
                <Button label="Voir les suggestions" variant="secondary" onClick={onSeeAll} style={{ flex: '1 1 150px' }} />
                <Button label="Lancer un matching" icon="play_arrow" variant="primary" onClick={onLaunch} style={{ flex: '1 1 150px' }} />
            </div>
        </Card>
    );
}
