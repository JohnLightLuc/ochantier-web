import { ActionBar, BlockNav, TableBlock } from '@/components/blocks/blocks';
import { useBlockActions } from '@/components/blocks/useBlockActions';
import { FilterSelect, SearchInput } from '@/components/list/ListToolbar';
import { useAdminUi } from '@/contexts/AdminUiContext';
import Button from '@/components/ui/Button';
import Field, { SelectInput, TextInput } from '@/components/ui/Field';
import Icon from '@/components/ui/Icon';
import StatusBadge from '@/components/ui/StatusBadge';
import { AlertList } from '@/components/workers/blocks';
import { formatNumber } from '@/lib/format';
import { toneColors } from '@/lib/tone';
import type { SettingField, SettingGroup, SettingsPageProps, SettingValue, Trade } from '@/types/settings';
import { Head, router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState, type ReactNode } from 'react';

type Draft = Record<string, SettingValue>;

const card = { background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-5)', minWidth: 0 } as const;
const grid = (min: number) => ({ display: 'grid', gridTemplateColumns: `repeat(auto-fit,minmax(min(100%,${min}px),1fr))`, gap: 'var(--space-4)' });
const num = (v: SettingValue | undefined) => Number(String(v ?? '').replace(/\s/g, '').replace(',', '.')) || 0;
const norm = (v: SettingValue | undefined) => JSON.stringify(Array.isArray(v) ? v.map((x) => (typeof x === 'object' ? x : String(x))) : String(v ?? ''));
const fcfa = (v: number) => `${formatNumber(Math.round(v))} FCFA`;

/**
 * Paramètres de la plateforme : sections de réglages éditables (validation par le serveur, simulateurs,
 * réinitialisation, protection des modifications non enregistrées), utilisateurs & rôles, intégrations et
 * journal d’audit.
 */
