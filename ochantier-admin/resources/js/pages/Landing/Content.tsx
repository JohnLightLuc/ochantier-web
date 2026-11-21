import LandingShell from '@/components/landing/LandingShell';
import { CheckList, saveLayout, Switch } from '@/components/landing/SiteBlock';
import Button from '@/components/ui/Button';
import Field, { SelectInput, TextArea, TextInput } from '@/components/ui/Field';
import Icon from '@/components/ui/Icon';
import StatusBadge from '@/components/ui/StatusBadge';
import { useAdminUi } from '@/contexts/AdminUiContext';
import type { LandingEditor, LandingField, LandingShellProps, LandingValue } from '@/types/landing';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

type Props = LandingShellProps & { editor: LandingEditor };
type Item = Record<string, string | boolean | null>;
type FileInfo = { name: string; url: string | null };

/**
 * Landing Page, onglet Contenu : sections du site à gauche, champs de la section à droite. Les modifications vont
 * dans le brouillon ; le site ne change qu’à la publication.
 */
export default function LandingContent({ tab, tabs, draft, editor }: Props) {
    const s = editor.section;
    const canEdit = !!draft?.canEdit;

    return (
        <>
            <Head title={`Contenu · ${s.label}`} />
            <LandingShell tab={tab} tabs={tabs} draft={draft}>
                <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-5)', alignItems: 'flex-start' }}>
                    <nav aria-label="Sections du site" style={{ flex: '1 1 200px', maxWidth: 260, display: 'flex', flexDirection: 'column', gap: 2, position: 'sticky', top: 'var(--space-4)' }}>
                        {editor.nav.map((n) => {
                            const on = n.key === s.key;
                            return (
                                <Link
                                    key={n.key}
                                    href={`/landing-page/contenu/${n.key}`}
                                    aria-current={on ? 'page' : undefined}
                                    className="och-hover-soft"
                                    style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)', padding: 'var(--space-2) var(--space-2_5)', borderRadius: 'var(--radius-lg)', background: on ? 'var(--c-primary-50)' : 'transparent', color: on ? 'var(--c-primary)' : 'var(--c-ink-2)', fontWeight: on ? 'var(--fw-bold)' : 'var(--fw-medium)', fontSize: 'var(--fs-md)', textDecoration: 'none' }}
                                >
                                    <Icon name={n.icon} size="var(--icon-sm)" />
                                    <span style={{ flex: 1 }}>{n.label}</span>
                                    {n.hidden && <Icon name="visibility_off" size="var(--icon-xs)" />}
                                    {(n.error || n.changed) && <span title={n.error ? 'Erreur à corriger' : 'Modifiée'} style={{ width: 8, height: 8, borderRadius: '50%', background: n.error ? 'var(--c-danger)' : 'var(--c-warning)' }} />}
                                </Link>
                            );
                        })}
                    </nav>

                    <SectionForm key={`${s.key}:${JSON.stringify(s.values)}`} editor={editor} canEdit={canEdit} />
                </div>
            </LandingShell>
        </>
    );
}

