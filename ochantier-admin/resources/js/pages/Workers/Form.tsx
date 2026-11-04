import Button from '@/components/ui/Button';
import Field, { PhoneInput, SelectInput, TextInput, type Option } from '@/components/ui/Field';
import Icon from '@/components/ui/Icon';
import { useAdminUi } from '@/contexts/AdminUiContext';
import { Head, router, useForm } from '@inertiajs/react';
import { useEffect, type FormEvent, type ReactNode } from 'react';

type Reference = { project: string; role: string; employer: string; start_date: string; end_date: string };

type Values = {
    commercial_id: string;
    first_name: string;
    last_name: string;
    phone: string;
    secondary_phone: string;
    gender: string;
    birth_date: string;
    birth_place: string;
    residence_commune_id: string;
    residence_neighborhood_id: string;
    residence_address: string;
    residence_landmark: string;
    emergency_contact_name: string;
    emergency_contact_relation: string;
    emergency_phone: string;
    trade_id: string;
    experience_years: string;
    secondary_trade_id: string;
    secondary_experience_years: string;
    specialty_ids: string[];
    skill_ids: string[];
    daily_rate: string;
    mobility_radius_km: string;
    transport_mode: string;
    accepts_long_travel: boolean;
    needs_accommodation: boolean;
    works_solo: boolean;
    works_in_team: boolean;
    accepted_offer_types: string[];
    accepted_payment_frequencies: string[];
    references: Reference[];
};

type TextKey = { [K in keyof Values]: Values[K] extends string ? K : never }[keyof Values];
type ListKey = 'specialty_ids' | 'skill_ids' | 'accepted_offer_types' | 'accepted_payment_frequencies';
type FlagKey = 'accepts_long_travel' | 'needs_accommodation' | 'works_solo' | 'works_in_team';

type Props = {
    mode: 'create' | 'edit';
    worker: { id: string; name: string; status: string } | null;
    values: Values;
    options: Record<'commercial' | 'trade' | 'commune' | 'gender' | 'offer_type' | 'payment_frequency' | 'transport', Option[]>;
    neighborhoods: Record<string, Option[]>;
    tradeLists: Record<string, { specialties: Option[]; skills: Option[] }>;
};

const EMPTY_REFERENCE: Reference = { project: '', role: '', employer: '', start_date: '', end_date: '' };

