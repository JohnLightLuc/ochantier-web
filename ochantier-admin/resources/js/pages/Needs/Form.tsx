import Button from '@/components/ui/Button';
import Field, { PhoneInput, SelectInput, TextArea, TextInput, phoneDigits } from '@/components/ui/Field';
import Icon from '@/components/ui/Icon';
import { useAdminUi } from '@/contexts/AdminUiContext';
import { formatDate, formatNumber } from '@/lib/format';
import type { NeedRules } from '@/types/needs';
import { Head, router, useForm } from '@inertiajs/react';
import { useEffect, useState, type ReactNode } from 'react';

type Line = { trade_group: string; trade: string; specialty: string; quantity: string; experience: string; tools: string; skills: string[]; certifications: string[]; own_equipment: boolean; constraints: string; comment: string };

type Values = {
    promoter: string; site: string; source: string; district: string; contact: string; contact_phone: string;
    lines: Line[];
    type: string; urgency: string; starts_on: string; ends_on: string; working_days: string[]; start_time: string; end_time: string; break_time: string; application_delay: string;
    weekend: boolean; night: boolean; recurring: boolean; frequency: string; intervention_dates: string; days_count: string; timesheet_mode: string; weeks: string; renewal: string;
    deliverables: string; termination: string; justification: string; coverage_deadline: string; follow_up: string; teams: string; team_size: string; team_lead: boolean; access: string;
    pay_type: string; negotiation: string; daily_cost: string; weekly_cost: string; contract_amount: string; bonus: string; transport: string; meals: string; benefits: string; deductions: string;
    pay_frequency: string; pay_owner: string; pay_method: string; pay_conditions: string; finance_comment: string;
    id_card: boolean; verified_profile: boolean; no_dispute: boolean; confirmed_availability: boolean; medical: boolean; briefing: boolean; professional_docs: string[]; certification: string;
    min_experience: string; min_rating: string; ppe: string[]; safety_rules: string; instructions: string; site_documents: string; exclusions: string;
    deadline: string; visibility: string; zones: string[]; target_trades: string[]; target_levels: string[]; favorites: string[]; notify_workers: boolean; notify_sales: boolean; notify_promoter: boolean;
    max_applications: string; preselection: string; auto_renew: boolean; questions: string;
};

type FileKey = 'doc_specs' | 'doc_mission' | 'doc_plan' | 'doc_photos' | 'doc_safety' | 'doc_schedule' | 'doc_contract' | 'doc_technical' | 'doc_other';
type Files = Record<FileKey, File | null>;

type Options = {
    sites: Record<string, { value: string; label: string }[]>;
    siteInfo: Record<string, { commune: string; city: string; type: string; manager: string | null; phone: string }>;
    suspendedPromoters: string[];
    sources: string[];
    tradeGroups: string[];
    trades: Record<string, { specialties: string[]; skills: string[]; certifications: string[] }>;
    experiences: string[];
    types: string[];
    urgencies: { value: string; label: string }[];
    delays: string[];
    frequencies: string[];
    admins: string[];
    payTypes: string[];
    visibilities: string[];
    zones: string[];
    files: Record<FileKey, string>;
    favorites: { name: string; trade: string }[];
    rules: NeedRules;
    today: string;
};

type Props = { need: { slug: string; reference: string } | null; reference: string; values: Partial<Values> | null; options: Options };

const BLANK_LINE: Line = { trade_group: '', trade: '', specialty: '', quantity: '1', experience: '', tools: '', skills: [], certifications: [], own_equipment: false, constraints: '', comment: '' };

const BLANK: Values = {
    promoter: '', site: '', source: 'Back-office', district: '', contact: '', contact_phone: '',
    lines: [BLANK_LINE],
    type: 'Besoin journalier', urgency: 'normal', starts_on: '', ends_on: '', working_days: ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam'], start_time: '07:30', end_time: '17:00', break_time: '12:00 – 13:00', application_delay: '5 jours',
    weekend: false, night: false, recurring: false, frequency: '', intervention_dates: '', days_count: '', timesheet_mode: 'Pointage via l’application à l’arrivée et au départ', weeks: '', renewal: '',
    deliverables: '', termination: '', justification: '', coverage_deadline: '', follow_up: '', teams: '1', team_size: '', team_lead: false, access: '',
    pay_type: 'Journalier', negotiation: 'Fixe', daily_cost: '', weekly_cost: '', contract_amount: '', bonus: '', transport: '', meals: '', benefits: '', deductions: '',
    pay_frequency: 'Hebdomadaire', pay_owner: 'Promoteur via O’CHANTIER', pay_method: 'Mobile Money', pay_conditions: 'Validation des pointages par le promoteur', finance_comment: '',
    id_card: true, verified_profile: true, no_dispute: true, confirmed_availability: true, medical: false, briefing: true, professional_docs: [], certification: '',
    min_experience: '1 an', min_rating: '3,5 / 5', ppe: ['Casque', 'Chaussures de sécurité', 'Gilet haute visibilité'], safety_rules: '', instructions: '', site_documents: '', exclusions: '',
    deadline: '', visibility: 'Ouvriers éligibles uniquement', zones: [], target_trades: [], target_levels: [], favorites: [], notify_workers: true, notify_sales: false, notify_promoter: true,
    max_applications: '', preselection: 'Manuelle par l’administration', auto_renew: false, questions: '',
};

const NO_FILES: Files = { doc_specs: null, doc_mission: null, doc_plan: null, doc_photos: null, doc_safety: null, doc_schedule: null, doc_contract: null, doc_technical: null, doc_other: null };

