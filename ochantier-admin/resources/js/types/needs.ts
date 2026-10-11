import type { Option } from '@/components/ui/Field';
import type { Badge } from '@/types';
import type { ValueBadge } from '@/types/workers';

/** Offre d’un besoin, avec ce qu’il faut pour la prolonger, l’actualiser ou clôturer ses candidatures. */
export type OfferRef = {
    id: number;
    reference: string;
    trade: string;
    quantity: number;
    selected: number;
    rest: number;
    deadline: string;
    startsOn: string;
    status: Badge;
    applicationsOpen: boolean;
    /** Démarrage dépassé (ou demain) : l’actualisation exige une nouvelle date de démarrage. */
    startPassed: boolean;
};

/** Ce qu’il faut d’un besoin pour proposer ses actions (ligne de liste ou fiche). */
export type NeedActionRef = {
    slug: string;
    reference: string;
    /** Date du jour des données de démonstration. */
    today: string;
    status: Badge;
    manual: 'suspended' | 'cancelled' | 'closed' | 'archived' | null;
    covered: boolean;
    done: boolean;
    selected: number;
    need: number;
    percent: number;
    canMatch: boolean;
    expiredOffers: OfferRef[];
};

export type NeedRow = {
    id: number;
    slug: string;
    reference: string;
    site: string;
    siteType: string;
    promoter: string;
    commune: string;
    city: string;
    mainTrade: string;
    tradesCount: number;
    selected: number;
    need: number;
    rest: number;
    percent: number;
    startsOn: string;
    deadline: string | null;
    duration: number;
    urgency: ValueBadge;
    type: string;
    status: Badge;
    coverage: Badge;
    createdOn: string;
    actions: NeedActionRef;
};

export type NeedCard = {
    id: number;
    slug: string;
    site: string;
    reference: string;
    siteType: string;
    promoter: string;
    commune: string;
    status: Badge;
    urgency: Badge;
    line: string;
    percent: number;
    alert: { text: string; tone: string };
};

export type NeedTab = 'attente' | 'expires' | 'suspendus';

export type NeedFilters = Partial<Record<string, string>>;

export type FilterDef = { key: string; label: string; advanced: boolean; options: Option[] };

export type NeedIndexProps = {
    needs: { data: NeedRow[]; currentPage: number; lastPage: number; total: number; from: number; to: number };
    tab: NeedTab;
    tabs: { key: NeedTab; label: string; count: number }[];
    filters: NeedFilters;
    view: 'tableau' | 'cartes';
    filterDefs: FilterDef[];
    quick: { key: string; label: string; icon: string; tone: string; value: string }[];
    stats: { delay: string; pendingNeed: number; pendingSelected: number };
    todo: Record<'demarrage' | 'critique' | 'expire', number>;
    cards: NeedCard[];
};

export type NeedAlertAction = { type: 'link'; href: string } | { type: 'match' } | { type: 'extend' | 'refresh' | 'closeApplications'; offer: number };

export type NeedAlert = { key: string; tone: string; icon: string; title: string; text: string; cta: string | null; action: NeedAlertAction | null };

export type NeedActivity = {
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

export type NeedTabKey = '' | 'metiers' | 'affectations' | 'documents' | 'historique';

export type NeedRules = Record<'start_alert_days' | 'minimum_coverage' | 'processing_hours' | 'reminder_days' | 'expiry_alert_days' | 'payment_threshold' | 'deposit_rate' | 'promoter_commission', number>;

export type NeedShowProps = {
    need: {
        slug: string;
        reference: string;
        site: { name: string; slug: string; type: string; commune: string; city: string; promoter: string };
        urgency: ValueBadge;
        type: string;
        source: string;
        admin: string | null;
        district: string | null;
        contact: string;
        contactPhone: string;
        startsOn: string;
        endsOn: string;
        duration: number;
        daysToStart: number;
        workingHours: string;
        justification: string | null;
        conditions: string | null;
        createdOn: string;
        budget: number;
        readOnly: boolean;
    };
    actionRef: NeedActionRef;
    coverage: { need: number; selected: number; validated: number; contacted: number; toContact: number; refused: number; rest: number; percent: number; label: string; over: boolean };
    tab: NeedTabKey;
    kpis: { label: string; value: string; icon: string; tone: string; hint: string }[];
    tabCounts: Partial<Record<NeedTabKey, number>>;
    banner: { tone: string; icon: string; title: string; text: string; cta: boolean } | null;
    alerts: NeedAlert[];
    chronology: { label: string; date: string | null; status: string; tone: string }[];
    lines: { id: number; group: string; trade: string; specialty: string | null; quantity: number; experience: string; amount: string; payType: string; requirements: string; found: number; rest: number; percent: number; height: number; canMatch: boolean }[];
    offers: (OfferRef & { urgency: string })[];
    assignments: { id: number; reference: string; worker: string; workerSlug: string | null; trade: string; status: Badge; selectedOn: string; workerConfirmation: Badge; promoterConfirmation: Badge; pay: string; source: string }[];
    documents: { id: number; type: string; fileName: string | null; addedOn: string | null; author: string; status: ValueBadge; canDelete: boolean }[];
    activities: NeedActivity[];
    rules: NeedRules;
    documentTypes: string[];
};