/** Création ou modification d’un ouvrier par le Back Office. */
export default function WorkerForm({ mode, worker, values, options, neighborhoods, tradeLists }: Props) {
    const ui = useAdminUi();
    const form = useForm<Values>(values);
    const { data } = form;
    const errors = form.errors as Record<string, string | undefined>;
    const errorCount = Object.keys(errors).length;
    const isNew = mode === 'create';
    const backUrl = worker ? `/ouvriers/${worker.id}` : '/ouvriers';
    const displayName = `${data.first_name} ${data.last_name}`.trim() || 'cet ouvrier';
    const trades = [data.trade_id, data.secondary_trade_id].filter(Boolean);
    const specialties = trades.flatMap((t) => tradeLists[t]?.specialties ?? []);
    const skills = trades.flatMap((t) => tradeLists[t]?.skills ?? []);

    // Avertit avant de quitter la page avec des modifications non enregistrées.
    useEffect(() => {
        if (!form.isDirty) return;
        const onUnload = (e: BeforeUnloadEvent) => e.preventDefault();
        window.addEventListener('beforeunload', onUnload);
        return () => window.removeEventListener('beforeunload', onUnload);
    }, [form.isDirty]);

    const submit = (e: FormEvent) => {
        e.preventDefault();

        // Champs obligatoires vérifiés avant la confirmation ; l’API valide ensuite les formats et l’unicité.
        const missing: Record<string, string> = {};
        (['commercial_id', 'first_name', 'last_name', 'phone', 'residence_commune_id', 'trade_id', 'experience_years'] as TextKey[]).forEach((key) => {
            if (!data[key].trim()) missing[key] = 'Ce champ est obligatoire.';
        });
        if (Object.keys(missing).length) {
            form.clearErrors();
            form.setError(missing as never);
            window.scrollTo({ top: 0, behavior: 'smooth' });
            return;
        }

        ui.confirm({
            icon: isNew ? 'person_add' : 'save',
            title: isNew ? 'Enregistrer cet ouvrier ?' : 'Confirmer la modification',
            body: isNew
                ? `« ${displayName} » sera rattaché au commercial choisi, sans commission. Le dossier sera transmis au contrôle dès que la pièce d’identité et la photo seront ajoutées.`
                : `Les informations de « ${displayName} » seront mises à jour. Un dossier validé le reste ; la modification est inscrite au journal d’audit et signalée au commercial référent.`,
            ok: isNew ? 'Enregistrer l’ouvrier' : 'Enregistrer',
            onConfirm: () => {
                const opts = { onError: () => window.scrollTo({ top: 0, behavior: 'smooth' }) };
                if (isNew) form.post('/ouvriers', opts);
                else form.patch(`/ouvriers/${worker!.id}`, opts);
            },
        });
    };

    const cancel = () => {
        if (!form.isDirty) return router.visit(backUrl);
        ui.confirm({ tone: 'danger', icon: 'warning', title: 'Quitter sans enregistrer ?', body: 'Les modifications saisies dans ce formulaire seront perdues.', ok: 'Quitter sans enregistrer', cancel: 'Continuer la saisie', onConfirm: () => router.visit(backUrl) });
    };

    const set = (key: TextKey) => (value: string) =>
        form.setData((current) => {
            const next = { ...current, [key]: value };
            // Changer de commune vide le quartier ; changer de métier retire les spécialités et compétences qui ne s’y rattachent plus.
            if (key === 'residence_commune_id') next.residence_neighborhood_id = '';
            if (key === 'trade_id' || key === 'secondary_trade_id') {
                const kept = [next.trade_id, next.secondary_trade_id].filter(Boolean);
                const allowed = (list: 'specialties' | 'skills') => new Set(kept.flatMap((t) => (tradeLists[t]?.[list] ?? []).map((o) => o.value)));
                next.specialty_ids = next.specialty_ids.filter((id) => allowed('specialties').has(id));
                next.skill_ids = next.skill_ids.filter((id) => allowed('skills').has(id));
            }
            return next;
        });
    const toggle = (key: ListKey, value: string) => form.setData(key, data[key].includes(value) ? data[key].filter((v) => v !== value) : [...data[key], value]);
    const text = (key: TextKey, label: string, opts: { required?: boolean; placeholder?: string; type?: string; span?: 'full'; hint?: string } = {}) => (
        <Field label={label} required={opts.required} error={errors[key]} span={opts.span} hint={opts.hint}>
            <TextInput type={opts.type ?? 'text'} value={data[key]} onChange={(e) => set(key)(e.target.value)} placeholder={opts.placeholder} invalid={!!errors[key]} />
        </Field>
    );
    const select = (key: TextKey, label: string, list: Option[], opts: { required?: boolean; disabled?: boolean; hint?: string } = {}) => (
        <Field label={label} required={opts.required} error={errors[key]} hint={opts.hint}>
            <SelectInput value={data[key]} onChange={(e) => set(key)(e.target.value)} options={list} invalid={!!errors[key]} disabled={opts.disabled} />
        </Field>
    );
    const flag = (key: FlagKey, label: string) => (
        <label style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)', fontSize: 'var(--fs-md)' }}>
            <input type="checkbox" checked={data[key]} onChange={(e) => form.setData(key, e.target.checked)} />
            {label}
        </label>
    );
    const chips = (key: ListKey, label: string, list: Option[], empty: string) => (
        <Field label={label} error={errors[key]} span="full">
            <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2)' }}>
                {list.map((o) => {
                    const on = data[key].includes(o.value);
                    return (
                        <button
                            key={o.value}
                            type="button"
                            aria-pressed={on}
                            onClick={() => toggle(key, o.value)}
                            style={{ height: 'var(--control-xs)', padding: '0 var(--space-3)', borderRadius: 'var(--radius-pill)', border: `1px solid ${on ? 'var(--c-primary)' : 'var(--c-line-strong)'}`, background: on ? 'var(--c-primary)' : 'var(--c-surface)', color: on ? 'var(--c-on-dark)' : 'var(--c-ink-2)', fontWeight: 'var(--fw-semibold)', fontSize: 'var(--fs-sm)', cursor: 'pointer' }}
                        >
                            {o.label}
                        </button>
                    );
                })}
                {!list.length && <span style={{ fontSize: 'var(--fs-md)', color: 'var(--c-muted)' }}>{empty}</span>}
            </div>
        </Field>
    );
    const setReference = (index: number, key: keyof Reference, value: string) => form.setData('references', data.references.map((r, i) => (i === index ? { ...r, [key]: value } : r)));

    return (
        <>
            <Head title={isNew ? 'Nouvel ouvrier' : `Modifier ${worker?.name}`} />
            <form onSubmit={submit} noValidate style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)' }}>
                {errorCount > 0 && (
                    <div role="alert" style={{ display: 'flex', gap: 'var(--space-3)', alignItems: 'center', padding: 'var(--space-3) var(--space-4)', borderRadius: 'var(--radius-lg)', background: 'var(--c-danger-bg)', color: 'var(--c-danger-fg)' }}>
                        <Icon name="error" />
                        <span style={{ fontSize: 'var(--fs-md)', fontWeight: 'var(--fw-semibold)' }}>
                            {errorCount} champ{errorCount > 1 ? 's' : ''} à corriger avant d’enregistrer.
                        </span>
                    </div>
                )}

                <FormSection icon="badge" title="Rattachement" description="Commercial qui suivra l’ouvrier sur le terrain. Un ouvrier enregistré par le Back Office n’ouvre pas de commission.">
                    {select('commercial_id', 'Commercial référent', options.commercial, { required: true })}
                </FormSection>

                <FormSection icon="person" title="Identité" description="Identité et coordonnées de l’ouvrier. Le téléphone est unique sur O’CHANTIER.">
                    {text('first_name', 'Prénom', { required: true })}
                    {text('last_name', 'Nom', { required: true })}
                    <Field label="Téléphone" required error={errors.phone}>
                        <PhoneInput label="Téléphone" value={data.phone} onChange={set('phone')} invalid={!!errors.phone} />
                    </Field>
                    {text('secondary_phone', 'Téléphone secondaire', { placeholder: 'Facultatif' })}
                    {select('gender', 'Sexe', options.gender)}
                    {text('birth_date', 'Date de naissance', { type: 'date' })}
                    {text('birth_place', 'Lieu de naissance')}
                </FormSection>

                <FormSection icon="home_pin" title="Résidence et contact d’urgence" description="Lieu de résidence, utilisé par le matching pour proposer des chantiers proches.">
                    {select('residence_commune_id', 'Commune', options.commune, { required: true })}
                    {select('residence_neighborhood_id', 'Quartier', neighborhoods[data.residence_commune_id] ?? [], { disabled: !data.residence_commune_id })}
                    {text('residence_address', 'Adresse', { placeholder: 'Rue, lot', span: 'full' })}
                    {text('residence_landmark', 'Repère', { placeholder: 'Ex. derrière la pharmacie', span: 'full' })}
                    {text('emergency_contact_name', 'Contact d’urgence', { placeholder: 'Nom et prénom' })}
                    {text('emergency_contact_relation', 'Lien', { placeholder: 'Ex. Épouse, frère' })}
                    <Field label="Téléphone du contact" error={errors.emergency_phone} hint="Requis pour transmettre le dossier">
                        <PhoneInput label="Téléphone du contact" value={data.emergency_phone} onChange={set('emergency_phone')} invalid={!!errors.emergency_phone} />
                    </Field>
                </FormSection>

                <FormSection icon="engineering" title="Métiers et tarif" description="Le niveau est déduit des années d’expérience. Les spécialités et compétences dépendent des métiers choisis.">
                    {select('trade_id', 'Métier principal', options.trade, { required: true })}
                    {text('experience_years', 'Années d’expérience', { required: true, type: 'number' })}
                    {select('secondary_trade_id', 'Métier secondaire', options.trade.filter((t) => t.value !== data.trade_id))}
                    {text('secondary_experience_years', 'Années dans ce métier', { type: 'number' })}
                    {text('daily_rate', 'Coût journalier (FCFA)', { type: 'number', hint: 'Requis pour transmettre le dossier' })}
                    {chips('specialty_ids', 'Spécialités', specialties, 'Choisissez d’abord un métier.')}
                    {chips('skill_ids', 'Compétences', skills, 'Choisissez d’abord un métier.')}
                </FormSection>

                <FormSection icon="directions_walk" title="Conditions de travail" description="Mobilité et préférences de l’ouvrier pour les missions proposées.">
                    {text('mobility_radius_km', 'Rayon de mobilité (km)', { type: 'number' })}
                    {select('transport_mode', 'Moyen de transport', options.transport)}
                    <div style={{ gridColumn: '1 / -1', display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2) var(--space-5)' }}>
                        {flag('works_solo', 'Travaille seul')}
                        {flag('works_in_team', 'Travaille en équipe')}
                        {flag('accepts_long_travel', 'Accepte les longs déplacements')}
                        {flag('needs_accommodation', 'A besoin d’un hébergement')}
                    </div>
                    {chips('accepted_offer_types', 'Types de mission acceptés', options.offer_type, '')}
                    {chips('accepted_payment_frequencies', 'Rythmes de paiement acceptés', options.payment_frequency, '')}
                </FormSection>

                <FormSection icon="work_history" title="Références" description="Chantiers ou projets passés de l’ouvrier (10 au plus).">
                    <div style={{ gridColumn: '1 / -1', display: 'flex', flexDirection: 'column', gap: 'var(--space-3)' }}>
                        {data.references.map((r, i) => (
                            <div key={i} style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,160px),1fr)) auto', gap: 'var(--space-2)', alignItems: 'end' }}>
                                <Field label="Chantier ou projet" required error={errors[`references.${i}.project`]}>
                                    <TextInput value={r.project} onChange={(e) => setReference(i, 'project', e.target.value)} invalid={!!errors[`references.${i}.project`]} />
                                </Field>
                                <Field label="Rôle">
                                    <TextInput value={r.role} onChange={(e) => setReference(i, 'role', e.target.value)} />
                                </Field>
                                <Field label="Employeur">
                                    <TextInput value={r.employer} onChange={(e) => setReference(i, 'employer', e.target.value)} />
                                </Field>
                                <Field label="Début">
                                    <TextInput type="date" value={r.start_date} onChange={(e) => setReference(i, 'start_date', e.target.value)} />
                                </Field>
                                <Field label="Fin" error={errors[`references.${i}.end_date`]}>
                                    <TextInput type="date" value={r.end_date} onChange={(e) => setReference(i, 'end_date', e.target.value)} invalid={!!errors[`references.${i}.end_date`]} />
                                </Field>
                                <Button icon="delete" variant="secondary" aria-label="Retirer la référence" onClick={() => form.setData('references', data.references.filter((_, j) => j !== i))} />
                            </div>
                        ))}
                        {data.references.length < 10 && (
                            <div>
                                <Button label="Ajouter une référence" icon="add" variant="secondary" onClick={() => form.setData('references', [...data.references, { ...EMPTY_REFERENCE }])} />
                            </div>
                        )}
                    </div>
                </FormSection>

                <div style={{ position: 'sticky', bottom: 0, zIndex: 5, display: 'flex', alignItems: 'center', gap: 'var(--space-3)', flexWrap: 'wrap', padding: 'var(--space-4) var(--space-5)', background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', boxShadow: 'var(--shadow-md)' }}>
                    {form.isDirty && (
                        <span style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-1_5)', fontSize: 'var(--fs-md)', color: 'var(--c-warning-fg)', fontWeight: 'var(--fw-semibold)' }}>
                            <Icon name="edit_note" />
                            Modifications non enregistrées
                        </span>
                    )}
                    <span style={{ flex: 1, fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>* Champs obligatoires</span>
                    <Button label="Annuler" variant="secondary" onClick={cancel} />
                    <Button type="submit" label={isNew ? 'Enregistrer l’ouvrier' : 'Enregistrer les modifications'} icon="check" variant="primary" disabled={form.processing} />
                </div>
            </form>
        </>
    );
}

function FormSection({ icon, title, description, children }: { icon: string; title: string; description: string; children: ReactNode }) {
    return (
        <section style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,260px),1fr))', gap: 'var(--space-6)', background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-6)' }}>
            <div style={{ maxWidth: 300 }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)' }}>
                    <Icon name={icon} size="var(--icon-md)" color="var(--c-primary)" />
                    <h3 style={{ margin: 0, fontSize: 'var(--fs-xl)' }}>{title}</h3>
                </div>
                <p style={{ margin: 'var(--space-1_5) 0 0', fontSize: 'var(--fs-md)', color: 'var(--c-muted)' }}>{description}</p>
            </div>
            <div style={{ gridColumn: 'span 2', display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,240px),1fr))', gap: 'var(--space-4)' }}>{children}</div>
        </section>
    );
}
