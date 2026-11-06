import { RelationTable, stack } from '@/components/promoters/RelationTabs';
import Button from '@/components/ui/Button';
import StatCard from '@/components/ui/StatCard';
import StatusBadge from '@/components/ui/StatusBadge';
import { Block, KeyValues } from '@/components/workers/blocks';
import { fcfa, formatDate } from '@/lib/format';
import { Head, Link, router } from '@inertiajs/react';

type Schedule = {
    id: string;
    reference: string;
    state: { value: string; label: string; tone: string };
    type: string;
    sequence: number;
    site: { id: string; label: string };
    promoter: { name: string };
    trade: string | null;
    period: { start: string; end: string } | null;
    dueDate: string | null;
    isFlexible: boolean;
    due: number;
    paid: number;
    remaining: number;
    workersAmount: number;
    commissionAmount: number;
    depositDeduction: number;
    adjustmentAmount: number;
    lastReminderAt: string | null;
    days: { validated: number; pending: number } | null;
    payments: { id: string; reference: string; operator: string | null; amount: number; date: string | null }[];
    installments: { label: string; amount: number; dueDate: string | null; status: string; isOpen: boolean }[];
    canRemind: boolean;
};

/** Fiche d’une échéance : décomposition du montant, paiements reçus et journées de la période. */
export default function PaymentSchedule({ schedule: s, can }: { schedule: Schedule; can: { update: boolean; export: boolean } }) {
    const remind = () => router.post(`/paiements/echeances/${s.id}/relance`, {}, { preserveScroll: true });

    return (
        <>
            <Head title={s.reference} />

            {can.update && s.canRemind && (
                <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 'var(--space-2)', marginTop: -6 }}>
                    <Button label="Relancer le promoteur" icon="notifications_active" variant="secondary" onClick={remind} />
                </div>
            )}

            <div data-component="StatGrid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(230px,1fr))', gap: 'var(--space-4)' }}>
                <StatCard label="Montant dû" value={fcfa(s.due)} icon="receipt_long" tone="b" />
                <StatCard label="Déjà payé" value={fcfa(s.paid)} icon="verified" tone="g" />
                <StatCard label="Reste à payer" value={fcfa(s.remaining)} icon="pending_actions" tone="a" />
            </div>

            <div style={stack}>
                <Block icon="event_note" title={s.reference}>
                    <KeyValues
                        items={[
                            ['État', <StatusBadge label={s.state.label} tone={s.state.tone as never} />],
                            ['Type', `${s.type} · n° ${s.sequence}`],
                            ['Promoteur', s.promoter.name],
                            ['Chantier', <Link href={`/chantiers/${s.site.id}`}>{s.site.label}</Link>],
                            ['Métier', s.trade],
                            ['Période', s.period ? `${formatDate(s.period.start)} au ${formatDate(s.period.end)}` : null],
                            ['Date d’échéance', formatDate(s.dueDate)],
                            ['Paiement flexible', s.isFlexible ? 'Oui' : null],
                            ['Dernière relance', s.lastReminderAt ? formatDate(s.lastReminderAt) : null],
                        ]}
                    />
                </Block>

                <Block icon="calculate" title="Décomposition">
                    <KeyValues
                        items={[
                            ['Main-d’œuvre', fcfa(s.workersAmount)],
                            ['Commission promoteur', fcfa(s.commissionAmount)],
                            ['Acompte déduit', fcfa(s.depositDeduction)],
                            ['Ajustement', fcfa(s.adjustmentAmount)],
                        ]}
                    />
                </Block>

                {s.days && (
                    <Block icon="schedule" title="Journées de la période">
                        <KeyValues
                            items={[
                                ['Journées validées', String(s.days.validated)],
                                ['En attente de validation', String(s.days.pending)],
                                ['À savoir', 'Ces journées ne changent pas le montant de l’échéance.'],
                            ]}
                        />
                    </Block>
                )}

                {s.installments.length > 0 && (
                    <Block icon="splitscreen" title="Versements du paiement flexible">
                        <RelationTable columns={['Versement', 'Échéance', 'Montant', 'Statut']} empty={{ icon: 'splitscreen', title: 'Aucun versement', text: '' }} rows={s.installments.map((i) => ({ key: i.label, cells: [i.label, formatDate(i.dueDate), fcfa(i.amount), i.status] }))} />
                    </Block>
                )}

                <Block icon="payments" title="Paiements reçus">
                    <RelationTable
                        columns={['Référence', 'Date', 'Opérateur', 'Montant']}
                        empty={{ icon: 'payments', title: 'Aucun paiement', text: 'Aucun paiement reçu sur cette échéance.' }}
                        rows={s.payments.map((p) => ({ key: p.id, cells: [p.reference, formatDate(p.date), p.operator ?? '—', fcfa(p.amount)], actions: [{ icon: 'visibility', label: 'Voir le paiement', onClick: () => router.visit(`/paiements/${p.id}`) }] }))}
                    />
                </Block>
            </div>
        </>
    );
}
