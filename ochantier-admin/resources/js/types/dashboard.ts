import type { AnyTone, Tone } from '@/types';

export type Kpi = {
    label: string;
    value: string;
    unit: string;
    icon: string;
    tone: AnyTone;
    delta: string;
    deltaTone: AnyTone;
    deltaIcon: string;
    compare: string;
    highlight: boolean;
    href: string;
};

export type ActivityRange = '7j' | '30j' | '3m' | 'custom';

export type Activity = {
    range: ActivityRange;
    from: string;
    to: string;
    caption: string;
    labels: string[];
    dates: string[];
    active: number[];
    created: number[];
};

export type Priority = {
    count: number;
    title: string;
    text: string;
    priority: 'Urgent' | 'Élevé' | 'Moyen';
    icon: string;
    href: string;
};

export type Priorities = {
    total: number;
    totalAll: number;
    items: Priority[];
    more: Priority[];
};

export type Site = {
    id: string;
    name: string;
    promoter: string;
    promoterHref: string | null;
    location: string;
    progress: number;
    workers: string;
    status: string;
    tone: AnyTone;
    period: string;
    type: string;
    href: string | null;
};

export type SiteDistribution = {
    total: number;
    segments: { label: string; count: number; color: string; href: string }[];
};

export type WorkerProfile = {
    name: string;
    site: string;
    score: number;
    job: string;
    experience: string;
    rate: string;
    availability: string;
    rating: string;
    location: string;
    verification: string;
};

export type Matching = {
    relevance: number;
    pending: number;
    delta: string;
    profiles: WorkerProfile[];
};

export type FinanceRange = 'auj' | '7j' | '30j' | '3m';

export type Finance = {
    range: FinanceRange;
    labels: string[];
    collected: number[];
    paidOut: number[];
    pending: number;
};

export type FeedEntry = {
    icon: string;
    tone: Tone;
    title: string;
    target: string;
    user: string;
    when: string;
    href: string;
};

export type DashboardProps = {
    kpis: Kpi[];
    activity: Activity;
    priorities: Priorities;
    recentSites: Site[];
    siteDistribution: SiteDistribution;
    matching: Matching;
    finance: Finance;
    feed: FeedEntry[];
    updatedAt: string;
};