export default function SettingsPage(props: SettingsPageProps) {
    const { section, nav, definition, values, defaults, lastChange, profiles, operators, tables, toolbar, query, rubriques } = props;
    const errors = usePage().props.errors as Record<string, string>;
    const ui = useAdminUi();
    const { run, dialog } = useBlockActions({});
    const editable = definition.groups.length > 0;
    const [draft, setDraft] = useState<Draft>(values);
    const [saving, setSaving] = useState(false);
    const valuesKey = JSON.stringify(values);

    useEffect(() => setDraft(values), [valuesKey]); // eslint-disable-line react-hooks/exhaustive-deps

    const changed = useMemo(() => Object.keys(values).filter((k) => norm(draft[k]) !== norm(values[k])), [draft, values]);
    const dirty = changed.length > 0;

    useEffect(() => {
        if (!dirty) return;
        const leave = (e: BeforeUnloadEvent) => e.preventDefault();
        window.addEventListener('beforeunload', leave);
        const off = router.on('before', (event) => {
            if (event.detail.visit.method === 'get' && !window.confirm('Des modifications ne sont pas enregistrées. Quitter sans enregistrer ?')) return false;
        });
        return () => {
            window.removeEventListener('beforeunload', leave);
            off();
        };
    }, [dirty]);

    const set = (code: string) => (v: SettingValue) => setDraft((d) => ({ ...d, [code]: v }));
    const save = () =>
        router.post(`/parametres/${section}`, { values: draft }, { preserveScroll: true, preserveState: true, onStart: () => setSaving(true), onFinish: () => setSaving(false), onError: () => ui.toast('Corrigez les champs signalés avant d’enregistrer.', { tone: 'error' }) });
    const reset = () =>
        ui.confirm({ title: 'Réinitialiser la section', body: 'Les valeurs par défaut seront restaurées dans le formulaire. Elles ne sont appliquées qu’après enregistrement.', icon: 'restart_alt', tone: 'warning', ok: 'Réinitialiser', onConfirm: () => setDraft((d) => ({ ...d, ...defaults })) });
    const apply = (changes: Record<string, string | undefined>) => router.get(`/parametres/${section}`, { ...query, ...changes, page: undefined }, { preserveScroll: true, preserveState: true });

    return (
        <>
            <Head title={`Paramètres · ${definition.title}`} />
            <BlockNav nav={nav} label="Sections des paramètres" />
            <section style={{ ...card, display: 'flex', flexDirection: 'column', gap: 'var(--space-4)' }}>
                <div style={{ display: 'flex', alignItems: 'flex-start', gap: 'var(--space-3)', flexWrap: 'wrap' }}>
                    <span style={{ display: 'grid', placeItems: 'center', width: 44, height: 44, borderRadius: 'var(--radius-lg)', background: 'var(--c-primary-50)', color: 'var(--c-primary)', flex: 'none' }}>
                        <Icon name={definition.icon} />
                    </span>
                    <div style={{ flex: 1, minWidth: 220 }}>
                        <h2 style={{ margin: 0, fontSize: 'var(--fs-2xl)' }}>{definition.title}</h2>
                        <p style={{ margin: 'var(--space-1) 0 0', color: 'var(--c-muted)' }}>{definition.desc}</p>
                        {lastChange && (
                            <p style={{ margin: 'var(--space-1) 0 0', fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>
                                Dernière modification par <b>{lastChange.author}</b> le {new Date(lastChange.occurred_at).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' })}
                            </p>
                        )}
                    </div>
                    {editable && <StatusBadge label={dirty ? `${changed.length} modification(s) non enregistrée(s)` : 'Aucune modification'} tone={dirty ? 'warning' : 'success'} icon={dirty ? 'edit' : 'check_circle'} />}
                    {toolbar.length > 0 && <ActionBar buttons={toolbar} run={run} />}
                </div>
                <AlertList alerts={[{ tone: 'info', icon: 'info', title: '', text: definition.banner }]} />

                {definition.groups.map((g, i) => (
                    <Group key={g.title} group={g} draft={draft} set={set} errors={errors} groupError={errors[`group.${i}`]} />
                ))}

                {section === 'commissions' && <CommissionSimulator draft={draft} />}
                {section === 'paiements' && <FeeSimulator draft={draft} operators={operators} />}
                {section === 'matching' && <MatchingSimulator draft={draft} profiles={profiles} group={definition.groups[1]} />}

                {section === 'audit' && (
                    <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2)' }}>
                        <SearchInput value={query.q ?? ''} placeholder="Rechercher une modification ou un auteur" onChange={(q) => apply({ q: q || undefined })} />
                        <FilterSelect label="Toutes les rubriques" value={query.rubrique ?? ''} options={rubriques.map((r) => ({ value: r, label: r }))} onChange={(r) => apply({ rubrique: r || undefined })} />
                    </div>
                )}
                {tables.map((t) => (
                    <TableBlock key={t.title} table={t} run={run} onPage={(page) => router.get(`/parametres/${section}`, { ...query, page }, { preserveScroll: true, preserveState: true })} />
                ))}

                {editable && (
                    <div style={{ position: 'sticky', bottom: 0, display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2)', alignItems: 'center', padding: 'var(--space-3) 0', borderTop: '1px solid var(--c-line)', background: 'var(--c-surface)' }}>
                        <Button label="Réinitialiser" icon="restart_alt" variant="ghost" onClick={reset} />
                        <span style={{ flex: 1 }} />
                        <Button label="Annuler" icon="undo" disabled={!dirty || saving} onClick={() => setDraft(values)} />
                        <Button label={saving ? 'Enregistrement…' : 'Enregistrer'} icon="save" variant="primary" disabled={!dirty || saving} onClick={save} />
                    </div>
                )}
            </section>
            {dialog}
        </>
    );
}

type GroupProps = { group: SettingGroup; draft: Draft; set: (code: string) => (v: SettingValue) => void; errors: Record<string, string>; groupError?: string };

function Group({ group, draft, set, errors, groupError }: GroupProps) {
    const total = group.sum ? group.fields.reduce((s, f) => s + num(draft[f.code]), 0) : 0;
    const aside = group.sum ? <StatusBadge label={`Total ${total} / ${group.sum} %`} tone={total === group.sum ? 'success' : 'danger'} /> : null;

    return (
        <section style={{ border: '1px solid var(--c-line)', borderRadius: 'var(--radius-xl)', padding: 'var(--space-4)', minWidth: 0 }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)', marginBottom: 'var(--space-3)' }}>
                <Icon name={group.icon} size="var(--icon-md)" color="var(--c-primary)" />
                <h3 style={{ margin: 0, fontSize: 'var(--fs-xl)', flex: 1 }}>{group.title}</h3>
                {aside}
            </div>
            {groupError && <AlertList alerts={[{ tone: 'danger', icon: 'error', title: '', text: groupError }]} />}
            {group.kind === 'rules' ? (
                <div style={grid(260)}>
                    {group.fields.map((f) => (
                        <RuleCard key={f.code} field={f} draft={draft} set={set} error={errors[f.code]} />
                    ))}
                </div>
            ) : group.kind === 'thresholds' ? (
                <Thresholds field={group.fields[0]} first={num(draft.r)} list={(draft[group.fields[0].code] as (number | string)[]) ?? []} set={set(group.fields[0].code)} error={errors[group.fields[0].code]} />
            ) : (
                <div style={grid(group.kind === 'modes' ? 240 : 280)}>
                    {group.fields.map((f) => (f.type === 'trades' ? <Trades key={f.code} field={f} list={(draft[f.code] as Trade[]) ?? []} set={set(f.code)} error={errors[f.code]} /> : <SettingInput key={f.code} field={f} value={draft[f.code]} set={set(f.code)} error={errors[f.code]} />))}
                </div>
            )}
        </section>
    );
}

function Switch({ checked, label, onText, offText, onChange }: { checked: boolean; label: string; onText: string; offText: string; onChange: (v: boolean) => void }) {
    return (
        <button type="button" role="switch" aria-checked={checked} aria-label={label} onClick={() => onChange(!checked)} style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2_5)', border: 0, background: 'transparent', padding: 0, cursor: 'pointer', fontSize: 'var(--fs-base)', color: 'var(--c-ink-2)', textAlign: 'left' }}>
            <span style={{ position: 'relative', width: 46, height: 26, flex: 'none', borderRadius: 'var(--radius-pill)', background: checked ? 'var(--c-primary-600)' : 'var(--c-line-hover)' }}>
                <span style={{ position: 'absolute', top: 3, left: checked ? 23 : 3, width: 20, height: 20, borderRadius: 'var(--radius-round)', background: 'var(--c-surface)', boxShadow: 'var(--shadow-xs)' }} />
            </span>
            {checked ? onText : offText}
        </button>
    );
}

function NumberInput({ field, value, set, error }: { field: SettingField; value: SettingValue | undefined; set: (v: SettingValue) => void; error?: string }) {
    return (
        <div style={{ position: 'relative' }}>
            <TextInput inputMode={field.dec ? 'decimal' : 'numeric'} value={String(value ?? '')} invalid={!!error} aria-label={field.label} onChange={(e) => set(e.target.value)} style={{ paddingRight: 'var(--space-16)', fontVariantNumeric: 'tabular-nums' }} />
            <span style={{ position: 'absolute', right: 'var(--space-3)', top: '50%', transform: 'translateY(-50%)', color: 'var(--c-muted)', fontSize: 'var(--fs-sm)', pointerEvents: 'none' }}>{field.unit}</span>
        </div>
    );
}

function SettingInput({ field, value, set, error }: { field: SettingField; value: SettingValue | undefined; set: (v: SettingValue) => void; error?: string }) {
    const hint = field.type === 'num' ? `${field.desc} · ${formatNumber(field.min ?? 0)} à ${formatNumber(field.max ?? 0)} ${field.unit}` : field.desc;

    return (
        <Field label={field.label} hint={hint} error={error}>
            {field.type === 'num' && <NumberInput field={field} value={value} set={set} error={error} />}
            {field.type === 'text' && <TextInput value={String(value ?? '')} invalid={!!error} onChange={(e) => set(e.target.value)} />}
            {field.type === 'select' && <SelectInput value={String(value ?? '')} invalid={!!error} placeholder="" options={(field.options ?? []).map((o) => ({ value: o, label: o }))} onChange={(e) => set(e.target.value)} />}
            {field.type === 'bool' && <Switch checked={!!value} label={field.label} onText={field.labels?.[0] ?? 'Activé'} offText={field.labels?.[1] ?? 'Désactivé'} onChange={set} />}
        </Field>
    );
}

function RuleCard({ field, draft, set, error }: { field: SettingField; draft: Draft; set: GroupProps['set']; error?: string }) {
    const on = field.switch ? !!draft[field.switch] : true;
    const { bg, fg } = toneColors(field.tone as never);

    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-3)', padding: 'var(--space-4)', border: `1px solid ${error ? 'var(--c-danger)' : 'var(--c-line)'}`, borderRadius: 'var(--radius-xl)', opacity: on ? 1 : 0.75 }}>
            <div style={{ display: 'flex', gap: 'var(--space-2_5)', alignItems: 'flex-start' }}>
                <span style={{ display: 'grid', placeItems: 'center', width: 38, height: 38, borderRadius: 'var(--radius-lg)', background: bg, color: fg, flex: 'none' }}>
                    <Icon name={field.icon ?? 'tune'} />
                </span>
                <div style={{ minWidth: 0 }}>
                    <b style={{ display: 'block' }}>{field.label}</b>
                    <span style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>{field.desc}</span>
                </div>
            </div>
            <Field label="Valeur" error={error}>
                <NumberInput field={field} value={draft[field.code]} set={set(field.code)} error={error} />
            </Field>
            {field.switch && <Switch checked={on} label={`${field.label} · activation`} onText={field.labels?.[0] ?? 'Actif'} offText={field.labels?.[1] ?? 'Inactif'} onChange={set(field.switch)} />}
        </div>
    );
}

