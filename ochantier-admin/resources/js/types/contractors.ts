import type { Option } from '@/components/ui/Field';
import type { CommissionLedgerData } from '@/types/commissions';
import type { PayoutMethodData } from '@/types/payouts';
import type { Badge } from '@/types';
import type { ValueBadge, WorkerActivity, WorkerAlert, WorkerKpi } from '@/types/workers';

/** Type de profil : entreprise (contrôle KYB) ou indépendant (contrôle KYC). */
export type ContractorKind = { value: string; label: string; check: string };

/** Ligne de la liste. */
export type ContractorRow = {
    id: string;
    code: string;
    name: string;
    kind: ContractorKind;
    phone: string | null;
    city: string | null;
    trades: string[];
    zones: string[];
    plan: { name: string; seats: number };
    activity: { open: number; total: number };
    rating: { average: number | null; count: number };
    state: ValueBadge;
    submittedAt: string | null;
};

export type ContractorFilters = Partial<Record<'q' | 'type' | 'metier' | 'zone' | 'onglet' | 'page', string>>;

export type ContractorListTab = { key: string; label: string; icon: string; count: number };

export type ContractorIndexProps = {
    contractors: { data: ContractorRow[]; currentPage: number; lastPage: number; total: number; from: number; to: number };
    filters: ContractorFilters;
    filterOptions: Record<'type' | 'metier' | 'zone', Option[]>;
    tabs: ContractorListTab[];
    kpis: { verified: number; total: number; toReview: number; corrections: number };
    can: { validate: boolean; update: boolean; rules: boolean };
    /** Chargé à l’ouverture de « Règles & commissions ». */
    rules?: ProRule[];
};

export type ContractorReview = { number: number; status: Badge; submittedAt: string | null; decidedAt: string | null; decidedBy: string | null; reason: string | null };

export type Contractor = {
    id: string;
    code: string;
    name: string;
    photoUrl: string | null;
    kind: ContractorKind;
    state: ValueBadge;
    verificationStatus: string | null;
    reason: string | null;
    submittedAt: string | null;
    validatedAt: string | null;
    identity: [string, string | null][];
    contact: [string, string | null][];
    trades: string[];
    zones: string[];
    experienceYears: number | null;
    description: string | null;
    plan: { name: string; seats: number; status: string | null; until: string | null };
    rating: { average: number | null; count: number };
    completedSites: number;
    completion: { percent: number; missing: string[] };
    account: { status: string | null; lastLoginAt: string | null; createdAt: string | null } | null;
    suspendedReason: string | null;
    reviews: ContractorReview[];
    can: { approve: boolean; corrections: boolean; reject: boolean; reviewDocuments: boolean; suspend: boolean; reactivate: boolean };
};

export type ContractorDocumentFile = { name: string; side: string; mime: string; url: string };

/** Pièce du dossier KYB/KYC (déposée, ou demandée et pas encore fournie). */
export type ContractorDocument = {
    id: string;
    label: string;
    hint: string | null;
    required: boolean;
    reference: string | null;
    addedAt: string | null;
    expiresAt: string | null;
    status: ValueBadge;
    rejectionReason: string | null;
    hasFile: boolean;
    canDecide: boolean;
    files: ContractorDocumentFile[];
};

export type ContractorResponse = {
    id: string;
    kind: string;
    reference: string | null;
    title: string;
    promoter: string | null;
    amount: number | null;
    amountLabel: string;
    status: Badge;
    /** En attente d’une décision du promoteur. */
    open: boolean;
    at: string | null;
};

export type ContractorTab = '' | 'pieces' | 'reponses' | 'versements' | 'historique';

export type ContractorShowProps = {
    contractor: Contractor;
    tab: ContractorTab;
    kpis: WorkerKpi[];
    tabCounts: Partial<Record<ContractorTab, number>>;
    documents: ContractorDocument[];
    alerts: WorkerAlert[];
    responses: ContractorResponse[] | null;
    history: WorkerActivity[] | null;
    /** Onglet « Commission & versements » : registre du professionnel (finance.read) et numéro de versement (payouts.read). */
    ledger: CommissionLedgerData | null;
    payoutMethod: PayoutMethodData | null;
    can: { validate: boolean; update: boolean; documents: boolean; finance: boolean; payouts: boolean };
};

/** Réglage de « Règles & commissions ». */
export type ProRule = { key: string; label: string; unit: string | null; min: number | null; max: number | null; value: number | null };
