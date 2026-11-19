import PaymentNav from '@/components/payments/PaymentNav';
import Pagination from '@/components/list/Pagination';
import { RelationTable, stack } from '@/components/promoters/RelationTabs';
import StatCard from '@/components/ui/StatCard';
import StatusBadge from '@/components/ui/StatusBadge';
import { Block } from '@/components/workers/blocks';
import { fcfa } from '@/lib/format';
import type { PayoutCan } from '@/types/payouts';
import { Head, router } from '@inertiajs/react';

type Status = { value: string; label: string; tone: string };

type PayableRow = {
    contractor: { id: string; reference: string; name: string };
    items_count: number;
    total: number;
    number: string | null;
    operator: string | null;
    blockers: string[];
    items: { source_key: string; reference: string; label: string; site: string; amount: number; date: string | null }[];
};

type PayoutRow = { id: string; reference: string; contractor: { id: string; reference: string; name: string } | null; amount: number; itemsCount: number; status: Status; preparedBy: string | null; createdAt: string | null; paidAt: string | null };

type Props = {
    payable: PayableRow[];
    payouts: { data: PayoutRow[]; currentPage: number; lastPage: number; total: number };
    filters: { statut?: string };
    kpis: { ready: number; blocked: number; contractors: number };
    can: PayoutCan;
};

/** Professionnels O’CHANTIER PRO à payer (parts nettes dues après le service) et leurs versements. */
export default function PaymentContractorPayouts({ payable, payouts, kpis, can }: Props) {
    const prepare = (r: PayableRow) => router.post('/paiements/professionnels/versements', { contractor_id: r.contractor.id });
    const from = payouts.total ? (payouts.currentPage - 1) * 20 + 1 : 0;

    return (
        <>
            <Head title="Professionnels à payer" />

            <div style={{ display: 'flex', justifyContent: 'flex-end', flexWrap: 'wrap', gap: 'var(--space-2)', marginTop: -6 }}>
                <PaymentNav current="/paiements/professionnels" can={can} />
            </div>

            <div data-component="StatGrid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(230px,1fr))', gap: 'var(--space-4)' }}>
                <StatCard label="Prêt à verser" value={fcfa(kpis.ready)} icon="savings" tone="g" hint={`${kpis.contractors} professionnel(s) avec une part due`} />
                <StatCard label="Bloqué" value={fcfa(kpis.blocked)} icon="block" tone="r" hint="Numéro à confirmer ou versement déjà en cours" />
            </div>

            <div style={stack}>
                <Block icon="savings" title="Parts nettes dues">
                    <RelationTable
                        columns={['Professionnel', 'Parts', 'À verser', 'Numéro', 'État']}
                        empty={{ icon: 'handyman', title: 'Rien à verser', text: 'Aucune part nette n’attend de versement : frais de visite après la visite faite, forfait au fil des échéances payées.' }}
                        rows={payable.map((r) => ({
                            key: r.contractor.id,
                            cells: [
                                `${r.contractor.name} (${r.contractor.reference})`,
                                <span style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-1)' }}>
                                    {r.items.map((i) => (
                                        <span key={i.source_key} style={{ fontSize: 'var(--fs-sm)' }}>
                                            {i.reference} · {i.label} · <b>{fcfa(i.amount)}</b>
                                        </span>
                                    ))}
                                </span>,
                                fcfa(r.total),
                                r.number ? `${r.operator ?? ''} ${r.number}`.trim() : '—',
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
                                { icon: 'visibility', label: 'Voir le professionnel', onClick: () => router.visit(`/entrepreneurs/${r.contractor.id}/versements`) },
                                ...(can.create && r.blockers.length === 0 ? [{ icon: 'receipt_long', label: 'Préparer le versement', onClick: () => prepare(r) }] : []),
                            ],
                        }))}
                    />
                </Block>

                <Block icon="receipt_long" title="Versements" count={`${payouts.total}`}>
                    <RelationTable
                        columns={['Référence', 'Professionnel', 'Parts', 'Montant', 'Statut', 'Préparé', 'Payé le']}
                        empty={{ icon: 'receipt_long', title: 'Aucun versement', text: 'Les versements préparés aux professionnels apparaîtront ici.' }}
                        rows={payouts.data.map((p) => ({
                            key: p.id,
                            cells: [p.reference, p.contractor?.name ?? '—', String(p.itemsCount), fcfa(p.amount), <StatusBadge label={p.status.label} tone={p.status.tone as never} />, [p.createdAt, p.preparedBy].filter(Boolean).join(' · ') || '—', p.paidAt ?? '—'],
                            actions: [{ icon: 'visibility', label: 'Voir le versement', onClick: () => router.visit(`/paiements/professionnels/versements/${p.id}`) }],
                        }))}
                    />
                    {payouts.lastPage > 1 && <Pagination page={payouts.currentPage} lastPage={payouts.lastPage} from={from} to={from + payouts.data.length - 1} total={payouts.total} onPage={(page) => router.get('/paiements/professionnels', { page }, { preserveScroll: true })} />}
                </Block>
            </div>
        </>
    );
}
