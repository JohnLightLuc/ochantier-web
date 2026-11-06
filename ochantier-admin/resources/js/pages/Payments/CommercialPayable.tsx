import PaymentNav from '@/components/payments/PaymentNav';
import { RelationTable } from '@/components/promoters/RelationTabs';
import Field, { TextInput } from '@/components/ui/Field';
import StatCard from '@/components/ui/StatCard';
import StatusBadge from '@/components/ui/StatusBadge';
import { fcfa } from '@/lib/format';
import type { PayoutCan } from '@/types/payouts';
import { Head, router } from '@inertiajs/react';

type Row = {
    commercial: { id: string; reference: string; full_name: string };
    earnings_count: number;
    commissions: number;
    bonuses: number;
    deductions: number;
    total: number;
    number: string | null;
    blockers: string[];
};

type Props = {
    rows: Row[];
    month: string;
    monthFinished: boolean;
    kpis: { ready: number; blocked: number; ready_amount: number; blocked_amount: number };
    can: PayoutCan;
};

/** Commerciaux à payer : ce qui reste dû à la fin d’un mois terminé. */
export default function PaymentCommercialPayable({ rows, month, monthFinished, kpis, can }: Props) {
    const prepare = (r: Row) => router.post('/paiements/commerciaux/versements', { commercial_id: r.commercial.id, month });

    return (
        <>
            <Head title="Commerciaux à payer" />

            <div style={{ display: 'flex', justifyContent: 'flex-end', flexWrap: 'wrap', gap: 'var(--space-2)', marginTop: -6 }}>
                <PaymentNav current="/paiements/commerciaux" can={can} />
            </div>

            <div data-component="StatGrid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(230px,1fr))', gap: 'var(--space-4)' }}>
                <StatCard label="Prêt à verser" value={fcfa(kpis.ready_amount)} icon="savings" tone="g" hint={`${kpis.ready} commercial(aux)`} />
                <StatCard label="Bloqué" value={fcfa(kpis.blocked_amount)} icon="block" tone="r" hint={`${kpis.blocked} commercial(aux)`} />
            </div>

            <section data-component="ListPanel" style={{ background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-5) var(--space-6)' }}>
                <div style={{ maxWidth: 220, marginBottom: 'var(--space-3)' }}>
                    <Field label="Dû à la fin du mois" hint={monthFinished ? undefined : 'Ce mois n’est pas terminé : rien ne peut encore être préparé.'}>
                        <TextInput type="month" value={month} onChange={(e) => e.target.value && router.get('/paiements/commerciaux', { mois: e.target.value }, { preserveScroll: true, replace: true })} />
                    </Field>
                </div>

                <RelationTable
                    columns={['Commercial', 'Commissions', 'Primes', 'Retenues', 'À verser', 'Numéro', 'État']}
                    empty={{ icon: 'badge', title: 'Rien à verser', text: 'Aucune commission confirmée ni prime approuvée n’attend de versement pour ce mois.' }}
                    rows={rows.map((r) => ({
                        key: r.commercial.id,
                        cells: [
                            `${r.commercial.full_name} (${r.commercial.reference})`,
                            `${fcfa(r.commissions)} · ${r.earnings_count}`,
                            fcfa(r.bonuses),
                            fcfa(r.deductions),
                            fcfa(r.total),
                            r.number ?? '—',
                            r.blockers.length === 0 ? (
                                <StatusBadge label="Prêt" tone="g" />
                            ) : (
                                <span style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-1)' }}>
                                    <StatusBadge label="Bloqué" tone="r" />
                                    {r.blockers.map((b) => (
                                        <span key={b} style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>
                                            {b}
                                        </span>
                                    ))}
                                </span>
                            ),
                        ],
                        actions: [
                            { icon: 'visibility', label: 'Voir les gains', onClick: () => router.visit(`/commerciaux/${r.commercial.id}/gains`) },
                            ...(can.commercialCreate && monthFinished && r.blockers.length === 0 ? [{ icon: 'receipt_long', label: 'Préparer le versement', onClick: () => prepare(r) }] : []),
                        ],
                    }))}
                />
            </section>
        </>
    );
}
