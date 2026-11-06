import type { PayoutMethodData } from '@/types/payouts';
import type { Option } from '@/components/ui/Field';
import type { AnyTone, Badge } from '@/types';

export type ValueBadge = Badge & { value: string };

/** Ligne de la liste. `slug` est l’identifiant de l’ouvrier dans l’API, utilisé dans les URL. */
export type WorkerRow = {
    id: string;
    slug: string;
    code: string;
    name: string;
    trade: string;
    experienceYears: number;
    dailyRate: number;
    rating: number;
    commune: string | null;
    recruiter: string | null;
    expiredDocuments: number;
    availability: ValueBadge;
    status: ValueBadge;
};

export type WorkerFilters = Partial<Record<'q' | 'metier' | 'niveau' | 'disponibilite' | 'statut' | 'localisation' | 'cout' | 'note' | 'rapide' | 'tri' | 'sens' | 'page', string>>;

export type WorkerIndexProps = {
    workers: { data: WorkerRow[]; currentPage: number; lastPage: number; total: number; from: number; to: number };
    filters: WorkerFilters;
    filterOptions: Record<'metier' | 'niveau' | 'disponibilite' | 'statut' | 'localisation' | 'cout' | 'note', Option[]>;
    kpis: { total: number; available: number; onMission: number; toReview: number };
    todo: { review: number; corrections: number; documents: number };
    can: { create: boolean; validate: boolean; update: boolean; export: boolean };
};

export type WorkerReview = {
    number: number;
    status: Badge;
    submittedAt: string | null;
    assignedTo: string | null;
    decidedBy: string | null;
    decidedAt: string | null;
    reason: string | null;
    corrections: { target: string; reason: string; resolved: boolean }[];
};

export type Worker = WorkerRow & {
    firstName: string | null;
    lastName: string | null;
    gender: string | null;
    birthDate: string | null;
    birthPlace: string | null;
    nationality: string | null;
    phone: string;
    phoneSecondary: string | null;
    emergencyContact: string | null;
    emergencyPhone: string | null;
    address: string | null;
    neighborhood: string | null;
    level: string | null;
    secondaryTrades: string[];
    specialties: string[];
    skills: string[];
    references: { project: string | null; role: string | null; employer: string | null; period: string }[];
    work: [string, string | null][];
    reviewsCount: number;
    registeredAt: string | null;
    validatedAt: string | null;
    validatedBy: string | null;
    originRecruiter: string | null;
    recruiterPhone: string | null;
    acquisitionChannel: string | null;
    identityMismatch: boolean;
    stats: { missions_completed: number; attendance_rate: number | null; acceptance_rate: number | null; replacement_count: number; last_assignment_at: string | null };
    sanction: { type: 'suspension' | 'exclusion'; label: string; reason: string | null; startsAt: string | null; endsAt: string | null; decidedBy: string | null } | null;
    review: { canDecide: boolean; canApprove: boolean; approvalRefusal: string | null; current: WorkerReview | null; history: WorkerReview[] };
    missing: string[];
    can: { suspend: boolean; reactivate: boolean; exclude: boolean; edit: boolean };
};

export type WorkerDocumentFile = { name: string; side: string; mime: string; url: string };

/** Pièce du dossier ; `id` vaut la clé de l’emplacement quand aucune pièce n’a été déposée. */
export type WorkerDocument = {
    id: string;
    key: string;
    label: string;
    kind: string;
    required: boolean;
    reference: string | null;
    fileName: string | null;
    addedAt: string | null;
    expiresAt: string | null;
    status: ValueBadge;
    rejectionReason: string | null;
    hasFile: boolean;
    canDecide: boolean;
    files: WorkerDocumentFile[];
};

/** Événement du journal (pages qui affichent un historique). */
export type WorkerActivity = {
    id: number | string;
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

export type WorkerAlert = {
    tone: string;
    icon: string;
    title: string;
    text: string | null;
    cta: string | null;
    action: { type: 'link'; href: string } | null;
};

export type WorkerKpi = { label: string; value: string; unit?: string | null; icon: string; tone: AnyTone; hint: string };

export type WorkerTab = '' | 'identite-documents' | 'metiers-competences' | 'disponibilites' | 'affectations' | 'pointages' | 'paiements' | 'avis' | 'remplacements' | 'historique';

type Pagination = { currentPage: number; lastPage: number; from: number; to: number; total: number };
type StatusFilter = { value: string | null; label: string; count: number };
type Total = { label: string; value: string; tone: AnyTone; hint: string };

export type WorkerAssignmentRow = { id: string; site: string; promoter: string; mission: string; trade: string; period: string; days: string; rate: string; promoterRate: string; status: ValueBadge };

export type WorkerTimelineRow = { id: string; day: string; time: string; icon: string; tone: AnyTone; author: string; role: string | null; action: string; kind: ValueBadge; target: string | null };

export type WorkerRelations =
    | { kind: 'availability'; status: ValueBadge | null; availableFrom: string | null; current: WorkerAssignmentRow | null; upcoming: WorkerAssignmentRow[]; openUnavailabilities: number; declarations: { id: string; status: ValueBadge; period: string; source: string; comment: string | null }[] }
    | { kind: 'assignments'; status: string | null; filters: StatusFilter[]; rows: WorkerAssignmentRow[]; pagination: Pagination }
    | { kind: 'attendance'; status: string | null; filters: StatusFilter[]; summary: Total[]; rows: { id: string; date: string; site: string; arrival: string; departure: string; outOfZone: boolean; duration: string; validation: string; observation: string | null; status: ValueBadge }[]; pagination: Pagination }
    | { kind: 'earnings'; paymentMethod: PayoutMethodData | null; totals: Total[]; rows: { id: string; site: string; mission: string; period: string; days: number; rate: string; earned: string; paid: string; remaining: string; status: ValueBadge }[]; payouts: { id: string; reference: string; site: string; period: string; gross: string; commission: string; net: string; status: ValueBadge }[] }
    | { kind: 'ratings'; average: string | null; count: number; hidden: number; distribution: { score: number; count: number; percent: number }[]; rows: { id: string; score: number; comment: string; site: string; author: string; date: string; status: Badge; moderation: string | null }[] }
    | { kind: 'replacements'; unavailabilities: { id: string; site: string; moment: string; date: string; duration: string; reason: string; urgent: boolean; replacement: string; status: ValueBadge }[]; replacedOthers: { id: string; site: string; replaced: string; handover: string; status: ValueBadge }[] }
    | { kind: 'history'; origin: string | null; origins: { value: string; label: string }[]; rows: WorkerTimelineRow[]; pagination: Pagination };

export type WorkerShowProps = {
    worker: Worker;
    tab: WorkerTab;
    kpis: WorkerKpi[];
    tabCounts: Partial<Record<WorkerTab, number>>;
    documents: WorkerDocument[];
    alerts: WorkerAlert[];
    correctionFields: Option[];
    can: { validate: boolean; update: boolean; documents: boolean };
    documentTypes?: { value: string; label: string; hasBackSide: boolean; isIdentity: boolean; accept: string }[];
    relations: WorkerRelations | null;
};
