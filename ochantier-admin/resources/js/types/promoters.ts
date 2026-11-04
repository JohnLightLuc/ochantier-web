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

export type PromoterTab = '' | 'kyc' | 'chantiers' | 'offres' | 'paiements' | 'avis' | 'activite';

export type TabPagination = { currentPage: number; lastPage: number; from: number; to: number; total: number };

type SiteRef = { id: string; title: string };

export type PromoterSiteRow = { id: string; reference: string; title: string; location: string; type: string; period: string; schedule: string | null; responsible: string | null; offers: number; missions: number; status: StatusBadge };

export type PromoterOfferRow = { id: string; reference: string; title: string; site: SiteRef | null; needs: string; workers: number; type: string; proposals: number; amount: string; period: string; status: StatusBadge };

export type PromoterPaymentRow = { key: string; title: string; kind: string; site: SiteRef | null; who: string | null; amount: string; when: string | null; paidAt: string | null; method: string | null; reference: string | null; receipt: string | null; status: StatusBadge };

export type PromoterRatingRow = { id: string; name: string; subtitle: string | null; team: boolean; score: number; comment: string; date: string; author: string | null; status: StatusBadge; moderation: string | null };

export type PromoterDisputeRow = { id: string; reference: string; category: string; description: string; site: string; openedBy: string; urgency: StatusBadge | null; owner: string; date: string; status: StatusBadge };

export type PromoterActivityRow = { id: string; day: string; time: string; icon: string; tone: AnyTone; author: string; role: string | null; action: string; kind: StatusBadge & { value: string }; target: string | null };

export type PromoterRelations =
    | { kind: 'sites'; status: string | null; filters: { value: string | null; label: string; count: number }[]; rows: PromoterSiteRow[]; pagination: TabPagination }
    | { kind: 'offers'; site: SiteRef | null; rows: PromoterOfferRow[]; pagination: TabPagination }
    | { kind: 'payments'; site: SiteRef | null; totals: { label: string; value: string; tone: AnyTone; hint: string }[]; rows: PromoterPaymentRow[] }
    | { kind: 'ratings'; average: string | null; count: number; hidden: number; distribution: { score: number; count: number; percent: number }[]; rows: PromoterRatingRow[] }
    | { kind: 'disputes'; rows: PromoterDisputeRow[]; pagination: TabPagination }
    | { kind: 'activity'; author: 'admin' | 'promoteur' | null; rows: PromoterActivityRow[]; pagination: TabPagination };

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
    relations: PromoterRelations | null;
};
