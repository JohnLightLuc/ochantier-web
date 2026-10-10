import type { Option } from '@/components/ui/Field';
import type { AnyTone, Badge } from '@/types';

export type StatusBadge = Badge & { value?: string };

export type PromoterRow = {
    id: number;
    slug: string;
    representative: string;
    name: string;
    phone: string;
    email: string;
    location: string;
    sitesCount: number;
    payment: Badge;
    kyc: Badge;
    status: StatusBadge;
};

export type PromoterFilters = Partial<Record<'q' | 'statut' | 'localisation' | 'type' | 'kyc' | 'inscription' | 'rapide' | 'tri' | 'sens' | 'page', string>>;

export type PromoterIndexProps = {
    promoters: { data: PromoterRow[]; currentPage: number; lastPage: number; total: number; from: number; to: number };
    filters: PromoterFilters;
    filterOptions: Record<'statut' | 'localisation' | 'type' | 'kyc' | 'inscription', Option[]>;
    kpis: { total: number; active: number; pending: number; suspended: number };
    todo: { incomplete: number; expiredDocuments: number; expiredPromoters: number; reported: number; total: number };
};

export type Promoter = {
    id: number;
    slug: string;
    name: string;
    representative: string;
    representativeRole: string | null;
    legalForm: string;
    rccm: string;
    ncc: string | null;
    sector: string | null;
    headOffice: string;
    phone: string;
    email: string;
    accountType: string;
    location: string;
    registeredAt: string;
    lastActiveAt: string | null;
    status: StatusBadge;
    kyc: Badge;
    suspension: { date: string; reason: string | null } | null;
};

export type PromoterDocument = {
    id: number;
    type: string;
    label: string;
    required: boolean;
    reference: string;
    addedAt: string;
    expiresAt: string;
    status: Badge & { value: string };
    rejectionReason: string | null;
    hasFile: boolean;
    canValidate: boolean;
    canReject: boolean;
};

export type PromoterActivity = {
    id: number;
    day: string;
    time: string;
    author: string;
    kind: Badge & { value: 'admin' | 'promoter' | 'system' };
    action: string;
    target: string | null;
    icon: string;
    tone: string;
    link: string | null;
};

export type DemoSite = { id: string; name: string; location: string; type: string; period: string; workers: number; progress: number; status: string; statusTone: AnyTone };
export type DemoOffer = { id: string; name: string; site: string; trade: string; type: string; typeTone: AnyTone; applications: number; budget: string; due: string; status: string; statusTone: AnyTone };
export type DemoPayment = { ref: string; site: string; type: string; method: string; amount: number; commission: number; date: string; status: string; statusTone: AnyTone; beneficiary: string };
export type DemoReview = { id: string; author: string; detail: string; site: string; rating: string; comment: string; date: string; status: string; statusTone: AnyTone };
export type DemoDispute = { ref: string; party: string; site: string; reason: string; priority: string; priorityTone: AnyTone; date: string; owner: string; status: string; statusTone: AnyTone };

export type PromoterRelations = {
    sites: DemoSite[];
    offers: DemoOffer[];
    payments: DemoPayment[];
    reviews: DemoReview[];
    disputes: DemoDispute[];
};

export type PromoterAlert = {
    tone: 'danger' | 'warning' | 'info';
    icon: string;
    title: string;
    text: string;
    cta: string;
    href?: string;
    addDocument?: string;
};

export type PromoterKpi = { label: string; value: string; unit?: string; icon: string; tone: AnyTone; hint: string; highlight?: boolean };

export type PromoterDrawer =
    | { kind: 'document'; document: PromoterDocument }
    | { kind: 'offer'; offer: DemoOffer; candidates: { name: string; detail: string; score: string }[] };

export type PromoterTab = '' | 'kyc' | 'chantiers' | 'offres' | 'paiements' | 'avis' | 'litiges' | 'activite';

export type PromoterShowProps = {
    promoter: Promoter;
    tab: PromoterTab;
    kpis: PromoterKpi[];
    tabCounts: Record<'kyc' | 'chantiers' | 'offres' | 'paiements' | 'avis', number>;
    documents: PromoterDocument[];
    activities: PromoterActivity[];
    relations: PromoterRelations;
    alerts: PromoterAlert[];
    drawer: PromoterDrawer | null;
};
