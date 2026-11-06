import FormDialog from '@/components/overlays/FormDialog';
import { RelationTable, stack } from '@/components/promoters/RelationTabs';
import Button from '@/components/ui/Button';
import StatusBadge from '@/components/ui/StatusBadge';
import { Block, KeyValues } from '@/components/workers/blocks';
import { fcfa, formatDate } from '@/lib/format';
import type { CommercialPayoutRow, PayoutCan } from '@/types/payouts';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

type Status = { value: string; label: string; tone: string };

type Payout = CommercialPayoutRow & {
    earnings: { id: string; worker: string | null; amount: number; date: string | null }[];
    adjustments: { id: string; kind: { value: string; label: string }; amount: number; reason: string }[];
    transactions: { id: string; reference: string; amount: number; number: string | null; status: Status; failure: string | null; date: string | null }[];
    canApprove: boolean;
    canRetry: boolean;
    canCancel: boolean;
};

/** Fiche d’un versement à un commercial : ce qu’il contient et ses envois à l’opérateur. */
export default function PaymentCommercialPayout({ payout: p, can }: { payout: Payout; can: PayoutCan }) {
    const [cancelling, setCancelling] = useState(false);
    const base = `/paiements/commerciaux/versements/${p.id}`;
    const post = (action: string) => router.post(`${base}/${action}`, {}, { preserveScroll: true });

    return (
        <>
            <Head title={p.reference} />

            <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 'var(--space-2)', marginTop: -6 }}>
                {can.commercialCreate && p.canCancel && <Button label="Annuler le versement" icon="cancel" variant="danger-soft" onClick={() => setCancelling(true)} />}
                {can.commercialApprove && p.canRetry && <Button label="Renvoyer" icon="refresh" onClick={() => post('renvoi')} />}
                {can.commercialApprove && p.canApprove && <Button label={`Valider et envoyer ${fcfa(p.amount)}`} icon="payments" onClick={() => post('validation')} />}
            </div>

            <div style={stack}>
                <Block icon="receipt_long" title={p.reference}>
                    <KeyValues
                        items={[
                            ['Statut', <StatusBadge label={p.status.label} tone={p.status.tone as never} />],
                            ['Commercial', <Link href={`/commerciaux/${p.commercial.id}/gains`}>{p.commercial.full_name}</Link>],
                            ['Période', `${formatDate(p.periodStart)} au ${formatDate(p.periodEnd)}`],
                            ['Montant', fcfa(p.amount)],
                            ['Préparé par', p.preparedBy],
                            ['Validé par', p.approvedBy],
                            ['Payé le', p.paidAt],
                        ]}
                    />
                </Block>

                <Block icon="payments" title="Commissions">
                    <RelationTable columns={['Ouvrier', 'Date', 'Montant']} empty={{ icon: 'payments', title: 'Aucune commission', text: 'Ce versement ne contient plus de commission.' }} rows={p.earnings.map((e) => ({ key: e.id, cells: [e.worker ?? '—', e.date ?? '—', fcfa(e.amount)] }))} />
                </Block>

                {p.adjustments.length > 0 && (
                    <Block icon="tune" title="Primes et retenues">
                        <RelationTable columns={['Nature', 'Montant', 'Motif']} empty={{ icon: 'tune', title: '', text: '' }} rows={p.adjustments.map((a) => ({ key: a.id, cells: [a.kind.label, fcfa(a.amount), a.reason] }))} />
                    </Block>
                )}

                <Block icon="send" title="Envois à l’opérateur">
                    <RelationTable
                        columns={['Référence', 'Date', 'Numéro', 'Montant', 'Statut', 'Motif']}
                        empty={{ icon: 'send', title: 'Pas encore envoyé', text: 'L’envoi part à la validation par une autre personne.' }}
                        rows={p.transactions.map((t) => ({ key: t.id, cells: [t.reference, t.date ?? '—', t.number ?? '—', fcfa(t.amount), <StatusBadge label={t.status.label} tone={t.status.tone as never} />, t.failure ?? '—'] }))}
                    />
                </Block>
            </div>

            {cancelling && <FormDialog title="Annuler ce versement ?" body="Les commissions et ajustements redeviennent dus." icon="cancel" tone="danger" okLabel="Annuler le versement" url={`${base}/annulation`} onClose={() => setCancelling(false)} fields={[{ name: 'reason', label: 'Motif', type: 'textarea', required: true }]} />}
        </>
    );
}