const STEPS: [string, string, string][] = [
    ['Promoteur et chantier', 'apartment', 'Rattachez le besoin à un chantier existant des modules Promoteurs et Chantiers.'],
    ['Métiers et effectifs', 'engineering', 'Une ligne par métier recherché.'],
    ['Période et organisation', 'date_range', 'Calendrier, horaires, urgence et équipes.'],
    ['Rémunération et conditions', 'payments', 'Mode de rémunération et règles financières.'],
    ['Exigences et sécurité', 'health_and_safety', 'Critères d’éligibilité et consignes.'],
    ['Documents et médias', 'folder_open', 'Pièces jointes au besoin.'],
    ['Diffusion', 'campaign', 'Le besoin est publié automatiquement à l’enregistrement (une offre par métier).'],
    ['Récapitulatif', 'fact_check', 'Vérifiez les informations avant de soumettre.'],
];

/** Étape où se trouve chaque champ, pour y revenir en cas d’erreur du serveur. */
const FIELD_STEP: Record<string, number> = {
    promoter: 0, site: 0, source: 0, district: 0, contact: 0, contact_phone: 0, lines: 1,
    type: 2, urgency: 2, starts_on: 2, ends_on: 2, start_time: 2, end_time: 2, application_delay: 2, frequency: 2, weeks: 2, deliverables: 2, justification: 2, coverage_deadline: 2, follow_up: 2, teams: 2, team_size: 2,
    pay_type: 3, daily_cost: 3, weekly_cost: 3, contract_amount: 3, bonus: 3, transport: 3, meals: 3,
    min_experience: 4, min_rating: 4, ppe: 4, professional_docs: 4,
    doc_specs: 5, doc_mission: 5, doc_plan: 5, doc_photos: 5, doc_safety: 5, doc_schedule: 5, doc_contract: 5, doc_technical: 5, doc_other: 5,
    deadline: 6, visibility: 6, max_applications: 6, zones: 6,
};

const DAYS = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];
const SHORT_TYPES = ['Besoin journalier', 'Besoin ponctuel', 'Besoin urgent', 'Besoin de remplacement', 'Renfort d’équipe', 'Besoin récurrent'];
const CONTRACT_PAY = ['Forfaitaire', 'Contractuel', 'Par étape', 'Par livrable'];
const AMOUNTS = ['daily_cost', 'weekly_cost', 'contract_amount', 'bonus', 'transport', 'meals'] as const;
const DRAFT_KEY = 'och_besoin_draft';

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

const digits = (v: string) => Number(String(v || '').replace(/\s/g, '')) || 0;
const dayCount = (a: string, b: string) => Math.round((new Date(`${b}T12:00:00`).getTime() - new Date(`${a}T12:00:00`).getTime()) / 864e5) + 1;

/** Contrôles d’une étape avant de passer à la suivante (le serveur revalide tout à l’envoi). */
function stepErrors(v: Values, step: number, o: Options, editing: boolean): Record<string, string> {
    const e: Record<string, string> = {};
    const required = (key: keyof Values, message = 'Ce champ est obligatoire.') => {
        if (!String(v[key] ?? '').trim()) e[key] = message;
    };
    const amount = (key: keyof Values) => {
        if (v[key] && !/^\d[\d\s]*$/.test(String(v[key]).trim())) e[key] = 'Montant en FCFA, chiffres uniquement.';
    };

    if (step === 0) {
        (['promoter', 'site', 'source', 'contact', 'contact_phone'] as const).forEach((k) => required(k));
        if (v.promoter && o.suspendedPromoters.includes(v.promoter)) e.promoter = 'Promoteur suspendu : aucun besoin ne peut être créé.';
        if (v.site && !(o.sites[v.promoter] ?? []).some((s) => s.value === v.site)) e.site = 'Ce chantier n’appartient pas au promoteur sélectionné.';
        if (v.contact_phone && phoneDigits(v.contact_phone).length !== 10) e.contact_phone = 'Numéro à 10 chiffres attendu.';
    }
    if (step === 1) {
        v.lines.forEach((l, j) => {
            (['trade_group', 'trade', 'experience'] as const).forEach((k) => {
                if (!l[k]) e[`lines.${j}.${k}`] = 'Ce champ est obligatoire.';
            });
            if (!(parseInt(l.quantity, 10) > 0)) e[`lines.${j}.quantity`] = 'Nombre supérieur à zéro attendu.';
            if (v.lines.some((m, k) => k !== j && m.trade && m.trade === l.trade && m.specialty === l.specialty)) e[`lines.${j}.trade`] = 'Ce métier et cette spécialité figurent déjà sur une autre ligne.';
        });
    }
    if (step === 2) {
        (['type', 'urgency', 'starts_on', 'ends_on', 'start_time', 'end_time', 'application_delay'] as const).forEach((k) => required(k));
        if (v.starts_on && v.ends_on && v.ends_on < v.starts_on) e.ends_on = 'La date de fin doit suivre la date de début.';
        if (v.starts_on && v.starts_on < o.today && !editing) e.starts_on = 'La date de début ne peut pas être passée.';
        if (v.start_time && v.end_time && v.end_time <= v.start_time) e.end_time = 'L’heure de fin doit suivre l’heure de début.';
        if (v.recurring || v.type === 'Besoin récurrent') required('frequency');
        if (v.type === 'Besoin hebdomadaire') required('weeks');
        if (v.type === 'Besoin contractuel') required('deliverables');
        if (['urgent', 'critical'].includes(v.urgency)) required('justification', 'Justification obligatoire pour ce niveau d’urgence.');
        if (v.urgency === 'critical') {
            required('coverage_deadline');
            required('follow_up');
            if (v.coverage_deadline && v.ends_on && v.coverage_deadline > v.ends_on) e.coverage_deadline = 'Doit précéder la date de fin.';
        }
    }
    if (step === 3) {
        required('pay_type');
        if (v.pay_type === 'Journalier') required('daily_cost');
        if (v.pay_type === 'Hebdomadaire') required('weekly_cost');
        if (CONTRACT_PAY.includes(v.pay_type)) required('contract_amount');
        AMOUNTS.forEach(amount);
    }
    if (step === 6) {
        required('deadline');
        required('visibility');
        if (v.deadline && v.starts_on && v.deadline >= v.starts_on) e.deadline = `Doit précéder la date de début (${formatDate(v.starts_on)}).`;
        if (v.deadline && v.deadline < o.today) e.deadline = 'Ne peut pas être dans le passé.';
        if (v.max_applications && !/^\d+$/.test(v.max_applications)) e.max_applications = 'Nombre attendu.';
    }
    return e;
}

