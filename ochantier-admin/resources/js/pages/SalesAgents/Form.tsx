import Button from '@/components/ui/Button';
import Field, { PhoneInput, SelectInput, TextInput, type Option } from '@/components/ui/Field';
import Icon from '@/components/ui/Icon';
import { Head, router, useForm } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';

type Values = {
    first_name: string;
    last_name: string;
    phone: string;
    secondary_phone: string;
    email: string;
    address: string;
    started_at: string;
    supervisor_id: string;
    is_supervisor: boolean;
    primary_commune_id: string;
    secondary_commune_ids: string[];
    send_link: boolean;
};

type TextKey = { [K in keyof Values]: Values[K] extends string ? K : never }[keyof Values];

type Props = {
    mode: 'create' | 'edit';
    agent: { id: string; name: string; code: string } | null;
    values: Values;
    options: { supervisor: Option[]; commune: Option[] };
};

/** Création et modification d’un commercial. */
export default function SalesAgentForm({ mode, agent, values, options }: Props) {
    const form = useForm<Values>(values);
    const isNew = mode === 'create';
    const cancel = () => router.visit(agent ? `/commerciaux/${agent.id}` : '/commerciaux');

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (isNew) form.post('/commerciaux');
        else form.patch(`/commerciaux/${agent?.id}`);
    };

    const text = (name: TextKey, label: string, props: { required?: boolean; type?: string; placeholder?: string; span?: 'full' } = {}) => (
        <Field label={label} required={props.required} error={form.errors[name]} span={props.span}>
            <TextInput type={props.type} placeholder={props.placeholder} value={form.data[name]} invalid={!!form.errors[name]} onChange={(e) => form.setData(name, e.target.value)} />
        </Field>
    );

    const toggleZone = (id: string) => form.setData('secondary_commune_ids', form.data.secondary_commune_ids.includes(id) ? form.data.secondary_commune_ids.filter((z) => z !== id) : [...form.data.secondary_commune_ids, id]);

    return (
        <>
            <Head title={isNew ? 'Ajouter un commercial' : `Modifier ${agent?.name}`} />
            <form onSubmit={submit} noValidate style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)' }}>
                <FormSection icon="person" title="Identité et coordonnées" description="Le téléphone est l’identifiant de connexion à l’application. Le changer ferme ses sessions.">
                    {text('first_name', 'Prénom', { required: true })}
                    {text('last_name', 'Nom', { required: true })}
                    <Field label="Téléphone" required error={form.errors.phone}>
                        <PhoneInput label="Téléphone" value={form.data.phone} invalid={!!form.errors.phone} onChange={(v) => form.setData('phone', v)} />
                    </Field>
                    {text('secondary_phone', 'Second téléphone')}
                    {text('email', 'E-mail', { type: 'email' })}
                    {text('address', 'Adresse')}
                </FormSection>

                <FormSection icon="badge" title="Contrat" description="Le matricule est attribué automatiquement. La commission suit la règle commune à tous les commerciaux.">
                    {text('started_at', 'Date de début', { required: true, type: 'date' })}
                    <Field label="Superviseur" error={form.errors.supervisor_id}>
                        <SelectInput value={form.data.supervisor_id} options={options.supervisor} placeholder="Aucun" onChange={(e) => form.setData('supervisor_id', e.target.value)} />
                    </Field>
                    <label style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)', fontSize: 'var(--fs-md)' }}>
                        <input type="checkbox" checked={form.data.is_supervisor} onChange={(e) => form.setData('is_supervisor', e.target.checked)} />
                        Ce commercial supervise une équipe
                    </label>
                </FormSection>

                {isNew && (
                    <FormSection icon="location_on" title="Zones" description="Communes où le commercial recrute. Les changements ultérieurs se feront depuis sa fiche.">
                        <Field label="Zone principale" required error={form.errors.primary_commune_id} span="full">
                            <SelectInput value={form.data.primary_commune_id} options={options.commune} invalid={!!form.errors.primary_commune_id} onChange={(e) => form.setData('primary_commune_id', e.target.value)} />
                        </Field>
                        <Field label="Zones secondaires" error={form.errors.secondary_commune_ids} span="full">
                            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill,minmax(180px,1fr))', gap: 'var(--space-1_5)', maxHeight: 220, overflowY: 'auto' }}>
                                {options.commune
                                    .filter((c) => c.value !== form.data.primary_commune_id)
                                    .map((c) => (
                                        <label key={c.value} style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)', fontSize: 'var(--fs-md)' }}>
                                            <input type="checkbox" checked={form.data.secondary_commune_ids.includes(c.value)} onChange={() => toggleZone(c.value)} />
                                            {c.label}
                                        </label>
                                    ))}
                            </div>
                        </Field>
                    </FormSection>
                )}

                {isNew && (
                    <FormSection icon="forward_to_inbox" title="Accès" description="Le commercial choisit lui-même son mot de passe depuis un lien valable 72 heures.">
                        <label style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)', fontSize: 'var(--fs-md)' }}>
                            <input type="checkbox" checked={form.data.send_link} onChange={(e) => form.setData('send_link', e.target.checked)} />
                            Envoyer le lien d’activation par SMS, et par e-mail si l’adresse est renseignée
                        </label>
                    </FormSection>
                )}

                <div style={{ display: 'flex', alignItems: 'center', flexWrap: 'wrap', gap: 'var(--space-2_5)' }}>
                    <span style={{ flex: 1, fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>* Champs obligatoires</span>
                    <Button label="Annuler" variant="secondary" onClick={cancel} />
                    <Button type="submit" label={isNew ? 'Créer le commercial' : 'Enregistrer les modifications'} icon="check" variant="primary" disabled={form.processing} />
                </div>
            </form>
        </>
    );
}

function FormSection({ icon, title, description, children }: { icon: string; title: string; description: string; children: ReactNode }) {
    return (
        <section style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,260px),1fr))', gap: 'var(--space-6)', background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-5)' }}>
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
