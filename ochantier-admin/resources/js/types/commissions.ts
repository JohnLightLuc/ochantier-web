import type { Badge } from '@/types';

/** Ligne du registre des commissions O’CHANTIER PRO. */
export type CommissionLine = {
    id: string;
    source: string;
    reference: string;
    label: string;
    href: string | null;
    site: string;
    contractor: string;
    contractorId: string;
    payer: string;
    base: number;
    rate: string;
    commission: number;
    net: number | null;
    status: Badge;
    date: string | null;
    payout: { id: string; reference: string; label: string; tone: string } | null;
    serviceDone: boolean;
};

export type CommissionLedgerData = {
    lines: CommissionLine[];
    total: number;
    currentPage: number;
    lastPage: number;
    totals: { collected: number; toCollect: number; collectedAmount: number; professionalNet: number };
};
