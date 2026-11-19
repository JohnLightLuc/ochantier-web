import type { CommissionLedgerData } from '@/types/commissions';
import type { Option } from '@/components/ui/Field';
import type { Badge } from '@/types';
import type { ValueBadge, WorkerActivity } from '@/types/workers';

/** Ligne de la liste des offres contractuelles. */
export type ContractOfferRow = {
    id: string;
    reference: string;
    site: string;
    place: string;
    promoter: string;
    price: number;
    period: string;
    responses: number;
    counterProposals: number;
    retained: { contractor: string; total: number } | null;
    quoteReference: string | null;
    status: ValueBadge;
    deadline: string | null;
    deadlineSoon: boolean;
};

export type ContractOfferFilters = Partial<Record<'q' | 'metier' | 'ville' | 'statut' | 'onglet' | 'page', string>>;

export type ContractOfferIndexProps = {
    offers: { data: ContractOfferRow[]; currentPage: number; lastPage: number; total: number; from: number; to: number };
    filters: ContractOfferFilters;
    filterOptions: Record<'metier' | 'ville' | 'statut', Option[]>;
    tabs: { key: string; label: string; icon: string; count: number | null }[];
    /** Registre des commissions (onglet « Commissions », droit finance.read). */
    ledger: CommissionLedgerData | null;
    kpis: { open: number; comparing: number; deposit: number; attributed: number };
    rates: { commission: string; deposit: string };
};

/** Réponse d’un professionnel, au prix promoteur. */
export type ContractResponse = {
    id: string;
    contractor: string;
    contractorId: string | null;
    contractorRef: string | null;
    rating: number | null;
    mode: Badge;
    amount: number;
    commission: number;
    total: number;
    deposit: number;
    /** Écart avec le prix fixé par le promoteur (positif : plus cher). */
    gap: number;
    duration: string;
    team: string;
    dates: string | null;
    distance: string | null;
    message: string | null;
    status: Badge;
    shown: boolean;
    /** Encore en lice (proposée, choisie ou retenue). */
    competing: boolean;
    quoteAuthor: boolean;
    sentAt: string | null;
};

export type ContractOffer = ContractOfferRow & {
    description: string | null;
    paymentMode: string | null;
    promoterContact: { phone: string | null; email: string | null };
    rates: { commission: string; deposit: string };
    deadlineIso: string | null;
    extensions: number;
    cancellationReason: string | null;
    quoteRequestId: string | null;
    quoteLineId: string | null;
    responsesList: ContractResponse[];
    attribution: { mission: string; period: string; deposit: { reference: string; amount: string | null; paid: string | null; status: string } | null } | null;
    timeline: WorkerActivity[];
    can: { extend: boolean; cancel: boolean; remindPromoter: boolean; remindContractors: boolean };
};

export type ContractOfferShowProps = {
    offer: ContractOffer;
    can: { update: boolean; validate: boolean };
};
