import type { Option } from '@/components/ui/Field';
import type { Badge } from '@/types';
import type { PromoterActivityRow, TabPagination } from '@/types/promoters';
import type { ValueBadge } from '@/types/workers';

export type SiteRow = {
    id: string;
    reference: string;
    name: string;
    promoter: string;
    promoterId: string;
    commune: string | null;
    type: string | null;
    workType: string | null;
    priority: (Badge & { value: string }) | null;
    status: ValueBadge;
    startsOn: string | null;
    endsOn: string | null;
    required: number;
    assigned: number;
    isLate: boolean;
    openIncidents: number;
    pendingDays: number;
    archived: boolean;
};

export type SiteFilters = Partial<Record<'q' | 'statut' | 'type' | 'localisation' | 'periode' | 'priorite' | 'rapide' | 'promoteur' | 'tri' | 'sens' | 'page', string>>;

export type SiteCan = { update: boolean; validate: boolean; export: boolean };

export type SiteIndexProps = {
    sites: { data: SiteRow[]; currentPage: number; lastPage: number; total: number; from: number; to: number };
    filters: SiteFilters;
    filterOptions: Record<'statut' | 'type' | 'localisation' | 'periode' | 'priorite', Option[]>;
    kpis: { total: number; active: number; searching: number; late: number };
    todo: { retard: number; effectif: number; incidents: number; cloturer: number; pointages: number };
    can: SiteCan;
};

export type Site = SiteRow & {
    internalReference: string | null;
    description: string | null;
    address: string | null;
    neighborhood: string | null;
    access: string | null;
    location: { latitude?: number; longitude?: number; lat?: number; lng?: number } | null;
    geofence: number | null;
    durationDays: number | null;
    workHours: string | null;
    contact: { full_name: string; phone: string | null; email: string | null };
    responsible: { full_name: string; phone: string | null } | null;
    needs: { id: string; trade: string | null; headcount: number; startsOn: string | null; status: string }[];
    closedAt: string | null;
    closedBy: string | null;
    can: { suspend: boolean; resume: boolean; cancel: boolean; close: boolean; archive: boolean };
};

export type SiteShowProps = {
    site: Site;
    tab: SiteTab;
    relations: SiteRelations | null;
    tabCounts: Partial<Record<SiteTab, number>>;
    kpis: { label: string; value: string; unit?: string | null; icon: string; tone: string; hint: string }[];
    banner: { tone: string; icon: string; title: string; text: string } | null;
    can: SiteCan;
};

export type SiteTab = '' | 'equipe' | 'incidents' | 'documents' | 'historique';

export type SiteRelations =
    | {
          kind: 'team';
          members: { id: string; workerId: string; name: string; trade: string; role: string; since: string | null; dailyRate: number; status: string; replacement: string | null; replaces: string | null }[];
          today: { label: string; isOpen: boolean; closedMessage: string | null; present: number; absent: number; total: number; pending: number; validated: boolean };
          days: { date: string; label: string; present: number; total: number; rate: number; validated: boolean }[];
      }
    | {
          kind: 'incidents';
          types: Option[];
          severities: Option[];
          rows: { id: string; reference: string; type: string; description: string; resolution: string | null; resolvedBy: string | null; severity: ValueBadge; status: ValueBadge; occurredAt: string | null; resolvedAt: string | null }[];
      }
    | { kind: 'documents'; rows: { id: string; label: string; addedAt: string | null; version: number; files: { name: string; url: string }[] }[] }
    | { kind: 'history'; rows: PromoterActivityRow[]; pagination: TabPagination };
