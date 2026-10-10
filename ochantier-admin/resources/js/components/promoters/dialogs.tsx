import Dialog from '@/components/overlays/Dialog';
import Field, { SelectInput, TextArea, TextInput } from '@/components/ui/Field';
import Icon from '@/components/ui/Icon';
import { useForm } from '@inertiajs/react';

type ReasonDialogProps = {
    title: string;
    body: string;
    icon: string;
    okLabel: string;
    reasonLabel: string;
    categories: string[];
    url: string;
    onClose: () => void;
};

/** Action avec motif obligatoire (suspension d’un compte, rejet d’un document). */
export function ReasonDialog({ title, body, icon, okLabel, reasonLabel, categories, url, onClose }: ReasonDialogProps) {
    const form = useForm({ category: categories[0], reason: '' });

    return (
        <Dialog title={title} body={body} icon={icon} tone="danger" okLabel={okLabel} processing={form.processing} onClose={onClose} onSubmit={() => form.post(url, { onSuccess: onClose })}>
            <Field label="Catégorie">
                <SelectInput value={form.data.category} onChange={(e) => form.setData('category', e.target.value)} options={categories.map((c) => ({ value: c, label: c }))} placeholder="" />
            </Field>
            <Field label={reasonLabel} error={form.errors.reason}>
                <TextArea
                    value={form.data.reason}
                    onChange={(e) => form.setData('reason', e.target.value)}
                    rows={3}
                    maxLength={500}
                    invalid={!!form.errors.reason}
                    placeholder="Précisez le motif (visible dans l’historique et communiqué au promoteur)."
                />
            </Field>
            <span style={{ alignSelf: 'flex-end', marginTop: 'calc(-1 * var(--space-2))', fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>{form.data.reason.length} / 500</span>
        </Dialog>
    );
}

const CHANNELS = ['Notification push', 'SMS', 'E-mail'];

type NotifyDialogProps = {
    recipient: string;
    url: string;
    subject?: string;
    onClose: () => void;
};

/** Notification au promoteur : canaux, objet et message. */
export function NotifyDialog({ recipient, url, subject = '', onClose }: NotifyDialogProps) {
    const form = useForm({ channels: ['Notification push', 'E-mail'], subject, message: '' });

    const toggle = (channel: string) => {
        form.setData('channels', form.data.channels.includes(channel) ? form.data.channels.filter((c) => c !== channel) : [...form.data.channels, channel]);
        form.clearErrors('channels');
    };

    return (
        <Dialog title="Envoyer une notification" icon="send" okLabel="Envoyer" okIcon="send" processing={form.processing} onClose={onClose} onSubmit={() => form.post(url, { onSuccess: onClose })}>
            <div style={{ fontSize: 'var(--fs-md)', color: 'var(--c-muted)' }}>
                Destinataire : <b style={{ color: 'var(--c-ink)' }}>{recipient}</b>
            </div>
            <div>
                <span style={{ display: 'block', fontWeight: 'var(--fw-semibold)', fontSize: 'var(--fs-md)', marginBottom: 'var(--space-1_5)' }}>Canaux *</span>
                <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2)' }}>
                    {CHANNELS.map((c) => {
                        const on = form.data.channels.includes(c);
                        return (
                            <button
                                key={c}
                                type="button"
                                role="checkbox"
                                aria-checked={on}
                                onClick={() => toggle(c)}
                                style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-1_5)', height: 'var(--control-sm)', padding: '0 var(--space-3)', border: `1.5px solid ${on ? 'var(--c-primary-400)' : 'var(--c-line-strong)'}`, borderRadius: 'var(--radius-lg)', background: on ? 'var(--c-primary-50)' : 'var(--c-surface)', cursor: 'pointer', fontWeight: 'var(--fw-semibold)' }}
                            >
                                <Icon name={on ? 'check_box' : 'check_box_outline_blank'} color="var(--c-primary)" />
                                {c}
                            </button>
                        );
                    })}
                </div>
                {form.errors.channels && <span style={{ display: 'block', marginTop: 'var(--space-1)', fontSize: 'var(--fs-sm)', color: 'var(--c-danger-fg)' }}>{form.errors.channels}</span>}
            </div>
            <Field label="Objet" required error={form.errors.subject}>
                <TextInput value={form.data.subject} onChange={(e) => form.setData('subject', e.target.value)} invalid={!!form.errors.subject} placeholder="Ex. Document KYC à mettre à jour" />
            </Field>
            <Field label="Message" required error={form.errors.message}>
                <TextArea value={form.data.message} onChange={(e) => form.setData('message', e.target.value)} invalid={!!form.errors.message} rows={4} placeholder="Votre message…" />
            </Field>
        </Dialog>
    );
}

