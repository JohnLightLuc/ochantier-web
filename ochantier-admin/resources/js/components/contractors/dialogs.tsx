import Dialog from '@/components/overlays/Dialog';
import Field, { TextArea } from '@/components/ui/Field';
import Icon from '@/components/ui/Icon';
import type { ContractorDocument } from '@/types/contractors';
import { useForm } from '@inertiajs/react';

type ComplementDialogProps = {
    contractorName: string;
    documents: ContractorDocument[];
    url: string;
    onClose: () => void;
};

/**
 * Complément demandé sur le dossier : motif communiqué à l’entrepreneur et pièces à remplacer. Les pièces déjà
 * rejetées une à une sont reprises d’office, avec leur propre motif.
 */
export function ComplementDialog({ contractorName, documents, url, onClose }: ComplementDialogProps) {
    const form = useForm<{ reason: string; document_ids: string[] }>({ reason: '', document_ids: [] });
    const rejected = documents.filter((d) => d.status.value === 'rejete');
    const choosable = documents.filter((d) => d.hasFile && d.status.value !== 'rejete');
    const toggle = (id: string) => form.setData('document_ids', form.data.document_ids.includes(id) ? form.data.document_ids.filter((x) => x !== id) : [...form.data.document_ids, id]);

    return (
        <Dialog
            title="Demander un complément"
            body={`${contractorName} est notifié du motif et peut compléter son dossier dans l’application Pro. Le dossier reviendra en vérification à sa prochaine soumission.`}
            icon="edit_document"
            wide
            okLabel="Envoyer la demande"
            processing={form.processing}
            onClose={onClose}
            onSubmit={() => form.post(url, { preserveScroll: true, onSuccess: onClose })}
        >
            <Field label="Motif communiqué à l’entrepreneur *" error={form.errors.reason}>
                <TextArea value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} rows={3} maxLength={1000} invalid={!!form.errors.reason} placeholder="Ex. Merci d’ajouter votre attestation de régularité CNPS." />
            </Field>
            {rejected.length > 0 && (
                <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-1_5)', padding: 'var(--space-3)', borderRadius: 'var(--radius-lg)', background: 'var(--c-orange-bg)', color: 'var(--c-orange-fg)', fontSize: 'var(--fs-md)' }}>
                    <b>Pièces déjà rejetées, reprises dans la demande :</b>
                    {rejected.map((d) => (
                        <span key={d.id}>
                            {d.label}
                            {d.rejectionReason ? ` · ${d.rejectionReason}` : ''}
                        </span>
                    ))}
                </div>
            )}
            {choosable.length > 0 && (
                <Field label="Autres pièces à remplacer (même motif)">
                    <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-2)' }}>
                        {choosable.map((d) => {
                            const on = form.data.document_ids.includes(d.id);
                            return (
                                <button
                                    key={d.id}
                                    type="button"
                                    role="checkbox"
                                    aria-checked={on}
                                    onClick={() => toggle(d.id)}
                                    style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)', padding: 'var(--space-2) var(--space-3)', border: `1.5px solid ${on ? 'var(--c-primary-400)' : 'var(--c-line-strong)'}`, borderRadius: 'var(--radius-lg)', background: on ? 'var(--c-primary-50)' : 'var(--c-surface)', cursor: 'pointer', textAlign: 'left', fontWeight: 'var(--fw-semibold)' }}
                                >
                                    <Icon name={on ? 'check_box' : 'check_box_outline_blank'} color="var(--c-primary)" />
                                    <span style={{ flex: 1 }}>{d.label}</span>
                                    <span style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)', fontWeight: 'var(--fw-regular)' }}>{d.status.label}</span>
                                </button>
                            );
                        })}
                    </div>
                </Field>
            )}
        </Dialog>
    );
}
