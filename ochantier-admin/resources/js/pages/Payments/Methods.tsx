import Pagination from '@/components/list/Pagination';
import FormDialog from '@/components/overlays/FormDialog';
import PaymentNav from '@/components/payments/PaymentNav';
import { RelationTable } from '@/components/promoters/RelationTabs';
import type { PayoutCan } from '@/types/payouts';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

type Row = { id: string; operator: string | null; number: string; proposed_by: string | null; proposed_at: string | null; can_decide: boolean; worker: { id: string; reference: string; full_name: string } };

type Props = { methods: { data: Row[]; currentPage: number; lastPage: number; total: number; from: number; to: number }; can: PayoutCan };

/** Numéros Mobile Money saisis, en attente de la confirmation d’une autre personne. */
export default function PaymentMethods({ methods, can }: Props) {
    const [rejecting, setRejecting] = useState<Row | null>(null);
    const url = (m: Row) => `/paiements/ouvriers/${m.worker.id}/numero/${m.id}`;

    return (
        <>
            <Head title="Numéros à confirmer" />

            <div style={{ display: 'flex', justifyContent: 'flex-end', flexWrap: 'wrap', gap: 'var(--space-2)', marginTop: -6 }}>
                <PaymentNav current="/paiements/numeros" payouts />
            </div>

            <section data-component="ListPanel" style={{ background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-5) var(--space-6)' }}>
                <RelationTable
                    columns={['Ouvrier', 'Opérateur', 'Numéro', 'Saisi par', 'Saisi le']}
                    empty={{ icon: 'phone_iphone', title: 'Aucun numéro à confirmer', text: 'Les numéros saisis sur les fiches ouvriers apparaissent ici.' }}
                    rows={methods.data.map((m) => ({
                        key: m.id,
                        cells: [m.worker.full_name, m.operator ?? '—', m.number, m.proposed_by ?? '—', m.proposed_at ?? '—'],
                        actions: [
                            { icon: 'visibility', label: 'Voir la fiche', onClick: () => router.visit(`/ouvriers/${m.worker.id}/paiements`) },
                            ...(can.approve && m.can_decide
                                ? [
                                      { icon: 'check_circle', label: 'Confirmer', onClick: () => router.post(`${url(m)}/confirmation`, {}, { preserveScroll: true }) },
                                      { icon: 'close', label: 'Refuser', onClick: () => setRejecting(m) },
                                  ]
                                : []),
                        ],
                    }))}
                />
                <Pagination page={methods.currentPage} lastPage={methods.lastPage} from={methods.from} to={methods.to} total={methods.total} onPage={(page) => router.get('/paiements/numeros', { page }, { preserveScroll: true })} />
            </section>

            {rejecting && <FormDialog title="Refuser ce numéro ?" icon="close" tone="danger" okLabel="Refuser" url={`${url(rejecting)}/refus`} onClose={() => setRejecting(null)} fields={[{ name: 'reason', label: 'Motif', type: 'textarea', required: true }]} />}
        </>
    );
}
