import Button from '@/components/ui/Button';
import Field, { PhoneInput, SelectInput, TextArea, TextInput, type Option } from '@/components/ui/Field';
import Icon from '@/components/ui/Icon';
import { useAdminUi } from '@/contexts/AdminUiContext';
import { Head, router, useForm } from '@inertiajs/react';
import { useEffect, type FormEvent, type ReactNode } from 'react';

type Values = {
    name: string;
    legal_form: string;
    rccm: string;
    ncc: string;
    sector: string;
    head_office: string;
    representative: string;
    representative_role: string;
    phone: string;
    email: string;
    account_type: string;
    location: string;
    note: string;
};

type Props = {
    mode: 'create' | 'edit' | 'company';
    promoter: { slug: string; name: string } | null;
    values: Values;
    options: Record<'legal_form' | 'sector' | 'account_type' | 'location', Option[]>;
};

/** Création ou modification d’un promoteur ; en mode « company », seules l’entreprise et le représentant. */
export default function PromoterForm({ mode, promoter, values, options }: Props) {
    const ui = useAdminUi();
    const form = useForm<Values & { mode?: string }>({ ...values, ...(mode === 'company' ? { mode: 'company' } : {}) });
    const { data, errors } = form;
    const errorCount = Object.keys(errors).length;
    const isNew = mode === 'create';
    const backUrl = promoter ? `/promoteurs/${promoter.slug}${mode === 'company' ? '/kyc' : ''}` : '/promoteurs';

    // Avertit avant de quitter la page avec des modifications non enregistrées.
    useEffect(() => {
        if (!form.isDirty) return;
        const onUnload = (e: BeforeUnloadEvent) => e.preventDefault();
        window.addEventListener('beforeunload', onUnload);
        return () => window.removeEventListener('beforeunload', onUnload);
    }, [form.isDirty]);

    const submit = (e: FormEvent) => {
        e.preventDefault();

        // Champs obligatoires vérifiés avant la confirmation ; le serveur valide ensuite les formats.
        const required: (keyof Values)[] = ['name', 'legal_form', 'rccm', 'head_office', 'representative', 'phone', 'email', ...(mode === 'company' ? [] : (['account_type', 'location'] as const))];
        const missing = required.filter((key) => !String(data[key] ?? '').trim());
        if (missing.length) {
            form.clearErrors();
            form.setError(Object.fromEntries(missing.map((key) => [key, 'Ce champ est obligatoire.'])) as Partial<Record<keyof Values, string>>);
            window.scrollTo({ top: 0, behavior: 'smooth' });
            return;
        }

        ui.confirm({
            icon: isNew ? 'person_add' : 'save',
            title: isNew ? 'Créer ce promoteur ?' : 'Confirmer la modification',
            body: isNew
                ? `Le compte « ${data.name || 'sans nom'} » sera créé avec le statut « À valider ». Un e-mail d’activation sera envoyé à ${data.email || 'l’adresse indiquée'}.`
                : `Les informations de « ${data.name} » seront mises à jour. L’action est enregistrée dans l’historique.`,
            ok: isNew ? 'Créer le promoteur' : 'Enregistrer',
            onConfirm: () => {
                const options = { onError: () => window.scrollTo({ top: 0, behavior: 'smooth' }) };
                if (isNew) form.post('/promoteurs', options);
                else form.put(`/promoteurs/${promoter!.slug}`, options);
            },
        });
    };

    const cancel = () => {
        if (!form.isDirty) return router.visit(backUrl);
        ui.confirm({ tone: 'danger', icon: 'warning', title: 'Quitter sans enregistrer ?', body: 'Les modifications saisies dans ce formulaire seront perdues.', ok: 'Quitter sans enregistrer', cancel: 'Continuer la saisie', onConfirm: () => router.visit(backUrl) });
    };

    const set = (key: keyof Values) => (value: string) => form.setData((current) => ({ ...current, [key]: value }));
    const text = (key: keyof Values, label: string, opts: { required?: boolean; placeholder?: string; type?: string; span?: 'full' } = {}) => (
        <Field label={label} required={opts.required} error={errors[key]} span={opts.span}>
            <TextInput type={opts.type ?? 'text'} value={data[key]} onChange={(e) => set(key)(e.target.value)} placeholder={opts.placeholder} invalid={!!errors[key]} />
        </Field>
    );
    const select = (key: keyof Values & keyof Props['options'], label: string, required = false) => (
        <Field label={label} required={required} error={errors[key]}>
            <SelectInput value={data[key]} onChange={(e) => set(key)(e.target.value)} options={options[key]} invalid={!!errors[key]} />
        </Field>
    );

    return (
        <>
            <Head title={isNew ? 'Nouveau promoteur' : `Modifier ${promoter?.name}`} />
            <form onSubmit={submit} noValidate style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)' }}>
                {errorCount > 0 && (
                    <div role="alert" style={{ display: 'flex', gap: 'var(--space-3)', alignItems: 'center', padding: 'var(--space-3) var(--space-4)', borderRadius: 'var(--radius-lg)', background: 'var(--c-danger-bg)', color: 'var(--c-danger-fg)' }}>
                        <Icon name="error" />
                        <span style={{ fontSize: 'var(--fs-md)', fontWeight: 'var(--fw-semibold)' }}>
                            {errorCount} champ{errorCount > 1 ? 's' : ''} à corriger avant d’enregistrer.
                        </span>
                    </div>
                )}

                <FormSection icon="domain" title="Entreprise" description="Identité légale et adresse du siège.">
                    {text('name', 'Raison sociale', { required: true, placeholder: 'Ex. SICOGI' })}
                    {select('legal_form', 'Forme juridique', true)}
                    {text('rccm', 'N° d’immatriculation (RCCM)', { required: true, placeholder: 'CI-ABJ-2026-B-00000' })}
                    {text('ncc', 'N° de contribuable (NCC)', { placeholder: '0000000 A' })}
                    {select('sector', 'Secteur d’activité')}
                    {text('head_office', 'Adresse du siège', { required: true, placeholder: 'Rue, quartier, commune', span: 'full' })}
                </FormSection>

                <FormSection icon="badge" title="Représentant légal" description="Personne habilitée à engager l’entreprise.">
                    {text('representative', 'Nom du représentant', { required: true, placeholder: 'Prénom Nom' })}
                    {text('representative_role', 'Fonction', { placeholder: 'Ex. Gérant' })}
                    <Field label="Téléphone" required error={errors.phone}>
                        <PhoneInput label="Téléphone" value={data.phone} onChange={set('phone')} invalid={!!errors.phone} />
                    </Field>
                    {text('email', 'Adresse e-mail', { required: true, type: 'email', placeholder: 'nom@entreprise.ci' })}
                </FormSection>

                {mode !== 'company' && (
                    <FormSection icon="manage_accounts" title="Compte" description="Paramètres du compte sur la plateforme.">
                        {select('account_type', 'Type de compte', true)}
                        {select('location', 'Localisation', true)}
                        <Field label="Note interne" error={errors.note} span="full">
                            <TextArea value={data.note} onChange={(e) => set('note')(e.target.value)} rows={3} placeholder="Visible uniquement par les administrateurs" />
                        </Field>
                    </FormSection>
                )}

                <div style={{ position: 'sticky', bottom: 0, zIndex: 5, display: 'flex', alignItems: 'center', gap: 'var(--space-3)', flexWrap: 'wrap', padding: 'var(--space-4) var(--space-5)', background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', boxShadow: 'var(--shadow-md)' }}>
                    {form.isDirty && (
                        <span style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-1_5)', fontSize: 'var(--fs-md)', color: 'var(--c-warning-fg)', fontWeight: 'var(--fw-semibold)' }}>
                            <Icon name="edit_note" />
                            Modifications non enregistrées
                        </span>
                    )}
                    <span style={{ flex: 1, fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>* Champs obligatoires</span>
                    <Button label="Annuler" variant="secondary" onClick={cancel} />
                    <Button type="submit" label={isNew ? 'Créer le promoteur' : 'Enregistrer les modifications'} icon="check" variant="primary" disabled={form.processing} />
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
