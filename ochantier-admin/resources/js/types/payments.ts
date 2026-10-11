import type { AnyTone, Badge } from '@/types';
import type { FilterDef, Paginated, PeriodInfo } from '@/types/attendances';

export type PaymentSection = { href: string; label: string; icon: string; count: number; active: boolean };

export type PaymentFilters = Partial<Record<string, string>>;

export type PaymentOptions = Record<'refundTypes' | 'responsibilities' | 'reconcileMotifs' | 'anomalyTypes' | 'levels' | 'bonusTypes' | 'indemnityTypes' | 'deductionTypes', string[]>;

/** Propriétés communes à toutes les pages du module. */
export type PaymentPageProps = {
    sections: PaymentSection[];
    period: PeriodInfo;
    periods: Record<string, string>;
    filters: PaymentFilters;
    options: PaymentOptions;
};

export type PaymentRow = {
    reference: string;
    date: string;
    type: string;
    promoter: string;
    site: string;
    worker: { name: string; code: string; trade: string; slug: string } | null;
    period: string;
    gross: number;
    extras: number;
    deduction: number;
    com: number;
    comP: number;
    comO: number;
    fees: number;
    promoterTotal: number;
    net: number;
    comNet: number;
    comCollected: number;
    comRefunded: number;
    method: string | null;
    payStatus: Badge;
    payoutStatus: Badge;
    comStatus: Badge;
    reconciliation: Badge;
    isDeposit: boolean;
    paid: boolean;
    canRetry: boolean;
    rate: number;
};

export type ListProps = {
    filterDefs: FilterDef[];
    filterCount: number;
    sorts: Record<string, string>;
    payments: Paginated<PaymentRow>;
    totals: { gross: number; com: number; net: number };
    hasData: boolean;
};

export type Rule = { name: string; description: string; type: string; base: string; min: number; max: number; scope: string; trade: string; target: string; from: string; to: string | null; status: string; version: string; y: number; z: number; refundRule: string; previous: { name: string; version: string; from: string; to: string; status: string; type: string }[] };

export type Tile = { label: string; value: string; icon: string; tone: string; sub?: string; bar?: number };

export type Segment = { title: string; sub: string; total: number; items: { label: string; value: number; color: string; percent: number }[] };

export type Refund = {
    id: number;
    payment?: string;
    date: string;
    type: string;
    amount: number;
    motif: string;
    beneficiary: string;
    responsibility: string;
    impact: number;
    rule: string;
    timing: string;
    proof: string | null;
    author: string;
    approver: string | null;
    status: Badge;
    pending: boolean;
    commission: number;
    commissionNet: number;
};

export type PaymentActivity = {
    id: number;
    date: string;
    time: string;
    author: string;
    category: { value: string; label: string; tone: AnyTone; icon: string };
    action: string;
    oldValue: string | null;
    newValue: string | null;
    comment: string | null;
    origin: string;
};

export type PaymentCalc = {
    gross: number;
    bonus: number;
    indemnity: number;
    deduction: number;
    base: number;
    y: number;
    z: number;
    raw: number;
    comP: number;
    comO: number;
    com: number;
    fees: number;
    feeRate: [number, number];
    tax: number;
    promoterTotal: number;
    net: number;
    paid: boolean;
    received: number;
    refunded: number;
    comRefunded: number;
    comReversed: number;
    comCollected: number;
    comNet: number;
    check: number;
    gap: number;
    comStatus: string;
};

export type PaymentAnomaly = { type: string; level: string; detail: string };

export type PaymentDetail = PaymentRow & {
    key: string;
    periodStart: string;
    periodEnd: string;
    days: number;
    openDays: number;
    dailyRate: number;
    source: string;
    assignment: string | null;
    calcStatus: string;
    validatedBy: string | null;
    validatedAt: string | null;
    provider: string | null;
    account: string | null;
    workerMethod: string | null;
    workerAccount: string | null;
    externalReference: string | null;
    payoutReference: string | null;
    providerMessage: string | null;
    payoutMessage: string | null;
    batch: string | null;
    attempts: number;
    initiatedAt: string | null;
    paidAt: string | null;
    payoutAt: string | null;
    reconciliationNote: string | null;
    ruleVersion: string;
    siteSlug: string;
    promoterSlug: string | null;
    documents: { reference: string; type: string; date: string; status: string; version: number }[];
};

export type PaymentItem = { id: number; kind: 'bonus' | 'indemnity' | 'deduction'; type: string; amount: number; motif: string; base: string | null; proof: string | null; author: string; approver: string | null; date: string; status: Badge; pending: boolean };

export type PaymentShowProps = {
    sections: PaymentSection[];
    payment: PaymentDetail;
    calc: PaymentCalc;
    anomalies: PaymentAnomaly[];
    checklist: [string, boolean, string][];
    payoutChecks: [string, boolean][];
    items: PaymentItem[];
    refunds: Refund[];
    attendances: { reference: string; date: string; arrival: string | null; departure: string | null; net: string; overtime: string | null; status: Badge; counted: boolean }[];
    activities: PaymentActivity[];
    rule: Rule;
    fees: [number, number];
    tax: number;
    tab: string;
    tabs: [string, string, number][];
    options: PaymentOptions;
};