function SectionForm({ editor, canEdit }: { editor: LandingEditor; canEdit: boolean }) {
    const ui = useAdminUi();
    const s = editor.section;
    const form = useForm<{ values: Record<string, LandingValue> }>({ values: s.values });
    const errors = form.errors as Record<string, string | undefined>;
    const values = form.data.values;
    const set = (key: string, value: LandingValue) => form.setData('values', { ...values, [key]: value });
    const [uploaded, setUploaded] = useState<Record<string, FileInfo>>({});
    const { flash } = usePage();

    useEffect(() => {
        const up = flash.upload;
        if (up && up.field && up.field in form.data.values) {
            setUploaded((u) => ({ ...u, [up.id]: { name: up.name, url: up.url } }));
            form.setData('values', { ...form.data.values, [up.field]: up.id });
        }
    }, [flash]);

    const fileInfo = (id: string | null): FileInfo | null => (id ? (uploaded[id] ?? (editor.files[id] ? { name: editor.files[id].name, url: editor.files[id].url } : { name: 'Fichier envoyé', url: null })) : null);
    const save = () => form.put(`/landing-page/contenu/${s.key}`, { preserveScroll: true });
    const toggleVisible = (visible: boolean) => saveLayout(editor.layout.order, visible ? editor.layout.hidden.filter((k) => k !== s.key) : [...editor.layout.hidden, s.key]);

    return (
        <section style={{ flex: '999 1 480px', minWidth: 0, display: 'flex', flexDirection: 'column', gap: 'var(--space-4)' }}>
            <div style={{ display: 'flex', alignItems: 'flex-start', gap: 'var(--space-3)', flexWrap: 'wrap' }}>
                <span style={{ display: 'grid', placeItems: 'center', width: 40, height: 40, borderRadius: 'var(--radius-lg)', background: 'var(--c-primary-50)', color: 'var(--c-primary)' }}>
                    <Icon name={s.icon} />
                </span>
                <div style={{ flex: 1, minWidth: 220 }}>
                    <h2 style={{ margin: 0, fontSize: 'var(--fs-xl)', display: 'flex', alignItems: 'center', gap: 'var(--space-2)', flexWrap: 'wrap' }}>
                        {s.label}
                        {s.changed && <StatusBadge label="Modifiée" tone="a" />}
                    </h2>
                    <div style={{ fontSize: 'var(--fs-md)', color: 'var(--c-muted)' }}>{s.description}</div>
                </div>
                {s.hideable && (
                    <label style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)', fontSize: 'var(--fs-md)', fontWeight: 'var(--fw-semibold)' }}>
                        <Switch on={!s.hidden} disabled={!canEdit} label={`Afficher la section ${s.label}`} onChange={toggleVisible} />
                        {s.hidden ? 'Masquée' : 'Visible'}
                    </label>
                )}
                {canEdit && s.changed && (
                    <Button
                        label="Rétablir la version en ligne"
                        icon="undo"
                        size="sm"
                        variant="ghost"
                        onClick={() =>
                            ui.confirm({
                                tone: 'warning',
                                icon: 'undo',
                                title: `Rétablir « ${s.label} » ?`,
                                body: 'Les modifications de cette section sont remplacées par la version en ligne.',
                                ok: 'Rétablir',
                                onConfirm: () => router.post(`/landing-page/contenu/${s.key}/retablir`, {}, { preserveScroll: true }),
                            })
                        }
                    />
                )}
            </div>

            {s.hidden && (
                <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)', padding: 'var(--space-3)', borderRadius: 'var(--radius-lg)', background: 'var(--c-surface-3)', color: 'var(--c-ink-2)', fontSize: 'var(--fs-md)' }}>
                    <Icon name="visibility_off" />
                    Section masquée : elle n’apparaîtra pas sur le site après publication.
                </div>
            )}
            {editor.checks.length > 0 && <CheckList checks={editor.checks} empty="" />}
            {s.key === 'tarifs' && (
                <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)', padding: 'var(--space-3)', borderRadius: 'var(--radius-lg)', background: 'var(--c-info-bg)', color: 'var(--c-info-fg)', fontSize: 'var(--fs-md)' }}>
                    <Icon name="sync" />
                    <span style={{ flex: 1 }}>Les cartes de tarifs reprennent les formules promoteur actives du module Abonnements : nom, prix, avantages et badge « Le plus choisi ».</span>
                    <Button label="Gérer les formules" icon="workspace_premium" size="sm" variant="secondary" onClick={() => router.visit('/abonnements')} />
                </div>
            )}

            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,320px),1fr))', gap: 'var(--space-4)' }}>
                {s.fields.map((f) => (
                    <FieldInput key={f.key} field={f} path={f.key} value={values[f.key]} errors={errors} disabled={!canEdit} onChange={(v) => set(f.key, v)} fileInfo={fileInfo} />
                ))}
            </div>

            {canEdit && (
                <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 'var(--space-2)', borderTop: '1px solid var(--c-line)', paddingTop: 'var(--space-4)', position: 'sticky', bottom: 0, background: 'var(--c-surface)' }}>
                    {errors.form && <span style={{ alignSelf: 'center', color: 'var(--c-danger-fg)', fontSize: 'var(--fs-md)' }}>{errors.form}</span>}
                    <Button label="Annuler" variant="secondary" disabled={!form.isDirty || form.processing} onClick={() => form.reset()} />
                    <Button label={form.processing ? 'Enregistrement…' : 'Enregistrer dans le brouillon'} icon="save" variant="primary" disabled={!form.isDirty || form.processing} onClick={save} />
                </div>
            )}
        </section>
    );
}

