import Field, { SelectInput, TextArea, TextInput } from '@/components/ui/Field';
import Icon from '@/components/ui/Icon';
import { useForm } from '@inertiajs/react';
import Dialog, { type DialogTone } from './Dialog';

export type DialogField = {
    name: string;
    label: string;
    type?: 'text' | 'textarea' | 'select' | 'date' | 'time' | 'number' | 'file';
    options?: (string | { value: string; label: string })[];
    required?: boolean;
    /** Occupe toute la largeur de la grille. */
    full?: boolean;
    placeholder?: string;
    hint?: string;
};

type Props = {
    title: string;
    body?: string;
    icon: string;
    tone?: DialogTone;
    okLabel: string;
    okIcon?: string;
    url: string;
    method?: 'post' | 'put' | 'delete';
    fields: DialogField[];
    initial?: Record<string, string>;
    /** Données envoyées en plus des champs (identifiants, mode…). */
    extra?: Record<string, unknown>;
    onClose: () => void;
};

/** Modale de formulaire déclarative : les champs sont validés par le serveur, erreurs affichées sous chaque champ. */
export default function FormDialog({ title, body, icon, tone, okLabel, okIcon, url, method = 'post', fields, initial = {}, extra, onClose }: Props) {
    const defaults = Object.fromEntries(fields.map((f) => [f.name, initial[f.name] ?? (f.type === 'select' && f.required && f.options?.length ? optionValue(f.options[0]) : '')]));
    const form = useForm<Record<string, string | File | null>>(defaults);
    const hasFile = fields.some((f) => f.type === 'file');

    const submit = () => {
        if (extra) form.transform((data) => ({ ...data, ...extra }) as typeof data);
        form.submit(method, url, { preserveScroll: true, forceFormData: hasFile, onSuccess: onClose });
    };

    return (
        <Dialog title={title} body={body} icon={icon} tone={tone} wide={fields.length > 3} okLabel={okLabel} okIcon={okIcon} processing={form.processing} onClose={onClose} onSubmit={submit}>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(2,minmax(0,1fr))', gap: 'var(--space-3)' }}>
                {fields.map((f) => {
                    const error = form.errors[f.name];
                    const value = form.data[f.name];
                    const set = (v: string | File | null) => form.setData(f.name, v);
                    return (
                        <Field key={f.name} label={f.label} required={f.required} error={error} hint={f.hint} span={f.full || f.type === 'textarea' || f.type === 'file' ? 'full' : undefined}>
                            {f.type === 'select' ? (
                                <SelectInput value={String(value ?? '')} onChange={(e) => set(e.target.value)} invalid={!!error} options={(f.options ?? []).map((o) => (typeof o === 'string' ? { value: o, label: o } : o))} />
                            ) : f.type === 'textarea' ? (
                                <TextArea value={String(value ?? '')} onChange={(e) => set(e.target.value)} invalid={!!error} rows={3} placeholder={f.placeholder} />
                            ) : f.type === 'file' ? (
                                <label style={{ position: 'relative', display: 'flex', alignItems: 'center', gap: 'var(--space-3)', padding: 'var(--space-3) var(--space-4)', border: `1.5px dashed ${error ? 'var(--c-danger)' : 'var(--c-line-strong)'}`, borderRadius: 'var(--radius-xl)', background: 'var(--c-surface-2)', cursor: 'pointer' }}>
                                    <Icon name="cloud_upload" size="var(--icon-md)" color="var(--c-primary-600)" />
                                    <span style={{ flex: 1, minWidth: 0 }}>
                                        <b style={{ display: 'block', fontSize: 'var(--fs-md)' }}>{value instanceof File ? value.name : 'Glissez un fichier ou cliquez pour parcourir'}</b>
                                        <span style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>PDF, JPG ou PNG · 10 Mo maximum</span>
                                    </span>
                                    <input type="file" accept=".pdf,.jpg,.jpeg,.png" aria-label={f.label} onChange={(e) => set(e.target.files?.[0] ?? null)} style={{ position: 'absolute', inset: 0, opacity: 0, cursor: 'pointer' }} />
                                </label>
                            ) : (
                                <TextInput type={f.type === 'number' ? 'text' : f.type ?? 'text'} inputMode={f.type === 'number' ? 'numeric' : undefined} value={String(value ?? '')} onChange={(e) => set(e.target.value)} invalid={!!error} placeholder={f.placeholder} />
                            )}
                        </Field>
                    );
                })}
            </div>
        </Dialog>
    );
}

function optionValue(o: string | { value: string }): string {
    return typeof o === 'string' ? o : o.value;
}
