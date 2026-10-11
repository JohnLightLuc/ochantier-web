import { PaymentLayout, SegmentBar, useApply } from '@/components/payments/blocks';
import OperationList from '@/components/payments/OperationList';
import { fcfa, usePaymentActions } from '@/components/payments/usePaymentActions';
import Icon from '@/components/ui/Icon';
import StatCard from '@/components/ui/StatCard';
import { AlertList } from '@/components/workers/blocks';
import type { AnyTone } from '@/types';
import type { ListProps, PaymentPageProps, Segment } from '@/types/payments';
import { Head, Link, router } from '@inertiajs/react';

type Group = { title: string; sub: string; icon: string; tone: string; total: number; totalLabel: string; rows: { key: string; label: string; value: number; count: number; color: string }[] };

type Props = PaymentPageProps &
    ListProps & {
        kpis: { key: string | null; label: string; value: number; icon: string; tone: string; hint: string; highlight?: boolean; href?: string }[];
        groups: Group[];
        segments: Segment[];
        todo: { label: string; icon: string; count: number; href: string }[];
        configured: boolean;
    };

/** Vue d’ensemble des paiements : commissions nettes, encaissements, reversements, répartition, travail à faire et opérations. */
export default function PaymentsIndex(props: Props) {
    const { kpis, groups, segments, todo, configured, filters, period, options } = props;
    const actions = usePaymentActions(options);
    const apply = useApply(filters);
    const toggle = (key: string) => apply({ raccourci: filters.raccourci === key ? undefined : key });

    const top = (
        <>
            <div data-component="StatGrid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(230px,1fr))', gap: 'var(--space-3)' }}>
                {kpis.map((k) => {
                    const on = !!k.key && filters.raccourci === k.key;
                    return (
                        <button key={k.label} type="button" aria-pressed={on} onClick={() => (k.href ? router.visit(k.href) : k.key && toggle(k.key))} className="och-hover-card" style={{ display: 'block', minWidth: 0, padding: 0, border: 0, background: 'transparent', textAlign: 'left', borderRadius: 'var(--radius-2xl)', cursor: 'pointer' }}>
                            <StatCard label={k.label} value={fcfa(k.value)} icon={k.icon} tone={k.tone as AnyTone} hint={k.href ? k.hint : `${k.hint} · ${on ? 'filtre actif' : 'cliquer pour filtrer'}`} highlight={k.highlight || on} />
                        </button>
                    );
                })}
            </div>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,320px),1fr))', gap: 'var(--space-3)' }}>
                {groups.map((g) => (
                    <section key={g.title} style={{ border: '1px solid var(--c-line)', borderRadius: 'var(--radius-xl)', background: 'var(--c-surface)', padding: 'var(--space-4)', display: 'flex', flexDirection: 'column', gap: 'var(--space-2)' }}>
                        <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2_5)' }}>
                            <span style={{ width: 36, height: 36, borderRadius: 'var(--radius-lg)', display: 'grid', placeItems: 'center', background: `var(--c-${g.tone}-bg)`, color: `var(--c-${g.tone}-fg)` }}>
                                <Icon name={g.icon} />
                            </span>
                            <div style={{ flex: 1, minWidth: 0 }}>
                                <b>{g.title}</b>
                                <div style={{ fontSize: 'var(--fs-xs)', color: 'var(--c-muted)' }}>{g.sub}</div>
                            </div>
                        </div>
                        <div style={{ fontSize: 'var(--fs-xl)', fontWeight: 'var(--fw-bold)' }}>
                            {fcfa(g.total)} <span style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)', fontWeight: 'var(--fw-semibold)' }}>{g.totalLabel}</span>
                        </div>
                        {g.rows.map((r) => {
                            const on = filters.raccourci === r.key;
                            return (
                                <button key={r.key} type="button" aria-pressed={on} onClick={() => toggle(r.key)} style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)', padding: 'var(--space-1_5) var(--space-2)', border: 0, borderRadius: 'var(--radius-md)', background: on ? 'var(--c-primary-50)' : 'transparent', cursor: 'pointer', font: 'inherit', textAlign: 'left' }}>
                                    <span style={{ width: 8, height: 8, borderRadius: 'var(--radius-round)', background: r.color }} />
                                    <span style={{ flex: 1, fontWeight: on ? 700 : 500 }}>{r.label}</span>
                                    <span style={{ fontSize: 'var(--fs-xs)', color: 'var(--c-muted)' }}>{r.count} op.</span>
                                    <b style={{ fontVariantNumeric: 'tabular-nums' }}>{fcfa(r.value)}</b>
                                </button>
                            );
                        })}
                    </section>
                ))}
            </div>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,420px),1fr))', gap: 'var(--space-3)' }}>
                {segments.map((s) => (
                    <div key={s.title} style={{ background: 'var(--c-surface)', borderRadius: 'var(--radius-xl)' }}>
                        <SegmentBar segment={s} />
                    </div>
                ))}
            </div>
            <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2)', alignItems: 'center' }}>
                <span style={{ fontSize: 'var(--fs-sm)', fontWeight: 'var(--fw-bold)', color: 'var(--c-ink-2)', marginRight: 'var(--space-1)' }}>À traiter</span>
                {todo.map((t) => (
                    <Link key={t.label} href={t.href} style={{ display: 'inline-flex', alignItems: 'center', gap: 'var(--space-1_5)', height: 'var(--control-sm)', padding: '0 var(--space-3)', border: `1px solid ${t.count ? 'var(--c-warning-fg)' : 'var(--c-line-strong)'}`, borderRadius: 'var(--radius-pill)', background: t.count ? 'var(--c-warning-bg)' : 'var(--c-surface)', color: t.count ? 'var(--c-warning-fg)' : 'var(--c-muted)', fontWeight: 'var(--fw-semibold)', fontSize: 'var(--fs-sm)', textDecoration: 'none' }}>
                        <Icon name={t.icon} size="var(--icon-xs)" />
                        {t.label}
                        <b>{t.count}</b>
                    </Link>
                ))}
            </div>
        </>
    );

    return (
        <>
            <Head title="Paiements" />
            <PaymentLayout
                {...props}
                top={top}
                before={
                    <AlertList
                        alerts={[
                            ...(!configured ? [{ tone: 'danger', icon: 'settings_alert', title: 'Commission non configurée', text: 'Aucun taux Y/Z actif dans Paramètres : les calculs ne peuvent pas être validés.', cta: 'Paramètres', onAction: () => router.visit('/parametres') }] : []),
                            { tone: 'info', icon: 'autorenew', title: 'Traitement automatique', text: 'Les paiements des promoteurs et les commissions O’CHANTIER sont prélevés et encaissés automatiquement. Le Back-office traite uniquement les reversements aux ouvriers et les remboursements aux promoteurs. Les taux de commission se modifient uniquement dans Paramètres.', cta: 'Paramètres', onAction: () => router.visit('/parametres') },
                        ]}
                    />
                }
            >
                <OperationList {...props} title="Opérations financières" periodLabel={period.label} actions={actions} exportView="operations" selectable />
            </PaymentLayout>
            {actions.dialog}
        </>
    );
}
