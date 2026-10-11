import Button from '@/components/ui/Button';
import Field, { PhoneInput, SelectInput, TextArea, TextInput, phoneDigits } from '@/components/ui/Field';
import Icon from '@/components/ui/Icon';
import StatusBadge from '@/components/ui/StatusBadge';
import { useAdminUi } from '@/contexts/AdminUiContext';
import { formatDate, formatNumber, todayIso } from '@/lib/format';
import type { SalesDocument } from '@/types/sales';
import { Head, router, useForm } from '@inertiajs/react';
import { useEffect, useState, type ReactNode } from 'react';

type Values = {
    first_name: string;
    last_name: string;
    gender: string;
    birth_date: string;
    nationality: string;
    phone: string;
    phone_secondary: string;
    email: string;
    address: string;
    commune: string;
    city: string;
    emergency_contact: string;
    emergency_phone: string;
    type: string;
    job_title: string;
    agency: string;
    zone: string;
    secondary_zones: string[];
    supervisor: string;
    started_at: string;
    contract: string;
    weekly_target: string;
    monthly_target: string;
    specialties: string[];
    internal_note: string;
    id_type: string;
    id_number: string;
    id_issued_at: string;
    id_expires_at: string;
    role: string;
    level: string;
    modules: string[];
    data_zones: string[];
    permissions: Record<string, boolean>;
    two_factor: boolean;
    duration: string;
    pay_mode: string;
    fixed: string;
    per_registered: string;
    per_validated: string;
    bonus_objective: string;
    bonus_cotm: string;
    calc_frequency: string;
    pay_frequency: string;
    pay_method: string;
    effective_from: string;
    cap: string;
    eligibility: string;
    pay_note: string;
};

type FileKey = 'photo' | 'id_front' | 'id_back' | 'photo_id' | 'proof_of_address' | 'contract_file' | 'cv';
type Files = Record<FileKey, File | null>;

type ListOptions = Record<'communes' | 'trades' | 'nationalities' | 'types' | 'agencies' | 'supervisors' | 'contracts' | 'idTypes' | 'roles' | 'levels' | 'modules' | 'durations' | 'payModes' | 'calcFrequencies' | 'payFrequencies' | 'payMethods', string[]>;

type Props = {
    mode: 'create' | 'edit';
    agent: { slug: string; name: string; code: string } | null;
    code: string;
    step: number;
    values: Values | null;
    documents: SalesDocument[];
    options: ListOptions & { permissions: Record<string, string> };
};

const NO_AGENCY = 'Aucune agence (indépendant)';

const BLANK: Values = {
    first_name: '', last_name: '', gender: '', birth_date: '', nationality: 'Ivoirienne', phone: '', phone_secondary: '', email: '', address: '', commune: '', city: 'Abidjan', emergency_contact: '', emergency_phone: '',
    type: '', job_title: '', agency: '', zone: '', secondary_zones: [], supervisor: '', started_at: todayIso(), contract: '', weekly_target: '6', monthly_target: '24', specialties: [], internal_note: '',
    id_type: '', id_number: '', id_issued_at: '', id_expires_at: '',
    role: 'Commercial terrain', level: 'Standard', modules: ['Ouvriers', 'Documents', 'Gains'], data_zones: [], permissions: { create: true, edit_own: true, documents: true, submit: true, assignments: false, earnings: true, export: false }, two_factor: true, duration: 'Illimitée',
    pay_mode: 'Commission', fixed: '', per_registered: '1500', per_validated: '3500', bonus_objective: '50000', bonus_cotm: '100000', calc_frequency: 'Hebdomadaire', pay_frequency: 'Mensuelle', pay_method: 'Orange Money', effective_from: todayIso(), cap: '600000', eligibility: 'Profils validés uniquement, hors doublons et rejets', pay_note: '',
};

const NO_FILES: Files = { photo: null, id_front: null, id_back: null, photo_id: null, proof_of_address: null, contract_file: null, cv: null };

const FILE_FIELDS: [Exclude<FileKey, 'photo'>, string, string][] = [
    ['id_front', 'Pièce recto', 'id_front'],
    ['id_back', 'Pièce verso', 'id_back'],
    ['photo_id', 'Photo d’identité', 'photo'],
    ['proof_of_address', 'Justificatif de domicile', 'proof_of_address'],
    ['contract_file', 'Contrat ou convention', 'contract'],
    ['cv', 'Curriculum vitae', 'cv'],
];

