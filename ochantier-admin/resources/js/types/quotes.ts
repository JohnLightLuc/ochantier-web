import type { Option } from '@/components/ui/Field';
import type { Badge } from '@/types';
import type { ValueBadge, WorkerActivity } from '@/types/workers';

/** Ligne de la liste : un corps de métier d’une demande de devis. */
export type QuoteRow = {
    id: string;
    reference: string;
    trade: string;
    site: string;
    place: string;
    promoter: string;
    bidsCount: number;
    contractor: string | null;
    amount: number | null;
    amountLabel: string | null;
    status: ValueBadge;
    deadline: string | null;
    deadlineSoon: boolean;
    visit: string | null;
};

export type QuoteFilters = Partial<Record<'q' | 'metier' | 'ville' | 'statut' | 'onglet' | 'page', string>>;

export type QuoteIndexProps = {
    quotes: { data: QuoteRow[]; currentPage: number; lastPage: number; total: number; from: number; to: number };
    filters: QuoteFilters;
    filterOptions: Record<'metier' | 'ville' | 'statut', Option[]>;
    tabs: { key: string; label: string; icon: string; count: number }[];
    kpis: { open: number; inProgress: number; toNudge: number; received: number };
};

export type QuoteBid = {
    id: string;
    contractor: string;
    contractorId: string | null;
    contractorRef: string | null;
    rating: number | null;
    fee: number;
    serviceFee: number;
    servicePercent: number | null;
    total: number;
    visit: string;
    duration: string | null;
    distance: string | null;
    comment: string | null;
    status: Badge;
    shown: boolean;
    at: string | null;
};

export type QuoteVersion = {
    id: string;
    label: string;
    status: Badge;
    totalHt: string | null;
    totalTtc: string | null;
    duration: string | null;
    validity: string | null;
    sentAt: string | null;
    receivedAt: string | null;
    pdfUrl: string | null;
};

export type Quote = QuoteRow & {
    siteId: string;
    requestStatus: string | null;
    notes: string | null;
    tradesCount: number;
    otherLines: { id: string; trade: string | null }[];
    deadlineIso: string | null;
    submittedAt: string | null;
    orderedAt: string | null;
    promoterContact: { phone: string | null; email: string | null };
    bids: QuoteBid[];
    visitInfo: { status: string; date: string; duration: string | null; visitedAt: string | null; reschedule: { date: string; reason: string | null; requestedAt: string | null } | null } | null;
    finance: [string, string | null][];
    payment: { reference: string; status: Badge; amount: string | null; paidAt: string | null; shared: string | null } | null;
    versions: QuoteVersion[];
    timeline: WorkerActivity[];
    can: { extend: boolean; cancel: boolean; remindPromoter: boolean; remindContractor: boolean };
};

export type QuoteShowProps = {
    quote: Quote;
    can: { update: boolean; validate: boolean };
};
