import Button from '@/components/ui/Button';
import Field, { PhoneInput, SelectInput, TextArea, TextInput, phoneDigits } from '@/components/ui/Field';
import Icon from '@/components/ui/Icon';
import StatusBadge from '@/components/ui/StatusBadge';
import { useAdminUi } from '@/contexts/AdminUiContext';
import { formatDate, formatNumber, todayIso } from '@/lib/format';
import type { WorkerDocument } from '@/types/workers';
import { Head, router, useForm } from '@inertiajs/react';
import { useEffect, useState, type ReactNode } from 'react';

type Experience = { company: string; mission: string; trade: string; site: string; location: string; reference: string; started_at: string; ended_at: string; description: string };

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
    country: string;
    emergency_contact: string;
    emergency_phone: string;
    trade: string;
    qualification: string;
    secondary_trades: string[];
    specialties: string;
    skills: string;
    experience_years: string;
    daily_rate: string;
    experience_description: string;
    availability_choice: string;
    mobility: string;
    zones: string[];
    works_solo: boolean;
    works_in_team: boolean;
    accepts_contracts: boolean;
    experiences: Experience[];
    id_type: string;
    id_number: string;
    id_issued_at: string;
    id_expires_at: string;
    recruiter: string;
    sales_zone: string;
    registered_at: string;
    acquisition_channel: string;
    sales_note: string;
};

type Files = Record<'photo' | 'id_front' | 'id_back' | 'proof_of_address' | 'cv' | 'diploma' | 'professional_certificate', File | null>;

type Options = Record<'trades' | 'communes' | 'nationalities' | 'qualifications' | 'mobilities' | 'idTypes' | 'recruiters' | 'salesZones' | 'channels' | 'availabilityChoices', string[]>;

type Props = {
    mode: 'create' | 'edit';
    worker: { slug: string; name: string; code: string } | null;
    step: number;
    values: Values | null;
    documents: WorkerDocument[];
    options: Options;
};

const BLANK_EXPERIENCE: Experience = { company: '', mission: '', trade: '', site: '', location: '', reference: '', started_at: '', ended_at: '', description: '' };

const BLANK: Values = {
    first_name: '', last_name: '', gender: '', birth_date: '', nationality: 'Ivoirienne', phone: '', phone_secondary: '', email: '', address: '', commune: '', city: 'Abidjan', country: 'Côte d’Ivoire',
    emergency_contact: '', emergency_phone: '', trade: '', qualification: '', secondary_trades: [], specialties: '', skills: '', experience_years: '', daily_rate: '', experience_description: '',
    availability_choice: 'Disponible', mobility: '', zones: [], works_solo: true, works_in_team: true, accepts_contracts: false, experiences: [BLANK_EXPERIENCE],
    id_type: '', id_number: '', id_issued_at: '', id_expires_at: '', recruiter: '', sales_zone: '', registered_at: todayIso(), acquisition_channel: '', sales_note: '',
};

const NO_FILES: Files = { photo: null, id_front: null, id_back: null, proof_of_address: null, cv: null, diploma: null, professional_certificate: null };

const STEPS: [string, string, string][] = [
    ['Identité', 'badge', 'État civil, coordonnées et contact d’urgence.'],
    ['Métier et compétences', 'engineering', 'Savoir-faire, tarif et disponibilité.'],
    ['Expériences professionnelles', 'work_history', 'Missions passées et références.'],
    ['Documents justificatifs', 'folder_shared', 'Pièce d’identité et justificatifs.'],
    ['Informations commerciales', 'storefront', 'Recrutement et suivi commercial.'],
    ['Récapitulatif', 'fact_check', 'Vérifiez les informations avant de confirmer.'],
];