const STEPS: [string, string, (code: string) => string][] = [
    ['Informations personnelles', 'badge', () => 'Coordonnées. Le téléphone principal sert d’identifiant de connexion.'],
    ['Informations professionnelles', 'work', (code) => `Identifiant généré automatiquement : ${code}.`],
    ['Documents justificatifs', 'folder_shared', () => 'Pièce d’identité, contrat et justificatifs.'],
    ['Accès et autorisations', 'admin_panel_settings', () => 'Le mot de passe n’est jamais défini par l’administration : le commercial le crée à l’activation.'],
    ['Paramétrage des gains', 'payments', () => 'Mode de rémunération, commissions et bonus.'],
    ['Récapitulatif', 'fact_check', () => 'Vérifiez les informations avant de créer le compte.'],
];

/** Étape où se trouve chaque champ, pour y revenir en cas d’erreur du serveur. */
const FIELD_STEP: Record<string, number> = {
    photo: 0, first_name: 0, last_name: 0, gender: 0, birth_date: 0, nationality: 0, phone: 0, phone_secondary: 0, email: 0, address: 0, commune: 0, city: 0, emergency_contact: 0, emergency_phone: 0,
    type: 1, job_title: 1, agency: 1, zone: 1, secondary_zones: 1, supervisor: 1, started_at: 1, contract: 1, weekly_target: 1, monthly_target: 1, specialties: 1, internal_note: 1,
    id_type: 2, id_number: 2, id_issued_at: 2, id_expires_at: 2, id_front: 2, id_back: 2, photo_id: 2, proof_of_address: 2, contract_file: 2, cv: 2,
    role: 3, level: 3, modules: 3, data_zones: 3, permissions: 3, two_factor: 3, duration: 3,
    pay_mode: 4, fixed: 4, per_registered: 4, per_validated: 4, bonus_objective: 4, bonus_cotm: 4, calc_frequency: 4, pay_frequency: 4, pay_method: 4, effective_from: 4, cap: 4, eligibility: 4, pay_note: 4,
};

const AMOUNTS = ['fixed', 'per_registered', 'per_validated', 'bonus_objective', 'bonus_cotm', 'cap'] as const;

const DRAFT_KEY = 'och_comm_draft';

type Draft = { values: Values; step: number; at: string };

function readDraft(): Draft | null {
    try {
        return JSON.parse(localStorage.getItem(DRAFT_KEY) ?? 'null');
    } catch {
        return null;
    }
}

function writeDraft(draft: Draft | null): void {
    try {
        if (draft) localStorage.setItem(DRAFT_KEY, JSON.stringify(draft));
        else localStorage.removeItem(DRAFT_KEY);
    } catch {
        // Stockage indisponible : le brouillon n’est simplement pas conservé.
    }
}

/** Contrôles d’une étape avant de passer à la suivante (le serveur revalide tout à l’envoi, dont l’unicité). */
function stepErrors(v: Values, step: number): Record<string, string> {
    const e: Record<string, string> = {};
    const required = (key: keyof Values) => {
        if (!String(v[key] ?? '').trim()) e[key] = 'Ce champ est obligatoire.';
    };
    const tel = (key: 'phone' | 'phone_secondary' | 'emergency_phone') => {
        if (v[key] && phoneDigits(v[key]).length !== 10) e[key] = 'Le numéro doit contenir 10 chiffres.';
    };
    const integer = (key: keyof Values) => {
        if (v[key] && !/^\d+$/.test(String(v[key]).replace(/\s/g, ''))) e[key] = 'Nombre entier attendu.';
    };

    if (step === 0) {
        required('first_name');
        required('last_name');
        required('phone');
        required('email');
        tel('phone');
        tel('phone_secondary');
        tel('emergency_phone');
        if (v.email && !/^[^\s@]+@[^\s@]+\.[a-z]{2,}$/i.test(v.email)) e.email = 'Adresse e-mail invalide.';
    }
    if (step === 1) {
        required('type');
        required('zone');
        integer('weekly_target');
        integer('monthly_target');
    }
    if (step === 2 && v.id_expires_at && v.id_issued_at && v.id_expires_at <= v.id_issued_at) e.id_expires_at = 'La date d’expiration doit suivre la date de délivrance.';
    if (step === 4) AMOUNTS.forEach(integer);
    return e;
}