function Thresholds({ field, first, list, set, error }: { field: SettingField; first: number; list: (number | string)[]; set: (v: SettingValue) => void; error?: string }) {
    const max = field.max ?? 8;
    const add = () => {
        const last = num(list.length ? list[list.length - 1] : first);
        set([...list.map(num), Math.min(100, last + 10)]);
    };

    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-3)' }}>
            <p style={{ margin: 0, color: 'var(--c-muted)', fontSize: 'var(--fs-sm)' }}>{field.desc}. Chaque seuil doit être supérieur au précédent et ne pas dépasser 100 %.</p>
            <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2)', alignItems: 'center' }}>
                <StatusBadge label={`Seuil 1 (R) · ${first} %`} tone="success" />
                {list.map((v, i) => (
                    <span key={i} style={{ display: 'inline-flex', alignItems: 'center', gap: 'var(--space-1)' }}>
                        <Icon name="chevron_right" color="var(--c-muted)" />
                        <TextInput aria-label={`Seuil ${i + 2}`} inputMode="numeric" value={String(v)} invalid={!!error} onChange={(e) => set(list.map((x, j) => (j === i ? e.target.value : x)) as number[])} style={{ width: 80 }} />
                        <Button icon="close" variant="ghost" size="sm" aria-label={`Retirer le seuil ${i + 2}`} onClick={() => set(list.filter((_, j) => j !== i) as number[])} />
                    </span>
                ))}
                <Button label="Ajouter un seuil" icon="add" size="sm" disabled={list.length >= max} onClick={add} />
            </div>
            {error && (
                <span role="alert" style={{ color: 'var(--c-danger-fg)', fontSize: 'var(--fs-sm)' }}>
                    {error}
                </span>
            )}
        </div>
    );
}

