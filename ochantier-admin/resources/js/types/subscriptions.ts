type Status = { value: string; label: string; tone: string };

export type Plan = {
    id: string;
    code: string;
    name: string;
    subtitle: string | null;
    price: number;
    maxUsers: number;
    features: string[];
    popular: boolean;
    active: boolean;
    free: boolean;
    subscribers: number;
    revenue: number;
};

export type SubscriptionRow = {
    id: string;
    reference: string;
    promoter: { id: string; name: string; owner: string | null };
    plan: { code: string; name: string; price: number; maxUsers: number };
    pending: { name: string; at: string } | null;
    status: Status;
    seats: number;
    since: string | null;
    periodEnd: string | null;
    nextDue: string | null;
    autoRenew: boolean;
};

export type SubscriptionDetail = SubscriptionRow & {
    phone: string | null;
    email: string | null;
    periodStart: string | null;
    previousPlan: string | null;
    cancelledAt: string | null;
    cancellationReason: string | null;
    operator: string | null;
    users: { id: string; name: string; role: string; status: string }[];
    invoices: { id: string; reference: string; label: string; amount: number; date: string; status: Status; receiptUrl: string | null }[];
    history: { id: string; date: string | null; reason: string | null; status: string | null; actor: string }[];
    actions: { remind: boolean; changePlan: boolean; cancelPlanChange: boolean; suspend: boolean; reactivate: boolean; terminate: boolean; resume: boolean };
};

export type SubscriptionCan = { update: boolean; export: boolean };
