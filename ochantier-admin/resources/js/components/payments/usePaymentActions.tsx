import Dialog from '@/components/overlays/Dialog';
import FormDialog from '@/components/overlays/FormDialog';
import StatusBadge from '@/components/ui/StatusBadge';
import { KeyValues } from '@/components/workers/blocks';
import { useAdminUi } from '@/contexts/AdminUiContext';
import { formatDate, formatNumber } from '@/lib/format';
import type { PaymentItem, PaymentOptions, PaymentRow, Refund, Rule } from '@/types/payments';
import { router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';

export const fcfa = (n: number) => `${formatNumber(n)} FCFA`;

const ITEM_LABELS: Record<PaymentItem['kind'], [string, keyof PaymentOptions]> = {
    bonus: ['Ajouter un bonus', 'bonusTypes'],
    indemnity: ['Ajouter une indemnité', 'indemnityTypes'],
    deduction: ['Ajouter une retenue', 'deductionTypes'],
};

/**
 * Fenêtres d’action du module Paiements : reversement (individuel ou en lot), relance, remboursement et décision,
 * bonus, indemnités et retenues, rapprochement manuel, signalement et règle de commission. Rendre `dialog` dans la page.
 */
export function usePaymentActions(options: PaymentOptions) {
    const ui = useAdminUi();
    const [dialog, setDialog] = useState<ReactNode>(null);
    const close = () => setDialog(null);
    const base = (ref: string) => `/paiements/operations/${ref}`;
    const post = (url: string, data: Record<string, unknown> = {}, onSuccess?: () => void) => router.post(url, data as Record<string, string>, { preserveScroll: true, onSuccess });
    const reason = (title: string, body: string, ok: string, url: string, tone: 'danger' | 'warning' | 'success', icon: string) =>
        setDialog(<FormDialog title={title} body={body} icon={icon} tone={tone} okLabel={ok} url={url} onClose={close} fields={[{ name: 'reason', label: 'Commentaire', type: 'textarea', required: true }]} />);

    return {
        dialog,
        close,

        /** Reversement à l’ouvrier : contrôles affichés quand ils sont connus (fiche), sinon vérifiés par le serveur. */
        payout: (row: Pick<PaymentRow, 'reference' | 'net' | 'worker' | 'payoutStatus'>, checks?: [string, boolean][], meta?: [string, string][]) => {
            const blocked = checks?.filter((c) => !c[1]) ?? [];
            setDialog(
                <Dialog
                    title={`${fcfa(row.net)} à ${row.worker?.name ?? 'l’ouvrier'}`}
                    body={`Reversement ouvrier · ${row.reference}${blocked.length ? ' · reversement bloqué.' : ' · confirmation renforcée.'}`}
                    icon="send"
                    tone={blocked.length ? 'danger' : 'warning'}
                    okLabel={blocked.length ? 'Fermer' : 'Reverser'}
                    onClose={close}
                    onSubmit={() => (blocked.length ? close() : post(`${base(row.reference)}/reversement`, {}, close))}
                >
                    {meta && <KeyValues items={meta} />}
                    {checks && (
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-1_5)', marginTop: 'var(--space-3)' }}>
                            {checks.map(([label, ok]) => (
                                <div key={label} style={{ display: 'flex', justifyContent: 'space-between', gap: 'var(--space-3)', fontSize: 'var(--fs-md)' }}>
                                    <span>{label}</span>
                                    <StatusBadge label={ok ? 'Conforme' : 'Bloquant'} tone={ok ? 'g' : 'r'} />
                                </div>
                            ))}
                        </div>
                    )}
                </Dialog>,
            );
        },

        batch: (rows: PaymentRow[], onDone?: () => void) => {
            const list = rows.filter((r) => !r.isDeposit);
            if (!list.length) return ui.toast('Sélectionnez des opérations à reverser.', { tone: 'info' });
            ui.confirm({
                tone: 'warning',
                icon: 'stacks',
                title: `Créer et exécuter un lot de ${list.length} opération(s) ?`,
                body: `Net à reverser : ${fcfa(list.reduce((a, r) => a + r.net, 0))}. Les opérations non conformes aux contrôles sont bloquées ; chaque opération conserve sa référence et son résultat.`,
                ok: 'Exécuter',
                onConfirm: () => post('/paiements/lots', { refs: list.map((r) => r.reference) }, onDone),
            });
        },

        retry: (ref: string) => post(`${base(ref)}/relance`),

        refund: (ref: string, received: number) =>
            setDialog(
                <FormDialog
                    title={`Demander un remboursement · ${ref}`}
                    body={`Montant reçu : ${fcfa(received)}. L’impact sur la commission suit la règle de Paramètres.`}
                    icon="currency_exchange"
                    tone="warning"
                    okLabel="Soumettre"
                    url={`${base(ref)}/remboursements`}
                    onClose={close}
                    fields={[
                        { name: 'type', label: 'Type', type: 'select', required: true, options: options.refundTypes },
                        { name: 'amount', label: 'Montant (FCFA)', type: 'number', hint: 'Ignoré pour un remboursement total' },
                        { name: 'motif', label: 'Motif', required: true },
                        { name: 'responsibility', label: 'Responsabilité', type: 'select', required: true, options: options.responsibilities },
                        { name: 'proof', label: 'Justificatif (référence)' },
                    ]}
                />,
            ),

        decideRefund: (ref: string, r: Refund, approve: boolean) =>
            reason(
                `${approve ? 'Approuver' : 'Rejeter'} le remboursement`,
                `${r.type} · ${fcfa(r.amount)} · commission initiale ${fcfa(r.commission)} · part remboursée ${fcfa(r.impact)} · nouvelle commission nette ${fcfa(Math.max(0, r.commissionNet - r.impact))} (${r.rule}).${approve && r.timing === 'Après reversement' ? ' Le reversement ouvrier est déjà effectué : récupération à traiter hors Paiements.' : ''}`,
                approve ? 'Approuver et exécuter' : 'Rejeter',
                `${base(ref)}/remboursements/${r.id}/${approve ? 'approbation' : 'rejet'}`,
                approve ? 'warning' : 'danger',
                approve ? 'currency_exchange' : 'block',
            ),

        addItem: (ref: string, kind: PaymentItem['kind']) =>
            setDialog(
                <FormDialog
                    title={`${ITEM_LABELS[kind][0]} · ${ref}`}
                    body={kind === 'deduction' ? 'Une retenue n’est jamais appliquée sans approbation humaine.' : 'Montant séparé du montant brut ; non soumis à la commission.'}
                    icon={kind === 'deduction' ? 'remove_circle' : 'add_circle'}
                    tone={kind === 'deduction' ? 'warning' : undefined}
                    okLabel="Enregistrer"
                    url={`${base(ref)}/elements/${kind}`}
                    onClose={close}
                    fields={[
                        { name: 'type', label: 'Type', type: 'select', required: true, options: options[ITEM_LABELS[kind][1]] },
                        { name: 'amount', label: 'Montant (FCFA)', type: 'number', required: true },
                        { name: 'motif', label: 'Motif', required: true, full: kind !== 'deduction' },
                        ...(kind === 'deduction'
                            ? [
                                  { name: 'base', label: 'Base de calcul', required: true },
                                  { name: 'proof', label: 'Justificatif (référence)', required: true },
                              ]
                            : []),
                    ]}
                />,
            ),

        decideItem: (ref: string, item: PaymentItem, approve: boolean) =>
            reason(`${approve ? 'Approuver' : 'Rejeter'} la retenue`, `${item.type} · ${fcfa(item.amount)}`, approve ? 'Approuver' : 'Rejeter', `${base(ref)}/elements/${item.id}/${approve ? 'approbation' : 'rejet'}`, approve ? 'success' : 'danger', approve ? 'check' : 'block'),

        reconcile: (ref: string, body: string) =>
            setDialog(
                <FormDialog
                    title={`Rapprochement manuel · ${ref}`}
                    body={body}
                    icon="rule"
                    tone="warning"
                    okLabel="Rapprocher"
                    url={`${base(ref)}/rapprochement`}
                    onClose={close}
                    fields={[
                        { name: 'motif', label: 'Motif', type: 'select', required: true, full: true, options: options.reconcileMotifs },
                        { name: 'comment', label: 'Commentaire', type: 'textarea', required: true },
                        { name: 'proof', label: 'Justificatif (référence)', required: true },
                    ]}
                />,
            ),

        reportAnomaly: (ref: string) =>
            setDialog(
                <FormDialog
                    title={`Signaler une anomalie · ${ref}`}
                    icon="report"
                    tone="warning"
                    okLabel="Signaler"
                    url={`${base(ref)}/anomalies`}
                    onClose={close}
                    fields={[
                        { name: 'type', label: 'Type', type: 'select', required: true, options: options.anomalyTypes },
                        { name: 'level', label: 'Niveau', type: 'select', required: true, options: options.levels },
                        { name: 'detail', label: 'Description', type: 'textarea', required: true },
                    ]}
                />,
            ),

        rule: (r: Rule) =>
            setDialog(
                <Dialog title={r.name} body={r.description} icon="policy" okLabel="Modifier dans Paramètres" onClose={close} onSubmit={() => router.visit('/parametres')}>
                    <KeyValues
                        items={[
                            ['Version', `${r.version} · ${r.status}`],
                            ['Type', r.type],
                            ['Base de calcul', r.base],
                            ['Taux promoteur (Y)', `${r.y} %`],
                            ['Taux ouvrier (Z)', `${r.z} %`],
                            ['Minimum', r.min ? fcfa(r.min) : 'Aucun'],
                            ['Maximum', r.max ? fcfa(r.max) : 'Aucun'],
                            ['Type de prestation', r.scope],
                            ['Métier', r.trade],
                            ['Promoteurs concernés', r.target],
                            ['Prise d’effet', formatDate(r.from)],
                            ['Fin', r.to ? formatDate(r.to) : 'Indéterminée'],
                            ['Acomptes', 'Commission intégralement supportée par le promoteur (Y + Z)'],
                        ]}
                    />
                </Dialog>,
            ),
    };
}

export type PaymentActions = ReturnType<typeof usePaymentActions>;
