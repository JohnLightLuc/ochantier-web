import Dialog from '@/components/overlays/Dialog';
import Field, { SelectInput, TextArea, TextInput, type Option } from '@/components/ui/Field';
import Icon from '@/components/ui/Icon';
import { todayIso } from '@/lib/format';
import type { WorkerDocument } from '@/types/workers';
import { useForm } from '@inertiajs/react';

type CorrectionsDialogProps = {
    workerName: string;
    documents: WorkerDocument[];
    fields: Option[];
    url: string;
    onClose: () => void;
};

/**
 * Demande de corrections au commercial : pièces à remplacer (rejetées avec le motif) et informations du dossier à
 * reprendre, chacune avec son motif. Le dossier revient en vérification quand le commercial le renvoie.
 */
export function CorrectionsDialog({ workerName, documents, fields, url, onClose }: CorrectionsDialogProps) {
    const form = useForm<{ reasons: Record<string, string>; message: string }>({ reasons: {}, message: '' });
    const errors = form.errors as Record<string, string | undefined>;
    const chosen = Object.keys(form.data.reasons);
    const items = [...documents.map((d) => ({ key: `document:${d.id}`, label: d.label, hint: d.status.label })), ...fields.map((f) => ({ key: `field:${f.value}`, label: f.label, hint: 'Information' }))];

    const toggle = (key: string) => {
        const { [key]: removed, ...rest } = form.data.reasons;
        form.setData('reasons', removed === undefined ? { ...form.data.reasons, [key]: '' } : rest);
        form.clearErrors();
    };

    const submit = () => {
        form.transform((data) => ({
            corrections: Object.entries(data.reasons).map(([key, reason]) => {
                const [kind, value] = key.split(':');
                return kind === 'document' ? { document_id: value, reason } : { field: value, reason };
            }),
            message: data.message || null,
        }));
        form.post(url, { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Dialog title="Demander des corrections" body={`Choisissez ce que le commercial doit reprendre dans le dossier de ${workerName}, et indiquez pourquoi.`} icon="edit_document" okLabel="Envoyer la demande" processing={form.processing} onClose={onClose} onSubmit={submit}>
            {errors.corrections && <span style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-danger-fg)' }}>{errors.corrections}</span>}
            <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-2)', maxHeight: 360, overflowY: 'auto' }}>
                {items.map((item) => {
                    const on = chosen.includes(item.key);
                    const error = errors[`corrections.${chosen.indexOf(item.key)}.reason`];
                    return (
                        <div key={item.key} style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-2)' }}>
                            <button
                                type="button"
                                role="checkbox"
                                aria-checked={on}
                                onClick={() => toggle(item.key)}
                                style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)', padding: 'var(--space-2) var(--space-3)', border: `1.5px solid ${on ? 'var(--c-primary-400)' : 'var(--c-line-strong)'}`, borderRadius: 'var(--radius-lg)', background: on ? 'var(--c-primary-50)' : 'var(--c-surface)', cursor: 'pointer', textAlign: 'left', fontWeight: 'var(--fw-semibold)' }}
                            >
                                <Icon name={on ? 'check_box' : 'check_box_outline_blank'} color="var(--c-primary)" />
                                <span style={{ flex: 1 }}>{item.label}</span>
                                <span style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)', fontWeight: 'var(--fw-regular)' }}>{item.hint}</span>
                            </button>
                            {on && (
                                <Field label="Motif *" error={error}>
                                    <TextInput value={form.data.reasons[item.key]} onChange={(e) => form.setData('reasons', { ...form.data.reasons, [item.key]: e.target.value })} invalid={!!error} maxLength={500} placeholder="Ex. Photo floue, à reprendre" />
                                </Field>
                            )}
                        </div>
                    );
                })}
            </div>
            <Field label="Message au commercial" error={errors.message}>
                <TextArea value={form.data.message} onChange={(e) => form.setData('message', e.target.value)} rows={3} maxLength={1000} placeholder="Facultatif : précisions communiquées avec la demande." />
            </Field>
        </Dialog>
    );
}

const SUSPENSION_CATEGORIES = ['Absences répétées', 'Comportement inapproprié', 'Documents non conformes', 'Litige en cours', 'Demande de l’ouvrier', 'Autre'];

/** Suspension : catégorie, motif et date de fin facultative (réactivation automatique à cette date). */
export function SuspendDialog({ workerName, url, onClose }: { workerName: string; url: string; onClose: () => void }) {
    const form = useForm({ category: SUSPENSION_CATEGORIES[0], reason: '', until: '' });
    const errors = form.errors as Record<string, string | undefined>;
    const tomorrow = new Date(Date.now() + 86400000).toISOString().slice(0, 10);

    return (
        <Dialog
            title={`Suspendre ${workerName} ?`}
            body="L’ouvrier ne sera plus proposé sur les chantiers. Sans date de fin, il faudra le réactiver à la main."
            icon="block"
            tone="danger"
            okLabel="Suspendre"
            processing={form.processing}
            onClose={onClose}
            onSubmit={() => form.post(url, { preserveScroll: true, onSuccess: onClose })}
        >
            <Field label="Catégorie">
                <SelectInput value={form.data.category} onChange={(e) => form.setData('category', e.target.value)} options={SUSPENSION_CATEGORIES.map((c) => ({ value: c, label: c }))} placeholder="" />
            </Field>
            <Field label="Motif de la suspension *" error={errors.reason}>
                <TextArea value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} rows={3} maxLength={500} invalid={!!errors.reason} placeholder="Précisez le motif (visible dans l’historique)." />
            </Field>
            <Field label="Fin de la suspension" hint="Facultatif : l’ouvrier redevient validé à cette date." error={errors.until}>
                <TextInput type="date" value={form.data.until} min={tomorrow > todayIso() ? tomorrow : undefined} onChange={(e) => form.setData('until', e.target.value)} invalid={!!errors.until} />
            </Field>
        </Dialog>
    );
}
