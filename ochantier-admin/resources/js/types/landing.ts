import type { ValueBadge } from '@/types/workers';

export type LandingTab = '' | 'candidatures' | 'messages' | 'newsletter';

export type LandingApplicationRow = {
    id: string;
    reference: string;
    name: string;
    phone: string;
    trade: string;
    tradeMatched: boolean;
    commune: string;
    communeMatched: boolean;
    experience: string;
    availability: string;
    targetSite: string | null;
    status: ValueBadge;
    duplicates: number;
    worker: { id: string; reference: string } | null;
    receivedAt: string | null;
};

export type LandingMessageRow = {
    id: string;
    reference: string;
    profile: string;
    name: string;
    company: string | null;
    subject: string;
    status: ValueBadge;
    late: boolean;
    handledBy: string | null;
    receivedAt: string | null;
};

export type NewsletterRow = { id: string; email: string; subscribedAt: string | null };

export type LandingList<Row> = {
    data: Row[];
    currentPage: number;
    lastPage: number;
    total: number;
    from: number;
    to: number;
    counts: Record<string, number> | null;
    trades: string[] | null;
    responseHours: number | null;
};

export type LandingOverview = {
    applications?: Record<'nouvelle' | 'en_verification' | 'convertie' | 'refusee', number>;
    messages?: Record<'nouveau' | 'en_cours' | 'traite' | 'late', number> & { response_hours: number };
    newsletter?: { active: number; month: number };
};

export type LandingIndexProps = {
    tab: LandingTab;
    tabs: { key: LandingTab; label: string; icon: string; count: number | null }[];
    overview: LandingOverview;
    filters: Partial<Record<'statut' | 'q' | 'metier' | 'retard' | 'page', string>>;
    list: LandingList<LandingApplicationRow | LandingMessageRow | NewsletterRow> | null;
};

export type LandingFile = { name: string; mime_type: string; url: string } | null;

export type LandingApplication = LandingApplicationRow & {
    identity: [string, string | null][];
    profile: [string, string | null][];
    work: [string, string | null][];
    files: { identity: LandingFile; photo: LandingFile };
    duplicateApplications: { id: string; reference: string; status: string; date: string | null }[];
    duplicateWorkers: { id: string; reference: string; name: string; status: string }[];
    refusalReason: string | null;
    handledBy: string | null;
    can: { review: boolean; refuse: boolean; convert: boolean };
};

export type LandingMessage = LandingMessageRow & {
    phone: string;
    email: string;
    message: string;
    note: string | null;
    handledAt: string | null;
    dueAt: string | null;
    can: { start: boolean; close: boolean };
};
