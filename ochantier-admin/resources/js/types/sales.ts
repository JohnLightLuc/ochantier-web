import type { PayoutMethodData } from '@/types/payouts';
import type { Option } from '@/components/ui/Field';
import type { ComponentProps } from 'react';
import type { UploadDocumentDialog } from '@/components/promoters/dialogs';
import type { Badge } from '@/types';
import type { PromoterActivityRow, TabPagination } from '@/types/promoters';
import type { ValueBadge, WorkerDocument } from '@/types/workers';

export type SalesAgentRow = {
    id: string;
    code: string;
    name: string;
    firstName: string;
    phone: string | null;
    email: string | null;
    zone: string | null;
    zoneList: { id: string; name: string; primary: boolean }[];
    secondaryZones: string[];
    supervisor: string | null;
    workersTotal: number;
    monthRegistered: number;
    monthValidated: number;
    validationRate: number | null;
    objective: { target: number; achieved: number; percent: number } | null;
    monthCommissions: number;
    unpaidCommissions: number;
    status: ValueBadge;
    startedAt: string | null;
    lastActivityOn: string | null;
};

type UploadType = NonNullable<ComponentProps<typeof UploadDocumentDialog>['types']>[number];

export type SalesFilters = Partial<Record<'q' | 'statut' | 'zone' | 'superviseur' | 'periode' | 'rapide' | 'tri' | 'sens' | 'page', string>>;

export type SalesCan = { create: boolean; update: boolean; terminate: boolean; export: boolean; documents: boolean; validate: boolean; proposeAdjustment: boolean; approveAdjustment: boolean };

export type SalesIndexProps = {
    agents: { data: SalesAgentRow[]; currentPage: number; lastPage: number; total: number; from: number; to: number };
    filters: SalesFilters;
    filterOptions: Record<'statut' | 'zone' | 'superviseur' | 'periode', Option[]>;
    kpis: { total: number; active: number; recruited: number; payable: number };
    todo: { objective: number; followUp: number; earnings: number; pending: number };
    can: SalesCan;
};

export type SalesAgent = SalesAgentRow & {
    lastName: string;
    secondaryPhone: string | null;
    address: string | null;
    isSupervisor: boolean;
    verification: string;
    endedAt: string | null;
    createdBy: string | null;
    lastLoginAt: string | null;
    ranking: { rank: number; total: number; label: string } | null;
    months: { month: string; label: string; registered: number; validated: number }[];
    trades: { label: string; count: number }[];
    accessLink: { state: string; label: string; sentAt: string | null; expiresAt: string | null; usedAt: string | null };
    passwordSet: boolean;
    can: { suspend: boolean; reactivate: boolean; terminate: boolean; edit: boolean; send_link: boolean };
};

export type SalesTab = '' | 'profil-documents' | 'ouvriers' | 'activite' | 'zones' | 'objectifs' | 'gains' | 'acces-securite' | 'historique';

export type SalesAccess = {
    lastLoginAt: string | null;
    phoneVerified: boolean;
    failedLogins: number;
    lockedUntil: string | null;
    sessions: { id: string; device: string; platform: string | null; lastActivity: string | null }[];
};

export type SalesShowProps = {
    agent: SalesAgent;
    tab: SalesTab;
    kpis: { label: string; value: string; unit?: string; icon: string; tone: string; hint: string }[];
    banner: { tone: string; icon: string; title: string; text: string } | null;
    can: SalesCan;
    documents: WorkerDocument[];
    tabCounts: Partial<Record<SalesTab, number>>;
    access: SalesAccess | null;
    documentTypes?: UploadType[];
    relations: SalesRelations | null;
    fieldOptions: SalesFieldOptions | null;
};

export type SalesFieldOptions = { colleagues: Option[]; communes: Option[] };

type StatusFilter = { label: string; value: string | null; count: number };

export type SalesTransfer = { id: string; worker: { id: string; full_name: string }; from: string | null; to: string | null; direction: 'sortant' | 'entrant'; reason: string; by: string; at: string | null };

export type SalesZoneRequest = { id: string; communes: { id: string; name: string }[]; status: ValueBadge; createdAt: string | null; comment: string | null; decidedBy: string | null; decidedAt: string | null };

export type SalesObjective = { month: string; label: string; target: number; achieved: number; percent: number; comment: string | null; canEdit: boolean };

export type SalesRelations =
    | { kind: 'workers'; status: string | null; filters: StatusFilter[]; pagination: TabPagination; rows: { id: string; name: string; code: string; trade: string | null; commune: string | null; status: ValueBadge; submittedAt: string | null }[]; transfers: SalesTransfer[] }
    | { kind: 'prospects'; status: string | null; filters: StatusFilter[]; pagination: TabPagination; rows: { id: string; name: string; phone: string | null; trade: string | null; commune: string | null; status: Badge & { value: string }; percent: number; hint: string; createdAt: string | null; lastFollowupAt: string | null }[] }
    | { kind: 'zones'; requests: SalesZoneRequest[] }
    | { kind: 'objectives'; rows: SalesObjective[]; currentMonth: string; appreciations: { id: string; body: string; author: string; date: string | null }[] }
    | {
          kind: 'earnings';
          paymentMethod: PayoutMethodData | null;
          month: string;
          monthLabel: string;
          months: Option[];
          totals: { confirmed: number; paid: number; pending: number };
          history: { id: string; worker: string; workerId: string; amount: number; status: ValueBadge; date: string | null }[];
          adjustments: SalesAdjustment[];
          payouts: { id: string; reference: string; start: string | null; end: string | null; amount: number; status: ValueBadge; paidAt: string | null }[];
      }
    | { kind: 'history'; rows: PromoterActivityRow[]; pagination: TabPagination };

export type SalesAdjustment = { id: string; kind: { value: string; label: string }; amount: number; reason: string; grantedOn: string | null; proposedBy: string; decidedBy: string | null; canDecide: boolean; status: ValueBadge };

export type SalesRankingRow = { commercial_id: string; name: string; employee_number: string; validated: number; rank: number };

export type SalesRankingProps = {
    month: string;
    label: string;
    closedAt: string | null;
    publishedAt: string | null;
    canClose: boolean;
    canPublish: boolean;
    top: SalesRankingRow | null;
    rows: SalesRankingRow[];
    months: Option[];
    can: { export: boolean; validate: boolean };
};