type FieldInputProps = {
    field: LandingField;
    path: string;
    value: LandingValue | undefined;
    errors: Record<string, string | undefined>;
    disabled: boolean;
    onChange: (value: LandingValue) => void;
    fileInfo: (id: string | null) => FileInfo | null;
};

/** Champ de l’éditeur selon son type (texte, liste, éléments à sous-champs, fichier, oui / non, choix). */
function FieldInput({ field: f, path, value, errors, disabled, onChange, fileInfo }: FieldInputProps) {
    const error = errors[path] ?? Object.entries(errors).find(([k]) => k.startsWith(`${path}.`))?.[1];
    const wide = ['area', 'lines', 'items', 'file'].includes(f.type);
    const text = typeof value === 'string' ? value : '';
    const counter = f.max && ['text', 'area', 'url', 'tel', 'email'].includes(f.type) ? `${text.length} / ${f.max}` : '';
    const hint = [counter, f.hint].filter(Boolean).join(' · ') || undefined;

    if (f.type === 'toggle') {
        return (
            <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2_5)', gridColumn: '1 / -1' }}>
                <Switch on={!!value} disabled={disabled} label={f.label} onChange={onChange} />
                <span style={{ fontWeight: 'var(--fw-semibold)', fontSize: 'var(--fs-md)' }}>{f.label}</span>
                <span style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>{value ? 'Oui' : 'Non'}</span>
            </div>
        );
    }

    return (
        <div style={{ gridColumn: wide ? '1 / -1' : 'auto', minWidth: 0 }}>
            {f.type === 'lines' ? (
                <ListShell field={f} error={error} count={Array.isArray(value) ? value.length : 0}>
                    <Lines field={f} values={(Array.isArray(value) ? value : []) as string[]} disabled={disabled} onChange={onChange} />
                </ListShell>
            ) : f.type === 'items' ? (
                <ListShell field={f} error={error} count={Array.isArray(value) ? value.length : 0}>
                    <Items field={f} path={path} items={(Array.isArray(value) ? value : []) as Item[]} errors={errors} disabled={disabled} onChange={onChange} />
                </ListShell>
            ) : (
                <Field label={f.label} required={f.required} error={error} hint={f.type === 'file' ? f.hint : hint} span="full">
                    {f.type === 'area' && <TextArea value={text} rows={f.rows ?? 3} maxLength={f.max} placeholder={f.placeholder} disabled={disabled} invalid={!!error} onChange={(e) => onChange(e.target.value)} />}
                    {f.type === 'select' && <SelectInput value={text} options={f.options ?? []} disabled={disabled} invalid={!!error} onChange={(e) => onChange(e.target.value)} />}
                    {['text', 'url', 'tel', 'email'].includes(f.type) && <TextInput type={f.type === 'text' ? 'text' : f.type} value={text} maxLength={f.max} placeholder={f.placeholder} disabled={disabled} invalid={!!error} onChange={(e) => onChange(e.target.value)} />}
                    {f.type === 'file' && <FileField field={f} path={path} value={typeof value === 'string' ? value : null} disabled={disabled} onChange={onChange} info={fileInfo(typeof value === 'string' ? value : null)} />}
                </Field>
            )}
        </div>
    );
}

