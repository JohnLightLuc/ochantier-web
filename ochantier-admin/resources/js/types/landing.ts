import type { ValueBadge } from '@/types/workers';

export type LandingTab = '' | 'contenu' | 'candidatures' | 'messages' | 'publications' | 'newsletter';

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

export type LandingTabItem = { key: LandingTab; label: string; icon: string; count: number | null };

/** Modifications du brouillon non publiées (bandeau commun aux onglets). */
export type LandingDraft = {
    changes: { key: string; label: string }[];
    errors: number;
    firstError: string | null;
    liveNumber: number;
    restoredFrom: number | null;
    updatedAt: string | null;
    updatedBy: string | null;
    canEdit: boolean;
};

export type LandingShellProps = { tab: LandingTab; tabs: LandingTabItem[]; draft: LandingDraft | null };

export type LandingCheck = { level: 'error' | 'warning'; section: string; sectionLabel: string; message: string };

export type LandingSiteSection = { key: string; label: string; icon: string; description: string; hideable: boolean; movable: boolean; hidden: boolean; changed: boolean; errors: number; number: string };

export type LandingSite = {
    url: string;
    live: { number: number; publishedAt: string | null; publishedBy: string | null };
    visible: number;
    movable: number;
    checks: LandingCheck[];
    order: string[];
    hidden: string[];
    sections: LandingSiteSection[];
};

export type LandingFieldType = 'text' | 'area' | 'url' | 'tel' | 'email' | 'toggle' | 'file' | 'lines' | 'items' | 'select';

export type LandingField = {
    key: string;
    label: string;
    type: LandingFieldType;
    required?: boolean;
    max?: number;
    min?: number;
    item_max?: number;
    rows?: number;
    hint?: string;
    placeholder?: string;
    accept?: string;
    add?: string;
    options?: { value: string; label: string }[];
    sub?: LandingField[];
};

export type LandingValue = string | boolean | null | string[] | Record<string, string | boolean | null>[];

export type LandingEditor = {
    section: { key: string; label: string; icon: string; description: string; hideable: boolean; hidden: boolean; changed: boolean; fields: LandingField[]; values: Record<string, LandingValue> };
    nav: { key: string; label: string; icon: string; hidden: boolean; changed: boolean; error: boolean }[];
    checks: LandingCheck[];
    layout: { order: string[]; hidden: string[] };
    files: Record<string, { id: string; name: string; mimeType: string; url: string }>;
};

export type LandingVersion = { number: number; note: string | null; sections: string[]; restoredFrom: number | null; publishedBy: string; publishedAt: string | null; live: boolean };

export type LandingIndexProps = LandingShellProps & {
    site: LandingSite | null;
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
