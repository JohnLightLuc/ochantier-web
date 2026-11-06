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

export type PayoutCan = { create: boolean; approve: boolean; export: boolean };