function ListShell({ field: f, error, count, children }: { field: LandingField; error?: string; count: number; children: React.ReactNode }) {
    return (
        <fieldset style={{ border: '1px solid var(--c-line)', borderRadius: 'var(--radius-lg)', padding: 'var(--space-3)', margin: 0, minWidth: 0 }}>
            <legend style={{ padding: '0 var(--space-1)', fontWeight: 'var(--fw-semibold)', fontSize: 'var(--fs-md)' }}>
                {f.label}
                <span style={{ marginLeft: 'var(--space-1_5)', fontWeight: 'var(--fw-regular)', color: 'var(--c-muted)', fontSize: 'var(--fs-sm)' }}>
                    {count}
                    {f.max ? ` / ${f.max}` : ''}
                </span>
            </legend>
            {f.hint && <div style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)', marginBottom: 'var(--space-2)' }}>{f.hint}</div>}
            {children}
            {error && (
                <div role="alert" style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-1)', marginTop: 'var(--space-2)', fontSize: 'var(--fs-sm)', color: 'var(--c-danger-fg)' }}>
                    <Icon name="error" size="var(--icon-xs)" />
                    {error}
                </div>
            )}
        </fieldset>
    );
}

function moveIn<T>(list: T[], i: number, dir: -1 | 1): T[] {
    const next = [...list];
    const j = i + dir;
    if (j < 0 || j >= next.length) return next;
    [next[i], next[j]] = [next[j], next[i]];
    return next;
}

function RowTools({ i, count, fixed, disabled, onMove, onRemove, label }: { i: number; count: number; fixed: boolean; disabled: boolean; onMove: (dir: -1 | 1) => void; onRemove: () => void; label: string }) {
    return (
        <>
            <Button icon="arrow_upward" size="sm" variant="ghost" aria-label={`Monter ${label}`} title="Monter" disabled={disabled || i === 0} onClick={() => onMove(-1)} />
            <Button icon="arrow_downward" size="sm" variant="ghost" aria-label={`Descendre ${label}`} title="Descendre" disabled={disabled || i === count - 1} onClick={() => onMove(1)} />
            {!fixed && <Button icon="delete" size="sm" variant="ghost" aria-label={`Retirer ${label}`} title="Retirer" disabled={disabled} onClick={onRemove} />}
        </>
    );
}

function Lines({ field: f, values, disabled, onChange }: { field: LandingField; values: string[]; disabled: boolean; onChange: (v: string[]) => void }) {
    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-2)' }}>
            {values.map((line, i) => (
                <div key={i} style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-1)' }}>
                    <TextInput value={line} maxLength={f.item_max} aria-label={`${f.label} ${i + 1}`} disabled={disabled} onChange={(e) => onChange(values.map((l, j) => (j === i ? e.target.value : l)))} />
                    <RowTools i={i} count={values.length} fixed={false} disabled={disabled} label={`l’élément ${i + 1}`} onMove={(dir) => onChange(moveIn(values, i, dir))} onRemove={() => onChange(values.filter((_, j) => j !== i))} />
                </div>
            ))}
            {!disabled && (!f.max || values.length < f.max) && <Button label={f.add ?? 'Ajouter'} icon="add" size="sm" variant="ghost" style={{ alignSelf: 'flex-start' }} onClick={() => onChange([...values, ''])} />}
        </div>
    );
}

