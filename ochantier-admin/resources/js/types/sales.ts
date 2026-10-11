import type { Option } from '@/components/ui/Field';
import type { Badge } from '@/types';
import type { ValueBadge } from '@/types/workers';

export type SalesFlags = { objective: boolean; followUp: boolean; earnings: boolean; pending: number };

export type SalesAgentRow = {
    id: number;
    slug: string;
    code: string;
    name: string;
    firstName: string;
    phone: string;
    email: string;
    zone: string;
    agency: string;
    supervisor: string | null;
    registered: number | null;
    validated: number | null;
    validationRate: number | null;
    objective: number;
    score: number | null;
    monthGain: number;
    weeklyGain: number | null;
    performance: Badge;
    availability: ValueBadge;
    status: ValueBadge;
    lastActivityOn: string | null;
    flags: SalesFlags;
};

export type SalesFilters = Partial<Record<'q' | 'statut' | 'zone' | 'agence' | 'disponibilite' | 'performance' | 'superviseur' | 'periode' | 'rapide' | 'tri' | 'sens' | 'page' | 'mois', string>>;

export type CommercialOfTheMonth = {
    month: string;
    label: string;
    closed: boolean;
    top: { slug: string; name: string; code: string; zone: string; score: number; evolution: number | null; previousLabel: string; stats: [string, string][] } | null;
};

export type SalesIndexProps = {
    agents: { data: SalesAgentRow[]; currentPage: number; lastPage: number; total: number; from: number; to: number };
    filters: SalesFilters;
    filterOptions: Record<'statut' | 'zone' | 'agence' | 'disponibilite' | 'performance' | 'superviseur' | 'periode', Option[]>;
    kpis: { total: number; active: number; recruited: number; payable: number };
    todo: { objective: number; followUp: number; earnings: number; pending: number; previousMonth: string };
    cotm: CommercialOfTheMonth;
    months: Option[];
    currentMonth: string;
};

export type Session = { id: string; device: string; location: string; ip: string; last: string };

export type SalesAgent = {
    id: number;
    slug: string;
    code: string;
    name: string;
    firstName: string;
    lastName: string;
    gender: string | null;
    birthDate: string | null;
    nationality: string | null;
    phone: string;
    phoneSecondary: string | null;
    email: string;
    address: string | null;
    commune: string | null;
    city: string | null;
    emergencyContact: string | null;
    emergencyPhone: string | null;
    type: string;
    jobTitle: string | null;
    agency: string | null;
    agencyLabel: string;
    zone: string;
    secondaryZones: string[];
    communes: string[];
    priorityTrades: string[];
    specialties: string[];
    supervisor: string | null;
    startedAt: string;
    contract: string | null;
    weeklyTarget: number;
    monthlyTarget: number;
    internalNote: string | null;
    idType: string | null;
    idNumber: string | null;
    idIssuedAt: string | null;
    idExpiresAt: string | null;
    status: ValueBadge;
    invitation: ValueBadge;
    availability: ValueBadge;
    suspensionReason: string | null;
    lastActivityOn: string | null;
    recruitedTotal: number;
    isArchived: boolean;
    isPreActivation: boolean;
    pay: {
        mode: string;
        fixed: number;
        perRegistered: number;
        perValidated: number;
        bonusObjective: number;
        bonusCotm: number;
        calcFrequency: string;
        payFrequency: string;
        effectiveFrom: string;
        cap: number;
        eligibility: string;
        method: string;
        note: string | null;
    };
    access: {
        role: string;
        level: string;
        modules: string[];
        dataZones: string[];
        permissions: Record<string, boolean>;
        twoFactor: boolean;
        duration: string;
        sentAt: string | null;
        viewedAt: string | null;
        activatedAt: string | null;
        expiresAt: string | null;
        lastLogin: string | null;
        passwordChangedAt: string | null;
        failedAttempts: number;
        sessions: Session[];
    };
};

export type Metrics = {
    registered: number;
    complete: number;
    incomplete: number;
    validated: number;
    rejected: number;
    frauds: number;
    validationRate: number;
    objective: number;
    quality: number;
    activity: number;
    delays: number;
    averageDelay: string;
};

export type Normalized = Record<'validated' | 'validation_rate' | 'objective' | 'quality' | 'activity' | 'delays', number>;