function Trades({ field, list, set, error }: { field: SettingField; list: Trade[]; set: (v: SettingValue) => void; error?: string }) {
    const [name, setName] = useState('');
    const usage = field.usage ?? {};
    const exists = list.some((t) => t.name.toLowerCase() === name.trim().toLowerCase());
    const add = () => {
        set([...list, { name: name.trim(), active: true }]);
        setName('');
    };

    return (
        <div style={{ gridColumn: '1 / -1', display: 'flex', flexDirection: 'column', gap: 'var(--space-3)' }}>
            <div style={grid(260)}>
                {list.map((t, i) => {
                    const u = usage[t.name] ?? { workers: 0, validated: 0 };
                    return (
                        <div key={t.name} style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)', padding: 'var(--space-3)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-lg)', opacity: t.active ? 1 : 0.7 }}>
                            <div style={{ flex: 1, minWidth: 0 }}>
                                <b style={{ display: 'block' }}>{t.name}</b>
                                <span style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>{u.workers ? `${u.workers} ouvrier(s) · ${u.validated} validé(s)` : 'Aucun ouvrier'}</span>
                            </div>
                            <Switch checked={t.active} label={`${t.name} · activation`} onText="Actif" offText="Désactivé" onChange={(active) => set(list.map((x, j) => (j === i ? { ...x, active } : x)))} />
                            <Button icon="delete" variant="ghost" size="sm" aria-label={`Supprimer ${t.name}`} title={u.workers ? 'Utilisé par des ouvriers : désactivez-le plutôt' : 'Supprimer'} disabled={u.workers > 0} onClick={() => set(list.filter((_, j) => j !== i))} />
                        </div>
                    );
                })}
            </div>
            <div style={{ display: 'flex', gap: 'var(--space-2)', flexWrap: 'wrap' }}>
                <TextInput aria-label="Nouveau métier" placeholder="Nouveau métier (ex. Étancheur)" value={name} onChange={(e) => setName(e.target.value)} style={{ maxWidth: 320 }} />
                <Button label="Ajouter le métier" icon="add" disabled={name.trim().length < 3 || exists} onClick={add} />
            </div>
            {error && (
                <span role="alert" style={{ color: 'var(--c-danger-fg)', fontSize: 'var(--fs-sm)' }}>
                    {error}
                </span>
            )}
        </div>
    );
}

