import type { Option } from '@/components/ui/Field';
import type { Badge } from '@/types';

export type ValueBadge = Badge & { value: string };

export type WorkerRow = {
    id: number;
    slug: string;
    code: string;
    name: string;
    trade: string;
    experienceYears: number;
    dailyRate: number;
    rating: number;
    availability: ValueBadge;
    status: ValueBadge;
};

export type WorkerFilters = Partial<Record<'q' | 'metier' | 'niveau' | 'disponibilite' | 'statut' | 'localisation' | 'cout' | 'note' | 'rapide' | 'tri' | 'sens' | 'page', string>>;

export type WorkerIndexProps = {
    workers: { data: WorkerRow[]; currentPage: number; lastPage: number; total: number; from: number; to: number };
    filters: WorkerFilters;
    filterOptions: Record<'metier' | 'niveau' | 'disponibilite' | 'statut' | 'localisation' | 'cout' | 'note', Option[]>;
    kpis: { total: number; available: number; onMission: number; toReview: number };
    todo: { review: number; replacement: number; documents: number };
};

export type Worker = {
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
    email: string | null;
    address: string | null;
    commune: string | null;
    city: string | null;
    country: string | null;
    emergencyContact: string | null;
    emergencyPhone: string | null;
    trade: string;
    secondaryTrades: string[];
    specialties: string[];
    skills: string[];
    certifications: string[];
    qualification: string | null;
    experienceYears: number;
    experienceDescription: string | null;
    dailyRate: number;
    mobility: string | null;
    zones: string[];
    worksSolo: boolean;
    worksInTeam: boolean;
    acceptsContracts: boolean;
    idType: string | null;
    idNumber: string | null;
    idIssuedAt: string | null;
    idExpiresAt: string | null;
    recruiter: string | null;
    salesZone: string | null;
    acquisitionChannel: string | null;
    salesNote: string | null;
    rating: number;
    reviewsCount: number;
    registeredAt: string;
    status: ValueBadge;
    availability: ValueBadge;
    suspensionReason: string | null;
    rejection: { reason: string; comment: string; request: string } | null;
    identityMismatch: boolean;
    possibleDuplicate: boolean;
};

export type WorkerDocument = {
    id: number;
    type: string;
    label: string;
    kind: string;
    required: boolean;
    reference: string | null;
    fileName: string | null;
    addedAt: string | null;
    expiresAt: string | null;
    status: ValueBadge;
    rejectionReason: string | null;
    internalComment: string | null;
    hasFile: boolean;
};

export type WorkerExperience = {
    id: number;
    company: string;
    mission: string;
    trade: string;
    site: string | null;
    location: string | null;
    startedAt: string;
    endedAt: string | null;
    description: string | null;
    reference: string | null;
};

export type WorkerPeriod = {
    id: number;
    startsOn: string;
    endsOn: string;
    type: ValueBadge & { blocks: boolean };
    reason: string | null;
    zone: string | null;
    comment: string | null;
};

export type WorkerActivity = {
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

export type Assignment = { id: string; site: string; promoter: string; offer: string; trade: string; type: string; start: string; end: string; pay: string; amount: number; status: string; progress: number };
export type Timesheet = { id: string; date: string; site: string; in: string; out: string; status: string; validation: string; anomaly: string; correction: string; photoOk: boolean };
export type Payout = { id: string; ref: string; site: string; from: string; to: string; days: number; status: string; paidAt: string | null; method: string; gross: number; commission: number; net: number };
export type Review = {
    id: string;
    team: boolean;
    site: string;
    promoter: string;
    date: string;
    note: number;
    criteria: number[];
    comment: string;
    reply: string;
    status: string;
    teamName: string;
    teamNote: number;
    teamComment: string;
    members: [string, number][];
};
export type ReplacementCase = {
    id: string;
    assignmentId: string | null;
    site: string;
    moment: string;
    start: string;
    end: string;
    reason: string;
    comment: string;
    urgency: string;
    recovery: string;
    step: number;
    status: string;
    chosen: { name: string; detail: string; score: number } | null;
    times: string[];
};
export type Candidate = { slug: string; name: string; trade: string; experienceYears: number; commune: string | null; dailyRate: number; rating: number; availability: string; score: number; criteria: [string, number][] };

export type WorkerAlert = {
    tone: string;
    icon: string;
    title: string;
    text: string;
    cta: string | null;
    action: { type: 'link'; href: string } | { type: 'document'; document: string } | { type: 'replacement' } | null;
};

export type WorkerKpi = { label: string; value: string; unit?: string; icon: string; tone: string; hint: string };

export type WorkerTab = '' | 'identite-documents' | 'metiers-competences' | 'disponibilites' | 'affectations' | 'pointages' | 'paiements' | 'avis' | 'remplacements' | 'historique';

export type WorkerShowProps = {
    worker: Worker;
    tab: WorkerTab;
    kpis: WorkerKpi[];
    tabCounts: Partial<Record<WorkerTab, number>>;
    banner: { tone: string; icon: string; title: string; text: string; showDocuments: boolean } | null;
    alerts: WorkerAlert[];
    documents: WorkerDocument[];
    experiences: WorkerExperience[];
    periods: WorkerPeriod[];
    activities: WorkerActivity[];
    demo: { assignments: Assignment[]; timesheets: Timesheet[]; payouts: Payout[]; reviews: Review[]; replacements: ReplacementCase[]; stats: { missions: number; days: number; presence: number; acceptance: number; cancellation: number } };
    candidates: Candidate[];
    commissionRate: number;
    options: { trades: string[]; communes: string[]; sites: string[] };
};