/** Ajout ou modification d’un commercial : parcours en 6 étapes. */
export default function SalesAgentForm({ mode, agent, code, step: initialStep, values, documents, options }: Props) {
    const ui = useAdminUi();
    const isNew = mode === 'create';
    const [draft] = useState(() => (isNew ? readDraft() : null));
    const form = useForm<Values & Files>({ ...(values ?? (draft ? { ...BLANK, ...draft.values } : BLANK)), ...NO_FILES });
    const [step, setStep] = useState(draft?.step ?? initialStep);
    const [tried, setTried] = useState<Record<number, boolean>>({});
    const [savedAt, setSavedAt] = useState<string | null>(draft?.at ?? null);
    const [restored, setRestored] = useState(!!draft);
    const { data } = form;
    const backUrl = agent ? `/commerciaux/${agent.slug}` : '/commerciaux';

    const localErrors = tried[step] ? stepErrors(data, step) : {};
    const errors: Record<string, string | undefined> = { ...form.errors, ...localErrors };
    const errorCount = Object.keys(localErrors).length + Object.keys(form.errors).filter((k) => (FIELD_STEP[k.split('.')[0]] ?? 0) === step).length;

    useEffect(() => {
        if (!form.isDirty) return;
        const onUnload = (e: BeforeUnloadEvent) => e.preventDefault();
        window.addEventListener('beforeunload', onUnload);
        return () => window.removeEventListener('beforeunload', onUnload);
    }, [form.isDirty]);

    const saveDraft = (silent = false, nextStep = step) => {
        if (!isNew) return;
        const at = new Date().toLocaleString('fr-FR', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
        const rest = Object.fromEntries(Object.entries(data).filter(([k]) => !(k in NO_FILES))) as Values;
        writeDraft({ values: rest, step: nextStep, at });
        setSavedAt(at);
        if (!silent) ui.toast('Brouillon enregistré. Les fichiers joints devront être ajoutés à nouveau.');
    };

    const goTo = (target: number) => {
        if (target > step && Object.keys(stepErrors(data, step)).length) {
            setTried((t) => ({ ...t, [step]: true }));
            window.scrollTo({ top: 0, behavior: 'smooth' });
            return;
        }
        setStep(target);
        saveDraft(true, target);
        window.scrollTo({ top: 0 });
    };

    const submit = () => {
        const bad = [0, 1, 2, 3, 4].find((i) => Object.keys(stepErrors(data, i)).length);
        if (bad !== undefined) {
            setTried({ 0: true, 1: true, 2: true, 3: true, 4: true });
            setStep(bad);
            ui.toast('Certaines étapes contiennent des erreurs.', { tone: 'error' });
            return;
        }
        const name = `${data.first_name.trim()} ${data.last_name.trim()}`;
        ui.confirm({
            icon: isNew ? 'forward_to_inbox' : 'save',
            title: isNew ? 'Créer le commercial et envoyer ses accès ?' : 'Confirmer la modification',
            body: isNew
                ? `Le compte de ${name} sera créé (identifiant ${code}). Un lien d’activation valable 72 heures sera envoyé à ${data.email}. Il se connectera avec le ${data.phone}. Aucun mot de passe n’est défini par l’administration.`
                : `Les informations de ${name} seront mises à jour.`,
            ok: isNew ? 'Créer et envoyer' : 'Enregistrer',
            onConfirm: () =>
                form.post(isNew ? '/commerciaux' : `/commerciaux/${agent!.slug}`, {
                    forceFormData: true,
                    onSuccess: () => isNew && writeDraft(null),
                    onError: (errs) => {
                        const first = Object.keys(errs).map((k) => FIELD_STEP[k.split('.')[0]] ?? 0).sort()[0];
                        setStep(first ?? 0);
                        ui.toast('Certains champs sont à corriger.', { tone: 'error' });
                    },
                }),
        });
    };

    const cancel = () => {
        if (!form.isDirty) return router.visit(backUrl);
        ui.confirm({ tone: 'danger', icon: 'warning', title: 'Quitter sans enregistrer ?', body: 'Les modifications saisies dans ce formulaire seront perdues.', ok: 'Quitter sans enregistrer', cancel: 'Continuer la saisie', onConfirm: () => router.visit(backUrl) });
    };

    const discardDraft = () => {
        writeDraft(null);
        form.setData({ ...BLANK, ...NO_FILES });
        setStep(0);
        setTried({});
        setSavedAt(null);
        setRestored(false);
        ui.toast('Nouveau formulaire vierge.');
    };

    /** Un commercial indépendant n’a ni agence ni superviseur direct, et il est rémunéré à la commission. */
    const setType = (type: string) =>
        form.setData((d) => ({ ...d, type, ...(type === 'Commercial indépendant' ? { agency: NO_AGENCY, supervisor: 'Direction commerciale', contract: 'Convention', pay_mode: 'Commission' } : {}) }));

    const set = (key: keyof Values) => (value: Values[keyof Values]) => form.setData((d) => ({ ...d, [key]: value }));
    const text = (key: keyof Values, label: string, extra: { required?: boolean; type?: string; placeholder?: string; full?: boolean; numeric?: boolean; hint?: string } = {}) => (
        <Field label={label} required={extra.required} error={errors[key]} span={extra.full ? 'full' : undefined} hint={extra.hint}>
            <TextInput type={extra.type ?? 'text'} inputMode={extra.numeric ? 'numeric' : undefined} value={String(data[key] ?? '')} onChange={(e) => set(key)(e.target.value)} placeholder={extra.placeholder} invalid={!!errors[key]} aria-label={label} />
        </Field>
    );
    const select = (key: keyof Values, label: string, list: string[], extra: { required?: boolean; hint?: string; onChange?: (v: string) => void; full?: boolean } = {}) => (
        <Field label={label} required={extra.required} error={errors[key]} hint={extra.hint} span={extra.full ? 'full' : undefined}>
            <SelectInput value={String(data[key] ?? '')} onChange={(e) => (extra.onChange ?? set(key))(e.target.value)} options={list.map((o) => ({ value: o, label: o }))} invalid={!!errors[key]} aria-label={label} />
        </Field>
    );
    const phone = (key: 'phone' | 'phone_secondary' | 'emergency_phone', label: string, required = false, hint?: string) => (
        <Field label={label} required={required} error={errors[key]} hint={hint}>
            <PhoneInput label={label} value={data[key]} onChange={set(key)} invalid={!!errors[key]} />
        </Field>
    );
    const multi = (key: 'secondary_zones' | 'specialties' | 'modules' | 'data_zones', label: string, list: string[], hint?: string) => (
        <Field label={label} span="full" hint={hint}>
            <span style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-1_5)' }}>
                {list.map((o) => {
                    const on = data[key].includes(o);
                    return (
                        <button
                            key={o}
                            type="button"
                            aria-pressed={on}
                            onClick={() => set(key)(on ? data[key].filter((x) => x !== o) : [...data[key], o])}
                            style={{ display: 'inline-flex', alignItems: 'center', gap: 'var(--space-1)', height: 30, padding: '0 var(--space-3)', borderRadius: 'var(--radius-pill)', border: `1.5px solid ${on ? 'var(--c-primary-400)' : 'var(--c-line-strong)'}`, background: on ? 'var(--c-primary-50)' : 'var(--c-surface)', color: on ? 'var(--c-primary)' : 'var(--c-ink-2)', fontSize: 'var(--fs-sm)', fontWeight: 'var(--fw-semibold)', cursor: 'pointer' }}
                        >
                            {on && <Icon name="check" size="var(--icon-xs)" />}
                            {o}
                        </button>
                    );
                })}
            </span>
        </Field>
    );
    const toggle = (label: string, on: boolean, onText: string, onChange: (v: boolean) => void) => (
        <Field label={label}>
            <button type="button" role="switch" aria-checked={on} aria-label={label} onClick={() => onChange(!on)} style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2_5)', border: 0, background: 'transparent', padding: 0, cursor: 'pointer', fontSize: 'var(--fs-base)', color: 'var(--c-ink-2)' }}>
                <span style={{ position: 'relative', width: 46, height: 26, flex: 'none', borderRadius: 'var(--radius-pill)', background: on ? 'var(--c-primary-600)' : 'var(--c-line-hover)', transition: 'background var(--dur) var(--ease)' }}>
                    <span style={{ position: 'absolute', top: 3, left: on ? 23 : 3, width: 20, height: 20, borderRadius: 'var(--radius-round)', background: 'var(--c-surface)', boxShadow: 'var(--shadow-xs)', transition: 'left var(--dur) var(--ease)' }} />
                </span>
                {on ? onText : 'Non autorisé'}
            </button>
        </Field>
    );
    const file = (key: FileKey, label: string, full = false, accept = '.pdf,.jpg,.jpeg,.png', existing?: SalesDocument) => {
        const current = data[key];
        return (
            <Field label={label} error={errors[key]} span={full ? 'full' : undefined}>
                <label style={{ position: 'relative', display: 'flex', alignItems: 'center', gap: 'var(--space-3)', padding: 'var(--space-3)', border: `1.5px dashed ${errors[key] ? 'var(--c-danger)' : 'var(--c-line-strong)'}`, borderRadius: 'var(--radius-lg)', background: 'var(--c-surface-2)', cursor: 'pointer' }}>
                    <Icon name={key === 'photo' ? 'add_a_photo' : current ? 'description' : 'cloud_upload'} size="var(--icon-md)" color="var(--c-primary-600)" />
                    <span style={{ flex: 1, minWidth: 0 }}>
                        <b style={{ display: 'block', fontSize: 'var(--fs-md)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{current ? current.name : existing?.fileName ?? (key === 'photo' ? 'Ajouter une photo' : 'Glissez un fichier ou cliquez pour parcourir')}</b>
                        <span style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>{current ? `${Math.max(1, Math.round(current.size / 1024))} Ko · ajouté aujourd’hui` : key === 'photo' ? 'JPG ou PNG · 5 Mo maximum' : 'PDF, JPG ou PNG · 10 Mo maximum'}</span>
                    </span>
                    {existing && !current && <StatusBadge label={existing.status.label} tone={existing.status.tone} />}
                    <input type="file" accept={accept} aria-label={label} onChange={(e) => form.setData(key, e.target.files?.[0] ?? null)} style={{ position: 'absolute', inset: 0, opacity: 0, cursor: 'pointer' }} />
                </label>
            </Field>
        );
    };

    const amount = (v: string) => (v ? `${formatNumber(Number(v.replace(/\s/g, '')) || 0)} FCFA` : '');
    const summary = (value: unknown): string => {
        if (value == null || value === '') return '—';
        if (Array.isArray(value)) return value.length ? value.join(', ') : '—';
        if (typeof value === 'boolean') return value ? 'Oui' : 'Non';
        if (value instanceof File) return value.name;
        if (typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value)) return formatDate(value);
        return String(value);
    };
    const granted = Object.entries(options.permissions)
        .filter(([k]) => data.permissions[k])
        .map(([, l]) => l);

    const recap: [number, [string, unknown][]][] = [
        [0, [['Prénom', data.first_name], ['Nom', data.last_name], ['Sexe', data.gender], ['Date de naissance', data.birth_date], ['Nationalité', data.nationality], ['Téléphone principal (identifiant)', data.phone], ['Téléphone secondaire', data.phone_secondary], ['Adresse e-mail', data.email], ['Adresse', data.address], ['Commune · ville', [data.commune, data.city].filter(Boolean).join(' · ')], ['Contact d’urgence', [data.emergency_contact, data.emergency_phone].filter(Boolean).join(' · ')]]],
        [1, [['Identifiant', code], ['Type', data.type], ['Fonction', data.job_title], ['Agence', data.agency], ['Zone principale', data.zone], ['Zones secondaires', data.secondary_zones], ['Superviseur', data.supervisor], ['Prise de fonction', data.started_at], ['Contrat', data.contract], ['Objectifs', `${data.weekly_target || 0} / semaine · ${data.monthly_target || 0} / mois`], ['Spécialités', data.specialties]]],
        [2, [['Type de pièce', data.id_type], ['Numéro', data.id_number], ['Délivrée le', data.id_issued_at], ['Expire le', data.id_expires_at], ...FILE_FIELDS.map(([k, label, type]) => [label, data[k] ?? documents.find((d) => d.type === type)?.fileName ?? null] as [string, unknown])]],
        [3, [['Rôle · niveau', `${data.role} · ${data.level}`], ['Modules', data.modules], ['Zones de données', data.data_zones.length ? data.data_zones : [data.zone, ...data.secondary_zones].filter(Boolean)], ['Autorisations', granted], ['Double authentification', data.two_factor], ['Validité de l’accès', data.duration]]],
        [4, [['Mode', data.pay_mode], ['Fixe mensuel', amount(data.fixed)], ['Commission par ouvrier enregistré', amount(data.per_registered)], ['Commission par profil validé', amount(data.per_validated)], ['Bonus d’objectif', amount(data.bonus_objective)], ['Bonus Commercial du mois', amount(data.bonus_cotm)], ['Fréquences', `${data.calc_frequency} (calcul) · ${data.pay_frequency} (paiement)`], ['Moyen de paiement', data.pay_method], ['Prise d’effet', data.effective_from], ['Plafond mensuel', amount(data.cap)]]],
    ];

    const pct = Math.round(((step + 1) / 6) * 100);

    return (
        <>
            <Head title={isNew ? 'Nouveau commercial' : `Modifier ${agent?.name}`} />

            <div data-component="Stepper" style={{ background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-4) var(--space-5)' }}>
                <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 'var(--space-3)', flexWrap: 'wrap', marginBottom: 'var(--space-2_5)' }}>
                    <b style={{ fontSize: 'var(--fs-md)' }}>
                        Étape {step + 1} sur 6 · {STEPS[step][0]}
                    </b>
                    {isNew && savedAt && (
                        <span style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-1_5)', fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>
                            <Icon name="cloud_done" size="var(--icon-xs)" color="var(--c-success)" />
                            Brouillon enregistré · {savedAt}
                        </span>
                    )}
                </div>
                <div role="progressbar" aria-valuenow={pct} aria-valuemin={0} aria-valuemax={100} style={{ height: 6, borderRadius: 'var(--radius-pill)', background: 'var(--c-line-soft)', overflow: 'hidden', marginBottom: 'var(--space-3)' }}>
                    <div style={{ height: '100%', width: `${pct}%`, background: 'var(--c-primary-500)', transition: 'width var(--dur) var(--ease)' }} />
                </div>
                <ol style={{ listStyle: 'none', margin: 0, padding: 0, display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(140px,1fr))', gap: 'var(--space-2)' }}>
                    {STEPS.map(([label], i) => {
                        const current = i === step;
                        const done = i < step;
                        const hasError = tried[i] && !current && Object.keys(stepErrors(data, i)).length > 0;
                        return (
                            <li key={label} style={{ minWidth: 0 }}>
                                <button type="button" onClick={() => goTo(i)} aria-current={current ? 'step' : undefined} className="och-hover-menu" style={{ width: '100%', display: 'flex', alignItems: 'center', gap: 'var(--space-2)', padding: 'var(--space-2)', border: 0, borderRadius: 'var(--radius-md)', background: current ? 'var(--c-primary-50)' : 'transparent', cursor: 'pointer', textAlign: 'left' }}>
                                    <span style={{ width: 28, height: 28, flex: 'none', borderRadius: 'var(--radius-round)', display: 'grid', placeItems: 'center', fontSize: 'var(--fs-sm)', fontWeight: 'var(--fw-bold)', background: done ? 'var(--c-primary)' : current ? 'var(--c-surface)' : 'var(--c-surface-3)', color: done ? 'var(--c-on-dark)' : current ? 'var(--c-primary)' : 'var(--c-muted)', border: `1.5px solid ${done || current ? 'var(--c-primary)' : 'var(--c-line-strong)'}` }}>
                                        {done ? <Icon name="check" size="var(--icon-xs)" /> : i + 1}
                                    </span>
                                    <span style={{ minWidth: 0, flex: 1 }}>
                                        <span style={{ display: 'block', fontSize: 'var(--fs-xs)', color: 'var(--c-muted)' }}>Étape {i + 1}</span>
                                        <span style={{ display: 'block', fontSize: 'var(--fs-sm)', fontWeight: current ? 700 : 500, color: 'var(--c-ink)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{label}</span>
                                    </span>
                                    {hasError && <Icon name="error" color="var(--c-danger-fg)" />}
                                </button>
                            </li>
                        );
                    })}
                </ol>
            </div>

            {isNew && restored && savedAt && (
                <div role="status" style={{ display: 'flex', gap: 'var(--space-3)', alignItems: 'center', flexWrap: 'wrap', padding: 'var(--space-3) var(--space-4)', borderRadius: 'var(--radius-lg)', background: 'var(--c-info-bg)', color: 'var(--c-info-fg)' }}>
                    <Icon name="history" />
                    <span style={{ flex: 1, fontSize: 'var(--fs-md)' }}>
                        <b>Brouillon restauré.</b> Données saisies le {savedAt} récupérées.
                    </span>
                    <button type="button" onClick={discardDraft} style={{ border: 0, background: 'transparent', padding: 0, color: 'inherit', fontWeight: 'var(--fw-bold)', cursor: 'pointer', textDecoration: 'underline', textUnderlineOffset: 2 }}>
                        Repartir de zéro
                    </button>
                </div>
            )}

            {errorCount > 0 && (
                <div role="alert" style={{ display: 'flex', gap: 'var(--space-3)', alignItems: 'center', padding: 'var(--space-3) var(--space-4)', borderRadius: 'var(--radius-lg)', background: 'var(--c-danger-bg)', color: 'var(--c-danger-fg)' }}>
                    <Icon name="error" />
                    <span style={{ fontSize: 'var(--fs-md)', fontWeight: 'var(--fw-semibold)' }}>
                        {errorCount} champ{errorCount > 1 ? 's' : ''} à corriger avant de continuer.
                    </span>
                </div>
            )}

            {step === 0 && (
                <Section step={0} code={code}>
                    {file('photo', 'Photo', true, 'image/*')}
                    {text('first_name', 'Prénom', { required: true })}
                    {text('last_name', 'Nom', { required: true })}
                    {select('gender', 'Sexe', ['Homme', 'Femme'])}
                    {text('birth_date', 'Date de naissance', { type: 'date' })}
                    {select('nationality', 'Nationalité', options.nationalities)}
                    {phone('phone', 'Téléphone principal (identifiant de connexion)', true, 'Unique · sert d’identifiant dans l’Application Commercial.')}
                    {phone('phone_secondary', 'Téléphone secondaire')}
                    {text('email', 'Adresse e-mail', { required: true, type: 'email', placeholder: 'prenom.nom@ochantier.ci', hint: 'Unique · reçoit le lien d’activation et les notifications de sécurité.' })}
                    {text('address', 'Adresse de résidence', { full: true })}
                    {select('commune', 'Commune', options.communes)}
                    {text('city', 'Ville')}
                    {text('emergency_contact', 'Contact d’urgence')}
                    {phone('emergency_phone', 'Téléphone du contact d’urgence')}
                </Section>
            )}

            {step === 1 && (
                <Section step={1} code={code}>
                    {select('type', 'Type de commercial', options.types, { required: true, onChange: setType })}
                    {text('job_title', 'Fonction', { placeholder: 'Ex. Chargé de recrutement terrain' })}
                    {select('agency', 'Agence ou antenne', options.agencies, { hint: 'Facultatif : un commercial peut exercer de manière indépendante, sans agence.' })}
                    {select('zone', 'Zone principale d’intervention', options.communes, { required: true })}
                    {multi('secondary_zones', 'Zones secondaires', options.communes.filter((c) => c !== data.zone))}
                    {select('supervisor', 'Responsable ou superviseur', options.supervisors)}
                    {text('started_at', 'Date de prise de fonction', { type: 'date' })}
                    {select('contract', 'Type de contrat', options.contracts)}
                    {text('weekly_target', 'Objectif hebdomadaire (profils validés)', { numeric: true })}
                    {text('monthly_target', 'Objectif mensuel (profils validés)', { numeric: true })}
                    {multi('specialties', 'Spécialités métiers', options.trades)}
                    <Field label="Commentaire interne" span="full">
                        <TextArea value={data.internal_note} onChange={(e) => set('internal_note')(e.target.value)} rows={3} />
                    </Field>
                </Section>
            )}

            {step === 2 && (
                <Section step={2} code={code}>
                    {select('id_type', 'Type de pièce d’identité', options.idTypes)}
                    {text('id_number', 'Numéro de la pièce')}
                    {text('id_issued_at', 'Date de délivrance', { type: 'date' })}
                    {text('id_expires_at', 'Date d’expiration', { type: 'date' })}
                    {FILE_FIELDS.map(([key, label, type]) => (
                        <span key={key} style={{ display: 'contents' }}>
                            {file(key, label, false, undefined, documents.find((d) => d.type === type))}
                        </span>
                    ))}
                </Section>
            )}

            {step === 3 && (
                <Section step={3} code={code}>
                    {select('role', 'Rôle', options.roles)}
                    {select('level', 'Niveau d’accès', options.levels)}
                    {multi('modules', 'Modules autorisés', options.modules)}
                    {multi('data_zones', 'Zones de données autorisées', options.communes, 'Par défaut : zone principale et zones secondaires.')}
                    {Object.entries(options.permissions).map(([key, label]) => (
                        <span key={key} style={{ display: 'contents' }}>
                            {toggle(label, !!data.permissions[key], 'Autorisé', (v) => form.setData((d) => ({ ...d, permissions: { ...d.permissions, [key]: v } })))}
                        </span>
                    ))}
                    {toggle('Authentification à deux facteurs', data.two_factor, 'Code OTP par SMS', (v) => form.setData((d) => ({ ...d, two_factor: v })))}
                    {select('duration', 'Durée de validité de l’accès', options.durations, { full: true })}
                </Section>
            )}

            {step === 4 && (
                <Section step={4} code={code}>
                    {select('pay_mode', 'Mode de rémunération', options.payModes)}
                    {text('fixed', 'Montant fixe mensuel (FCFA)', { numeric: true })}
                    {text('per_registered', 'Commission par ouvrier enregistré (FCFA)', { numeric: true })}
                    {text('per_validated', 'Commission par profil validé (FCFA)', { numeric: true })}
                    {text('bonus_objective', 'Bonus d’objectif (FCFA)', { numeric: true })}
                    {text('bonus_cotm', 'Bonus Commercial du mois (FCFA)', { numeric: true })}
                    {select('calc_frequency', 'Fréquence de calcul', options.calcFrequencies)}
                    {select('pay_frequency', 'Fréquence de paiement', options.payFrequencies)}
                    {select('pay_method', 'Moyen de paiement', options.payMethods)}
                    {text('effective_from', 'Date de prise d’effet', { type: 'date' })}
                    {text('cap', 'Plafond mensuel (FCFA)', { numeric: true })}
                    <Field label="Conditions d’éligibilité" span="full">
                        <TextArea value={data.eligibility} onChange={(e) => set('eligibility')(e.target.value)} rows={2} />
                    </Field>
                    <Field label="Commentaire interne" span="full">
                        <TextArea value={data.pay_note} onChange={(e) => set('pay_note')(e.target.value)} rows={2} />
                    </Field>
                </Section>
            )}

            {step === 5 &&
                recap.map(([i, items]) => (
                    <Section
                        key={i}
                        step={i}
                        code={code}
                        hideDescription
                        aside={
                            <button type="button" onClick={() => goTo(i)} style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-1)', marginTop: 'var(--space-3)', border: 0, background: 'transparent', padding: 0, color: 'var(--c-primary-600)', fontWeight: 'var(--fw-semibold)', cursor: 'pointer' }}>
                                <Icon name="edit" size="var(--icon-xs)" />
                                Modifier
                            </button>
                        }
                    >
                        {items.map(([k, v]) => (
                            <div key={k} style={{ display: 'flex', flexDirection: 'column', gap: 2, padding: 'var(--space-2) 0', borderTop: '1px solid var(--c-line-soft)', minWidth: 0 }}>
                                <span style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>{k}</span>
                                <b style={{ fontWeight: 'var(--fw-semibold)', overflowWrap: 'anywhere' }}>{summary(v)}</b>
                            </div>
                        ))}
                    </Section>
                ))}

            <div style={{ position: 'sticky', bottom: 0, zIndex: 5, display: 'flex', alignItems: 'center', gap: 'var(--space-3)', flexWrap: 'wrap', padding: 'var(--space-4) var(--space-5)', background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', boxShadow: 'var(--shadow-md)' }}>
                {form.isDirty && (
                    <span style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-1_5)', fontSize: 'var(--fs-md)', color: 'var(--c-warning-fg)', fontWeight: 'var(--fw-semibold)' }}>
                        <Icon name="edit_note" />
                        Modifications non enregistrées
                    </span>
                )}
                <span style={{ flex: 1, fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>* Champs obligatoires</span>
                <Button label="Annuler" variant="ghost" onClick={cancel} />
                {isNew && <Button label="Enregistrer comme brouillon" icon="save" variant="secondary" onClick={() => saveDraft()} />}
                <Button label="Précédent" icon="arrow_back" variant="secondary" disabled={step === 0} onClick={() => goTo(Math.max(0, step - 1))} />
                {step < 5 && <Button label="Suivant" iconRight="arrow_forward" variant="primary" onClick={() => goTo(step + 1)} />}
                {(step === 5 || !isNew) && <Button label={isNew ? 'Créer et envoyer l’invitation' : 'Enregistrer les modifications'} icon={isNew ? 'send' : 'check'} variant="primary" disabled={form.processing} onClick={submit} />}
            </div>
        </>
    );
}

function Section({ step, code, title, hideDescription, aside, children }: { step: number; code: string; title?: string; hideDescription?: boolean; aside?: ReactNode; children: ReactNode }) {
    const [label, icon, description] = STEPS[step];

    return (
        <section style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,260px),1fr))', gap: 'var(--space-6)', background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-6)' }}>
            <div style={{ maxWidth: 300 }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)' }}>
                    <Icon name={icon} size="var(--icon-md)" color="var(--c-primary)" />
                    <h3 style={{ margin: 0, fontSize: 'var(--fs-xl)' }}>{title ?? label}</h3>
                </div>
                {!hideDescription && <p style={{ margin: 'var(--space-1_5) 0 0', fontSize: 'var(--fs-md)', color: 'var(--c-muted)' }}>{description(code)}</p>}
                {aside}
            </div>
            <div style={{ gridColumn: 'span 2', display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,240px),1fr))', gap: 'var(--space-4)', alignContent: 'start' }}>{children}</div>
        </section>
    );
}