/** Étape où se trouve chaque champ, pour y revenir en cas d’erreur du serveur. */
const FIELD_STEP: Record<string, number> = {
    photo: 0, first_name: 0, last_name: 0, gender: 0, birth_date: 0, nationality: 0, phone: 0, phone_secondary: 0, email: 0, address: 0, commune: 0, city: 0, country: 0, emergency_contact: 0, emergency_phone: 0,
    trade: 1, qualification: 1, secondary_trades: 1, specialties: 1, skills: 1, experience_years: 1, daily_rate: 1, experience_description: 1, availability_choice: 1, mobility: 1, zones: 1,
    id_type: 3, id_number: 3, id_issued_at: 3, id_expires_at: 3, id_front: 3, id_back: 3, proof_of_address: 3, cv: 3, diploma: 3, professional_certificate: 3,
    recruiter: 4, sales_zone: 4, registered_at: 4, acquisition_channel: 4, sales_note: 4,
};

const FILE_FIELDS: [keyof Files, string, string][] = [
    ['id_front', 'Recto de la pièce', 'id_front'],
    ['id_back', 'Verso de la pièce', 'id_back'],
    ['proof_of_address', 'Justificatif de domicile', 'proof_of_address'],
    ['cv', 'Curriculum vitae', 'cv'],
    ['diploma', 'Certificat ou diplôme', 'diploma'],
    ['professional_certificate', 'Attestation professionnelle', 'professional_certificate'],
];

const DRAFT_KEY = 'och_worker_draft';

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

/** Contrôles d’une étape avant de passer à la suivante (le serveur revalide tout à l’envoi). */
function stepErrors(v: Values, step: number): Record<string, string> {
    const e: Record<string, string> = {};
    const required = (key: keyof Values) => {
        if (!String(v[key] ?? '').trim()) e[key] = 'Ce champ est obligatoire.';
    };
    const tel = (key: 'phone' | 'phone_secondary' | 'emergency_phone') => {
        if (v[key] && phoneDigits(v[key]).length !== 10) e[key] = 'Le numéro doit contenir 10 chiffres.';
    };

    if (step === 0) {
        required('first_name');
        required('last_name');
        required('phone');
        tel('phone');
        tel('phone_secondary');
        tel('emergency_phone');
        if (v.email && !/^[^\s@]+@[^\s@]+\.[a-z]{2,}$/i.test(v.email)) e.email = 'Adresse e-mail invalide.';
        const adult = new Date();
        adult.setFullYear(adult.getFullYear() - 18);
        if (v.birth_date && new Date(v.birth_date) > adult) e.birth_date = 'L’ouvrier doit avoir au moins 18 ans.';
    }
    if (step === 1) {
        required('trade');
        if (v.experience_years && !/^\d{1,2}$/.test(v.experience_years)) e.experience_years = 'Nombre entier entre 0 et 50.';
        const rate = Number(v.daily_rate.replace(/\s/g, ''));
        if (v.daily_rate && !(Number.isInteger(rate) && rate >= 3000 && rate <= 100000)) e.daily_rate = 'Montant entre 3 000 et 100 000 FCFA.';
    }
    if (step === 2) {
        v.experiences.forEach((x, i) => {
            const filled = x.company || x.mission || x.site || x.started_at;
            if (!filled) return;
            for (const k of ['company', 'mission', 'started_at'] as const) if (!x[k]) e[`experiences.${i}.${k}`] = 'Ce champ est obligatoire.';
            if (x.ended_at && x.started_at && x.ended_at < x.started_at) e[`experiences.${i}.ended_at`] = 'La date de fin doit suivre la date de début.';
        });
    }
    if (step === 3) {
        if (v.id_expires_at && v.id_issued_at && v.id_expires_at <= v.id_issued_at) e.id_expires_at = 'La date d’expiration doit suivre la date de délivrance.';
        else if (v.id_expires_at && v.id_expires_at <= todayIso()) e.id_expires_at = 'La pièce est expirée.';
    }
    return e;
}