function Simulation({ icon, title, sub, children }: { icon: string; title: string; sub: string; children: ReactNode }) {
    return (
        <section style={{ border: '1px dashed var(--c-line-strong)', borderRadius: 'var(--radius-xl)', padding: 'var(--space-4)', background: 'var(--c-surface-2)' }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)', marginBottom: 'var(--space-3)' }}>
                <Icon name={icon} size="var(--icon-md)" color="var(--c-primary)" />
                <div style={{ flex: 1 }}>
                    <h3 style={{ margin: 0, fontSize: 'var(--fs-xl)' }}>{title}</h3>
                    <span style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>{sub}</span>
                </div>
            </div>
            {children}
        </section>
    );
}

function Rows({ rows }: { rows: [string, string, boolean?][] }) {
    return (
        <dl style={{ margin: 0, display: 'grid', gridTemplateColumns: '1fr auto', gap: 'var(--space-2) var(--space-4)' }}>
            {rows.map(([label, value, strong]) => (
                <div key={label} style={{ display: 'contents' }}>
                    <dt style={{ color: strong ? 'var(--c-ink)' : 'var(--c-ink-2)', fontWeight: strong ? 'var(--fw-semibold)' : undefined }}>{label}</dt>
                    <dd style={{ margin: 0, textAlign: 'right', fontVariantNumeric: 'tabular-nums', fontWeight: strong ? 'var(--fw-bold)' : 'var(--fw-semibold)' }}>{value}</dd>
                </div>
            ))}
        </dl>
    );
}

function CommissionSimulator({ draft }: { draft: Draft }) {
    const [cost, setCost] = useState('15000');
    const c = num(cost);
    const z = draft['z.on'] ? num(draft.z) : 0;
    const y = draft['y.on'] ? num(draft.y) : 0;
    const x = draft['x.on'] ? num(draft.x) : 0;

    return (
        <Simulation icon="calculate" title="Simulateur de coût" sub="Calcul avec les valeurs saisies, avant enregistrement">
            <div style={grid(260)}>
                <Field label="Coût journalier de l’ouvrier" hint="Montant en FCFA">
                    <TextInput inputMode="numeric" value={cost} onChange={(e) => setCost(e.target.value.replace(/\D/g, ''))} />
                </Field>
                <Rows
                    rows={[
                        [`Commission ouvrier (Z · ${z} %)`, `− ${fcfa((c * z) / 100)}`],
                        ['Net versé à l’ouvrier', fcfa(c - (c * z) / 100)],
                        [`Commission promoteur (Y · ${y} %)`, `+ ${fcfa((c * y) / 100)}`],
                        ['Total payé par le promoteur', fcfa(c + (c * y) / 100), true],
                        [`Acompte exigé (X · ${x} %)`, fcfa(((c + (c * y) / 100) * x) / 100)],
                    ]}
                />
            </div>
        </Simulation>
    );
}

function FeeSimulator({ draft, operators }: { draft: Draft; operators: string[] }) {
    return (
        <Simulation icon="calculate" title="Frais sur un paiement de 100 000 FCFA" sub={`Frais à la charge de : ${String(draft['py.feePayer'] ?? '')}`}>
            <Rows rows={operators.map((o) => [o, fcfa((100000 * num(draft[`fee.${o}.p`])) / 100 + num(draft[`fee.${o}.f`]))] as [string, string])} />
        </Simulation>
    );
}

function MatchingSimulator({ draft, profiles, group }: { draft: Draft; profiles: SettingsPageProps['profiles']; group?: SettingGroup }) {
    const weights = Object.keys(draft).filter((k) => k.startsWith('mx.w.'));
    const levels = (group?.fields ?? []).map((f) => ({ label: f.label, min: num(draft[f.code]) }));

    return (
        <Simulation icon="science" title="Simulation du score" sub="Score de trois profils types avec les pondérations saisies">
            <div style={grid(220)}>
                {profiles.map((p) => {
                    const score = Math.round(weights.reduce((s, k, i) => s + ((p.notes[i] ?? 0) * num(draft[k])) / 100, 0));
                    const level = levels.find((l) => score >= l.min)?.label ?? 'Non qualifié';
                    return (
                        <div key={p.label} style={{ padding: 'var(--space-3)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-lg)', background: 'var(--c-surface)' }}>
                            <b style={{ display: 'block' }}>{p.label}</b>
                            <span style={{ display: 'block', fontSize: 'var(--fs-3xl)', fontWeight: 'var(--fw-bold)', fontVariantNumeric: 'tabular-nums' }}>{score}</span>
                            <StatusBadge label={level} tone={level === 'Non qualifié' || level === 'Match faible' ? 'warning' : 'success'} />
                        </div>
                    );
                })}
            </div>
        </Simulation>
    );
}
