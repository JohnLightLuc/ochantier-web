import FormDialog from '@/components/overlays/FormDialog';
import { RelationTable, stack } from '@/components/promoters/RelationTabs';
import Button from '@/components/ui/Button';
import StatusBadge from '@/components/ui/StatusBadge';
import { Block, KeyValues } from '@/components/workers/blocks';
import { fcfa } from '@/lib/format';
import type { PayoutCan } from '@/types/payouts';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

type Status = { value: string; label: string; tone: string };

type Payout = {
    id: string;
    reference: string;
    contractor: { id: string; reference: string; name: string } | null;
    amount: number;
    itemsCount: number;
    status: Status;
    preparedBy: string | null;
    approvedBy: string | null;
    createdAt: string | null;
    paidAt: string | null;
    cancellationReason: string | null;
    canApprove: boolean;
    canRetry: boolean;
    canCancel: boolean;
    method: { operator: string | null; number: string } | null;
    items: { source_key: string; reference: string; label: string; site_label: string | null; amount: number }[];
    transactions: { reference: string; status: Status; amount: number; failure_message: string | null; date: string | null }[];
};

/** Fiche d’un versement à un professionnel : parts nettes réunies, numéro, envois à l’opérateur et décisions. */
export default function PaymentContractorPayout({ payout: p, can }: { payout: Payout; can: PayoutCan }) {
    const [cancelling, setCancelling] = useState(false);
    const base = `/paiements/professionnels/versements/${p.id}`;
    const post = (action: string) => router.post(`${base}/${action}`, {}, { preserveScroll: true });

    return (
        <>
            <Head title={p.reference} />

            <div style={{ display: 'flex', justifyContent: 'flex-end', flexWrap: 'wrap', gap: 'var(--space-2)', marginTop: -6 }}>
                {can.create && p.canCancel && <Button label="Annuler le versement" icon="cancel" variant="danger-soft" onClick={() => setCancelling(true)} />}
                {can.approve && p.canRetry && <Button label="Renvoyer" icon="refresh" onClick={() => post('renvoi')} />}
                {can.approve && p.canApprove && <Button label={`Valider et envoyer ${fcfa(p.amount)}`} icon="payments" onClick={() => post('validation')} />}
            </div>

            <div style={stack}>
                <Block icon="receipt_long" title={p.reference}>
                    <KeyValues
                        items={[
                            ['Statut', <StatusBadge label={p.status.label} tone={p.status.tone as never} />],
                            ['Professionnel', p.contractor ? <Link href={`/entrepreneurs/${p.contractor.id}/versements`}>{p.contractor.name}</Link> : '—'],
                            ['Montant', fcfa(p.amount)],
                            ['Numéro', p.method ? `${p.method.operator ?? ''} ${p.method.number}`.trim() : 'Choisi à l’envoi (numéro confirmé)'],
                            ['Préparé', [p.createdAt, p.preparedBy].filter(Boolean).join(' · ') || null],
                            ['Validé par', p.approvedBy],
                            ['Payé le', p.paidAt],
                            ['Motif d’annulation', p.cancellationReason],
                        ]}
                    />
                    {p.status.value === 'a_payer' && !p.canApprove && <p style={{ margin: 'var(--space-3) 0 0', fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>Une autre personne que celle qui l’a préparé doit valider ce versement.</p>}
                </Block>

                <Block icon="handyman" title="Parts nettes" count={`${p.items.length}`}>
                    <RelationTable
                        columns={['Référence', 'Opération', 'Chantier', 'Montant']}
                        empty={{ icon: 'handyman', title: 'Aucune part', text: 'Ce versement a été annulé : ses parts redeviennent dues.' }}
                        rows={p.items.map((i) => ({ key: i.source_key, cells: [i.reference, i.label, i.site_label ?? '—', fcfa(i.amount)] }))}
                    />
                </Block>

                <Block icon="send" title="Envois à l’opérateur">
                    <RelationTable
                        columns={['Référence', 'Date', 'Montant', 'Statut', 'Motif']}
                        empty={{ icon: 'send', title: 'Pas encore envoyé', text: 'L’envoi part à la validation par une autre personne.' }}
                        rows={p.transactions.map((t) => ({ key: t.reference, cells: [t.reference, t.date ?? '—', fcfa(t.amount), <StatusBadge label={t.status.label} tone={t.status.tone as never} />, t.failure_message ?? '—'] }))}
                    />
                </Block>
            </div>

            {cancelling && <FormDialog title="Annuler ce versement ?" body="Ses parts nettes redeviennent dues au professionnel." icon="cancel" tone="danger" okLabel="Annuler le versement" url={`${base}/annulation`} onClose={() => setCancelling(false)} fields={[{ name: 'reason', label: 'Motif', type: 'textarea', required: true }]} />}
        </>
    );
}
