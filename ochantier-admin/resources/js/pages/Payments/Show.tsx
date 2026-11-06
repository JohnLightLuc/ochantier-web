import { RelationTable, stack } from '@/components/promoters/RelationTabs';
import StatCard from '@/components/ui/StatCard';
import StatusBadge from '@/components/ui/StatusBadge';
import { Block, KeyValues } from '@/components/workers/blocks';
import { fcfa } from '@/lib/format';
import { Head, Link, router } from '@inertiajs/react';

type Transaction = {
    id: string;
    reference: string;
    status: { value: string; label: string; tone: string };
    purpose: string;
    label: string | null;
    site: { id: string; label: string } | null;
    promoter: { name: string };
    operator: string | null;
    amount: number;
    fees: number;
    total: number;
    date: string | null;
    completedAt: string | null;
    providerReference: string | null;
    phone: string | null;
    lines: { label: string; amount: number }[];
    failure: { code: string | null; message: string } | null;
    invoice: { reference: string; label: string } | null;
    allocations: { amount: number; schedule: { id: string; reference: string; type: { label: string } } }[];
    receipts: { number: string; issued_at: string | null; url: string | null }[];
    history: { label: string; at: string | null }[];
};

/** Fiche d’un encaissement : montant, opérateur, échéances réglées, reçus et historique. */
export default function PaymentShow({ transaction: t }: { transaction: Transaction }) {
    return (
        <>
            <Head title={t.reference} />

            <div data-component="StatGrid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(230px,1fr))', gap: 'var(--space-4)' }}>
                <StatCard label="Montant" value={fcfa(t.amount)} icon="payments" tone="g" />
                <StatCard label="Frais de l’opérateur" value={fcfa(t.fees)} icon="account_balance" tone="a" />
                <StatCard label="Total prélevé" value={fcfa(t.total)} icon="receipt_long" tone="b" />
            </div>

            <div style={stack}>
                <Block icon="receipt_long" title={t.reference}>
                    <KeyValues
                        items={[
                            ['Statut', <StatusBadge label={t.status.label} tone={t.status.tone as never} />],
                            ['Nature', t.purpose],
                            ['Libellé', t.label],
                            ['Promoteur', t.promoter.name],
                            ['Chantier', t.site ? <Link href={`/chantiers/${t.site.id}`}>{t.site.label}</Link> : null],
                            ['Opérateur', t.operator],
                            ['Numéro', t.phone],
                            ['Référence opérateur', t.providerReference],
                            ['Initié le', t.date],
                            ['Terminé le', t.completedAt],
                            ['Facture d’abonnement', t.invoice ? `${t.invoice.reference} · ${t.invoice.label}` : null],
                            ['Motif de l’échec', t.failure?.message],
                        ]}
                    />
                </Block>

                {t.lines.length > 0 && (
                    <Block icon="list_alt" title="Détail du montant">
                        <KeyValues items={t.lines.map((l): [string, string] => [l.label, fcfa(l.amount)])} />
                    </Block>
                )}

                <Block icon="event_note" title="Échéances réglées">
                    <RelationTable
                        columns={['Référence', 'Type', 'Montant']}
                        empty={{ icon: 'event_note', title: 'Aucune échéance', text: 'Ce paiement n’a réglé aucune échéance de mission.' }}
                        rows={t.allocations.map((a) => ({ key: a.schedule.id, cells: [a.schedule.reference, a.schedule.type.label, fcfa(a.amount)], actions: [{ icon: 'visibility', label: 'Voir l’échéance', onClick: () => router.visit(`/paiements/echeances/${a.schedule.id}`) }] }))}
                    />
                </Block>

                <Block icon="description" title="Reçus">
                    <RelationTable
                        columns={['Numéro', 'Émis le', 'Document']}
                        empty={{ icon: 'description', title: 'Aucun reçu', text: 'Un reçu est émis à chaque paiement réussi.' }}
                        rows={t.receipts.map((r) => ({
                            key: r.number,
                            cells: [
                                r.number,
                                r.issued_at ?? '—',
                                r.url ? (
                                    <a href={r.url} target="_blank" rel="noreferrer">
                                        Ouvrir
                                    </a>
                                ) : (
                                    '—'
                                ),
                            ],
                        }))}
                    />
                </Block>

                <Block icon="history" title="Historique">
                    <KeyValues items={t.history.map((h, i): [string, string | null] => [`${i + 1}. ${h.label}`, h.at])} />
                </Block>
            </div>
        </>
    );
}
