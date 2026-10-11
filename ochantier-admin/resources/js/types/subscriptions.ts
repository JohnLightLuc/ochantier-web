import type { Badge } from '@/types';

export type Plan = {
    key: string;
    name: string;
    subtitle: string;
    description: string;
    price: number;
    priceLabel: string;
    period: string;
    maxUsers: number;
    features: string[];
    popular: boolean;
    solo: boolean;
    subscribers?: number;
    mrr?: number | null;
    averageUsers?: string;
};

export type SubscriptionRow = {
    slug: string;
    promoter: string;
    owner: string;
    plan: { key: string; name: string };
    users: number;
    maxUsers: number;
    amount: string;
    period: string;
    status: Badge;
    nextDue: string | null;
    note: string | null;
};

export type SubscriptionsIndexProps = {
    tab: 'abonnes' | 'formules';
    kpis: { label: string; value: string; unit?: string; icon: string; tone: string; hint?: string; highlight?: boolean }[];
    filters: Partial<Record<'q' | 'formule' | 'statut', string>>;
    statuses: Record<string, string>;
    plans: Plan[];
    counts: { subscriptions: number; plans: number };
    subscriptions: { data: SubscriptionRow[]; page: number; lastPage: number; total: number; from: number; to: number };
};

export type SubscriptionShowProps = {
    subscription: {
        slug: string;
        promoter: string;
        owner: string;
        phone: string;
        email: string;
        status: Badge;
        statusLabel: string;
        locked: boolean;
        plan: Plan;
        previousPlan: string | null;
        since: string;
        nextDue: string | null;
        paymentMethod: string | null;
        pending: { name: string; at: string | null } | null;
    };
    plans: Plan[];
    users: { id: number; name: string; role: string; via: string; status: string; owner: boolean }[];
    invoices: { id: number; reference: string; date: string; label: string; amount: number; status: string }[];
    activities: { id: number; date: string; text: string }[];
    roles: string[];
};
