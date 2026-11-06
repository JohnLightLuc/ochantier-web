import FormDialog from '@/components/overlays/FormDialog';
import Button from '@/components/ui/Button';
import type { WorkerPaymentMethodData } from '@/types/workers';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Block, KeyValues } from './blocks';

/** Numéro qui reçoit les reversements : saisi par une personne, confirmé par une autre. */
export default function WorkerPaymentMethod({ worker, method }: { worker: string; method: WorkerPaymentMethodData }) {
    const [dialog, setDialog] = useState<'propose' | 'reject' | null>(null);
    const { current, pending, can } = method;
    const base = `/paiements/ouvriers/${worker}/numero`;
    const approve = () => pending && router.post(`${base}/${pending.id}/confirmation`, {}, { preserveScroll: true });

    return (
        <Block icon="phone_iphone" title="Numéro Mobile Money">
            <KeyValues
                items={[
                    ['Numéro confirmé', current ? `${current.operator ?? '—'} · ${current.number}` : 'Aucun : cet ouvrier ne peut pas recevoir de reversement.'],
                    ['En attente de confirmation', pending ? `${pending.operator ?? '—'} · ${pending.number}${pending.proposed_by ? ` (saisi par ${pending.proposed_by})` : ''}` : null],
                ]}
            />
            <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2)', marginTop: 'var(--space-3)' }}>
                {can.create && !pending && <Button label={current ? 'Changer le numéro' : 'Saisir le numéro'} icon="edit" variant="secondary" onClick={() => setDialog('propose')} />}
                {can.approve && pending?.can_decide && (
                    <>
                        <Button label="Confirmer le numéro" icon="check_circle" onClick={approve} />
                        <Button label="Refuser" icon="close" variant="danger-soft" onClick={() => setDialog('reject')} />
                    </>
                )}
            </div>

            {dialog === 'propose' && (
                <FormDialog
                    title="Numéro Mobile Money"
                    body="Ce numéro ne recevra de reversement qu’une fois confirmé par une autre personne."
                    icon="phone_iphone"
                    okLabel="Enregistrer"
                    url={base}
                    initial={{ dial_code: method.default_number.dial_code ?? '225', phone_number: method.default_number.phone_number ?? '' }}
                    onClose={() => setDialog(null)}
                    fields={[
                        { name: 'operator_id', label: 'Opérateur', type: 'select', required: true, full: true, options: method.operators.map((o) => ({ value: o.id, label: o.name })) },
                        { name: 'dial_code', label: 'Indicatif', required: true },
                        { name: 'phone_number', label: 'Numéro', required: true },
                    ]}
                />
            )}

            {dialog === 'reject' && pending && (
                <FormDialog title="Refuser ce numéro ?" icon="close" tone="danger" okLabel="Refuser" url={`${base}/${pending.id}/refus`} onClose={() => setDialog(null)} fields={[{ name: 'reason', label: 'Motif', type: 'textarea', required: true }]} />
            )}
        </Block>
    );
}
