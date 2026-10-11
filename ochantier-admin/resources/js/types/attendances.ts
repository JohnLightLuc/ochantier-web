import type { AnyTone, Badge } from '@/types';

export type Section = { href: string; label: string; icon: string; active: boolean };

export type PeriodInfo = { key: string; from: string; to: string; label: string; shortcut?: string | null };

/** Ligne de tableau d’une journée de pointage. */
export type AttendanceRow = {
    reference: string;
    date: string;
    night: boolean;
    worker: { name: string; code: string; trade: string; slug: string };
    site: { name: string; slug: string; promoter: string };
    assignment: string;
    plannedStart: string;
    plannedEnd: string;
    arrival: { time: string | null; status: string };
    departure: { time: string | null; status: string };
    net: string;
    hasNet: boolean;
    late: string | null;
    overtime: string | null;
    status: Badge;
    indicators: string[];
    incomplete: string[];
    closable: boolean;
    closed: boolean;
    blockers: string[];
};

export type Paginated<T> = { data: T[]; page: number; lastPage: number; total: number; from: number; to: number };

export type FilterDef = { key: string; label: string; options: string[] };

export type Shortcut = { key: string; label: string; icon: string; tone: string; count: number; kpi: boolean };

export type AttendanceFilters = Partial<Record<string, string>>;

export type AttendanceIndexProps = {
    sections: Section[];
    period: PeriodInfo;
    periods: Record<string, string>;
    filters: AttendanceFilters;
    shortcuts: Shortcut[];
    filterDefs: FilterDef[];
    filterCount: number;
    sorts: Record<string, string>;
    attendances: Paginated<AttendanceRow>;
    hasData: boolean;
    options: AttendanceOptions;
};

export type Proof = { id: string; type: string; file: string; at: string; by: string; status: string; hash: string; version: number; comment: string | null };

export type AttendanceEvent = {
    status: string;
    time: string | null;
    by: string | null;
    at: string | null;
    validated_by: string | null;
    validated_at: string | null;
    location: { meters: number; accuracy: number; status: string; at: string; source: string | null } | null;
    proofs: Proof[];
    comment: string | null;
};

export type Decision = { key: 'lateness' | 'early_leave' | 'overtime' | 'absence'; label: string; status: string; options: string[] };

export type AttendanceCalc = {
    arrivalValid: boolean;
    departureValid: boolean;
    arrivalPending: boolean;
    departurePending: boolean;
    plan: number;
    late: number;
    lateFlag: boolean;
    net: number | null;
    overtime: number;
    early: number;
    closed: boolean;
    closable: boolean;
    blockers: string[];
    status: string;
    indicators: string[];
    incomplete: string[];
    closureStatus: string;
    cost: number;
    amount: number | null;
    prime: number;
    proofMissing: boolean;
    locationMissing: boolean;
    past: boolean;
    openAnomalies: { id: number; type: string; level: string; note: string }[];
    pendingCorrections: { id: number; field: string; old_value: string; new_value: string }[];
    labels: Record<'net' | 'gross' | 'pause' | 'plan' | 'min', string> & Record<'late' | 'early' | 'overtime' | 'unpaid', string | null>;
};

export type AttendanceDetail = AttendanceRow & {
    site: { name: string; slug: string; promoter: string; promoterSlug: string | null; commune: string; latitude: number | null; longitude: number | null };
    arrivalEvent: AttendanceEvent;
    departureEvent: AttendanceEvent;
    pauses: { type: string; start: string; end: string; minutes: number; validated: boolean; by: string | null }[];
    absence: { motif: string; comment: string | null; status: string; by: string; at: string; proof: string | null } | null;
    decisions: Decision[];
    closure: { status: string; by?: string | null; at?: string | null; reason?: string | null; previous?: string | null };
    transmission: string;
    notes: { date: string; by: string; text: string }[];
    tag: Badge;
};

export type Anomaly = { id: number; type: string; level: string; status: string; note: string; decision: string | null; detectedOn: string };

