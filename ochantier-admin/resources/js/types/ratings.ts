type Status = { value: string; label: string; tone: string };

export type RatingRow = {
    id: string;
    scope: string;
    target: { kind: 'worker' | 'team'; id: string | null; name: string };
    score: number;
    tag: string | null;
    comment: string | null;
    site: { id: string; title: string } | null;
    promoter: { id: string | null; name: string };
    author: string | null;
    status: Status;
    moderation: (Status & { reason: string | null; by: string | null }) | null;
    suspected: boolean;
    verification: string;
    publishedAt: string | null;
    reportedAt: string | null;
    version: number;
};

export type RatingDetail = RatingRow & {
    versions: { version: number; score: number; satisfaction_tag: string | null; comment: string | null; date: string | null }[];
    history: { id: string; label: string; reason: string | null; by: string | null; date: string | null }[];
    actions: { report: boolean; keep: boolean; hide: boolean; restore: boolean };
};