export const DOCUMENT_TYPES = [
    { value: 'trade_register', label: 'Registre de commerce' },
    { value: 'representative_id', label: 'Pièce d’identité du représentant' },
    { value: 'tax_certificate', label: 'Attestation fiscale' },
    { value: 'proof_of_address', label: 'Justificatif d’adresse' },
    { value: 'additional', label: 'Document complémentaire' },
];

type DocumentDialogProps = {
    promoterName: string;
    url: string;
    type: string;
    onClose: () => void;
};

/** Dépôt d’une pièce KYC (fichier, référence, expiration). */
export function DocumentDialog({ promoterName, url, type, onClose }: DocumentDialogProps) {
    const form = useForm<{ type: string; reference: string; expires_at: string; file: File | null }>({ type, reference: '', expires_at: '', file: null });
    const file = form.data.file;

    return (
        <Dialog
            title="Ajouter un document KYC"
            body={`Le document sera ajouté au dossier de ${promoterName} avec le statut « À vérifier ».`}
            icon="upload_file"
            okLabel="Ajouter le document"
            processing={form.processing}
            onClose={onClose}
            onSubmit={() => form.post(url, { forceFormData: true, onSuccess: onClose })}
        >
            <Field label="Type de document" required>
                <SelectInput value={form.data.type} onChange={(e) => form.setData('type', e.target.value)} options={DOCUMENT_TYPES} placeholder="" />
            </Field>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(180px,1fr))', gap: 'var(--space-3)' }}>
                <Field label="N° / référence" required error={form.errors.reference}>
                    <TextInput value={form.data.reference} onChange={(e) => form.setData('reference', e.target.value)} invalid={!!form.errors.reference} placeholder="Ex. AF-2026-00871" />
                </Field>
                <Field label="Date d’expiration" error={form.errors.expires_at}>
                    <TextInput type="date" value={form.data.expires_at} onChange={(e) => form.setData('expires_at', e.target.value)} />
                </Field>
            </div>
            <label style={{ position: 'relative', display: 'flex', alignItems: 'center', gap: 'var(--space-3)', padding: 'var(--space-4)', border: `1.5px dashed ${form.errors.file ? 'var(--c-danger)' : 'var(--c-line-strong)'}`, borderRadius: 'var(--radius-xl)', background: 'var(--c-surface-2)', cursor: 'pointer' }}>
                <Icon name="cloud_upload" size="var(--icon-lg)" color="var(--c-primary-600)" />
                <span style={{ flex: 1, minWidth: 0 }}>
                    <b style={{ display: 'block', fontSize: 'var(--fs-md)' }}>{file ? `${file.name} · ${Math.max(1, Math.round(file.size / 1024))} Ko` : 'Glissez un fichier ou cliquez pour parcourir'}</b>
                    <span style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>PDF, JPG ou PNG · 10 Mo maximum</span>
                </span>
                <input type="file" accept=".pdf,.jpg,.jpeg,.png" aria-label="Fichier du document" onChange={(e) => form.setData('file', e.target.files?.[0] ?? null)} style={{ position: 'absolute', inset: 0, opacity: 0, cursor: 'pointer' }} />
            </label>
            {form.errors.file && <span style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-danger-fg)' }}>{form.errors.file}</span>}
        </Dialog>
    );
}
