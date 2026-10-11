import type { Option } from '@/components/ui/Field';
import type { Badge } from '@/types';
import type { NeedActionRef, OfferRef } from '@/types/needs';

export type Kpi = { label: string; value: string; icon: string; tone: string; hint: string; highlight?: boolean };

/** Besoin suivi (liste « Besoins en cours d’affectation »). */
export type TrackedNeedRow = {
    slug: string;
    reference: string;
    site: string;
    location: string;
    promoter: string;
    trades: string;
    selected: number;
    need: number;
    percent: number;
    rest: number;
    toContact: number;
    steps: string;
    startsOn: string;
    status: Badge;
    payment: string;
    filters: string[];
    search: string;
};

export type DepositInfo = { rate: number; accepted: number; days: number; total: number; deposit: number };

/** Offre d’un promoteur (liste « Offres des promoteurs »). */
export type PromoterOfferRow = {
    slug: string;
    reference: string;
    sentOn: string;
    promoter: string;
    site: string;
    location: string;
    trade: string;
    post: string;
    profiles: string;
    accepted: number;
    total: number;
    percent: number;
    progress: string;
    refused: boolean;
    urgency: Badge;
    status: Badge;
    canPay: boolean;
    deposit: DepositInfo;
    search: string;
};

export type AssignmentsIndexProps = {
    kpis: Kpi[];
    needs: TrackedNeedRow[];
    offers: PromoterOfferRow[];
    needFilters: string[];
    offerFilters: string[];
    filters: { besoins: string; q: string; offres: string; qo: string };
    options: AssignmentOptions;
    openPosts: Option[];
    refreshedAt: string;
};

export type AssignmentOptions = Record<'channels' | 'declineReasons' | 'refuseReasons' | 'replacementReasons' | 'paymentMethods' | 'notificationChannels' | 'trades' | 'experiences' | 'urgencies', string[]>;

export type AssignmentState = 'to_contact' | 'contacted' | 'validated' | 'refused' | 'replaced';

export type AssignmentRow = {
    id: number;
    reference: string;
    worker: string;
    workerSlug: string | null;
    post: string;
    phone: string | null;
    commune: string | null;
    cost: number;
    source: 'Manuel' | 'Matching';
    selectedOn: string;
    contact: string;
    contacted: boolean;
    replaces: string | null;
    status: Badge;
    state: AssignmentState;
    depositPaid: boolean;
};

/** Profil proposé par le Matching (proposition automatique ou remplaçant). */
export type Candidate = {
    key: string;
    matchId?: string;
    offerId?: number;
    name: string;
    code?: string;
    workerSlug: string | null;
    trade: string;
    commune: string;
    km?: number;
    experience: number;
    rating: number;
    cost: number;
    availability: string;
    score: number;
    level: Badge;
};

export type MissingRow = {
    key: string;
    trade: string;
    sub: string;
    child: boolean;
    needSlug: string;
    needReference: string;
    quantity: number;
    selected: number;
    validated: number;
    rest: number;
    percent: number;
    matchOffer: number | null;
    expired: OfferRef | null;
    actionRef: NeedActionRef;
};

export type ScheduleInfo = { mode: string; days: number; total: number; deposit: number; entries: { label: string; amount: number; date: string; status: string }[] };

export type ProgressStep = { label: string; sub: string; state: 'done' | 'current' | 'todo' };

export type ManualPost = { offerId: number; label: string; trade: string; selected: number; quantity: number; rest: number; options: Option[] };

export type AssignmentNeedProps = {
    need: {
        slug: string;
        reference: string;
        site: { name: string; slug: string; type: string; commune: string; city: string };
        promoter: string;
        promoterSlug: string | null;
        urgency: Badge;
        status: Badge;
        statusLabel: string;
        createdOn: string;
        startsOn: string;
        endsOn: string;
        contact: string;
        open: boolean;
        depositPaid: boolean;
        paymentReference: string | null;
        notifiedOn: string | null;
    };
    actionRef: NeedActionRef;
    coverage: { need: number; selected: number; validated: number; contacted: number; toContact: number; refused: number; rest: number; percent: number; label: string; over: boolean };
    assignments: AssignmentRow[];
    proposals: { rows: Candidate[]; rest: number } | null;
    replacement: { assignment: { id: number; reference: string; name: string; trade: string }; rows: Candidate[] } | null;
    schedule: ScheduleInfo;
    progress: ProgressStep[];
    missing: MissingRow[];
    childrenCount: number;
    manualPosts: ManualPost[];
    autoAdd: string | null;
    options: AssignmentOptions;
};

export type ProfileState = 'pending' | 'contacted' | 'accepted' | 'refused' | 'refused_by_worker' | 'replaced';

export type OfferProfileRow = {
    id: number;
    name: string;
    workerSlug: string | null;
    trade: string;
    source: string;
    commune: string | null;
    experience: number;
    rating: number;
    cost: number;
    availability: string;
    contact: string;
    contacted: boolean;
    status: Badge;
    state: ProfileState;
    detail: string;
    replaces: string | null;
};

export type AssignmentOfferProps = {
    offer: {
        slug: string;
        reference: string;
        promoter: string;
        promoterSlug: string | null;
        site: { name: string; slug: string; type: string; commune: string; city: string };
        trade: string;
        specialty: string | null;
        urgency: Badge;
        status: Badge;
        statusLabel: string;
        sentOn: string;
        startsOn: string;
        endsOn: string;
        duration: number;
        dailyCost: number;
        message: string | null;
        open: boolean;
        notifiedOn: string | null;
        paymentReference: string | null;
    };
    counts: { accepted: number; pending: number; contacted: number; refused: number; replaced: number; total: number };
    deposit: DepositInfo;
    profiles: OfferProfileRow[];
    replacement: { profile: { id: number; name: string; experience: number }; rows: Candidate[] } | null;
    missing: MissingRow[];
    activities: { id: number; date: string; time: string; author: string; action: string; comment: string | null; badge: Badge }[];
    options: AssignmentOptions;
    today: string;
};