/** Ajout ou modification d’un ouvrier : parcours en 6 étapes. */
export default function WorkerForm({ mode, worker, step: initialStep, values, documents, options }: Props) {
    const ui = useAdminUi();
    const isNew = mode === 'create';
    const [draft] = useState(() => (isNew ? readDraft() : null));
    const form = useForm<Values & Files>({ ...(values ?? draft?.values ?? BLANK), ...NO_FILES });
    const [step, setStep] = useState(draft?.step ?? initialStep);
    const [tried, setTried] = useState<Record<number, boolean>>({});
    const [savedAt, setSavedAt] = useState<string | null>(draft?.at ?? null);
    const [restored, setRestored] = useState(!!draft);
    const { data } = form;
    const backUrl = worker ? `/ouvriers/${worker.slug}` : '/ouvriers';

    const localErrors = tried[step] ? stepErrors(data, step) : {};
    const errors: Record<string, string | undefined> = { ...form.errors, ...localErrors };
    const errorCount = Object.keys(tried[step] ? localErrors : {}).length + Object.keys(form.errors).filter((k) => (FIELD_STEP[k.split('.')[0]] ?? 2) === step).length;

    useEffect(() => {
        if (!form.isDirty) return;
        const onUnload = (e: BeforeUnloadEvent) => e.preventDefault();
        window.addEventListener('beforeunload', onUnload);
        return () => window.removeEventListener('beforeunload', onUnload);
    }, [form.isDirty]);

    const saveDraft = (silent = false, nextStep = step) => {
        if (!isNew) return;
        const at = new Date().toLocaleString('fr-FR', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
        const { photo, id_front, id_back, proof_of_address, cv, diploma, professional_certificate, ...rest } = data;
        void [photo, id_front, id_back, proof_of_address, cv, diploma, professional_certificate];
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
            icon: isNew ? 'send' : 'save',
            title: isNew ? 'Soumettre pour vérification ?' : 'Confirmer la modification',
            body: isNew ? `Le profil de ${name} sera créé avec le statut « À vérifier » et transmis à l’équipe de vérification.` : `Les informations de ${name} seront mises à jour.`,
            ok: isNew ? 'Soumettre' : 'Enregistrer',
            onConfirm: () =>
                form.post(isNew ? '/ouvriers' : `/ouvriers/${worker!.slug}`, {
                    forceFormData: true,
                    onSuccess: () => isNew && writeDraft(null),
                    onError: (errs) => {
                        const first = Object.keys(errs).map((k) => FIELD_STEP[k.split('.')[0]] ?? 2).sort()[0];
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

    const set = (key: keyof Values) => (value: Values[keyof Values]) => form.setData((d) => ({ ...d, [key]: value }));
    const text = (key: keyof Values, label: string, extra: { required?: boolean; type?: string; placeholder?: string; full?: boolean; numeric?: boolean; hint?: string } = {}) => (
        <Field label={label} required={extra.required} error={errors[key]} span={extra.full ? 'full' : undefined} hint={extra.hint}>
            <TextInput type={extra.type ?? 'text'} inputMode={extra.numeric ? 'numeric' : undefined} value={String(data[key] ?? '')} onChange={(e) => set(key)(e.target.value)} placeholder={extra.placeholder} invalid={!!errors[key]} aria-label={label} />
        </Field>
    );
    const select = (key: keyof Values, label: string, list: string[], required = false) => (
        <Field label={label} required={required} error={errors[key]}>
            <SelectInput value={String(data[key] ?? '')} onChange={(e) => set(key)(e.target.value)} options={list.map((o) => ({ value: o, label: o }))} invalid={!!errors[key]} aria-label={label} />
        </Field>
    );
    const phone = (key: 'phone' | 'phone_secondary' | 'emergency_phone', label: string, required = false) => (
        <Field label={label} required={required} error={errors[key]}>
            <PhoneInput label={label} value={data[key]} onChange={set(key)} invalid={!!errors[key]} />
        </Field>
    );
    const multi = (key: 'secondary_trades' | 'zones', label: string, list: string[]) => (
        <Field label={label} span="full">
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
    const toggle = (key: 'works_solo' | 'works_in_team' | 'accepts_contracts', label: string, onText: string, full = false) => (
        <Field label={label} span={full ? 'full' : undefined}>
            <button type="button" role="switch" aria-checked={data[key]} onClick={() => set(key)(!data[key])} style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2_5)', border: 0, background: 'transparent', padding: 0, cursor: 'pointer', fontSize: 'var(--fs-base)', color: 'var(--c-ink-2)' }}>
                <span style={{ position: 'relative', width: 46, height: 26, flex: 'none', borderRadius: 'var(--radius-pill)', background: data[key] ? 'var(--c-primary-600)' : 'var(--c-line-hover)', transition: 'background var(--dur) var(--ease)' }}>
                    <span style={{ position: 'absolute', top: 3, left: data[key] ? 23 : 3, width: 20, height: 20, borderRadius: 'var(--radius-round)', background: 'var(--c-surface)', boxShadow: 'var(--shadow-xs)', transition: 'left var(--dur) var(--ease)' }} />
                </span>
                {data[key] ? onText : 'Non'}
            </button>
        </Field>
    );
    const file = (key: keyof Files, label: string, full = false, accept = '.pdf,.jpg,.jpeg,.png', existing?: WorkerDocument) => {
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

    const setExperience = (i: number, key: keyof Experience, value: string) => form.setData('experiences', data.experiences.map((x, j) => (j === i ? { ...x, [key]: value } : x)));
    const expText = (i: number, key: keyof Experience, label: string, type = 'text', full = false) => (
        <Field label={label} error={errors[`experiences.${i}.${key}`]} span={full ? 'full' : undefined}>
            <TextInput type={type} value={data.experiences[i][key]} onChange={(e) => setExperience(i, key, e.target.value)} invalid={!!errors[`experiences.${i}.${key}`]} aria-label={`${label} — expérience ${i + 1}`} />
        </Field>
    );

    const summary = (value: unknown): string => {
        if (value == null || value === '') return '—';
        if (Array.isArray(value)) return value.length ? value.join(', ') : '—';
        if (typeof value === 'boolean') return value ? 'Oui' : 'Non';
        if (value instanceof File) return value.name;
        if (typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value)) return formatDate(value);
        return String(value);
    };

    const recap: [number, [string, unknown][]][] = [
        [0, [['Prénom', data.first_name], ['Nom', data.last_name], ['Sexe', data.gender], ['Date de naissance', data.birth_date], ['Nationalité', data.nationality], ['Téléphone principal', data.phone], ['Téléphone secondaire', data.phone_secondary], ['Adresse e-mail', data.email], ['Adresse', data.address], ['Commune', data.commune], ['Contact d’urgence', [data.emergency_contact, data.emergency_phone].filter(Boolean).join(' · ')]]],
        [1, [['Métier principal', data.trade], ['Qualification', data.qualification], ['Métiers secondaires', data.secondary_trades], ['Spécialités', data.specialties], ['Compétences', data.skills], ['Années d’expérience', data.experience_years], ['Coût journalier', data.daily_rate ? `${formatNumber(Number(data.daily_rate.replace(/\s/g, '')))} FCFA/jour` : ''], ['Mobilité', data.mobility], ['Zones d’intervention', data.zones], ['Missions contractuelles', data.accepts_contracts]]],
        [2, data.experiences.filter((x) => x.company || x.mission).map((x, i) => [`Expérience ${i + 1}`, [x.mission, x.company, x.trade, `${summary(x.started_at)} → ${summary(x.ended_at)}`].filter((y) => y && y !== '—').join(' · ')] as [string, unknown])],
        [3, [['Type de pièce', data.id_type], ['Numéro', data.id_number], ['Délivrée le', data.id_issued_at], ['Expire le', data.id_expires_at], ...FILE_FIELDS.map(([k, label]) => [label, data[k] ?? documents.find((d) => d.type === k)?.fileName ?? null] as [string, unknown])]],
        [4, [['Commercial recruteur', data.recruiter], ['Zone commerciale', data.sales_zone], ['Date d’enregistrement', data.registered_at], ['Canal d’acquisition', data.acquisition_channel], ['Commentaire', data.sales_note]]],
    ];

    const pct = Math.round(((step + 1) / 6) * 100);

    return (
        <>
            <Head title={isNew ? 'Nouvel ouvrier' : `Modifier ${worker?.name}`} />

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
                <Section step={0}>
                    {file('photo', 'Photo de profil', true, 'image/*')}
                    {text('first_name', 'Prénom', { required: true })}
                    {text('last_name', 'Nom', { required: true })}
                    {select('gender', 'Sexe', ['Homme', 'Femme'])}
                    {text('birth_date', 'Date de naissance', { type: 'date' })}
                    {select('nationality', 'Nationalité', options.nationalities)}
                    {phone('phone', 'Téléphone principal', true)}
                    {phone('phone_secondary', 'Téléphone secondaire')}
                    {text('email', 'Adresse e-mail', { type: 'email', placeholder: 'prenom.nom@gmail.com' })}
                    {text('address', 'Adresse de résidence', { full: true })}
                    {select('commune', 'Commune', options.communes)}
                    {text('city', 'Ville')}
                    {text('country', 'Pays')}
                    {text('emergency_contact', 'Contact d’urgence')}
                    {phone('emergency_phone', 'Téléphone du contact d’urgence')}
                </Section>
            )}

            {step === 1 && (
                <Section step={1}>
                    {select('trade', 'Métier principal', options.trades, true)}
                    {select('qualification', 'Niveau de qualification', options.qualifications)}
                    {multi('secondary_trades', 'Métiers secondaires', options.trades.filter((t) => t !== data.trade))}
                    {text('specialties', 'Spécialités', { full: true, hint: 'Séparez les valeurs par des virgules.' })}
                    {text('skills', 'Compétences', { full: true, hint: 'Séparez les valeurs par des virgules.' })}
                    {text('experience_years', 'Années d’expérience', { numeric: true })}
                    {text('daily_rate', 'Coût journalier estimé (FCFA)', { numeric: true, placeholder: '10 000' })}
                    <Field label="Description de l’expérience" span="full">
                        <TextArea value={data.experience_description} onChange={(e) => set('experience_description')(e.target.value)} rows={3} />
                    </Field>
                    {isNew && select('availability_choice', 'Disponibilité', options.availabilityChoices)}
                    {select('mobility', 'Mobilité géographique', options.mobilities)}
                    {multi('zones', 'Zones d’intervention', options.communes)}
                    {toggle('works_solo', 'Travail individuel', 'Capable de travailler seul')}
                    {toggle('works_in_team', 'Travail en équipe', 'Capable d’intégrer une équipe')}
                    {toggle('accepts_contracts', 'Mission contractuelle', 'Disponible pour une mission contractuelle', true)}
                </Section>
            )}

            {step === 2 && (
                <>
                    {data.experiences.map((x, i) => (
                        <Section
                            key={i}
                            step={2}
                            title={`Expérience ${i + 1}`}
                            hideDescription={i > 0}
                            aside={
                                data.experiences.length > 1 && (
                                    <button
                                        type="button"
                                        onClick={() =>
                                            ui.confirm({ tone: 'danger', icon: 'delete', title: 'Supprimer cette expérience ?', body: 'Les informations saisies pour cette expérience seront perdues.', ok: 'Supprimer', onConfirm: () => form.setData('experiences', data.experiences.filter((_, j) => j !== i)) })
                                        }
                                        style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-1)', marginTop: 'var(--space-3)', border: 0, background: 'transparent', padding: 0, color: 'var(--c-danger-fg)', fontWeight: 'var(--fw-semibold)', cursor: 'pointer' }}
                                    >
                                        <Icon name="delete" size="var(--icon-xs)" />
                                        Supprimer cette expérience
                                    </button>
                                )
                            }
                        >
                            {expText(i, 'company', 'Entreprise ou promoteur')}
                            {expText(i, 'mission', 'Intitulé de la mission')}
                            <Field label="Métier exercé">
                                <SelectInput value={x.trade} onChange={(e) => setExperience(i, 'trade', e.target.value)} options={options.trades.map((t) => ({ value: t, label: t }))} />
                            </Field>
                            {expText(i, 'site', 'Chantier')}
                            {expText(i, 'location', 'Localisation')}
                            {expText(i, 'reference', 'Référence professionnelle')}
                            {expText(i, 'started_at', 'Date de début', 'date')}
                            {expText(i, 'ended_at', 'Date de fin', 'date')}
                            <Field label="Description" span="full">
                                <TextArea value={x.description} onChange={(e) => setExperience(i, 'description', e.target.value)} rows={3} />
                            </Field>
                        </Section>
                    ))}
                    <div>
                        <Button label="Ajouter une expérience" icon="add" variant="secondary" onClick={() => form.setData('experiences', [...data.experiences, BLANK_EXPERIENCE])} />
                    </div>
                </>
            )}

            {step === 3 && (
                <Section step={3}>
                    {select('id_type', 'Type de pièce d’identité', options.idTypes)}
                    {text('id_number', 'Numéro de la pièce')}
                    {text('id_issued_at', 'Date de délivrance', { type: 'date' })}
                    {text('id_expires_at', 'Date d’expiration', { type: 'date' })}
                    {FILE_FIELDS.map(([key, label]) => (
                        <span key={key} style={{ display: 'contents' }}>
                            {file(key, label, false, undefined, documents.find((d) => d.type === key))}
                        </span>
                    ))}
                </Section>
            )}

            {step === 4 && (
                <Section step={4}>
                    {select('recruiter', 'Commercial recruteur', options.recruiters)}
                    {select('sales_zone', 'Agence ou zone commerciale', options.salesZones)}
                    {text('registered_at', 'Date d’enregistrement', { type: 'date' })}
                    {select('acquisition_channel', 'Canal d’acquisition', options.channels)}
                    <Field label="Commentaire interne du commercial" span="full">
                        <TextArea value={data.sales_note} onChange={(e) => set('sales_note')(e.target.value)} rows={3} />
                    </Field>
                </Section>
            )}

            {step === 5 &&
                recap.map(([i, items]) => (
                    <Section
                        key={i}
                        step={i}
                        hideDescription
                        aside={
                            <button type="button" onClick={() => goTo(i)} style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-1)', marginTop: 'var(--space-3)', border: 0, background: 'transparent', padding: 0, color: 'var(--c-primary-600)', fontWeight: 'var(--fw-semibold)', cursor: 'pointer' }}>
                                <Icon name="edit" size="var(--icon-xs)" />
                                Modifier
                            </button>
                        }
                    >
                        {items.length ? (
                            items.map(([k, v]) => (
                                <div key={k} style={{ display: 'flex', flexDirection: 'column', gap: 2, padding: 'var(--space-2) 0', borderTop: '1px solid var(--c-line-soft)', minWidth: 0 }}>
                                    <span style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>{k}</span>
                                    <b style={{ fontWeight: 'var(--fw-semibold)', overflowWrap: 'anywhere' }}>{summary(v)}</b>
                                </div>
                            ))
                        ) : (
                            <span style={{ color: 'var(--c-muted)' }}>Aucune expérience renseignée.</span>
                        )}
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
                {(step === 5 || !isNew) && <Button label={isNew ? 'Soumettre pour vérification' : 'Enregistrer les modifications'} icon="send" variant="primary" disabled={form.processing} onClick={submit} />}
            </div>
        </>
    );
}

function Section({ step, title, hideDescription, aside, children }: { step: number; title?: string; hideDescription?: boolean; aside?: ReactNode; children: ReactNode }) {
    const [label, icon, description] = STEPS[step];

    return (
        <section style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,260px),1fr))', gap: 'var(--space-6)', background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-6)' }}>
            <div style={{ maxWidth: 300 }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)' }}>
                    <Icon name={icon} size="var(--icon-md)" color="var(--c-primary)" />
                    <h3 style={{ margin: 0, fontSize: 'var(--fs-xl)' }}>{title ?? label}</h3>
                </div>
                {!hideDescription && <p style={{ margin: 'var(--space-1_5) 0 0', fontSize: 'var(--fs-md)', color: 'var(--c-muted)' }}>{description}</p>}
                {aside}
            </div>
            <div style={{ gridColumn: 'span 2', display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,240px),1fr))', gap: 'var(--space-4)', alignContent: 'start' }}>{children}</div>
        </section>
    );
}
