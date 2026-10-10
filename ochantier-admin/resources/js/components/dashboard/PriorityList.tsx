import Icon from '@/components/ui/Icon';
import StateBlock from '@/components/ui/StateBlock';
import StatusBadge from '@/components/ui/StatusBadge';
import { PRIORITY_TONES, toneColors } from '@/lib/tone';
import type { Priorities } from '@/types/dashboard';
import { Link } from '@inertiajs/react';
import { useState } from 'react';
import Card, { FooterLink } from './Card';

/** Actions prioritaires de l’administrateur. */
export default function PriorityList({ priorities }: { priorities: Priorities }) {
    const [showAll, setShowAll] = useState(false);
    const items = showAll ? [...priorities.items, ...priorities.more] : priorities.items;
    const empty = priorities.items.length === 0;

    return (
        <Card
            component="PriorityList"
            span={4}
            icon="bolt"
            title="Actions prioritaires"
            aside={
                !empty && (
                    <span style={{ padding: 'var(--space-0_5) var(--space-2_5)', borderRadius: 'var(--radius-pill)', background: 'var(--c-danger-bg)', color: 'var(--c-danger-fg)', fontSize: 'var(--fs-sm)', fontWeight: 'var(--fw-bold)', whiteSpace: 'nowrap' }}>
                        {showAll ? priorities.totalAll : priorities.total} à traiter
                    </span>
                )
            }
        >
            {empty ? (
                <StateBlock kind="success" icon="task_alt" title="Aucune action en attente" text="Tout est à jour pour aujourd’hui." />
            ) : (
                <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-2)' }}>
                    {items.map((p) => {
                        const { bg, fg } = toneColors(PRIORITY_TONES[p.priority]);
                        return (
                            <Link
                                key={p.title}
                                href={p.href}
                                className="och-hover-card"
                                style={{ width: '100%', display: 'flex', alignItems: 'center', gap: 'var(--space-3)', padding: 'var(--space-3)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-xl)', background: 'var(--c-surface)', color: 'var(--c-ink)', textDecoration: 'none', minWidth: 0 }}
                            >
                                <span style={{ width: 'var(--control-md)', height: 'var(--control-md)', flex: 'none', borderRadius: 'var(--radius-lg)', display: 'grid', placeItems: 'center', background: bg }}>
                                    <Icon name={p.icon} size="var(--icon-md)" color={fg} />
                                </span>
                                <span style={{ flex: 1, minWidth: 0 }}>
                                    <span style={{ display: 'flex', alignItems: 'baseline', gap: 'var(--space-1_5)', flexWrap: 'wrap' }}>
                                        <b style={{ fontSize: 'var(--fs-2xl)', lineHeight: 1 }}>{p.count}</b>
                                        <span style={{ fontSize: 'var(--fs-base)', fontWeight: 'var(--fw-semibold)' }}>{p.title}</span>
                                    </span>
                                    <span style={{ display: 'block', marginTop: 'var(--space-0_5)', fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>{p.text}</span>
                                </span>
                                <StatusBadge label={p.priority} tone={PRIORITY_TONES[p.priority]} />
                                <Icon name="arrow_forward" color="var(--c-ink-2)" />
                            </Link>
                        );
                    })}
                </div>
            )}
            {!empty && priorities.more.length > 0 && (
                <div style={{ marginTop: 'auto', paddingTop: 'var(--space-3)', display: 'flex', justifyContent: 'center' }}>
                    <FooterLink
                        label={showAll ? 'Afficher moins' : `Voir toutes les priorités (${priorities.items.length + priorities.more.length})`}
                        icon={showAll ? 'expand_less' : 'expand_more'}
                        onClick={() => setShowAll(!showAll)}
                    />
                </div>
            )}
        </Card>
    );
}
