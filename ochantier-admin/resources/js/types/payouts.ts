export type PayoutRow = {
    id: string;
    reference: string;
    batch: { id: string; reference: string } | null;
    worker: { id: string; reference: string; full_name: string };
    site: { id: string; label: string } | null;
    mission: { id: string; reference: string };
    periodStart: string | null;
    periodEnd: string | null;
    days: number;
    gross: number;
    commission: number;
    deduction: number;
    bonus: number;
    net: number;
    number: string | null;
    operator: string | null;
    status: { value: string; label: string; tone: string };
};

export type BatchRow = {
    id: string;
    reference: string;
    status: { value: string; label: string; tone: string };
    count: number;
    net: number;
    commission: number;
    results: { reussis?: number; echoues?: number } | null;
    preparedBy: string | null;
    approvedBy: string | null;
    createdAt: string | null;
    executedAt: string | null;
};

export type PayoutCan = {
    create: boolean;
    approve: boolean;
    export: boolean;
    payouts: boolean;
    commercials: boolean;
    commercialCreate: boolean;
    commercialApprove: boolean;
    commercialExport: boolean;
};

export type CommercialPayoutRow = {
    id: string;
    reference: string;
    commercial: { id: string; reference: string; full_name: string };
    periodStart: string | null;
    periodEnd: string | null;
    amount: number;
    status: { value: string; label: string; tone: string };
    preparedBy: string | null;
    approvedBy: string | null;
    paidAt: string | null;
};

export type PayoutMethodRow = { id: string; operator: string | null; number: string; holder: string; proposed_by: string | null; can_decide: boolean };

export type PayoutMethodData = {
    current: PayoutMethodRow | null;
    pending: PayoutMethodRow | null;
    operators: { id: string; name: string }[];
    default_number: { dial_code: string | null; phone_number: string | null };
    can: { create: boolean; approve: boolean };
};

export type RefundCan = { update: boolean; decide: boolean; exportRefunds: boolean; payouts: boolean; commercials: boolean };

export type RefundRow = {
    id: string;
    reference: string;
    origin: { value: string; label: string };
    status: { value: string; label: string; tone: string };
    amount: number;
    reason: string;
    refusalReason: string | null;
    mission: { id: string; reference: string };
    site: { id: string; label: string };
    promoter: { id: string; full_name: string };
    transaction: { id: string; reference: string; amount: number } | null;
    number: string | null;
    operator: string | null;
    requestedBy: string | null;
    approvedBy: string | null;
    secondApprover: string | null;
    approvalsRequired: number;
    date: string | null;
    executedAt: string | null;
    canApprove: boolean;
    canRetry: boolean;
    canRefuse: boolean;
    canChangeNumber: boolean;
};

export type RegularisationRow = {
    id: string;
    reference: string;
    site: { id: string; label: string };
    promoter: { id: string; full_name: string };
    closedAt: string | null;
    state: { value: string; label: string; tone: string };
    days: number;
    workers: number;
    commission: number;
    yRate: number;
    total: number;
    plannedTotal: number;
    paid: number;
    refunded: number;
    balance: number;
    blockers: string[];
    schedule: { id: string; reference: string; amount_due: number; amount_paid: number; status: { value: string; label: string; tone: string }; due_date: string | null } | null;
};
