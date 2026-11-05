import type { Option } from '@/components/ui/Field';
import type { PromoterActivityRow, TabPagination } from '@/types/promoters';
import type { ValueBadge } from '@/types/workers';

export type OfferRow = {
    id: string;
    reference: string;
    title: string;
    type: string | null;
    urgency: string | null;
    status: ValueBadge;
    site: { id: string; label: string; reference: string; commune: string | null };
    promoter: { id: string; name: string };
    mainTrade: string | null;
    tradesCount: number;
    required: number;
    assigned: number;
    percent: number;
    proposalPending: boolean;
    depositExpected: boolean;
    startsOn: string | null;
    endsOn: string | null;
    deadline: string | null;
};

export type OfferFilters = Partial<Record<'q' | 'statut' | 'metier' | 'localisation' | 'type' | 'urgence' | 'rapide' | 'chantier' | 'tri' | 'sens' | 'page', string>>;

export type OfferCan = { update: boolean; validate: boolean; export: boolean };

export type OfferIndexProps = {
    offers: { data: OfferRow[]; currentPage: number; lastPage: number; total: number; from: number; to: number };
    filters: OfferFilters;
    filterOptions: Record<'statut' | 'metier' | 'localisation' | 'type' | 'urgence', Option[]>;
    kpis: { total: number; open: number; filled: number; suspended: number };
    todo: { a_matcher: number; proposition: number; acompte: number; incomplet: number; expires: number };
    can: OfferCan;
};

export type Offer = OfferRow & {
    description: string | null;
    deliverables: string | null;
    extensions: number;
    engagement: string | null;
    needs: { id: string; reference: string; trade: string | null; headcount: number; assigned: number; level: string | null; experience: number | null; startsOn: string | null; endsOn: string | null; days: number; budget: number | null; status: string }[];
    can: { suspend: boolean; resume: boolean; extend: boolean; cancel: boolean; close: boolean };
};

export type OfferTab = '' | 'ouvriers' | 'documents' | 'historique';

export type OfferRelations =
    | {
          kind: 'workers';
          proposals: { id: string; reference: string; status: ValueBadge; requested: number; proposed: number; partial: boolean; total: number; deposit: number; publishedAt: string | null; decidedAt: string | null; refusal: string | null }[];
          assigned: { id: string; workerId: string; name: string; trade: string | null; status: string; startsOn: string | null; endsOn: string | null }[];
      }
    | { kind: 'documents'; rows: { id: string; label: string; addedAt: string | null; files: { name: string; url: string }[] }[] }
    | { kind: 'history'; rows: PromoterActivityRow[]; pagination: TabPagination };

export type OfferShowProps = {
    offer: Offer;
    tab: OfferTab;
    relations: OfferRelations | null;
    kpis: { label: string; value: string; unit?: string | null; icon: string; tone: string; hint: string }[];
    banner: { tone: string; icon: string; title: string; text: string } | null;
    can: OfferCan;
};
