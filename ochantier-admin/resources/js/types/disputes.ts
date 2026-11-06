type Status = { value: string; label: string; tone: string };

export type DisputeParty = { type: string; type_label: string; id: string; name: string; role: { value: string; label: string } };

export type DisputeRow = {
    id: string;
    reference: string;
    category: string;
    parties: DisputeParty[];
    site: { id: string; title: string } | null;
    mission: { id: string; reference: string } | null;
    urgency: Status;
    status: Status;
    owner: string | null;
    appealOf: { id: string; reference: string } | null;
    createdAt: string | null;
};

export type DisputeDetail = DisputeRow & {
    description: string;
    claim: { id: string; reference: string } | null;
    decision: string | null;
    decidedBy: string | null;
    decidedAt: string | null;
    appealDeadline: string | null;
    outcome: 'confirmee' | 'annulee' | null;
    financial: { refundAmount: number | null; note: string | null; refundPending: boolean; refund: { id: string; reference: string; status: Status } | null };
    appeal: { id: string; reference: string; status: { value: string; label: string } } | null;
    messages: { id: string; body: string; internal: boolean; author: string; from_team: boolean; date: string | null }[];
    proofs: { id: string; label: string | null; url: string | null; from_team: boolean; date: string | null }[];
    history: { id: string; label: string; reason: string | null; by: string | null; date: string | null }[];
    actions: { take: boolean; urgency: boolean; linkMission: boolean; message: boolean; proof: boolean; decide: boolean; decideAppeal: boolean; appeal: boolean; close: boolean; requestRefund: boolean };
};

export type DisputeCan = { update: boolean; decide: boolean; approve: boolean };
