import type { Badge } from '@/types';

export type Criterion = {
    key: string;
    label: string;
    weight: number;
    n: number;
    data: string;
    expected: string;
    value: string;
    why: string;
    contribution: number;
    level: string;
    source: string;
    updatedOn: string;
};

export type Blocking = { criterion: string; expected: string; found: string; date: string; fix: string; absolute: boolean };

/** Profil recommandé (ou exclu) pour une offre. */
export type Profile = {
    key: string;
    matchId: string;
    offerId: number;
    name: string;
    workerSlug: string | null;
    code: string;
    trade: string;
    specialty: string | null;
    commune: string;
    km: number;
    minutes: number;
    experience: number;
    cost: number;
    rating: number;
    reviews: number;
    missions: number;
    availability: string;
    availableOn: string;
    verified: boolean;
    dossier: number;
    certs: string[];
    conflict: string;
    acceptance: number;
    presence: number;
    incidents: number;
    newcomer: boolean;
    former: boolean;
    previous: boolean;
    lastActivity: string;
    locatedOn: string;
    locationReliability: string;
    score: number;
    raw: number;
    level: Badge;
    blocked: boolean;
    absolute: boolean;
    blocking: Blocking[];
    warnings: string[];
    criteria: Criterion[];
    computedOn: string;
    computedBy: string;
    status: Badge;
    selected: boolean;
    replacement: string | null;
    derogation: string | null;
    rejection: string | null;
};

/** Offre (poste d’un besoin) vue par le Matching. */
export type OfferInfo = {
    id: number;
    reference: string;
    need: { slug: string; reference: string; admin: string | null; requirements: string[]; district: string | null };
    site: { name: string; slug: string; type: string; commune: string; city: string; promoter: string };
    trade: string;
    specialty: string | null;
    experience: string;
    quantity: number;
    selected: number;
    rest: number;
    coverage: string;
    startsOn: string;
    endsOn: string;
    deadline: string;
    duration: number;
    workingHours: string;
    cost: number;
    payType: string;
    negotiable: boolean;
    urgency: Badge & { value: string };
    status: Badge;
    eligible: boolean;
    obsolete: boolean;
    lastRun: { date: string; by: string; cycle: string | null } | null;
};

export type Budget = { days: number; allowance: number; per: { key: string; name: string; cost: number; total: number }[]; total: number; available: number; gap: number; percent: number; over: boolean; negotiable: boolean; note: string };

export type SelectionInfo = {
    status: Badge;
    author: string;
    updatedOn: string;
    note: string | null;
    persisted: boolean;
    main: Profile[];
    replacements: (Profile & { role: string })[];
    budget: Budget;
};

export type MatchingConfig = {
    algorithm: string;
    weights_version: string;
    weights_date: string;
    weights: Record<string, [string, number]>;
    thresholds: Record<string, number>;
    radius: number;
    radius_max: number;
    tolerance: number;
    tolerance_max: number;
    limit: number;
    obsolete_days: number;
    identity_check: boolean;
    success_days: number;
    sum: number;
    ok: boolean;
};

export type MatchingGroup = {
    offer: OfferInfo;
    head: { title: string; sub: string; badge: string; complete: boolean; offers: number[]; selected: number } | null;
    altOn: boolean;
    alternatives: number;
    label: string;
    chosen: number;
    selection: SelectionInfo;
    picks: Profile[];
};

export type MatchingIndexProps = {
    filters: Record<string, string>;
    filterDefs: { key: string; label: string; options: string[] }[];
    trades: string[];
    sorts: Record<string, string>;
    sort: string;
    kpis: { label: string; value: string; icon: string; tone: string; hint: string }[];
    toValidate: number;
    needs: { id: number; slug: string; name: string; short: string; line: string; location: string; startsOn: string; urgency: Badge; meta: string; auto: string; on: boolean }[];
    currentOffer: number | null;
    groups: MatchingGroup[];
    sums: { required: number; selected: number; replacements: number; conflicts: number; total: number; available: number };
    alerts: { tone: string; icon: string; title: string; text: string; cta: string; action: string }[];
    configOk: boolean;
};

export type ResultRow = Profile & { offer: { id: number; reference: string; site: string; trade: string; cost: number } };

export type Success = Record<'recommendations' | 'selections' | 'validations' | 'proposals' | 'confirmed' | 'started' | 'failures' | 'rate' | 'acceptance' | 'confirmation' | 'start' | 'completion', number>;
