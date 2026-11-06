import FormDialog from '@/components/overlays/FormDialog';
import { RelationTable, stack } from '@/components/promoters/RelationTabs';
import Button from '@/components/ui/Button';
import StatusBadge from '@/components/ui/StatusBadge';
import { Block, KeyValues } from '@/components/workers/blocks';
import { fcfa } from '@/lib/format';
import type { RefundCan, RefundRow } from '@/types/payouts';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

type Status = { value: string; label: string; tone: string };

type Refund = RefundRow & {
    savedNumbers: { id: string; operator: string | null; number: string }[];
    operators: { id: string; name: string }[];
    transactions: { id: string; reference: string; amount: number; number: string | null; status: Status; failure: string | null; date: string | null }[];
};

/** Fiche d’un remboursement : numéro, validations et envois à l’opérateur. */
export default function PaymentRefund({ refund: r, can }: { refund: Refund; can: RefundCan }) {
    const [dialog, setDialog] = useState<'saved' | 'number' | 'refuse' | null>(null);
    const base = `/paiements/remboursements/${r.id}`;
    const post = (action: string) => router.post(`${base}/${action}`, {}, { preserveScroll: true });
    const close = () => setDialog(null);
    const change = can.update && r.canChangeNumber;
    const approvals = [r.approvedBy, r.secondApprover].filter(Boolean).length;

    return (
        <>
            <Head title={r.reference} />

            <div style={{ display: 'flex', justifyContent: 'flex-end', flexWrap: 'wrap', gap: 'var(--space-2)', marginTop: -6 }}>
                {can.decide && r.canRefuse && <Button label="Refuser" icon="cancel" variant="danger-soft" onClick={() => setDialog('refuse')} />}
                {change && r.savedNumbers.length > 0 && <Button label="Choisir un numéro enregistré" icon="contacts" variant="secondary" onClick={() => setDialog('saved')} />}
                {change && <Button label="Saisir un numéro" icon="phone_iphone" variant="secondary" onClick={() => setDialog('number')} />}
                {can.decide && r.canRetry && <Button label="Renvoyer" icon="refresh" onClick={() => post('renvoi')} />}
                {can.decide && r.canApprove && <Button label={approvals + 1 < r.approvalsRequired ? 'Valider' : `Valider et envoyer ${fcfa(r.amount)}`} icon="payments" onClick={() => post('validation')} />}
            </div>

            <div style={stack}>
                <Block icon="undo" title={r.reference}>
                    <KeyValues
                        items={[
                            ['Statut', <StatusBadge label={r.status.label} tone={r.status.tone as never} />],
                            ['Montant', fcfa(r.amount)],
                            ['Promoteur', r.promoter.full_name],
                            ['Mission', <Link href={`/paiements/regularisations/${r.mission.id}`}>{`${r.site.label} · ${r.mission.reference}`}</Link>],
                            ['Origine', r.origin.label],
                            ['Motif', r.reason],
                            ['Paiement de référence', r.transaction ? <Link href={`/paiements/${r.transaction.id}`}>{r.transaction.reference}</Link> : null],
                            ['Numéro', r.number ? `${r.number}${r.operator ? ` · ${r.operator}` : ''}` : 'À choisir'],
                            ['Demandé par', r.requestedBy],
                            ['Validations', `${approvals} sur ${r.approvalsRequired}`],
                            ['Validé par', [r.approvedBy, r.secondApprover].filter(Boolean).join(', ') || null],
                            ['Exécuté le', r.executedAt],
                            ['Motif du refus', r.refusalReason],
                        ]}
                    />
                </Block>

                <Block icon="send" title="Envois à l’opérateur">
                    <RelationTable
                        columns={['Référence', 'Date', 'Numéro', 'Montant', 'Statut', 'Motif']}
                        empty={{ icon: 'send', title: 'Pas encore envoyé', text: 'L’envoi part à la dernière validation.' }}
                        rows={r.transactions.map((t) => ({ key: t.id, cells: [t.reference, t.date ?? '—', t.number ?? '—', fcfa(t.amount), <StatusBadge label={t.status.label} tone={t.status.tone as never} />, t.failure ?? '—'] }))}
                    />
                </Block>
            </div>

            {dialog === 'saved' && (
                <FormDialog
                    title="Choisir un numéro enregistré"
                    body="Les validations déjà données sont effacées."
                    icon="contacts"
                    okLabel="Enregistrer"
                    url={`${base}/numero-enregistre`}
                    onClose={close}
                    fields={[{ name: 'payment_method_id', label: 'Numéro', type: 'select', required: true, options: r.savedNumbers.map((s) => ({ value: s.id, label: `${s.number}${s.operator ? ` · ${s.operator}` : ''}` })) }]}
                />
            )}
            {dialog === 'number' && (
                <FormDialog
                    title="Saisir un numéro"
                    body="Vérifiez ce numéro avec le promoteur. Les validations déjà données sont effacées, et vous ne pourrez pas valider ce remboursement."
                    icon="phone_iphone"
                    okLabel="Enregistrer"
                    url={`${base}/numero`}
                    initial={{ dial_code: '225' }}
                    onClose={close}
                    fields={[
                        { name: 'operator_id', label: 'Opérateur', type: 'select', required: true, options: r.operators.map((o) => ({ value: o.id, label: o.name })) },
                        { name: 'dial_code', label: 'Indicatif', required: true },
                        { name: 'phone_number', label: 'Numéro', required: true },
                    ]}
                />
            )}
            {dialog === 'refuse' && <FormDialog title="Refuser ce remboursement ?" body="Il ne pourra plus être envoyé." icon="cancel" tone="danger" okLabel="Refuser" url={`${base}/refus`} onClose={close} fields={[{ name: 'reason', label: 'Motif', type: 'textarea', required: true }]} />}
        </>
    );
}
