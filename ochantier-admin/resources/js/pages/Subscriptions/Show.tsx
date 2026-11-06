import FormDialog from '@/components/overlays/FormDialog';
import { RelationTable, stack } from '@/components/promoters/RelationTabs';
import Button from '@/components/ui/Button';
import Icon from '@/components/ui/Icon';
import StatusBadge from '@/components/ui/StatusBadge';
import { Block, KeyValues } from '@/components/workers/blocks';
import { useAdminUi } from '@/contexts/AdminUiContext';
import { fcfa, formatDate } from '@/lib/format';
import type { Plan, SubscriptionCan, SubscriptionDetail } from '@/types/subscriptions';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

/** Fiche d’un abonnement : formule, utilisateurs, factures, journal et décisions du Back Office. */
export default function SubscriptionShow({ subscription: s, plans, can }: { subscription: SubscriptionDetail; plans: Plan[]; can: SubscriptionCan }) {
    const ui = useAdminUi();
    const [dialog, setDialog] = useState<'plan' | 'suspend' | 'terminate' | null>(null);
    const close = () => setDialog(null);
    const base = `/abonnements/${s.id}`;
    const post = (path: string) => router.post(`${base}/${path}`, {}, { preserveScroll: true });
    const remove = (path: string) => router.delete(`${base}/${path}`, { preserveScroll: true });
    const a = s.actions;
    const scheduled = !s.autoRenew && s.status.value === 'active';
    const immediate = s.status.value === 'en_retard' || s.status.value === 'suspendue';

    return (
        <>
            <Head title={`Abonnement · ${s.promoter.name}`} />

            <div style={{ display: 'flex', justifyContent: 'flex-end', flexWrap: 'wrap', gap: 'var(--space-2)', marginTop: -6 }}>
                <Button label="Fiche promoteur" icon="open_in_new" variant="secondary" onClick={() => router.visit(`/promoteurs/${s.promoter.id}`)} />
                {can.update && a.terminate && <Button label="Résilier" icon="cancel" variant="danger-soft" onClick={() => setDialog('terminate')} />}
                {can.update && a.suspend && <Button label="Suspendre" icon="pause_circle" variant="secondary" onClick={() => setDialog('suspend')} />}
                {can.update && a.changePlan && plans.length > 0 && <Button label="Changer de formule" icon="swap_vert" variant="secondary" onClick={() => setDialog('plan')} />}
                {can.update && a.resume && <Button label="Annuler la résiliation" icon="undo" variant="secondary" onClick={() => remove('resiliation')} />}
                {can.update && a.remind && <Button label="Relancer le promoteur" icon="send" onClick={() => post('relance')} />}
                {can.update && a.reactivate && <Button label="Réactiver" icon="play_circle" onClick={() => ui.confirm({ tone: 'success', icon: 'play_circle', title: 'Réactiver l’abonnement ?', body: 'Les collaborateurs retrouvent leur accès. La durée de la suspension n’est pas ajoutée à la période.', ok: 'Réactiver', onConfirm: () => post('reactivation') })} />}
            </div>

            {s.pending && (
                <div role="status" style={{ display: 'flex', gap: 'var(--space-3)', alignItems: 'center', padding: 'var(--space-3) var(--space-4)', borderRadius: 'var(--radius-lg)', background: 'var(--c-info-bg)', color: 'var(--c-info-fg)' }}>
                    <Icon name="schedule" />
                    <span style={{ flex: 1 }}>
                        Passage à la formule <b>{s.pending.name}</b> programmé le {formatDate(s.pending.at)}. Il s’applique une fois la facture réglée.
                    </span>
                    {can.update && a.cancelPlanChange && <Button label="Annuler le changement" size="sm" variant="secondary" onClick={() => remove('formule')} />}
                </div>
            )}

            <div style={stack}>
                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,420px),1fr))', gap: 'var(--space-4)' }}>
                    <Block icon="workspace_premium" title={s.reference}>
                        <KeyValues
                            items={[
                                ['Statut', <StatusBadge label={s.status.label} tone={s.status.tone as never} />],
                                ['Promoteur', <Link href={`/promoteurs/${s.promoter.id}`}>{s.promoter.name}</Link>],
                                ['Responsable', s.promoter.owner],
                                ['Téléphone', s.phone],
                                ['E-mail', s.email],
                                ['Formule', `${s.plan.name} · ${fcfa(s.plan.price)} / mois`],
                                ['Formule précédente', s.previousPlan],
                                ['Utilisateurs', `${s.seats} / ${s.plan.maxUsers}`],
                                ['Abonné depuis', s.since ? formatDate(s.since) : null],
                                ['Période en cours', s.periodStart && s.periodEnd ? `${formatDate(s.periodStart)} au ${formatDate(s.periodEnd)}` : null],
                                ['Prochaine échéance', s.nextDue ? formatDate(s.nextDue) : null],
                                ['Dernier moyen de paiement', s.operator],
                                ['Résiliation', s.cancelledAt ? (scheduled && s.periodEnd ? `Programmée · fin le ${formatDate(s.periodEnd)}` : s.cancelledAt) : null],
                                ['Motif de résiliation', s.cancellationReason],
                            ]}
                        />
                    </Block>

                    <Block icon="group" title="Utilisateurs du compte" count={`${s.users.length}`}>
                        <RelationTable columns={['Nom', 'Rôle', 'Statut']} empty={{ icon: 'group', title: 'Aucun utilisateur', text: '' }} rows={s.users.map((u) => ({ key: u.id, cells: [u.name, u.role, u.status] }))} />
                    </Block>
                </div>

                <Block icon="receipt_long" title="Factures" count={`${s.invoices.length}`}>
                    <RelationTable
                        columns={['Référence', 'Libellé', 'Date', 'Montant', 'Statut', 'Reçu']}
                        empty={{ icon: 'receipt_long', title: 'Aucune facture', text: 'Les factures apparaissent dès le premier paiement.' }}
                        rows={s.invoices.map((i) => ({
                            key: i.id,
                            cells: [
                                i.reference,
                                i.label,
                                i.date,
                                fcfa(i.amount),
                                <StatusBadge label={i.status.label} tone={i.status.tone as never} />,
                                i.receiptUrl ? (
                                    <a href={i.receiptUrl} target="_blank" rel="noreferrer">
                                        Ouvrir
                                    </a>
                                ) : (
                                    '—'
                                ),
                            ],
                        }))}
                    />
                </Block>

                <Block icon="history" title="Journal">
                    <RelationTable columns={['Date', 'Événement', 'Statut', 'Par']} empty={{ icon: 'history', title: 'Aucun événement', text: '' }} rows={s.history.map((h) => ({ key: h.id, cells: [h.date ?? '—', h.reason ?? '—', h.status ?? '—', h.actor] }))} />
                </Block>
            </div>

            {dialog === 'plan' && (
                <FormDialog
                    title="Changer de formule"
                    body={`Le changement s’applique au renouvellement du ${s.nextDue ? formatDate(s.nextDue) : '—'} : le promoteur reçoit la facture de la nouvelle formule et la règle dans l’application.`}
                    icon="swap_vert"
                    okLabel="Programmer"
                    url={`${base}/formule`}
                    onClose={close}
                    fields={[{ name: 'plan_code', label: 'Nouvelle formule', type: 'select', required: true, full: true, options: plans.map((p) => ({ value: p.code, label: `${p.name} · ${fcfa(p.price)} · ${p.maxUsers} utilisateurs` })) }]}
                />
            )}
            {dialog === 'suspend' && <FormDialog title="Suspendre l’abonnement ?" body="Les collaborateurs perdent l’accès tout de suite. Le propriétaire garde son compte avec les droits Solo et ne peut plus payer d’abonnement." icon="pause_circle" tone="warning" okLabel="Suspendre" url={`${base}/suspension`} onClose={close} fields={[{ name: 'reason', label: 'Motif', type: 'textarea', required: true }]} />}
            {dialog === 'terminate' && (
                <FormDialog
                    title="Résilier l’abonnement ?"
                    body={immediate ? 'L’abonnement prend fin tout de suite et la facture ouverte est annulée. Seul le propriétaire garde l’accès.' : `La formule reste active jusqu’au ${s.periodEnd ? formatDate(s.periodEnd) : '—'}. Le compte revient ensuite à Solo : seul le propriétaire garde l’accès.`}
                    icon="cancel"
                    tone="danger"
                    okLabel="Résilier"
                    url={`${base}/resiliation`}
                    onClose={close}
                    fields={[{ name: 'reason', label: 'Motif', type: 'textarea', required: true }]}
                />
            )}
        </>
    );
}
