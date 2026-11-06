import FormDialog from '@/components/overlays/FormDialog';
import { RelationTable, stack } from '@/components/promoters/RelationTabs';
import Button from '@/components/ui/Button';
import StatCard from '@/components/ui/StatCard';
import StatusBadge from '@/components/ui/StatusBadge';
import { Block, KeyValues } from '@/components/workers/blocks';
import { fcfa, formatDate } from '@/lib/format';
import type { PayoutCan, PayoutRow } from '@/types/payouts';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

type Status = { value: string; label: string; tone: string };

type Payout = PayoutRow & {
    schedule: { id: string; reference: string } | null;
    preparedBy: string | null;
    approvedBy: string | null;
    approvedAt: string | null;
    workDays: { id: string; date: string; worked_minutes: number | null }[];
    adjustments: { id: string; kind: { value: string; label: string }; amount: number; reason: string; basis: string | null; status: Status; proposed_by: string | null; decided_by: string | null; can_decide: boolean }[];
    transactions: { id: string; reference: string; amount: number; operator: string | null; number: string | null; status: Status; failure: string | null; date: string | null }[];
    canAdjust: boolean;
    canCancel: boolean;
    canRetry: boolean;
};

type Dialog = { kind: 'adjust' } | { kind: 'cancel' } | { kind: 'refuse'; id: string };

/** Fiche d’un reversement : calcul du net, journées payées, retenues et bonus, envois à l’opérateur. */
export default function PaymentPayout({ payout: p, can }: { payout: Payout; can: PayoutCan }) {
    const [dialog, setDialog] = useState<Dialog | null>(null);
    const base = `/paiements/reversements/${p.id}`;
    const post = (url: string) => router.post(url, {}, { preserveScroll: true });

    return (
        <>
            <Head title={p.reference} />

            <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 'var(--space-2)', marginTop: -6 }}>
                {can.create && p.canAdjust && <Button label="Proposer une retenue ou un bonus" icon="tune" variant="secondary" onClick={() => setDialog({ kind: 'adjust' })} />}
                {can.create && p.canCancel && <Button label="Retirer ce reversement" icon="remove_circle" variant="danger-soft" onClick={() => setDialog({ kind: 'cancel' })} />}
                {can.approve && p.canRetry && <Button label="Renvoyer" icon="refresh" onClick={() => post(`${base}/renvoi`)} />}
            </div>

            <div data-component="StatGrid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(230px,1fr))', gap: 'var(--space-4)' }}>
                <StatCard label="Net à l’ouvrier" value={fcfa(p.net)} icon="savings" tone="g" />
                <StatCard label="Commission" value={fcfa(p.commission)} icon="percent" tone="b" />
                <StatCard label="Journées" value={String(p.days)} icon="event_available" tone="b" />
            </div>

            <div style={stack}>
                <Block icon="account_balance_wallet" title={p.reference}>
                    <KeyValues
                        items={[
                            ['Statut', <StatusBadge label={p.status.label} tone={p.status.tone as never} />],
                            ['Ouvrier', <Link href={`/ouvriers/${p.worker.id}/paiements`}>{p.worker.full_name}</Link>],
                            ['Chantier', p.site ? <Link href={`/chantiers/${p.site.id}`}>{p.site.label}</Link> : null],
                            ['Échéance', p.schedule ? <Link href={`/paiements/echeances/${p.schedule.id}`}>{p.schedule.reference}</Link> : null],
                            ['Lot', p.batch ? <Link href={`/paiements/lots/${p.batch.id}`}>{p.batch.reference}</Link> : null],
                            ['Numéro', p.number ? `${p.operator ?? ''} · ${p.number}` : null],
                            ['Préparé par', p.preparedBy],
                            ['Validé par', p.approvedBy],
                        ]}
                    />
                </Block>

                <Block icon="calculate" title="Calcul">
                    <KeyValues
                        items={[
                            ['Brut facturé', fcfa(p.gross)],
                            ['Commission', fcfa(p.commission)],
                            ['Retenues', fcfa(p.deduction)],
                            ['Bonus', fcfa(p.bonus)],
                            ['Net', fcfa(p.net)],
                        ]}
                    />
                </Block>

                <Block icon="tune" title="Retenues et bonus">
                    <RelationTable
                        columns={['Nature', 'Montant', 'Motif', 'Proposé par', 'Décidé par', 'Statut']}
                        empty={{ icon: 'tune', title: 'Aucune retenue ni bonus', text: 'Une proposition doit être décidée par une autre personne.' }}
                        rows={p.adjustments.map((a) => ({
                            key: a.id,
                            cells: [a.kind.label, fcfa(a.amount), a.reason, a.proposed_by ?? '—', a.decided_by ?? '—', <StatusBadge label={a.status.label} tone={a.status.tone as never} />],
                            actions:
                                can.approve && a.can_decide
                                    ? [
                                          { icon: 'check_circle', label: 'Approuver', onClick: () => post(`${base}/ajustements/${a.id}/approbation`) },
                                          { icon: 'close', label: 'Refuser', onClick: () => setDialog({ kind: 'refuse', id: a.id }) },
                                      ]
                                    : undefined,
                        }))}
                    />
                </Block>

                <Block icon="send" title="Envois à l’opérateur">
                    <RelationTable
                        columns={['Référence', 'Date', 'Numéro', 'Montant', 'Statut', 'Motif']}
                        empty={{ icon: 'send', title: 'Pas encore envoyé', text: 'L’envoi part à la validation du lot.' }}
                        rows={p.transactions.map((t) => ({ key: t.id, cells: [t.reference, t.date ?? '—', t.number ?? '—', fcfa(t.amount), <StatusBadge label={t.status.label} tone={t.status.tone as never} />, t.failure ?? '—'] }))}
                    />
                </Block>

                <Block icon="event_available" title="Journées payées">
                    <RelationTable columns={['Date']} empty={{ icon: 'event_available', title: 'Aucune journée', text: 'Ce reversement a été retiré : ses journées sont de nouveau à reverser.' }} rows={p.workDays.map((d) => ({ key: d.id, cells: [formatDate(d.date)] }))} />
                </Block>
            </div>

            {dialog?.kind === 'adjust' && (
                <FormDialog
                    title="Retenue ou bonus"
                    body="Une autre personne doit décider de cette proposition avant la soumission du lot."
                    icon="tune"
                    okLabel="Proposer"
                    url={`${base}/ajustements`}
                    onClose={() => setDialog(null)}
                    fields={[
                        { name: 'kind', label: 'Nature', type: 'select', required: true, options: [{ value: 'retenue', label: 'Retenue' }, { value: 'bonus', label: 'Bonus' }] },
                        { name: 'amount', label: 'Montant (FCFA)', type: 'number', required: true },
                        { name: 'reason', label: 'Motif', type: 'textarea', required: true },
                    ]}
                />
            )}
            {dialog?.kind === 'cancel' && (
                <FormDialog title="Retirer ce reversement ?" body="Ses journées redeviennent à reverser." icon="remove_circle" tone="danger" okLabel="Retirer" url={`${base}/retrait`} onClose={() => setDialog(null)} fields={[{ name: 'reason', label: 'Motif', type: 'textarea', required: true }]} />
            )}
            {dialog?.kind === 'refuse' && (
                <FormDialog title="Refuser cette proposition ?" icon="close" tone="danger" okLabel="Refuser" url={`${base}/ajustements/${dialog.id}/refus`} onClose={() => setDialog(null)} fields={[{ name: 'reason', label: 'Motif', type: 'textarea', required: true }]} />
            )}
        </>
    );
}
