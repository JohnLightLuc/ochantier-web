import FormDialog from '@/components/overlays/FormDialog';
import { RelationTable, stack } from '@/components/promoters/RelationTabs';
import Button from '@/components/ui/Button';
import StatCard from '@/components/ui/StatCard';
import StatusBadge from '@/components/ui/StatusBadge';
import { Block, KeyValues } from '@/components/workers/blocks';
import { fcfa } from '@/lib/format';
import type { BatchRow, PayoutCan, PayoutRow } from '@/types/payouts';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

type Batch = BatchRow & { pendingAdjustments: number; payouts: PayoutRow[]; canSubmit: boolean; canCancel: boolean; canApprove: boolean; canResume: boolean };

/** Fiche d’un lot : ses reversements et les étapes jusqu’à l’exécution. */
export default function PaymentBatch({ batch: b, can }: { batch: Batch; can: PayoutCan }) {
    const [rejecting, setRejecting] = useState(false);
    const base = `/paiements/lots/${b.id}`;
    const post = (action: string) => router.post(`${base}/${action}`, {}, { preserveScroll: true });

    return (
        <>
            <Head title={b.reference} />

            <div style={{ display: 'flex', justifyContent: 'flex-end', flexWrap: 'wrap', gap: 'var(--space-2)', marginTop: -6 }}>
                {can.create && b.canCancel && <Button label="Annuler le lot" icon="cancel" variant="danger-soft" onClick={() => post('annulation')} />}
                {can.create && b.canSubmit && <Button label="Soumettre à validation" icon="send" onClick={() => post('soumission')} />}
                {can.approve && b.canApprove && <Button label="Renvoyer en préparation" icon="undo" variant="secondary" onClick={() => setRejecting(true)} />}
                {can.approve && b.canApprove && <Button label={`Valider et envoyer ${fcfa(b.net)}`} icon="payments" onClick={() => post('validation')} />}
                {can.approve && b.canResume && <Button label="Reprendre l’exécution" icon="play_arrow" onClick={() => post('reprise')} />}
            </div>

            <div data-component="StatGrid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(230px,1fr))', gap: 'var(--space-4)' }}>
                <StatCard label="Net à envoyer" value={fcfa(b.net)} icon="savings" tone="g" hint={`${b.count} reversement(s)`} />
                <StatCard label="Commission" value={fcfa(b.commission)} icon="percent" tone="b" />
                <StatCard label="Propositions à décider" value={String(b.pendingAdjustments)} icon="tune" tone="a" />
            </div>

            <div style={stack}>
                <Block icon="inventory_2" title={b.reference}>
                    <KeyValues
                        items={[
                            ['Statut', <StatusBadge label={b.status.label} tone={b.status.tone as never} />],
                            ['Préparé par', b.preparedBy],
                            ['Préparé le', b.createdAt],
                            ['Validé par', b.approvedBy],
                            ['Exécuté le', b.executedAt],
                            ['Résultat', b.results?.reussis !== undefined ? `${b.results.reussis} réussi(s), ${b.results.echoues ?? 0} échoué(s)` : null],
                        ]}
                    />
                </Block>

                <Block icon="account_balance_wallet" title="Reversements du lot">
                    <RelationTable
                        columns={['Référence', 'Ouvrier', 'Chantier', 'Journées', 'Net', 'Numéro', 'Statut']}
                        empty={{ icon: 'account_balance_wallet', title: 'Aucun reversement', text: 'Ce lot est vide.' }}
                        rows={b.payouts.map((p) => ({
                            key: p.id,
                            cells: [p.reference, p.worker.full_name, p.site?.label ?? '—', p.days, fcfa(p.net), p.number ?? '—', <StatusBadge label={p.status.label} tone={p.status.tone as never} />],
                            actions: [{ icon: 'visibility', label: 'Voir le reversement', onClick: () => router.visit(`/paiements/reversements/${p.id}`) }],
                        }))}
                    />
                </Block>
            </div>

            {rejecting && <FormDialog title="Renvoyer ce lot en préparation ?" icon="undo" okLabel="Renvoyer" url={`${base}/renvoi`} onClose={() => setRejecting(false)} fields={[{ name: 'reason', label: 'Motif', type: 'textarea', required: true }]} />}
        </>
    );
}
