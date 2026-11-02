import Button from '@/components/ui/Button';
import Field, { PhoneInput, SelectInput, TextInput, type Option } from '@/components/ui/Field';
import Icon from '@/components/ui/Icon';
import { useAdminUi } from '@/contexts/AdminUiContext';
import { Head, router, useForm } from '@inertiajs/react';
import { useEffect, type FormEvent, type ReactNode } from 'react';

type Values = {
    profile_kind: string;
    first_name: string;
    last_name: string;
    phone: string;
    email: string;
    job_title: string;
    address: string;
    commune_id: string;
    neighborhood_id: string;
    company_legal_name: string;
    company_trade_name: string;
    company_legal_form: string;
    company_rccm: string;
    company_ncc: string;
    hq_address: string;
    hq_commune_id: string;
    hq_neighborhood_id: string;
    send_invitation: boolean;
};

type TextKey = Exclude<keyof Values, 'send_invitation'>;

type Props = {
    mode: 'create' | 'edit';
    promoter: { id: string; name: string } | null;
    values: Values;
    canChangeKind: boolean;
    options: Record<'profile_kind' | 'commune' | 'legal_form', Option[]>;
    neighborhoods: Record<string, Option[]>;
};

/** Création ou modification d’un promoteur (particulier, technicien ou entreprise). */
export default function PromoterForm({ mode, promoter, values, canChangeKind, options, neighborhoods }: Props) {
    const ui = useAdminUi();
    const form = useForm<Values>(values);
    const { data, errors } = form;
    const errorCount = Object.keys(errors).length;
    const isNew = mode === 'create';
    const isCompany = data.profile_kind === 'entreprise';
    const backUrl = promoter ? `/promoteurs/${promoter.id}` : '/promoteurs';
    const displayName = (isCompany ? data.company_legal_name : `${data.first_name} ${data.last_name}`).trim() || 'ce promoteur';

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
        const missing: Partial<Record<keyof Values, string>> = {};
        (['first_name', 'last_name', ...(isCompany ? (['company_legal_name'] as const) : [])] as TextKey[]).forEach((key) => {
            if (!data[key].trim()) missing[key] = 'Ce champ est obligatoire.';
        });
        if (!data.phone.trim() && !data.email.trim()) missing.phone = missing.email = 'Saisissez un numéro de téléphone ou une adresse e-mail.';
        if (Object.keys(missing).length) {
            form.clearErrors();
            form.setError(missing);
            window.scrollTo({ top: 0, behavior: 'smooth' });
            return;
        }

        const channel = data.phone.trim() ? `par SMS au ${data.phone}` : `par e-mail à ${data.email}`;
        ui.confirm({
            icon: isNew ? 'person_add' : 'save',
            title: isNew ? 'Créer ce promoteur ?' : 'Confirmer la modification',
            body: isNew
                ? `Le compte de « ${displayName} » sera créé en attente d’activation. ${data.send_invitation ? `Une invitation à l’activer dans l’application sera envoyée ${channel}.` : 'Aucune invitation ne sera envoyée.'}`
                : `Les informations de « ${displayName} » seront mises à jour. Un dossier validé le reste ; la modification est enregistrée dans le journal d’audit.${values.phone !== data.phone || values.email !== data.email ? ' Le changement de téléphone ou d’e-mail déconnecte le promoteur de ses appareils.' : ''}`,
            ok: isNew ? 'Créer le promoteur' : 'Enregistrer',
            onConfirm: () => {
                const opts = { onError: () => window.scrollTo({ top: 0, behavior: 'smooth' }) };
                if (isNew) form.post('/promoteurs', opts);
                else form.patch(`/promoteurs/${promoter!.id}`, opts);
            },
        });
    };

    const cancel = () => {
        if (!form.isDirty) return router.visit(backUrl);
        ui.confirm({ tone: 'danger', icon: 'warning', title: 'Quitter sans enregistrer ?', body: 'Les modifications saisies dans ce formulaire seront perdues.', ok: 'Quitter sans enregistrer', cancel: 'Continuer la saisie', onConfirm: () => router.visit(backUrl) });
    };

    const set = (key: TextKey) => (value: string) =>
        form.setData((current) => ({
            ...current,
            [key]: value,
            // Changer de commune vide le quartier, qui dépend d’elle.
            ...(key === 'commune_id' ? { neighborhood_id: '' } : {}),
            ...(key === 'hq_commune_id' ? { hq_neighborhood_id: '' } : {}),
        }));
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

                <FormSection icon="manage_accounts" title="Compte" description="Type de compte et coordonnées de connexion. Le téléphone ou l’e-mail sert d’identifiant dans l’application.">
                    {select('profile_kind', 'Type de compte', options.profile_kind, {
                        required: true,
                        disabled: !isNew && !canChangeKind,
                        hint: !isNew && !canChangeKind ? 'Le dossier a été transmis pour vérification : le type ne peut plus être modifié.' : undefined,
                    })}
                    <Field label="Téléphone" error={errors.phone} hint="Ou une adresse e-mail">
                        <PhoneInput label="Téléphone" value={data.phone} onChange={set('phone')} invalid={!!errors.phone} />
                    </Field>
                    {text('email', 'Adresse e-mail', { type: 'email', placeholder: 'nom@exemple.ci' })}
                    {isNew && (
                        <label style={{ gridColumn: '1 / -1', display: 'flex', alignItems: 'center', gap: 'var(--space-2)', fontSize: 'var(--fs-md)' }}>
                            <input type="checkbox" checked={data.send_invitation} onChange={(e) => form.setData('send_invitation', e.target.checked)} />
                            Envoyer au promoteur une invitation à activer son compte (SMS, ou e-mail à défaut de téléphone)
                        </label>
                    )}
                </FormSection>

                <FormSection icon="badge" title={isCompany ? 'Dirigeant' : 'Identité'} description={isCompany ? 'Personne habilitée à engager l’entreprise.' : 'Identité et adresse du promoteur.'}>
                    {text('first_name', 'Prénom', { required: true })}
                    {text('last_name', 'Nom', { required: true })}
                    {text('job_title', 'Fonction', { placeholder: isCompany ? 'Ex. Gérant' : 'Ex. Particulier' })}
                    {select('commune_id', 'Commune', options.commune)}
                    {select('neighborhood_id', 'Quartier', neighborhoods[data.commune_id] ?? [], { disabled: !data.commune_id })}
                    {text('address', 'Adresse', { placeholder: 'Rue, lot, repère', span: 'full' })}
                </FormSection>

                {isCompany && (
                    <FormSection icon="domain" title="Entreprise" description="Identité légale et siège. RCCM, NCC et siège peuvent être complétés plus tard.">
                        {text('company_legal_name', 'Raison sociale', { required: true, placeholder: 'Ex. Bâtir Afrique' })}
                        {text('company_trade_name', 'Nom commercial')}
                        {select('company_legal_form', 'Forme juridique', options.legal_form)}
                        {text('company_rccm', 'N° RCCM', { placeholder: 'CI-ABJ-2026-B-00000' })}
                        {text('company_ncc', 'N° NCC', { placeholder: '0000000 A' })}
                        {select('hq_commune_id', 'Commune du siège', options.commune)}
                        {select('hq_neighborhood_id', 'Quartier du siège', neighborhoods[data.hq_commune_id] ?? [], { disabled: !data.hq_commune_id })}
                        {text('hq_address', 'Adresse du siège', { placeholder: 'Rue, quartier, commune', span: 'full' })}
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
