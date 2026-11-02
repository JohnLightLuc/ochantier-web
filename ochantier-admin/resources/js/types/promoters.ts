import type { Option } from '@/components/ui/Field';
import type { AnyTone, Badge } from '@/types';

export type StatusBadge = Badge & { value?: string };

/** Ligne de la liste. `slug` est l’identifiant du profil promoteur dans l’API, utilisé dans les URL. */
export type PromoterRow = {
    id: string;
    slug: string;
    representative: string;
    name: string;
    phone: string;
    email: string;
    location: string;
    kind: string;
    sitesCount: number;
    expiredDocuments: number;
    openDisputes: number;
    registeredAt: string | null;
    status: StatusBadge & { value: string };
};

export type PromoterFilters = Partial<Record<'q' | 'statut' | 'localisation' | 'type' | 'inscription' | 'rapide' | 'tri' | 'sens' | 'page', string>>;

export type PromoterTodo = Record<'a_verifier' | 'incomplets' | 'pieces_expirees' | 'signales', number>;

export type PromoterIndexProps = {
    promoters: { data: PromoterRow[]; currentPage: number; lastPage: number; total: number; from: number; to: number };
    filters: PromoterFilters;
    filterOptions: Record<'statut' | 'localisation' | 'type' | 'inscription', Option[]>;
    kpis: { total: number; active: number; pending: number; suspended: number };
    todo: PromoterTodo;
    can: { create: boolean };
};

export type PromoterReview = {
    number: number;
    status: string;
    submittedAt: string | null;
    assignedTo: string | null;
    decidedBy: string | null;
    decidedAt: string | null;
    reason: string | null;
    corrections: { label: string | null; reason: string; resolved: boolean }[];
};

export type Promoter = {
    id: string;
    slug: string;
    isCompany: boolean;
    name: string;
    representative: string;
    representativeRole: string | null;
    legalForm: string | null;
    rccm: string | null;
    ncc: string | null;
    headOffice: string | null;
    phone: string;
    email: string;
    accountType: string;
    location: string;
    registeredAt: string | null;
    lastActiveAt: string | null;
    validatedAt: string | null;
    validatedBy: string | null;
    status: StatusBadge & { value: string };
    kyc: Badge;
    completion: { percent: number; missing: string[] };
    suspension: { date: string; reason: string | null } | null;
    review: { canDecide: boolean; canApprove: boolean; approvalRefusal: string | null; current: PromoterReview | null; history: PromoterReview[] };
    subscription: { plan: string; status: string; periodEnd: string | null } | null;
    stats: { sites: number; active_sites: number; users: number };
    flags: { expired_documents_count: number; open_disputes_count: number };
};

export type PromoterDocumentFile = { name: string; side: string; mime: string; url: string };

/** Pièce du dossier : pièce attendue (fournie ou non) ou document complémentaire. */
export type PromoterDocument = {
    id: string;
    type: string;
    label: string;
    kind: string | null;
    required: boolean;
    reference: string;
    addedAt: string;
    expiresAt: string;
    status: Badge & { value: string; icon?: string | null };
    rejectionReason: string | null;
    hasFile: boolean;
    files: PromoterDocumentFile[];
    canValidate: boolean;
    canReject: boolean;
};

export type PromoterAlert = {
    tone: 'danger' | 'warning' | 'info';
    icon: string;
    title: string;
    text: string;
    cta: string;
    href: string;
};

export type PromoterKpi = { label: string; value: string; unit?: string; icon: string; tone: AnyTone; hint: string; highlight?: boolean };

export type PromoterDrawer = { kind: 'document'; document: PromoterDocument };

export type PromoterTab = '' | 'kyc';

export type PromoterShowProps = {
    promoter: Promoter;
    tab: PromoterTab;
    kpis: PromoterKpi[];
    tabCounts: { kyc: number };
    documents: PromoterDocument[];
    alerts: PromoterAlert[];
    drawer: PromoterDrawer | null;
    can: { update: boolean; addDocument: boolean };
    documentTypes?: { value: string; label: string; hasBackSide: boolean; isIdentity: boolean; accept: string }[];
};
