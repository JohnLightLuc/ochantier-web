import type { Option } from '@/components/ui/Field';
import type { ComponentProps } from 'react';
import type { UploadDocumentDialog } from '@/components/promoters/dialogs';
import type { ValueBadge, WorkerDocument } from '@/types/workers';

export type SalesAgentRow = {
    id: string;
    code: string;
    name: string;
    firstName: string;
    phone: string | null;
    email: string | null;
    zone: string | null;
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

export type SalesCan = { create: boolean; update: boolean; terminate: boolean; export: boolean; documents: boolean };

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

export type SalesTab = '' | 'profil-documents' | 'acces-securite';

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
};
