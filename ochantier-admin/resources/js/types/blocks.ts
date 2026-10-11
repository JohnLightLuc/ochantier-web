import type { DialogField } from '@/components/overlays/FormDialog';
import type { DialogTone } from '@/components/overlays/Dialog';
import type { ButtonVariant } from '@/components/ui/Button';
import type { Tile } from '@/components/workers/blocks';
import type { HistoryEvent } from '@/components/workers/tabs';
import type { ToneAlias } from '@/types';
import type { Paginated } from '@/types/attendances';

/** Action déclarée par le serveur et exécutée par la page (les modules peuvent ajouter leurs propres types). */
export type BlockAction =
    | { type: 'link'; href: string }
    | { type: 'download'; href: string }
    | { type: 'post'; url: string }
    | { type: 'form'; title: string; body: string; icon: string; ok: string; url: string; fields: DialogField[]; tone: DialogTone; extra: Record<string, unknown>; initial?: Record<string, string> }
    | { type: 'confirm'; title: string; body: string; icon: string; ok: string; url: string; tone: DialogTone }
    | { type: 'kpi'; id: string }
    | { type: 'error'; message: string }
    | { type: string; [key: string]: unknown };

export type BlockButton = { label: string; icon: string; variant: ButtonVariant; action: BlockAction };

export type BlockAlert = { tone: string; icon: string; title: string; text: string; cta: string | null; action: BlockAction | null };

export type BlockKpi = { label: string; value: string; icon: string; tone: ToneAlias; hint: string; action: BlockAction };

export type BlockChip = { label: string; value: string; icon: string; action: BlockAction };

export type BlockChart = { title: string; sub: string; color: string | null; bars: { label: string; value: number; text: string }[] };

export type BlockStep = { label: string; sub: string; state: 'done' | 'cur' | 'warn' | 'fail' | 'todo'; icon: string; action: BlockAction | null };

/** Courbes : séries tracées, valeurs complémentaires de l’info-bulle et total mis en avant. */
export type BlockLines = {
    title: string;
    sub: string;
    labels: string[];
    series: { label: string; color: string; values: number[]; texts: string[] }[];
    extra: { label: string; color: string; texts: string[] }[];
    note: { label: string; value: string } | null;
};

/** Anneau de répartition (segments en valeurs et parts). */
export type BlockDonut = { title: string; sub: string; total: string; totalLabel: string; segments: { label: string; value: number; color: string; text: string; percent: string }[] };

export type BlockStack = { title: string; sub: string; total: string; segments: { label: string; value: number; color: string; text: string; percent: string }[] };

export type BlockCard = { icon: string; kicker: string; title: string; sub: string; badge: string; tone: ToneAlias; action: BlockAction | null };

export type BlockPanel = {
    icon: string;
    title: string;
    kind: 'cards' | 'kv' | 'feed';
    cards?: BlockCard[];
    items?: [string, string][];
    feed?: { icon: string; title: string; text: string; when: string }[];
    button: BlockButton | null;
};

/** Cellule typée : texte, texte sur deux lignes, badge, progression, personne, lieu. */
export type BlockCell =
    | { k: 'txt'; v: string; b: boolean; m: boolean }
    | { k: 'stk'; v: string; sub: string | null }
    | { k: 'bdg'; v: string; tn: ToneAlias }
    | { k: 'prog'; v: number }
    | { k: 'per'; v: string; sub: string | null }
    | { k: 'loc'; v: string };

export type BlockMenuItem = { icon: string; label: string; action: BlockAction };

export type BlockRow = { key: string; cells: BlockCell[]; open: BlockAction | null; acts: BlockMenuItem[]; menu?: BlockMenuItem[] };

export type BlockTable = {
    icon: string;
    title: string;
    count: string;
    columns: string[];
    rows: BlockRow[];
    minWidth: number;
    empty: { icon: string; title: string; text: string };
    pagination: Omit<Paginated<never>, 'data'> | null;
};

export type BlockSelect = { key: string; label: string; value: string; options: { value: string; label: string }[] };

export type BlockTab = { label: string; href: string; count: number; active: boolean };

/** En-tête de fiche : titre, statut, informations clés, actions et menu. */
export type BlockProfile = {
    title: string;
    status: { label: string; tone: ToneAlias };
    tag: { label: string; tone: ToneAlias } | null;
    sub: string;
    meta: { icon: string; label: string; value: string }[];
    buttons: BlockButton[];
    menu: BlockMenuItem[];
};

/** Fiche complète d’un indicateur (panneau latéral). */
export type KpiSheet = { id: string; kicker: string; title: string; badge: { label: string; tone: ToneAlias }; meta: [string, string][]; drill: string | null };

/** Contenu d’une page décrite par le serveur. */
export type BlockPageProps = {
    page: string;
    nav: { label: string; icon: string; href: string; active: boolean }[];
    period: { key: string; label: string; range: string; from: string; to: string };
    periods: Record<string, string>;
    query: Record<string, string>;
    search: boolean;
    banner: BlockAlert | null;
    selects: BlockSelect[];
    views: { name: string; href: string }[];
    kpis: BlockKpi[];
    chips: BlockChip[];
    alerts: BlockAlert[];
    tiles: Tile[];
    toolbar: BlockButton[];
    flow: { steps: BlockStep[]; stacks: BlockStack[] } | null;
    charts: BlockChart[];
    lines?: BlockLines[];
    donuts?: BlockDonut[];
    panels: BlockPanel[];
    tables: BlockTable[];
    timeline: HistoryEvent[];
    missing: { title: string; text: string; back: string } | null;
    sheets: Record<string, KpiSheet>;
    tabs?: BlockTab[];
    profile?: BlockProfile | null;
    header: { title: string; subtitle: string };
};
