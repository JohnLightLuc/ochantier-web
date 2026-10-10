/** Tons de couleur : alias courts de la maquette (g, a, o…) ou noms complets des tokens. */
export type ToneAlias = 'g' | 'a' | 'o' | 'r' | 'b' | 'p' | 'n' | 'R';
export type Tone = 'success' | 'warning' | 'orange' | 'danger' | 'danger-solid' | 'info' | 'purple' | 'neutral' | 'primary';
export type AnyTone = Tone | ToneAlias;

export type AuthUser = {
    name: string;
    email: string;
    initials: string;
    role: string;
};

export type NavModule = {
    key: string;
    label: string;
    icon: string;
    path: string;
    title: string;
    subtitle: string;
    badge: number | null;
};

export type AdminNotification = {
    id: string;
    icon: string;
    tone: Tone;
    title: string;
    text: string;
    priority: 'Urgent' | 'Élevé' | 'Moyen';
    when: string;
    read: boolean;
    href: string;
};

export type Badge = { label: string; tone: AnyTone; icon?: string };

export type PageHeader = {
    title: string;
    subtitle: string;
    crumbs: { label: string; href: string | null }[];
};

export type SharedProps = {
    auth: { user: AuthUser | null };
    navigation: NavModule[];
    notifications: AdminNotification[];
    /** En-tête propre à la page ; à défaut, celui du module courant. */
    header?: PageHeader;
};

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: SharedProps;
        flashDataType: { toast?: string };
    }
}
