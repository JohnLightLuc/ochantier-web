type Status = { value: string; label: string; tone: string };

type Party = { type: string; type_label: string; id: string; name: string };

export type ClaimRow = {
    id: string;
    reference: string;
    category: string;
    subject: string;
    claimant: Party;
    site: { id: string; title: string } | null;
    priority: Status;
    status: Status;
    owner: string | null;
    dueAt: string | null;
    overdue: boolean;
    createdAt: string | null;
};

export type ClaimDetail = ClaimRow & {
    description: string;
    otherParty: Party | null;
    resolution: string | null;
    dispute: { id: string; reference: string } | null;
    history: { id: string; label: string; reason: string | null; by: string | null; date: string | null }[];
    actions: { take: boolean; priority: boolean; resolve: boolean; reject: boolean; escalate: boolean; close: boolean };
};
