import type { Option } from '@/components/ui/Field';
import type { Badge } from '@/types';
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
    kpis: { label: string; value: string; unit?: string | null; icon: string; tone: string; hint: string }[];
    banner: { tone: string; icon: string; title: string; text: string } | null;
    can: SiteCan;
};
