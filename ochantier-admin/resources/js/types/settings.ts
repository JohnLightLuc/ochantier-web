import type { BlockButton, BlockTable } from '@/types/blocks';

export type SettingValue = string | number | boolean | number[] | Trade[];

export type Trade = { name: string; active: boolean };

/** Champ de réglage : nombre (bornes, unité), interrupteur, liste de choix, texte, seuils ou métiers. */
export type SettingField = {
    code: string;
    label: string;
    desc: string;
    type: 'num' | 'bool' | 'select' | 'text' | 'list' | 'trades';
    unit?: string;
    min?: number;
    max?: number;
    dec?: boolean;
    labels?: [string, string];
    options?: string[];
    /** Interrupteur associé (règles financières). */
    switch?: string;
    icon?: string;
    tone?: string;
    usage?: Record<string, { workers: number; validated: number }>;
};

export type SettingGroup = { icon: string; title: string; kind?: 'rules' | 'thresholds' | 'modes'; sum?: number; fields: SettingField[] };

export type SettingsPageProps = {
    section: string;
    nav: { key: string; label: string; icon: string; href: string; active: boolean }[];
    definition: { title: string; desc: string; icon: string; banner: string; groups: SettingGroup[] };
    values: Record<string, SettingValue>;
    defaults: Record<string, SettingValue>;
    lastChange: { author: string; occurred_at: string } | null;
    profiles: { label: string; notes: number[] }[];
    operators: string[];
    tables: BlockTable[];
    toolbar: BlockButton[];
    query: Record<string, string>;
    rubriques: string[];
};
