import Dialog from '@/components/overlays/Dialog';
import Field, { SelectInput, TextArea, TextInput } from '@/components/ui/Field';
import Icon from '@/components/ui/Icon';
import type { PromoterDocument } from '@/types/promoters';
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

type NotifyDialogProps = {
    recipient: string;
    url: string;
    subject?: string;
    onClose: () => void;
};

/** Message au promoteur : centre de notifications de l’application et notification push. */
export function NotifyDialog({ recipient, url, subject = '', onClose }: NotifyDialogProps) {
    const form = useForm({ subject, message: '' });
    const errors = form.errors as Record<string, string | undefined>;

    return (
        <Dialog title="Envoyer un message" icon="send" okLabel="Envoyer" okIcon="send" processing={form.processing} onClose={onClose} onSubmit={() => form.post(url, { preserveScroll: true, onSuccess: onClose })}>
            <div style={{ fontSize: 'var(--fs-md)', color: 'var(--c-muted)' }}>
                Destinataire : <b style={{ color: 'var(--c-ink)' }}>{recipient}</b> · reçu dans l’application (notifications et push)
            </div>
            <Field label="Objet" required error={errors.title ?? form.errors.subject}>
                <TextInput value={form.data.subject} onChange={(e) => form.setData('subject', e.target.value)} invalid={!!(errors.title ?? form.errors.subject)} maxLength={120} placeholder="Ex. Document KYC à mettre à jour" />
            </Field>
            <Field label="Message" required error={errors.body ?? form.errors.message}>
                <TextArea value={form.data.message} onChange={(e) => form.setData('message', e.target.value)} invalid={!!(errors.body ?? form.errors.message)} rows={4} maxLength={1000} placeholder="Votre message…" />
            </Field>
        </Dialog>
    );
}

type CorrectionsDialogProps = {
    promoterName: string;
    documents: PromoterDocument[];
    url: string;
    onClose: () => void;
};

/**
 * Demande de corrections : pièces à remplacer, chacune avec son motif, et message au promoteur. Les pièces
 * choisies sont rejetées ; le dossier revient en vérification dès que le promoteur les remplace.
 */
export function CorrectionsDialog({ promoterName, documents, url, onClose }: CorrectionsDialogProps) {
    const form = useForm<{ reasons: Record<string, string>; message: string }>({ reasons: {}, message: '' });
    const errors = form.errors as Record<string, string | undefined>;
    const chosen = Object.keys(form.data.reasons);

    const toggle = (id: string) => {
        const { [id]: removed, ...rest } = form.data.reasons;
        form.setData('reasons', removed === undefined ? { ...form.data.reasons, [id]: '' } : rest);
        form.clearErrors();
    };

    const submit = () => {
        form.transform((data) => ({ corrections: Object.entries(data.reasons).map(([document_id, reason]) => ({ document_id, reason })), message: data.message || null }));
        form.post(url, { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Dialog title="Demander des corrections" body={`Choisissez les pièces que ${promoterName} doit remplacer et indiquez pourquoi.`} icon="edit_document" okLabel="Envoyer la demande" processing={form.processing} onClose={onClose} onSubmit={submit}>
            {errors.corrections && <span style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-danger-fg)' }}>{errors.corrections}</span>}
            {documents.map((d) => {
                const on = chosen.includes(d.id);
                const index = chosen.indexOf(d.id);
                const error = errors[`corrections.${index}.reason`];
                return (
                    <div key={d.id} style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-2)' }}>
                        <button
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
                        {on && (
                            <Field label="Motif *" error={error}>
                                <TextInput value={form.data.reasons[d.id]} onChange={(e) => form.setData('reasons', { ...form.data.reasons, [d.id]: e.target.value })} invalid={!!error} maxLength={500} placeholder="Ex. Certificat daté de plus de 3 mois" />
                            </Field>
                        )}
                    </div>
                );
            })}
            <Field label="Message au promoteur" error={errors.message}>
                <TextArea value={form.data.message} onChange={(e) => form.setData('message', e.target.value)} rows={3} maxLength={1000} placeholder="Facultatif : précisions communiquées avec la demande." />
            </Field>
        </Dialog>
    );
}

export type DocumentTypeOption = { value: string; label: string; hasBackSide: boolean; isIdentity: boolean; accept: string };

type UploadDocumentDialogProps = {
    promoterName: string;
    types: DocumentTypeOption[] | undefined;
    url: string;
    onClose: () => void;
};

/** Ajout d’une pièce au nom du promoteur : elle arrive « À vérifier » dans le dossier. */
export function UploadDocumentDialog({ promoterName, types, url, onClose }: UploadDocumentDialogProps) {
    const form = useForm<{ document_type: string; number: string; expires_at: string; recto: File | null; verso: File | null }>({ document_type: '', number: '', expires_at: '', recto: null, verso: null });
    const errors = form.errors as Record<string, string | undefined>;
    const type = types?.find((t) => t.value === form.data.document_type);
    const file = (key: 'recto' | 'verso', label: string) => (
        <Field label={label} required error={errors[key]}>
            <input type="file" accept={type?.accept || 'application/pdf,image/jpeg,image/png'} onChange={(e) => form.setData(key, e.target.files?.[0] ?? null)} aria-invalid={!!errors[key]} style={{ fontSize: 'var(--fs-md)' }} />
        </Field>
    );

    return (
        <Dialog title="Ajouter une pièce" icon="upload_file" okLabel="Ajouter au dossier" okIcon="upload" processing={form.processing} onClose={onClose} onSubmit={() => form.post(url, { forceFormData: true, preserveScroll: true, onSuccess: onClose })}>
            <div style={{ fontSize: 'var(--fs-md)', color: 'var(--c-muted)' }}>
                Dossier de <b style={{ color: 'var(--c-ink)' }}>{promoterName}</b> · la pièce arrivera « À vérifier ».
            </div>
            <Field label="Type de pièce" required error={errors.document_type}>
                <SelectInput value={form.data.document_type} onChange={(e) => form.setData((d) => ({ ...d, document_type: e.target.value, verso: null }))} options={types ?? []} invalid={!!errors.document_type} placeholder={types ? 'Sélectionner…' : 'Chargement…'} />
            </Field>
            {type && (
                <>
                    {file('recto', type.hasBackSide ? 'Recto' : 'Fichier')}
                    {type.hasBackSide && file('verso', 'Verso')}
                    {type.isIdentity && (
                        <Field label="N° de la pièce" error={errors.number}>
                            <TextInput value={form.data.number} onChange={(e) => form.setData('number', e.target.value)} maxLength={40} invalid={!!errors.number} />
                        </Field>
                    )}
                    <Field label="Date d’expiration" error={errors.expires_at} hint="Facultatif">
                        <TextInput type="date" value={form.data.expires_at} onChange={(e) => form.setData('expires_at', e.target.value)} invalid={!!errors.expires_at} />
                    </Field>
                </>
            )}
        </Dialog>
    );
}