/** Création ou modification d’un besoin : parcours en 8 étapes, publication automatique d’une offre par métier. */
export default function NeedForm({ need, reference, values, options: o }: Props) {
    const ui = useAdminUi();
    const isNew = !need;
    const [draft] = useState(() => (isNew ? readDraft() : null));
    const form = useForm<Values & Files>({ ...BLANK, ...(values ?? draft?.values ?? {}), ...NO_FILES });
    const [step, setStep] = useState(draft?.step ?? 0);
    const [tried, setTried] = useState<Record<number, boolean>>({});
    const [savedAt, setSavedAt] = useState<string | null>(draft?.at ?? null);
    const [restored, setRestored] = useState(!!draft);
    const { data: v } = form;
    const backUrl = need ? `/besoins/${need.slug}` : '/besoins';
    const N = STEPS.length;

    const localErrors = tried[step] ? stepErrors(v, step, o, !isNew) : {};
    const errors: Record<string, string | undefined> = { ...form.errors, ...localErrors };
    const errorCount = Object.keys(localErrors).length + Object.keys(form.errors).filter((k) => (FIELD_STEP[k.split('.')[0]] ?? 0) === step).length;

    useEffect(() => {
        if (!form.isDirty) return;
        const onUnload = (e: BeforeUnloadEvent) => e.preventDefault();
        window.addEventListener('beforeunload', onUnload);
        return () => window.removeEventListener('beforeunload', onUnload);
    }, [form.isDirty]);

    const saveDraft = (nextStep = step) => {
        if (!isNew) return;
        const at = new Date().toLocaleString('fr-FR', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
        const rest = Object.fromEntries(Object.entries(v).filter(([k]) => !(k in NO_FILES))) as Values;
        writeDraft({ values: rest, step: nextStep, at });
        setSavedAt(at);
    };

    const goTo = (target: number) => {
        if (target > step) {
            for (let k = step; k < target; k++) {
                if (Object.keys(stepErrors(v, k, o, !isNew)).length) {
                    setTried((t) => ({ ...t, [k]: true }));
                    setStep(k);
                    ui.toast('Complétez les champs obligatoires de cette étape.', { tone: 'error' });
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                    return;
                }
            }
        }
        setStep(target);
        saveDraft(target);
        window.scrollTo({ top: 0 });
    };

    const submit = () => {
        const bad = [0, 1, 2, 3, 6].find((i) => Object.keys(stepErrors(v, i, o, !isNew)).length);
        if (bad !== undefined) {
            setTried(Object.fromEntries(STEPS.map((_, i) => [i, true])));
            setStep(bad);
            return ui.toast(`Formulaire incomplet : ${STEPS[bad][0]}.`, { tone: 'error' });
        }
        if (o.suspendedPromoters.includes(v.promoter)) return ui.toast('Promoteur suspendu : aucun besoin ne peut être enregistré.', { tone: 'error' });
        const send = () =>
            form.post(isNew ? '/besoins' : `/besoins/${need.slug}`, {
                forceFormData: true,
                onSuccess: () => isNew && writeDraft(null),
                onError: (errs) => {
                    const first = Object.keys(errs).map((k) => FIELD_STEP[k.split('.')[0]] ?? 0).sort()[0];
                    setStep(first ?? 0);
                    setTried(Object.fromEntries(STEPS.map((_, i) => [i, true])));
                    ui.toast('Certains champs sont à corriger.', { tone: 'error' });
                },
            });
        if (!isNew) return send();
        const critical = v.urgency === 'critical';
        ui.confirm({
            tone: critical ? 'danger' : 'success',
            icon: critical ? 'crisis_alert' : 'campaign',
            title: critical ? 'Enregistrer et publier en priorité ?' : 'Enregistrer et publier le besoin ?',
            body: (critical ? `Besoin critique · ${v.justification} Couverture exigée avant le ${formatDate(v.coverage_deadline)} · suivi : ${v.follow_up}. ` : '') + `${v.lines.length} offre(s) seront publiées automatiquement (une par métier) · visibilité : ${v.visibility} · date limite ${formatDate(v.deadline)}.`,
            ok: 'Enregistrer et publier',
            onConfirm: send,
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

    const set = <K extends keyof Values>(key: K) => (value: Values[K]) => form.setData((d) => ({ ...d, [key]: value }));
    const setLine = (j: number, patch: Partial<Line>) => form.setData((d) => ({ ...d, lines: d.lines.map((l, k) => (k === j ? { ...l, ...patch } : l)) }));

    /** Le chantier pré-remplit le responsable et son téléphone. */
    const pickSite = (slug: string) => {
        const info = o.siteInfo[slug];
        form.setData((d) => ({ ...d, site: slug, ...(info ? { contact: d.contact || (info.manager ?? ''), contact_phone: d.contact_phone || info.phone } : {}) }));
    };

    const text = (key: keyof Values, label: string, extra: { required?: boolean; type?: string; placeholder?: string; full?: boolean; numeric?: boolean; hint?: string } = {}) => (
        <Field key={key} label={label} required={extra.required} error={errors[key]} span={extra.full ? 'full' : undefined} hint={extra.hint}>
            <TextInput type={extra.type ?? 'text'} inputMode={extra.numeric ? 'numeric' : undefined} value={String(v[key] ?? '')} onChange={(e) => set(key)(e.target.value as never)} placeholder={extra.placeholder} invalid={!!errors[key]} aria-label={label} />
        </Field>
    );
    const area = (key: keyof Values, label: string, required = false) => (
        <Field key={key} label={label} required={required} error={errors[key]} span="full">
            <TextArea value={String(v[key] ?? '')} onChange={(e) => set(key)(e.target.value as never)} rows={3} invalid={!!errors[key]} aria-label={label} />
        </Field>
    );
    const select = (key: keyof Values, label: string, list: (string | { value: string; label: string })[], extra: { required?: boolean; hint?: string; onChange?: (value: string) => void; full?: boolean } = {}) => (
        <Field key={key} label={label} required={extra.required} error={errors[key]} hint={extra.hint} span={extra.full ? 'full' : undefined}>
            <SelectInput value={String(v[key] ?? '')} onChange={(e) => (extra.onChange ?? ((x: string) => set(key)(x as never)))(e.target.value)} options={[{ value: '', label: 'Sélectionner' }, ...list.map((x) => (typeof x === 'string' ? { value: x, label: x } : x))]} invalid={!!errors[key]} aria-label={label} />
        </Field>
    );
    const chips = (current: string[], list: string[], onChange: (next: string[]) => void) => (
        <span style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-1_5)' }}>
            {list.map((x) => {
                const on = current.includes(x);
                return (
                    <button key={x} type="button" aria-pressed={on} onClick={() => onChange(on ? current.filter((y) => y !== x) : [...current, x])} style={{ display: 'inline-flex', alignItems: 'center', gap: 'var(--space-1)', height: 30, padding: '0 var(--space-3)', borderRadius: 'var(--radius-pill)', border: `1.5px solid ${on ? 'var(--c-primary-400)' : 'var(--c-line-strong)'}`, background: on ? 'var(--c-primary-50)' : 'var(--c-surface)', color: on ? 'var(--c-primary)' : 'var(--c-ink-2)', fontSize: 'var(--fs-sm)', fontWeight: 'var(--fw-semibold)', cursor: 'pointer' }}>
                        {on && <Icon name="check" size="var(--icon-xs)" />}
                        {x}
                    </button>
                );
            })}
            {!list.length && <span style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>Aucune option disponible.</span>}
        </span>
    );
    const multi = (key: 'working_days' | 'professional_docs' | 'ppe' | 'zones' | 'target_trades' | 'target_levels' | 'favorites', label: string, list: string[], hint?: string) => (
        <Field key={key} label={label} span="full" hint={hint}>
            {chips(v[key], list, set(key))}
        </Field>
    );
    const toggle = (key: keyof Values, label: string, onText: string) => (
        <Field key={key} label={label}>
            <button type="button" role="switch" aria-checked={!!v[key]} aria-label={label} onClick={() => set(key)(!v[key] as never)} style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2_5)', border: 0, background: 'transparent', padding: 0, cursor: 'pointer', fontSize: 'var(--fs-base)', color: 'var(--c-ink-2)' }}>
                <span style={{ position: 'relative', width: 46, height: 26, flex: 'none', borderRadius: 'var(--radius-pill)', background: v[key] ? 'var(--c-primary-600)' : 'var(--c-line-hover)' }}>
                    <span style={{ position: 'absolute', top: 3, left: v[key] ? 23 : 3, width: 20, height: 20, borderRadius: 'var(--radius-round)', background: 'var(--c-surface)', boxShadow: 'var(--shadow-xs)' }} />
                </span>
                {v[key] ? onText : 'Non'}
            </button>
        </Field>
    );
    const file = (key: FileKey) => {
        const current = v[key];
        return (
            <Field key={key} label={o.files[key]} error={errors[key]}>
                <label style={{ position: 'relative', display: 'flex', alignItems: 'center', gap: 'var(--space-3)', padding: 'var(--space-3)', border: `1.5px dashed ${errors[key] ? 'var(--c-danger)' : 'var(--c-line-strong)'}`, borderRadius: 'var(--radius-lg)', background: 'var(--c-surface-2)', cursor: 'pointer' }}>
                    <Icon name={key === 'doc_photos' ? 'add_a_photo' : current ? 'description' : 'cloud_upload'} size="var(--icon-md)" color="var(--c-primary-600)" />
                    <span style={{ flex: 1, minWidth: 0 }}>
                        <b style={{ display: 'block', fontSize: 'var(--fs-md)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{current ? current.name : 'Glissez un fichier ou cliquez pour parcourir'}</b>
                        <span style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>{current ? `${Math.max(1, Math.round(current.size / 1024))} Ko · à vérifier` : 'PDF, JPG, PNG ou ZIP · 10 Mo maximum'}</span>
                    </span>
                    <input type="file" accept=".pdf,.jpg,.jpeg,.png,.zip" aria-label={o.files[key]} onChange={(e) => form.setData(key, e.target.files?.[0] ?? null)} style={{ position: 'absolute', inset: 0, opacity: 0, cursor: 'pointer' }} />
                </label>
            </Field>
        );
    };

    const siteInfo = o.siteInfo[v.site];
    const days = v.starts_on && v.ends_on && v.ends_on >= v.starts_on ? dayCount(v.starts_on, v.ends_on) : 0;
    const workDays = Math.round((days * 6) / 7);
    const workers = v.lines.reduce((a, l) => a + (parseInt(l.quantity, 10) || 0), 0);
    const cost = v.pay_type === 'Journalier' ? digits(v.daily_cost) : v.pay_type === 'Hebdomadaire' ? digits(v.daily_cost) || Math.round(digits(v.weekly_cost) / 6) : v.pay_type === 'À négocier' ? 0 : Math.round(digits(v.contract_amount) / Math.max(1, workDays));
    const budget = cost * workers * Math.max(1, workDays);
    const lineTrades = [...new Set(v.lines.map((l) => l.trade).filter(Boolean))];
    const promoters = Object.keys(o.sites);
    const urgencyLabel = o.urgencies.find((u) => u.value === v.urgency)?.label ?? '—';
    const show = (value: unknown): string => {
        if (value == null || value === '') return '—';
        if (Array.isArray(value)) return value.length ? value.join(', ') : '—';
        if (typeof value === 'boolean') return value ? 'Oui' : 'Non';
        if (typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value)) return formatDate(value);
        return String(value);
    };

    const missing = [0, 1, 2, 3, 6].flatMap((i) => {
        const n = Object.keys(stepErrors(v, i, o, !isNew)).length;
        return n ? [`${n} information(s) manquante(s) ou invalide(s) · ${STEPS[i][0]}`] : [];
    });
    const warnings = [
        ...(!v.doc_specs ? ['Cahier des charges non joint'] : []),
        ...(!v.doc_plan ? ['Plan du chantier non joint'] : []),
        ...(v.starts_on && dayCount(o.today, v.starts_on) - 1 <= o.rules.start_alert_days ? [`Démarrage dans moins de ${o.rules.start_alert_days} jours (valeur définie dans Paramètres)`] : []),
    ];
    const recap: [number, [string, unknown][]][] = [
        [0, [['Promoteur', v.promoter], ['Chantier', o.sites[v.promoter]?.find((s) => s.value === v.site)?.label], ['Localisation', siteInfo ? `${v.district ? `${v.district}, ` : ''}${siteInfo.commune} · ${siteInfo.city}` : null], ['Source', v.source], ['Responsable du chantier', `${v.contact} · ${v.contact_phone}`]]],
        [1, v.lines.map((l, j) => [`Ligne ${j + 1}`, [`${l.quantity} × ${l.trade || '—'}`, l.specialty, l.experience, l.trade_group].filter(Boolean).join(' · ')] as [string, unknown])],
        [2, [['Type', v.type], ['Urgence', urgencyLabel + (v.justification ? ` · ${v.justification}` : '')], ['Calendrier', `${show(v.starts_on)} → ${show(v.ends_on)}${days ? ` · ${days} jours` : ''}`], ['Horaires', `${v.start_time} – ${v.end_time}${v.break_time ? ` · pause ${v.break_time}` : ''}`], ['Jours de travail', v.working_days], ['Délai de candidature', v.application_delay]]],
        [3, [['Rémunération', `${v.pay_type} · ${v.negotiation.toLowerCase()}`], ['Coût journalier équivalent', cost ? `${formatNumber(cost)} FCFA` : 'À négocier'], ['Budget estimé', budget ? `${formatNumber(budget)} FCFA` : '—'], ['Paiement', `${v.pay_frequency} · ${v.pay_method}`]]],
        [4, [['Critères', [v.id_card && 'Pièce d’identité', v.verified_profile && 'Profil vérifié', v.no_dispute && 'Absence de litige', v.confirmed_availability && 'Disponibilité confirmée', v.medical && 'Visite médicale', v.briefing && 'Briefing'].filter(Boolean)], ['Expérience · note minimales', `${v.min_experience} · ${v.min_rating}`], ['EPI', v.ppe]]],
        [5, [['Documents joints', `${(Object.keys(NO_FILES) as FileKey[]).filter((k) => v[k]).length} document(s)`]]],
        [6, [['Publication', `Automatique à l’enregistrement · une offre par métier (${v.lines.length})`], ['Visibilité', v.visibility], ['Date limite de candidature', v.deadline], ['Zones ciblées', v.zones], ['Actualisation automatique', v.auto_renew]]],
    ];
    const pct = Math.round(((step + 1) / N) * 100);

    return (
        <>
            <Head title={isNew ? 'Nouveau besoin' : `Modifier ${reference}`} />

            <div data-component="Stepper" style={{ background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-4) var(--space-5)' }}>
                <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 'var(--space-3)', flexWrap: 'wrap', marginBottom: 'var(--space-2_5)' }}>
                    <b style={{ fontSize: 'var(--fs-md)' }}>
                        Étape {step + 1} sur {N} · {STEPS[step][0]}
                    </b>
                    {isNew && savedAt && (
                        <span style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-1_5)', fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>
                            <Icon name="cloud_done" size="var(--icon-xs)" color="var(--c-success)" />
                            Sauvegarde temporaire · {savedAt}
                        </span>
                    )}
                </div>
                <div role="progressbar" aria-valuenow={pct} aria-valuemin={0} aria-valuemax={100} style={{ height: 6, borderRadius: 'var(--radius-pill)', background: 'var(--c-line-soft)', overflow: 'hidden', marginBottom: 'var(--space-3)' }}>
                    <div style={{ height: '100%', width: `${pct}%`, background: 'var(--c-primary-500)' }} />
                </div>
                <ol style={{ listStyle: 'none', margin: 0, padding: 0, display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(130px,1fr))', gap: 'var(--space-2)' }}>
                    {STEPS.map(([label], i) => {
                        const current = i === step;
                        const done = i < step;
                        const hasError = tried[i] && !current && Object.keys(stepErrors(v, i, o, !isNew)).length > 0;
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
                        <b>Saisie du {savedAt} récupérée.</b> Les fichiers joints devront être ajoutés à nouveau.
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
                    <Info items={[['Référence automatique', isNew ? `${reference} (attribuée à l’enregistrement)` : reference], ...(siteInfo ? ([['Commune', siteInfo.commune], ['Ville', siteInfo.city], ['Type de chantier', siteInfo.type], ['Responsable', siteInfo.manager ?? '—']] as [string, string][]) : [])]} />
                    {select('promoter', 'Promoteur', promoters, { required: true, hint: 'Données du module Promoteurs', onChange: (p) => form.setData((d) => ({ ...d, promoter: p, site: '' })) })}
                    {select('site', 'Chantier', o.sites[v.promoter] ?? [], { required: true, hint: v.promoter ? `${(o.sites[v.promoter] ?? []).length} chantier(s) de ce promoteur · module Chantiers` : 'Sélectionnez d’abord le promoteur', onChange: pickSite })}
                    {select('source', 'Source de la demande', o.sources, { required: true })}
                    {text('district', 'Quartier', { placeholder: 'Ex. Riviera 3' })}
                    {text('contact', 'Responsable du chantier', { required: true })}
                    <Field label="Téléphone du contact chantier" required error={errors.contact_phone}>
                        <PhoneInput label="Téléphone du contact chantier" value={v.contact_phone} onChange={set('contact_phone')} invalid={!!errors.contact_phone} />
                    </Field>
                </Section>
            )}

            {step === 1 &&
                v.lines.map((l, j) => {
                    const detail = o.trades[l.trade];
                    const err = (k: string) => errors[`lines.${j}.${k}`];
                    return (
                        <Section
                            key={j}
                            step={1}
                            title={`Ligne ${j + 1}${l.trade ? ` · ${l.trade}` : ''}`}
                            hideDescription={j > 0}
                            aside={
                                v.lines.length > 1 && (
                                    <button type="button" onClick={() => form.setData((d) => ({ ...d, lines: d.lines.filter((_, k) => k !== j) }))} style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-1)', marginTop: 'var(--space-3)', border: 0, background: 'transparent', padding: 0, color: 'var(--c-danger-fg)', fontWeight: 'var(--fw-semibold)', cursor: 'pointer' }}>
                                        <Icon name="delete" size="var(--icon-xs)" />
                                        Retirer cette ligne
                                    </button>
                                )
                            }
                        >
                            <Field label="Corps de métier" required error={err('trade_group')}>
                                <SelectInput value={l.trade_group} onChange={(e) => setLine(j, { trade_group: e.target.value })} options={[{ value: '', label: 'Sélectionner' }, ...o.tradeGroups.map((x) => ({ value: x, label: x }))]} invalid={!!err('trade_group')} aria-label={`Corps de métier ligne ${j + 1}`} />
                            </Field>
                            <Field label="Métier" required error={err('trade')}>
                                <SelectInput value={l.trade} onChange={(e) => setLine(j, { trade: e.target.value, specialty: '', skills: [], certifications: [] })} options={[{ value: '', label: 'Sélectionner' }, ...Object.keys(o.trades).map((x) => ({ value: x, label: x }))]} invalid={!!err('trade')} aria-label={`Métier ligne ${j + 1}`} />
                            </Field>
                            <Field label="Spécialité">
                                <SelectInput value={l.specialty} onChange={(e) => setLine(j, { specialty: e.target.value })} options={[{ value: '', label: 'Sélectionner' }, ...(detail?.specialties ?? []).map((x) => ({ value: x, label: x }))]} aria-label={`Spécialité ligne ${j + 1}`} />
                            </Field>
                            <Field label="Nombre d’ouvriers recherchés" required error={err('quantity')}>
                                <TextInput inputMode="numeric" value={l.quantity} onChange={(e) => setLine(j, { quantity: e.target.value })} invalid={!!err('quantity')} aria-label={`Nombre d’ouvriers ligne ${j + 1}`} />
                            </Field>
                            <Field label="Niveau d’expérience" required error={err('experience')}>
                                <SelectInput value={l.experience} onChange={(e) => setLine(j, { experience: e.target.value })} options={[{ value: '', label: 'Sélectionner' }, ...o.experiences.map((x) => ({ value: x, label: x }))]} invalid={!!err('experience')} aria-label={`Expérience ligne ${j + 1}`} />
                            </Field>
                            <Field label="Outils ou équipements requis">
                                <TextInput value={l.tools} onChange={(e) => setLine(j, { tools: e.target.value })} placeholder="Ex. truelle, niveau laser" aria-label={`Outils ligne ${j + 1}`} />
                            </Field>
                            <Field label="Compétences obligatoires" span="full">
                                {chips(l.skills, detail?.skills ?? [], (skills) => setLine(j, { skills }))}
                            </Field>
                            <Field label="Certifications éventuelles" span="full">
                                {chips(l.certifications, detail?.certifications ?? [], (certifications) => setLine(j, { certifications }))}
                            </Field>
                            <Field label="Propre matériel">
                                <label style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)', fontSize: 'var(--fs-base)' }}>
                                    <input type="checkbox" checked={l.own_equipment} onChange={(e) => setLine(j, { own_equipment: e.target.checked })} />
                                    L’ouvrier doit disposer de son matériel
                                </label>
                            </Field>
                            <Field label="Contraintes physiques éventuelles">
                                <TextInput value={l.constraints} onChange={(e) => setLine(j, { constraints: e.target.value })} placeholder="Ex. travail en hauteur" aria-label={`Contraintes ligne ${j + 1}`} />
                            </Field>
                            <Field label="Commentaire" span="full">
                                <TextArea value={l.comment} onChange={(e) => setLine(j, { comment: e.target.value })} rows={2} aria-label={`Commentaire ligne ${j + 1}`} />
                            </Field>
                        </Section>
                    );
                })}
            {step === 1 && (
                <div>
                    <Button label="Ajouter un métier" icon="add" variant="secondary" onClick={() => form.setData((d) => ({ ...d, lines: [...d.lines, { ...BLANK_LINE }] }))} />
                </div>
            )}

            {step === 2 && (
                <Section step={2}>
                    {days > 0 && <Info items={[['Durée estimée', `${days} jours calendaires · environ ${workDays} jours ouvrés`]]} />}
                    {select('type', 'Type de besoin', o.types, { required: true, hint: 'Changer de type conserve les données déjà saisies.' })}
                    {select('urgency', 'Niveau d’urgence', o.urgencies, { required: true })}
                    {text('starts_on', 'Date de début souhaitée', { required: true, type: 'date' })}
                    {text('ends_on', 'Date de fin prévisionnelle', { required: true, type: 'date' })}
                    {multi('working_days', 'Jours de travail', DAYS)}
                    {text('start_time', 'Heure de début', { required: true, type: 'time' })}
                    {text('end_time', 'Heure de fin', { required: true, type: 'time' })}
                    {text('break_time', 'Pause', { placeholder: '12:00 – 13:00' })}
                    {select('application_delay', 'Délai de candidature', o.delays, { required: true })}
                    {toggle('weekend', 'Travail le week-end', 'Oui')}
                    {toggle('night', 'Travail de nuit', 'Oui')}
                    {toggle('recurring', 'Besoin récurrent', 'Oui')}
                    {(v.recurring || v.type === 'Besoin récurrent') && select('frequency', 'Fréquence', o.frequencies, { required: true })}
                    {SHORT_TYPES.includes(v.type) && (
                        <>
                            {text('intervention_dates', 'Date(s) d’intervention', { placeholder: 'Ex. 06/10, 07/10 et 08/10/2026' })}
                            {text('days_count', 'Nombre de jours', { numeric: true })}
                            {text('timesheet_mode', 'Conditions de pointage', { full: true })}
                        </>
                    )}
                    {v.type === 'Besoin hebdomadaire' && (
                        <>
                            {text('weeks', 'Nombre de semaines', { required: true, numeric: true })}
                            {text('renewal', 'Conditions de renouvellement', { placeholder: 'Ex. reconduction selon avancement' })}
                        </>
                    )}
                    {v.type === 'Besoin contractuel' && (
                        <>
                            {area('deliverables', 'Livrables ou résultats attendus', true)}
                            {area('termination', 'Conditions de résiliation')}
                        </>
                    )}
                    {['urgent', 'critical'].includes(v.urgency) && area('justification', 'Justification de l’urgence', true)}
                    {v.urgency === 'critical' && (
                        <>
                            {text('coverage_deadline', 'Date limite de couverture', { required: true, type: 'date' })}
                            {select('follow_up', 'Responsable du suivi', o.admins, { required: true })}
                        </>
                    )}
                    {text('teams', 'Nombre d’équipes', { numeric: true })}
                    {text('team_size', 'Taille d’une équipe', { numeric: true })}
                    {toggle('team_lead', 'Chef d’équipe requis', 'Oui')}
                    {area('access', 'Conditions d’accès au chantier')}
                </Section>
            )}

            {step === 3 && (
                <Section step={3}>
                    <Info
                        items={[
                            ['Seuil R % applicable', `${o.rules.payment_threshold} % · Paramètres`],
                            ['Acompte initial promoteur (X)', `${o.rules.deposit_rate} % · Paramètres`],
                            ['Commission promoteur (Y)', `${o.rules.promoter_commission} % · Paramètres`],
                            ['Budget estimé', budget ? `${formatNumber(budget)} FCFA` : 'Complétez les montants et le calendrier'],
                        ]}
                    />
                    {select('pay_type', 'Type de rémunération', o.payTypes, { required: true })}
                    {select('negotiation', 'Rémunération fixe ou négociable', ['Fixe', 'Négociable'])}
                    {['Journalier', 'Hebdomadaire'].includes(v.pay_type) && text('daily_cost', 'Coût journalier (FCFA)', { required: v.pay_type === 'Journalier', numeric: true })}
                    {v.pay_type === 'Hebdomadaire' && text('weekly_cost', 'Coût hebdomadaire (FCFA)', { required: true, numeric: true })}
                    {CONTRACT_PAY.includes(v.pay_type) && text('contract_amount', 'Montant contractuel (FCFA)', { required: true, numeric: true })}
                    {text('bonus', 'Prime éventuelle (FCFA)', { numeric: true })}
                    {text('transport', 'Indemnité de transport (FCFA/jour)', { numeric: true })}
                    {text('meals', 'Indemnité de repas (FCFA/jour)', { numeric: true })}
                    {text('benefits', 'Autres avantages')}
                    {text('deductions', 'Retenues éventuelles')}
                    {select('pay_frequency', 'Fréquence de paiement', ['Journalière', 'Hebdomadaire', 'Par quinzaine', 'Mensuelle', 'À la livraison'])}
                    {select('pay_owner', 'Responsable du paiement', ['Promoteur via O’CHANTIER', 'Promoteur en direct', 'O’CHANTIER (séquestre)'])}
                    {select('pay_method', 'Mode de paiement', ['Mobile Money', 'Virement bancaire', 'Espèces contre reçu'])}
                    {area('pay_conditions', 'Conditions de validation du paiement')}
                    {area('finance_comment', 'Commentaire financier')}
                </Section>
            )}

            {step === 4 && (
                <Section step={4}>
                    {toggle('id_card', 'Pièce d’identité obligatoire', 'Oui')}
                    {toggle('verified_profile', 'Profil vérifié obligatoire', 'Oui')}
                    {toggle('no_dispute', 'Absence de litige actif', 'Oui')}
                    {toggle('confirmed_availability', 'Disponibilité confirmée', 'Oui')}
                    {toggle('medical', 'Visite médicale', 'Exigée')}
                    {toggle('briefing', 'Briefing obligatoire', 'Oui, le premier jour')}
                    {multi('professional_docs', 'Documents professionnels', ['Attestation de travail', 'Certificat de qualification', 'Carte professionnelle', 'Casier judiciaire'])}
                    {text('certification', 'Certification métier', { placeholder: 'Ex. habilitation électrique B1V' })}
                    {select('min_experience', 'Expérience minimale', ['Aucune', '6 mois', '1 an', '3 ans', '5 ans', '10 ans'])}
                    {select('min_rating', 'Note minimale', ['Aucune', '3 / 5', '3,5 / 5', '4 / 5', '4,5 / 5'])}
                    {multi('ppe', 'Équipements de protection individuelle', ['Casque', 'Chaussures de sécurité', 'Gilet haute visibilité', 'Gants', 'Harnais', 'Lunettes', 'Masque'])}
                    {area('safety_rules', 'Règles de sécurité')}
                    {area('instructions', 'Consignes particulières')}
                    {text('site_documents', 'Documents du chantier à consulter', { placeholder: 'Ex. plan de prévention HSE' })}
                    {area('exclusions', 'Conditions d’exclusion')}
                </Section>
            )}

            {step === 5 && <Section step={5}>{(Object.keys(NO_FILES) as FileKey[]).map(file)}</Section>}

            {step === 6 && (
                <Section step={6}>
                    {text('deadline', 'Date limite de candidature', { required: true, type: 'date', hint: `Doit précéder la date de début (${formatDate(v.starts_on)}). Passé ce délai, l’offre passe en « Offres expirées ».` })}
                    {select('visibility', 'Visibilité', o.visibilities, { required: true })}
                    {multi('zones', 'Zones ciblées', o.zones)}
                    {multi('target_trades', 'Métiers ciblés', lineTrades)}
                    {multi('target_levels', 'Niveaux d’expérience ciblés', o.experiences)}
                    {multi('favorites', 'Ouvriers favoris ou présélectionnés', o.favorites.filter((f) => !lineTrades.length || lineTrades.includes(f.trade)).map((f) => f.name), 'Ouvriers validés du module Ouvriers correspondant aux métiers')}
                    {toggle('notify_workers', 'Notification aux ouvriers', 'Oui')}
                    {toggle('notify_sales', 'Notification aux commerciaux', 'Oui')}
                    {toggle('notify_promoter', 'Notification au promoteur', 'Oui')}
                    {text('max_applications', 'Nombre maximal de candidatures', { numeric: true })}
                    {select('preselection', 'Mode de présélection', ['Manuelle par l’administration', 'Assistée par le score (validation humaine)', 'Proposée au promoteur'])}
                    {toggle('auto_renew', 'Actualisation automatique si non couvert', 'Oui, à l’expiration')}
                    {area('questions', 'Questions complémentaires')}
                </Section>
            )}

            {step === 7 && (
                <>
                    <Section step={7} title="Alertes et informations manquantes">
                        {[...(missing.length ? missing.map((t) => ['À corriger', t]) : [['Contrôle', 'Formulaire complet']]), ...warnings.map((t) => ['Avertissement', t])].map(([k, t], i) => (
                            <div key={i} style={{ gridColumn: '1 / -1', display: 'flex', alignItems: 'center', gap: 'var(--space-2)', padding: 'var(--space-2) var(--space-3)', borderRadius: 'var(--radius-md)', background: k === 'À corriger' ? 'var(--c-danger-bg)' : k === 'Avertissement' ? 'var(--c-warning-bg)' : 'var(--c-success-bg)', color: k === 'À corriger' ? 'var(--c-danger-fg)' : k === 'Avertissement' ? 'var(--c-warning-fg)' : 'var(--c-success-fg)', fontSize: 'var(--fs-md)' }}>
                                <Icon name={k === 'À corriger' ? 'error' : k === 'Avertissement' ? 'warning' : 'check_circle'} size="var(--icon-sm)" />
                                <b>{k}</b> · {t}
                            </div>
                        ))}
                    </Section>
                    {recap.map(([i, items]) => (
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
                            {items.map(([k, value]) => (
                                <div key={k} style={{ display: 'flex', flexDirection: 'column', gap: 2, padding: 'var(--space-2) 0', borderTop: '1px solid var(--c-line-soft)', minWidth: 0 }}>
                                    <span style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>{k}</span>
                                    <b style={{ fontWeight: 'var(--fw-semibold)', overflowWrap: 'anywhere' }}>{show(value)}</b>
                                </div>
                            ))}
                        </Section>
                    ))}
                </>
            )}

            <div style={{ position: 'sticky', bottom: 0, zIndex: 5, display: 'flex', alignItems: 'center', gap: 'var(--space-3)', flexWrap: 'wrap', padding: 'var(--space-4) var(--space-5)', background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', boxShadow: 'var(--shadow-md)' }}>
                {form.isDirty && (
                    <span style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-1_5)', fontSize: 'var(--fs-md)', color: 'var(--c-warning-fg)', fontWeight: 'var(--fw-semibold)' }}>
                        <Icon name="edit_note" />
                        Modifications non enregistrées
                    </span>
                )}
                <span style={{ flex: 1, fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>* Champs obligatoires</span>
                <Button label="Annuler" variant="ghost" onClick={cancel} />
                <Button label="Précédent" icon="arrow_back" variant="secondary" disabled={step === 0} onClick={() => goTo(Math.max(0, step - 1))} />
                {step < N - 1 && <Button label="Suivant" iconRight="arrow_forward" variant="primary" onClick={() => goTo(step + 1)} />}
                {(step === N - 1 || !isNew) && <Button label={isNew ? 'Enregistrer et publier' : 'Enregistrer les modifications'} icon={isNew ? 'campaign' : 'check'} variant="primary" disabled={form.processing} onClick={submit} />}
            </div>
        </>
    );
}

/** Encadré d’informations en tête d’étape. */
function Info({ items }: { items: [string, string][] }) {
    return (
        <div style={{ gridColumn: '1 / -1', display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,200px),1fr))', gap: 'var(--space-2) var(--space-4)', padding: 'var(--space-3) var(--space-4)', borderRadius: 'var(--radius-lg)', background: 'var(--c-surface-2)', border: '1px solid var(--c-line-soft)' }}>
            {items.map(([k, value]) => (
                <span key={k} style={{ minWidth: 0 }}>
                    <span style={{ display: 'block', fontSize: 'var(--fs-xs)', color: 'var(--c-muted)' }}>{k}</span>
                    <b style={{ fontSize: 'var(--fs-md)', fontWeight: 'var(--fw-semibold)' }}>{value}</b>
                </span>
            ))}
        </div>
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
