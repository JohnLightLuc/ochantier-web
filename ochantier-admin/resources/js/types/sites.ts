import type { Option } from '@/components/ui/Field';
import type { Badge } from '@/types';
import type { ValueBadge } from '@/types/workers';

/** Informations modifiables par l’administration (noms des champs du formulaire). */
export type SiteInfo = { name: string; priority: string; working_hours: string; instructions: string; access_conditions: string; safety_equipment: string };

type SiteSummary = {
    id: number;
    slug: string;
    name: string;
    reference: string;
    promoter: string;
    commune: string;
    progress: number;
    assigned: number;
    required: number;
    status: ValueBadge;
};

export type SiteRow = SiteSummary & {
    type: string;
    startsOn: string;
    endsOn: string;
    executedDays: number;
    plannedDays: number;
    staffing: Badge;
    timesheets: Badge;
    payments: Badge;
    alert: Badge;
    info: SiteInfo;
    acceptanceVerified: boolean;
};

export type SiteCard = SiteSummary & {
    startsOn: string;
    endsOn: string;
    latitude: number;
    longitude: number;
    alertText: string;
    alertTone: 'danger' | 'warning' | 'muted';
};

export type SiteEvent = { date: string; label: string; kind: 'start' | 'end' | 'milestone' | 'payment' | 'urgent' | 'replacement'; slug: string };

export type SiteView = 'tableau' | 'cartes' | 'calendrier' | 'carte';

type FilterKey = 'statut' | 'promoteur' | 'type' | 'localisation' | 'periode' | 'progression' | 'recrutement' | 'incident' | 'paiements' | 'priorite';

export type SiteFilters = Partial<Record<FilterKey | 'q' | 'rapide' | 'alerte' | 'tri' | 'sens' | 'page' | 'vue', string>>;

export type SiteIndexProps = {
    sites: { data: SiteRow[]; currentPage: number; lastPage: number; total: number; from: number; to: number };
    filters: SiteFilters;
    view: SiteView;
    filterOptions: Record<FilterKey, Option[]>;
    kpis: { key: string; label: string; icon: string; tone: string; value: number }[];
    todo: Record<'retard' | 'paiement' | 'remplacement', number>;
    cards: SiteCard[];
    events: SiteEvent[];
    /** Coordonnées des communes (vue Carte géographique). */
    communes: Record<string, [number, number]>;
    referenceDate: string;
    options: { promoters: string[]; types: string[]; communes: string[] };
};

export type Site = {
    id: number;
    slug: string;
    reference: string;
    name: string;
    promoter: string;
    commune: string;
    city: string;
    address: string | null;
    latitude: number | null;
    longitude: number | null;
    type: string;
    category: string | null;
    manager: string | null;
    managerPhone: string | null;
    startsOn: string;
    endsOn: string;
    plannedDays: number;
    executedDays: number;
    progress: number;
    required: number;
    assigned: number;
    present: number;
    status: ValueBadge;
    priority: ValueBadge;
    description: string | null;
    workingHours: string | null;
    workingDays: string | null;
    instructions: string | null;
    accessConditions: string | null;
    safetyEquipment: string | null;
    budget: number;
    engaged: number;
    paid: number;
    correctionRequest: string | null;
    submittedOn: string;
};

export type SiteDocument = {
    id: number;
    type: string;
    label: string;
    kind: string;
    reference: string | null;
    fileName: string | null;
    addedAt: string | null;
    expiresAt: string | null;
    status: ValueBadge;
    rejectionReason: string | null;
    internalComment: string | null;
    hasFile: boolean;
};

export type SiteIncident = { id: number; reference: string; date: string; type: string; severity: Badge; description: string; reportedBy: string; resolution: string | null; status: ValueBadge };

export type SiteActivity = {
    id: number;
    date: string;
    time: string;
    author: string;
    category: ValueBadge & { icon: string };
    action: string;
    oldValue: string | null;
    newValue: string | null;
    comment: string | null;
    origin: string;
};

export type SiteTab = '' | 'equipe' | 'incidents' | 'documents' | 'historique';

export type SiteShowProps = {
    site: Site;
    tab: SiteTab;
    kpis: { label: string; value: string; unit?: string; icon: string; tone: string; hint: string }[];
    tabCounts: Partial<Record<SiteTab, number>>;
    banner: { tone: string; icon: string; title: string; text: string } | null;
    alerts: { tone: string; icon: string; title: string; text: string; cta: string | null; href: string | null }[];
    flags: { staffing: Badge; vacancies: number; late: boolean; lateTimesheets: number; absences: number };
    milestones: { label: string; threshold: number; date: string; status: string; tone: string }[];
    team: { id: string; slug: string; name: string; code: string; trade: string; start: string; end: string; pay: string; status: string; availability: Badge }[];
    timesheets: { id: string; slug: string; name: string; trade: string; date: string; in: string; out: string; status: string; anomaly: string; validation: string }[];
    replacements: { id: string; slug: string; name: string; trade: string; start: string; end: string; reason: string; replacement: string | null; status: string }[];
    offers: { id: string; name: string; trade: string; type: string; applications: number; budget: string; due: string; status: Badge; href: string }[];
    payments: { ref: string; type: string; method: string; amount: number; date: string; status: Badge; href: string }[];
    incidents: SiteIncident[];
    documents: SiteDocument[];
    activities: SiteActivity[];
    info: SiteInfo;
    acceptanceVerified: boolean;
    promoterHref: string;
};
