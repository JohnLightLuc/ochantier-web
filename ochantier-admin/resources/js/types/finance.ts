import type { BlockAction, BlockPageProps } from '@/types/blocks';

export type Rule = { name: string; description: string; type: string; base: string; min: number; max: number; scope: string; trade: string; target: string; from: string; to: string | null; status: string; version: string; y: number; z: number; refundRule: string; previous: { name: string; version: string; from: string; to: string; status: string; type: string }[] };

/** Actions propres à Finance & Reporting : règles de commission et construction d’un rapport. */
export type FinanceAction = BlockAction | { type: 'rates' } | { type: 'export'; report: string };

export type FinancePageProps = BlockPageProps & {
    basis: string;
    bases: Record<string, string>;
    rule: Rule & { ok: boolean };
    canParams: boolean;
    reports: { value: string; label: string }[];
    exportInfo: string;
};