function Items({ field: f, path, items, errors, disabled, onChange }: { field: LandingField; path: string; items: Item[]; errors: Record<string, string | undefined>; disabled: boolean; onChange: (v: Item[]) => void }) {
    const fixed = !!f.max && f.min === f.max;
    const blank = (): Item => Object.fromEntries((f.sub ?? []).map((sf) => [sf.key, sf.type === 'toggle' ? false : sf.type === 'select' ? (sf.options?.[0]?.value ?? '') : '']));

    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-3)' }}>
            {items.map((item, i) => {
                const title = (item[f.sub?.[0]?.key ?? ''] as string) || `Élément ${i + 1}`;
                return (
                    <div key={i} style={{ border: '1px solid var(--c-line)', borderRadius: 'var(--radius-lg)', padding: 'var(--space-3)', background: 'var(--c-surface-2, var(--c-surface))' }}>
                        <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-1)', marginBottom: 'var(--space-2)' }}>
                            <span style={{ fontFamily: 'var(--font-mono, monospace)', fontSize: 'var(--fs-sm)', color: 'var(--c-muted)', width: 24 }}>{String(i + 1).padStart(2, '0')}</span>
                            <span style={{ flex: 1, fontWeight: 'var(--fw-semibold)', fontSize: 'var(--fs-md)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{title}</span>
                            <RowTools i={i} count={items.length} fixed={fixed} disabled={disabled} label={title} onMove={(dir) => onChange(moveIn(items, i, dir))} onRemove={() => onChange(items.filter((_, j) => j !== i))} />
                        </div>
                        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,240px),1fr))', gap: 'var(--space-3)' }}>
                            {(f.sub ?? []).map((sf) => (
                                <FieldInput key={sf.key} field={sf} path={`${path}.${i}.${sf.key}`} value={item[sf.key] as LandingValue} errors={errors} disabled={disabled} fileInfo={() => null} onChange={(v) => onChange(items.map((it, j) => (j === i ? { ...it, [sf.key]: v as string | boolean | null } : it)))} />
                            ))}
                        </div>
                    </div>
                );
            })}
            {!disabled && !fixed && (!f.max || items.length < f.max) && <Button label={f.add ?? 'Ajouter'} icon="add" size="sm" variant="ghost" style={{ alignSelf: 'flex-start' }} onClick={() => onChange([...items, blank()])} />}
        </div>
    );
}

function FileField({ field: f, path, value, disabled, onChange, info }: { field: LandingField; path: string; value: string | null; disabled: boolean; onChange: (v: string | null) => void; info: FileInfo | null }) {
    const input = useRef<HTMLInputElement>(null);
    const [sending, setSending] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const send = (file: File | undefined) => {
        if (!file) return;
        setError(null);
        setSending(true);
        router.post('/landing-page/fichiers', { file, accept: f.accept ?? 'image/*', field: path }, {
            forceFormData: true,
            preserveState: true,
            preserveScroll: true,
            onError: (e) => setError(e.file ?? 'Ce fichier a été refusé.'),
            onFinish: () => {
                setSending(false);
                if (input.current) input.current.value = '';
            },
        });
    };

    return (
        <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)', flexWrap: 'wrap', padding: 'var(--space-2_5) var(--space-3)', border: '1.5px dashed var(--c-line-strong)', borderRadius: 'var(--radius-lg)' }}>
            <Icon name={info ? (f.accept === 'video/mp4' ? 'movie' : 'image') : 'hide_image'} />
            <span style={{ flex: 1, minWidth: 160, fontSize: 'var(--fs-md)', color: info ? 'var(--c-ink)' : 'var(--c-muted)' }}>{info ? info.name : 'Aucun fichier · le site garde son fichier actuel'}</span>
            {info?.url && <Button label="Voir" icon="open_in_new" size="sm" variant="ghost" onClick={() => window.open(info.url as string, '_blank', 'noopener')} />}
            {!disabled && <Button label={sending ? 'Envoi…' : value ? 'Remplacer' : 'Envoyer un fichier'} icon="upload" size="sm" variant="secondary" disabled={sending} onClick={() => input.current?.click()} />}
            {!disabled && value && <Button icon="delete" size="sm" variant="ghost" aria-label="Retirer le fichier" title="Retirer le fichier" onClick={() => onChange(null)} />}
            <input ref={input} type="file" accept={f.accept} hidden onChange={(e) => send(e.target.files?.[0])} />
            {error && (
                <span role="alert" style={{ width: '100%', fontSize: 'var(--fs-sm)', color: 'var(--c-danger-fg)' }}>
                    {error}
                </span>
            )}
        </div>
    );
}
