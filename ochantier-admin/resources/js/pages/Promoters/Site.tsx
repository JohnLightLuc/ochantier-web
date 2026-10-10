import Avatar from '@/components/ui/Avatar';
import Button from '@/components/ui/Button';
import Icon from '@/components/ui/Icon';
import Panel, { LinkRow } from '@/components/ui/Panel';
import StatCard from '@/components/ui/StatCard';
import StateBlock from '@/components/ui/StateBlock';
import StatusBadge from '@/components/ui/StatusBadge';
import { useAdminUi } from '@/contexts/AdminUiContext';
import { formatNumber } from '@/lib/format';
import type { Badge } from '@/types';
import type { DemoOffer, DemoPayment, DemoSite } from '@/types/promoters';
import { Head, Link, router } from '@inertiajs/react';

type Props = {
    promoter: { slug: string; name: string };
    site: DemoSite;
    paid: number;
    milestones: { label: string; state: 'done' | 'current' | 'todo' }[];
    team: ({ name: string; job: string } & Badge)[];
    offers: DemoOffer[];
    payments: DemoPayment[];
};

const MILESTONE = {
    done: ['check_circle', 'var(--c-success)', 'Terminé'],
    current: ['pending', 'var(--c-accent-600)', 'En cours'],
    todo: ['radio_button_unchecked', 'var(--c-subtle)', 'À venir'],
} as const;