export type Correction = { id: number; field: string; oldValue: string; newValue: string; motif: string; comment: string | null; proof: string | null; status: string; requestedBy: string; requestedAt: string; decision: string | null; impact: string | null };

export type AttendanceActivity = {
    id: number;
    date: string;
    time: string;
    author: string;
    category: { value: string; label: string; tone: AnyTone; icon: string };
    action: string;
    oldValue: string | null;
    newValue: string | null;
    comment: string | null;
    origin: string;
};

export type AttendanceTab = '' | 'arrivee' | 'depart' | 'temps' | 'preuves' | 'anomalies' | 'corrections' | 'impact-financier' | 'historique';

export type AttendanceRules = Record<string, number | boolean | string>;

export type AttendanceOptions = Record<'anomalyTypes' | 'levels' | 'anomalyStatuses' | 'correctionFields' | 'correctionMotifs', string[]>;

export type AttendanceShowProps = {
    attendance: AttendanceDetail;
    calc: AttendanceCalc;
    anomalies: Anomaly[];
    corrections: Correction[];
    activities: AttendanceActivity[];
    correctionValues: Record<string, string>;
    tab: AttendanceTab;
    tabs: [string, string, number][];
    rules: AttendanceRules;
    options: AttendanceOptions;
};

export type SectionTile = { label: string; value: string; icon: string; tone: string; sub?: string; bar?: number };

/** Propriétés communes des sous-pages (incomplets, anomalies, corrections, absences, calendrier, rapports). */
export type SectionProps = {
    sections: Section[];
    period: PeriodInfo;
    periods: Record<string, string>;
    filters: AttendanceFilters;
    options: AttendanceOptions;
    filterDefs?: FilterDef[];
    tiles?: SectionTile[];
};

export type AnomalyRow = Anomaly & { reference: string; worker: { name: string; code: string }; site: string };

export type CorrectionRow = Correction & { reference: string; worker: string; site: string };

export type AbsenceRow = AttendanceRow & { absence: NonNullable<AttendanceDetail['absence']>; decision: Decision };

export type GridRow = { worker: { name: string; slug: string; sub: string }; cells: ({ reference: string; code: string; tone: string } | null)[]; net: string };

export type ChartData = { title: string; sub: string; unit: string; accent?: boolean; bars: [string, number][] };

type EntityBase = { sections: Section[]; kpis: { label: string; value: string; icon: string; tone: string; hint: string }[]; tab: string; tabs: [string, string][]; options: AttendanceOptions };

export type SiteEntityProps = EntityBase & {
    site: { name: string; slug: string; status: Badge; promoter: string; commune: string; city: string; hours: string; code: string; manager: string | null; blocked: boolean };
    trades: string[];
    trade: string;
    data: {
        day?: string;
        dates?: string[];
        tiles?: SectionTile[];
        rows?: AttendanceRow[];
        days?: string[] | { date: string; expected: number; present: number; absent: number; late: number; incomplete: number; anomalies: number; net: string; closure: number }[];
        grid?: GridRow[];
        monthLabel?: string;
    };
};

export type WorkerEntityProps = EntityBase & {
    worker: { name: string; slug: string; code: string; trade: string; commune: string | null; phone: string | null; status: Badge; availability: string };
    tiles: SectionTile[];
    rows: AttendanceRow[];
    assignments: { reference: string; site: string; siteSlug: string; promoter: string; hours: string; first: string; last: string; days: number }[];
};

export type AppRow = AttendanceRow & {
    recap: Record<string, [string, string][]>;
    serverTimes: { arrival: string; departure: string };
    absence: AttendanceDetail['absence'];
};

export type AppProps = {
    sections: Section[];
    sites: { value: string; label: string }[];
    site: { slug: string; name: string; promoter: string; manager: string | null; status: string; blocked: boolean; commune: string } | null;
    today: string;
    groups: { title: string; icon: string; actions: string[]; rows: AppRow[] }[];
    labels: Record<string, string>;
    absenceMotifs: string[];
};