export type RankSummary = {
    month: string;
    label: string;
    rank: number;
    total: number;
    score: number;
    performance: Badge;
    metrics: Metrics;
    normalized: Normalized;
    gain: number;
    closed: boolean;
    isTitle: boolean;
};

export type Weight = { key: keyof Normalized; label: string; weight: number };

export type SalesDocument = {
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

export type RecruitedWorker = {
    slug: string;
    name: string;
    code: string;
    trade: string;
    phone: string;
    commune: string | null;
    experience: number;
    dailyRate: number;
    registeredAt: string | null;
    quality: number;
    complete: boolean;
    status: ValueBadge;
    validation: Badge;
    availability: Badge;
    canSubmit: boolean;
};

export type FieldActivity = {
    id: number;
    date: string;
    time: string;
    type: string;
    worker: { name: string; slug: string } | null;
    location: string;
    comment: string;
    hasProof: boolean;
    status: Badge;
};

export type SalesPeriod = { id: number; startsOn: string; endsOn: string; type: ValueBadge; reason: string | null; replacement: string | null; comment: string | null };

export type Objective = {
    id: number;
    label: string;
    type: string;
    target: number;
    startsOn: string;
    endsOn: string;
    closed: boolean;
    future: boolean;
    done: number | null;
    progress: number;
    status: Badge;
};

export type PayoutLine = {
    ref: string;
    adjustmentId: number | null;
    month: string;
    period: string;
    type: string;
    profiles: number;
    fixed: number;
    commissions: number;
    bonus: number;
    deduction: number;
    net: number;
    status: Badge;
    paidAt: string | null;
    method: string;
    reason: string | null;
};

export type SalesActivity = {
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
    ip: string | null;
};

export type SalesAlert = { tone: string; icon: string; title: string; text: string; cta: string | null; action: { type: 'link'; href: string } | { type: 'document'; document: string } | null };

export type SalesTab = '' | 'profil-documents' | 'ouvriers' | 'activite' | 'zones-disponibilites' | 'objectifs-performances' | 'classement' | 'gains-paiements' | 'acces-securite' | 'historique';

export type SalesShowProps = {
    agent: SalesAgent;
    tab: SalesTab;
    kpis: { label: string; value: string; unit?: string; icon: string; tone: string; hint: string }[];
    tabCounts: Partial<Record<SalesTab, number>>;
    banner: { tone: string; icon: string; title: string; text: string; action: 'invite' | 'email' | 'activate' | null } | null;
    alerts: SalesAlert[];
    current: RankSummary | null;
    previous: RankSummary | null;
    leader: { slug: string; score: number } | null;
    above: number | null;
    history: { month: string; label: string; short: string; summary: RankSummary | null }[];
    titles: { month: string; label: string }[];
    tradeBreakdown: [string, number][];
    documents: SalesDocument[];
    workers: RecruitedWorker[];
    fieldActivities: FieldActivity[];
    periods: SalesPeriod[];
    objectives: Objective[];
    payouts: PayoutLine[];
    rewards: { month: string; label: string; amount: number }[];
    appreciations: { id: number; author: string; text: string; date: string }[];
    activities: SalesActivity[];
    weights: Weight[];
    referenceDate: string;
    currentMonth: string;
    options: { communes: string[]; trades: string[]; colleagues: string[]; roles: string[]; levels: string[]; durations: string[]; permissions: Record<string, string> };
};

export type RankingRow = {
    rank: number;
    evolution: number | null;
    slug: string;
    name: string;
    firstName: string;
    code: string;
    status: { value: string; label: string };
    eligible: boolean;
    agency: string;
    zone: string;
    supervisor: string | null;
    type: string;
    bonusCotm: number;
    score: number;
    performance: Badge;
    metrics: Metrics;
    normalized: Normalized;
    gain: number;
};

export type RankingProps = {
    month: string;
    label: string;
    closed: boolean;
    published: boolean;
    isCurrent: boolean;
    lastCalculation: string;
    referenceDate: string;
    rows: RankingRow[];
    topSlug: string | null;
    months: Option[];
    history: { month: string; label: string; name: string | null; score: number | null; validated: number | null; closed: boolean }[];
    weights: Weight[];
};