/** Détail d’un chantier vu depuis la fiche promoteur. */
export default function PromoterSite({ promoter, site, paid, milestones, team, offers, payments }: Props) {
    const ui = useAdminUi();
    const base = `/promoteurs/${promoter.slug}`;
    const filtered = (tab: string) => `${base}/${tab}?chantier=${encodeURIComponent(site.name)}`;

    return (
        <>
            <Head title={site.name} />
            <div>
                <Button label={`Retour à la fiche ${promoter.name}`} icon="arrow_back" variant="ghost" onClick={() => router.visit(`${base}/chantiers`)} />
            </div>

            <section style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-5)', alignItems: 'flex-start', background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-5)' }}>
                <div style={{ width: 180, height: 120, flex: 'none', borderRadius: 'var(--radius-xl)', background: 'repeating-linear-gradient(135deg,var(--c-surface-3) 0 10px,var(--c-line-soft) 10px 20px)', border: '1px solid var(--c-line)', display: 'grid', placeItems: 'center', fontFamily: 'ui-monospace,Menlo,monospace', fontSize: 'var(--fs-xs)', color: 'var(--c-muted)' }}>photo chantier</div>
                <div style={{ flex: '1 1 320px', minWidth: 0 }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2_5)', flexWrap: 'wrap' }}>
                        <h2 style={{ margin: 0, fontSize: 'var(--fs-4xl)', letterSpacing: '-.4px' }}>{site.name}</h2>
                        <StatusBadge label={site.status} tone={site.statusTone} />
                    </div>
                    <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2) var(--space-5)', marginTop: 'var(--space-3)' }}>
                        {[
                            ['location_on', 'Localisation', site.location],
                            ['domain', 'Type', site.type],
                            ['date_range', 'Période', site.period],
                        ].map(([icon, label, value]) => (
                            <span key={label} style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-1_5)', fontSize: 'var(--fs-md)', color: 'var(--c-muted)' }}>
                                <Icon name={icon} size="var(--icon-xs)" color="var(--c-primary-600)" />
                                {label} : <b style={{ color: 'var(--c-ink)', fontWeight: 'var(--fw-semibold)' }}>{value}</b>
                            </span>
                        ))}
                    </div>
                    <div style={{ marginTop: 'var(--space-2)', fontSize: 'var(--fs-md)' }}>
                        Promoteur :{' '}
                        <Link href={base} style={{ fontWeight: 'var(--fw-semibold)' }}>
                            {promoter.name}
                        </Link>
                    </div>
                </div>
                <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2)' }}>
                    <Button label="Modifier" icon="edit" variant="secondary" onClick={() => ui.toast('L’édition du chantier se fait depuis le module Chantiers.', { tone: 'info' })} />
                    <Button label="Consulter les besoins" icon="assignment" variant="secondary" onClick={() => router.visit(filtered('offres'))} />
                    <Button label="Consulter les pointages" icon="schedule" variant="secondary" onClick={() => router.visit('/pointages')} />
                </div>
            </section>

            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(230px,1fr))', gap: 'var(--space-4)' }}>
                <StatCard label="Ouvriers affectés" value={String(site.workers)} icon="groups" tone="g" />
                <StatCard label="Progression" value={`${site.progress} %`} icon="trending_up" tone={site.progress < 50 ? 'a' : 'g'} />
                <StatCard label="Montant payé" value={formatNumber(paid)} unit="FCFA" icon="payments" tone="a" />
                <StatCard label="Besoins liés" value={String(offers.length)} icon="assignment" tone="b" />
            </div>

            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,380px),1fr))', gap: 'var(--space-4)' }}>
                <Panel variant="section" icon="trending_up" title="Avancement" aside={<b style={{ fontSize: 'var(--fs-2xl)' }}>{site.progress} %</b>}>
                    <div style={{ height: 10, borderRadius: 'var(--radius-pill)', background: 'var(--c-line-soft)', overflow: 'hidden', marginBottom: 'var(--space-4)' }}>
                        <div style={{ height: '100%', background: site.progress < 50 ? 'var(--c-accent-600)' : 'var(--c-primary-500)', width: `${site.progress}%` }} />
                    </div>
                    {milestones.map((m) => {
                        const [icon, color, state] = MILESTONE[m.state];
                        return (
                            <div key={m.label} style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2_5)', padding: 'var(--space-2) 0', borderTop: '1px solid var(--c-line-soft)' }}>
                                <Icon name={icon} color={color} />
                                <span style={{ flex: 1, fontWeight: m.state === 'current' ? 700 : 400 }}>{m.label}</span>
                                <span style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>{state}</span>
                            </div>
                        );
                    })}
                </Panel>

                <Panel variant="section" icon="groups" title="Équipe affectée">
                    {team.map((w) => (
                        <div key={w.name} style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-3)', padding: 'var(--space-2) 0', borderTop: '1px solid var(--c-line-soft)' }}>
                            <Avatar name={w.name} />
                            <span style={{ flex: 1 }}>
                                <b style={{ display: 'block' }}>{w.name}</b>
                                <span style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>{w.job}</span>
                            </span>
                            <StatusBadge label={w.label} tone={w.tone} />
                        </div>
                    ))}
                    {site.workers > 4 && <div style={{ paddingTop: 'var(--space-2)', fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>+ {site.workers - 4} autres ouvriers affectés</div>}
                    {site.workers === 0 && <StateBlock kind="nodata" compact icon="group_off" title="Aucun ouvrier affecté" text="Les affectations apparaîtront ici dès validation." />}
                </Panel>

                <Panel variant="section" icon="assignment" title="Besoins liés">
                    {offers.map((o) => (
                        <LinkRow key={o.id} href={`${base}/offres/${o.id}`} title={o.name} sub={`${o.trade} · ${o.applications} candidats`} aside={<StatusBadge label={o.status} tone={o.statusTone} />} />
                    ))}
                    {offers.length === 0 && <StateBlock kind="nodata" compact icon="assignment" title="Aucun besoin" text="Aucun besoin n’a été publié pour ce chantier." />}
                </Panel>

                <Panel variant="section" icon="receipt_long" title="Paiements liés">
                    {payments.map((x) => (
                        <LinkRow key={x.ref} href={`${base}/paiements/${x.ref}`} title={<span style={{ fontVariantNumeric: 'tabular-nums' }}>{formatNumber(x.amount)} FCFA</span>} sub={`${x.ref} · ${x.type}`} aside={<StatusBadge label={x.status} tone={x.statusTone} />} />
                    ))}
                    {payments.length === 0 && <StateBlock kind="nodata" compact icon="receipt_long" title="Aucun paiement" text="Aucune transaction liée à ce chantier." />}
                </Panel>
            </div>
        </>
    );
}
