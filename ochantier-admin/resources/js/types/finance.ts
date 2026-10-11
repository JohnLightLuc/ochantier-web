import type { BlockAction, BlockPageProps } from '@/types/blocks';
import type { Rule } from '@/types/payments';

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
