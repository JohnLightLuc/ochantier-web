import { TextCell } from '@/components/list/Table';
import DetailHeader from '@/components/matching/DetailHeader';
import { PaymentNav, SegmentBar } from '@/components/payments/blocks';
import { RefundTable } from '@/components/payments/RefundTable';
import { fcfa, usePaymentActions } from '@/components/payments/usePaymentActions';
import Icon from '@/components/ui/Icon';
import type { MenuItem } from '@/components/ui/Menu';
import StatusBadge from '@/components/ui/StatusBadge';
import { AlertList, Block, DataTable, KeyValues, TileGrid, Toolbar } from '@/components/workers/blocks';
import { HistoryList } from '@/components/workers/tabs';
import { formatDate } from '@/lib/format';
import type { AnyTone } from '@/types';
import type { PaymentItem, PaymentShowProps } from '@/types/payments';
import { Head, router } from '@inertiajs/react';

const LEVEL_DANGER = ['Critique', 'Élevée'];

const DOCS: [string, string][] = [
    ['recu-promoteur', 'Reçu de paiement promoteur'],
    ['recu-reversement', 'Reçu de reversement ouvrier'],
    ['facture', 'Facture de service'],
    ['releve-commission', 'Relevé de commission'],
    ['remboursement', 'Justificatif de remboursement'],
];

type StepState = 'done' | 'cur' | 'fail' | 'todo' | 'na';

/** Fiche d’une opération : synthèse et parcours, montants et commission, paiement, reversement, remboursements, pointages, documents, historique. */
export default function PaymentShow({ sections, payment: p, calc: c, anomalies, checklist, payoutChecks, items, refunds, attendances, activities, rule, fees, tax, tab, tabs, options }: PaymentShowProps) {
    const actions = usePaymentActions(options);
    const base = `/paiements/operations/${p.reference}`;
    const go = (path: string) => () => router.visit(path);
    const doc = (key: string) => () => (window.location.href = `${base}/documents/${key}`);
    const blocking = anomalies.filter((a) => LEVEL_DANGER.includes(a.level));
    const payoutMeta: [string, string][] = [
        ['Moyen', p.workerMethod ?? '—'],
        ['Compte', p.workerAccount ?? '—'],
        ['Montant brut', fcfa(c.gross)],
        ['Bonus + indemnités', fcfa(c.bonus + c.indemnity)],
        ['Retenues', fcfa(c.deduction)],
        ['Commission supportée (Z)', fcfa(c.comO)],
        ['Net à reverser', fcfa(c.net)],
    ];
    const payout = () => actions.payout(p, payoutChecks, payoutMeta);
    const next = c.paid && !p.isDeposit && ['À préparer', 'Validé', 'À reprendre'].includes(p.payoutStatus.label) ? { label: 'Reverser à l’ouvrier', icon: 'send', onClick: payout } : p.canRetry ? { label: 'Relancer le reversement', icon: 'refresh', onClick: () => actions.retry(p.reference) } : null;
    const validated = ['Validé', 'Corrigé'].includes(p.calcStatus) && !!p.validatedAt;

    const menu: MenuItem[] = [
        { icon: 'apartment', label: 'Voir le promoteur', onClick: go(p.promoterSlug ? `/promoteurs/${p.promoterSlug}` : `/promoteurs?q=${encodeURIComponent(p.promoter)}`) },
        { icon: 'construction', label: 'Voir le chantier', onClick: go(`/chantiers/${p.siteSlug}`) },
        ...(p.worker
            ? [
                  { icon: 'person', label: 'Voir l’ouvrier', onClick: go(`/ouvriers/${p.worker.slug}`) },
                  { icon: 'assignment_ind', label: 'Voir l’affectation', onClick: go(`/ouvriers/${p.worker.slug}/affectations`) },
                  { icon: 'schedule', label: 'Voir les pointages', onClick: go(`/pointages/ouvriers/${p.worker.slug}`) },
              ]
            : []),
        { icon: 'currency_exchange', label: 'Rembourser', onClick: () => actions.refund(p.reference, c.received) },
        ...(p.canRetry ? [{ icon: 'refresh', label: 'Relancer le reversement', onClick: () => actions.retry(p.reference) }] : []),
        { icon: 'download', label: 'Exporter le justificatif', onClick: doc('justificatif') },
        { icon: 'report', label: 'Signaler une anomalie', onClick: () => actions.reportAnomaly(p.reference) },
    ];

    const step = (label: string, state: StepState, sub: string, icon: string, target: string) => ({ label, state, sub, icon, target });
    const steps = [
        step('Calcul', 'done', `Généré le ${formatDate(p.periodEnd)}`, 'calculate', 'calcul'),
        step('Validation', validated ? 'done' : p.calcStatus === 'Rejeté' ? 'fail' : 'cur', validated ? `${p.validatedBy} · ${formatDate(p.validatedAt)}` : p.calcStatus, 'fact_check', 'calcul'),
        step('Paiement promoteur', c.paid ? 'done' : p.payStatus.label === 'Échoué' ? 'fail' : validated ? 'cur' : 'todo', c.paid ? `${fcfa(c.received)} · ${formatDate(p.paidAt)}` : p.payStatus.label, 'payments', 'paiement'),
        step('Commission encaissée', c.comCollected ? 'done' : 'todo', c.comCollected ? `${fcfa(c.comNet)} nets` : c.comStatus, 'percent', 'calcul'),
        step('Reversement ouvrier', p.isDeposit ? 'na' : p.payoutStatus.label === 'Réussi' ? 'done' : p.payoutStatus.label === 'Échoué' ? 'fail' : c.paid ? 'cur' : 'todo', p.isDeposit ? 'Sans objet (acompte)' : p.payoutStatus.label === 'Réussi' ? `${fcfa(c.net)} · ${formatDate(p.payoutAt)}` : p.payoutStatus.label, 'send', p.isDeposit ? '' : 'reversement'),
        step('Rapprochement', /Rapproché/.test(p.reconciliation.label) ? 'done' : p.reconciliation.label === 'Écart détecté' ? 'fail' : c.paid ? 'cur' : 'todo', p.reconciliation.label, 'rule', 'paiement'),
    ];
    const STEP_STYLE: Record<StepState, [string, string, string]> = {
        done: ['var(--c-primary)', 'var(--c-on-dark)', 'check'],
        cur: ['var(--c-surface)', 'var(--c-primary)', ''],
        fail: ['var(--c-danger-bg)', 'var(--c-danger-fg)', 'priority_high'],
        todo: ['var(--c-surface-3)', 'var(--c-muted)', ''],
        na: ['var(--c-surface-3)', 'var(--c-muted)', 'remove'],
    };

    const breakdown: [string, number, string][] = [
        ['Montant de la prestation', c.gross, p.isDeposit ? 'Échéance du chantier' : 'Pointages clôturés × tarif de l’affectation'],
        ['Bonus', c.bonus, 'Validés par l’administration'],
        ['Indemnités', c.indemnity, 'Validées par l’administration'],
        ['Commission O’CHANTIER · part promoteur', c.comP, `Y = ${c.y} % · Paramètres`],
        [`Frais externes (${p.method ?? '—'})`, c.fees, `${fees[0]} % + ${fcfa(fees[1])} · Paramètres`],
        ['Taxes', c.tax, `${tax} % · Paramètres`],
        ['= Total payé par le promoteur', c.promoterTotal, ''],
        ['− Commission O’CHANTIER · part ouvrier', c.comO, `Z = ${c.z} % · Paramètres`],
        ['− Retenues approuvées', c.deduction, 'Approbation humaine'],
        ['= Montant net ouvrier', c.net, p.isDeposit ? 'Acompte : aucun reversement individuel' : ''],
    ];

    const itemTable = (kind: PaymentItem['kind'], title: string) => {
        const list = items.filter((i) => i.kind === kind);
        return (
            <DataTable
                key={kind}
                icon={kind === 'deduction' ? 'remove_circle' : 'add_circle'}
                title={title}
                count={`${list.length} ligne(s) · ${fcfa(list.filter((i) => ['Validé', 'Approuvée'].includes(i.status.label)).reduce((a, i) => a + i.amount, 0))}`}
                minWidth={1000}
                columns={['Type', 'Montant', 'Motif', 'Base', 'Justificatif', 'Auteur', 'Approbateur', 'Date', 'Statut']}
                empty={{ icon: 'remove', title: 'Aucune ligne', text: 'Aucun élément.' }}
                rows={list.map((i) => ({
                    key: String(i.id),
                    cells: [i.type, fcfa(i.amount), i.motif, i.base ?? '—', i.proof ?? '—', i.author, i.approver ?? '—', formatDate(i.date)].map((v, n) => <TextCell key={n} strong={n === 0}>{v}</TextCell>).concat([<StatusBadge key="s" label={i.status.label} tone={i.status.tone} />]),
                    actions: i.pending
                        ? [
                              { icon: 'check', label: 'Approuver', onClick: () => actions.decideItem(p.reference, i, true) },
                              { icon: 'close', label: 'Rejeter', onClick: () => actions.decideItem(p.reference, i, false) },
                          ]
                        : [],
                }))}
            />
        );
    };

    return (
        <>
            <Head title={`${p.reference} · Paiements`} />
            <PaymentNav sections={sections} />
            <DetailHeader
                icon="receipt_long"
                title={p.reference}
                badges={[
                    { label: `Paiement : ${p.payStatus.label}`, tone: p.payStatus.tone },
                    { label: `Reversement : ${p.payoutStatus.label}`, tone: p.payoutStatus.tone },
                ]}
                sub={`${p.type} · ${formatDate(p.periodStart)} → ${formatDate(p.periodEnd)} · Calcul : ${p.calcStatus}`}
                code={`${p.promoter} · ${p.site}${p.worker ? ` · ${p.worker.name} (${p.worker.trade})` : ''}`}
                meta={[
                    ['work', 'Montant brut', fcfa(c.gross)],
                    ['percent', 'Commission O’CHANTIER', fcfa(c.com)],
                    ['payments', 'Payé par le promoteur', fcfa(c.promoterTotal)],
                    ['savings', 'Net ouvrier', p.isDeposit ? '—' : fcfa(c.net)],
                ]}
                actions={[...(next ? [next] : []), { label: 'Consulter le calcul', icon: 'calculate', onClick: go(`${base}/calcul`) }]}
                menu={menu}
                banner={
                    blocking.length || p.payStatus.label === 'Suspendu' || p.openDays
                        ? { tone: 'danger', icon: 'report', title: p.payStatus.label === 'Suspendu' ? 'Opération suspendue.' : p.openDays ? 'Données de pointage incomplètes.' : 'Anomalie bloquante.', text: p.openDays ? `${p.openDays} journée(s) non clôturée(s) : calcul non définitif.` : blocking.map((a) => a.type).join(' · ') }
                        : null
                }
                kpis={[
                    { label: 'Montant brut', value: fcfa(c.gross), icon: 'work', tone: 'b', hint: p.isDeposit ? 'Montant de l’échéance' : `${p.days} j × ${fcfa(p.dailyRate)}` },
                    { label: 'Commission O’CHANTIER', value: fcfa(c.com), icon: 'percent', tone: 'g', hint: `Nette ${fcfa(c.comNet)} · ${c.comStatus}` },
                    { label: 'Payé par le promoteur', value: fcfa(c.promoterTotal), icon: 'payments', tone: 'b', hint: `Reçu ${fcfa(c.received)}` },
                    { label: 'Net ouvrier', value: p.isDeposit ? '—' : fcfa(c.net), icon: 'savings', tone: 'p', hint: p.payoutStatus.label },
                ]}
                tabs={tabs}
                tab={tab}
                base={base}
            >
                {tab === '' && (
                    <>
                        <AlertList alerts={anomalies.map((a) => ({ tone: LEVEL_DANGER.includes(a.level) ? 'danger' : 'warning', icon: 'report', title: `${a.type} · ${a.level}`, text: a.detail }))} />
                        <div role="list" aria-label="Parcours de l’opération" style={{ display: 'flex', gap: 'var(--space-2)', overflowX: 'auto', paddingBottom: 'var(--space-1)' }}>
                            {steps.map((s, i) => {
                                const [bg, fg, icon] = STEP_STYLE[s.state];
                                return (
                                    <button key={s.label} type="button" role="listitem" disabled={!s.target} onClick={go(`${base}/${s.target}`)} style={{ flex: '1 0 150px', display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 'var(--space-1)', border: 0, background: 'transparent', cursor: s.target ? 'pointer' : 'default', font: 'inherit', textAlign: 'center' }}>
                                        <span style={{ display: 'flex', alignItems: 'center', width: '100%' }}>
                                            <span style={{ flex: 1, height: 2, background: i ? 'var(--c-line)' : 'transparent' }} />
                                            <span style={{ width: 34, height: 34, borderRadius: 'var(--radius-round)', display: 'grid', placeItems: 'center', background: bg, color: fg, border: `2px solid ${s.state === 'fail' ? 'var(--c-danger-fg)' : s.state === 'todo' || s.state === 'na' ? 'var(--c-line-strong)' : 'var(--c-primary)'}` }}>
                                                <Icon name={icon || s.icon} size="var(--icon-sm)" />
                                            </span>
                                            <span style={{ flex: 1, height: 2, background: i < steps.length - 1 ? (s.state === 'done' ? 'var(--c-primary)' : 'var(--c-line)') : 'transparent' }} />
                                        </span>
                                        <b style={{ fontSize: 'var(--fs-sm)' }}>{s.label}</b>
                                        <span style={{ fontSize: 'var(--fs-xs)', color: 'var(--c-muted)' }}>{s.sub}</span>
                                    </button>
                                );
                            })}
                        </div>
                        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,420px),1fr))', gap: 'var(--space-4)' }}>
                            <SegmentBar
                                segment={{
                                    title: 'Composition du montant payé par le promoteur',
                                    sub: p.method ? `via ${p.method}` : '',
                                    total: c.promoterTotal,
                                    items: [
                                        ['Prestation', c.gross, 'var(--c-primary-600)'],
                                        ['Bonus et indemnités', c.bonus + c.indemnity, 'var(--c-primary-400)'],
                                        ['Commission O’CHANTIER · part promoteur', c.comP, 'var(--c-accent-600)'],
                                        ['Frais du prestataire', c.fees, 'var(--c-info)'],
                                        ['Taxes', c.tax, 'var(--c-line-strong)'],
                                    ].map(([label, value, color]) => ({ label: label as string, value: value as number, color: color as string, percent: c.promoterTotal ? Math.round(((value as number) / c.promoterTotal) * 1000) / 10 : 0 })),
                                }}
                            />
                            <SegmentBar
                                segment={{
                                    title: 'Répartition du montant',
                                    sub: c.check ? `Écart de ${fcfa(c.check)} · validation bloquée` : 'Somme des composantes = total ✓',
                                    total: c.promoterTotal,
                                    items: (p.isDeposit
                                        ? [
                                              ['Provision chantier', c.gross + c.bonus + c.indemnity - c.comO, 'var(--c-warning-fg)'],
                                              ['Commission O’CHANTIER', c.com, 'var(--c-accent-600)'],
                                              ['Frais du prestataire', c.fees, 'var(--c-info)'],
                                              ['Taxes', c.tax, 'var(--c-line-strong)'],
                                          ]
                                        : [
                                              ['Net ouvrier', c.net, 'var(--c-primary-600)'],
                                              ['Commission O’CHANTIER (promoteur + ouvrier)', c.com, 'var(--c-accent-600)'],
                                              ['Frais du prestataire', c.fees, 'var(--c-info)'],
                                              ['Retenues', c.deduction, 'var(--c-danger)'],
                                              ['Taxes', c.tax, 'var(--c-line-strong)'],
                                          ]
                                    ).map(([label, value, color]) => ({ label: label as string, value: value as number, color: color as string, percent: c.promoterTotal ? Math.round(((value as number) / c.promoterTotal) * 1000) / 10 : 0 })),
                                }}
                            />
                        </div>
                        <Block icon="summarize" title="Résumé">
                            <KeyValues
                                items={[
                                    ['Référence', p.reference],
                                    ['Clé d’idempotence', p.key],
                                    ['Type', p.type],
                                    ['Période', `${formatDate(p.periodStart)} → ${formatDate(p.periodEnd)}`],
                                    ['Prestation', p.isDeposit ? 'Échéance du chantier' : `${p.days} journée(s) clôturée(s) · ${p.worker?.trade ?? ''}`],
                                    ['Affectation', p.assignment ?? '—'],
                                    ['Moyen de paiement', `${p.method ?? '—'} · ${p.provider ?? ''}`],
                                    ['Références', [p.externalReference, p.payoutReference].filter(Boolean).join(' · ') || '—'],
                                    ['Calcul', p.calcStatus + (p.validatedBy ? ` · validé par ${p.validatedBy} le ${formatDate(p.validatedAt)}` : '')],
                                    ['Paiement', p.payStatus.label],
                                    ['Reversement', p.payoutStatus.label],
                                    ['Commission', c.comStatus],
                                    ['Rapprochement', p.reconciliation.label],
                                    ['Prochaine action', next?.label ?? 'Aucune'],
                                ]}
                            />
                        </Block>
                    </>
                )}

                {tab === 'calcul' && (
                    <>
                        <Toolbar
                            actions={[
                                { label: 'Consulter la règle', icon: 'policy', onClick: () => actions.rule(rule) },
                                { label: 'Signaler une erreur', icon: 'report', onClick: () => actions.reportAnomaly(p.reference) },
                                { label: 'Exporter le relevé', icon: 'download', variant: 'primary', onClick: doc('releve-commission') },
                            ]}
                        />
                        <AlertList alerts={[{ tone: 'info', icon: 'function', title: 'Formule', text: `Commission = base × taux applicable (${fcfa(c.base)} × ${c.y + c.z} % = ${fcfa(c.raw)}), puis minimum et maximum de Paramètres. Distincte des frais du prestataire (${fcfa(c.fees)}) et des taxes (${fcfa(c.tax)}).` }]} />
                        <DataTable
                            icon="calculate"
                            title="Calcul détaillé"
                            count={`Commission ${p.isDeposit ? 'supportée par le promoteur' : 'partagée promoteur / ouvrier'} · règle ${p.ruleVersion}`}
                            minWidth={900}
                            columns={['Composante', 'Montant', 'Source']}
                            empty={{ icon: 'calculate', title: '', text: '' }}
                            rows={breakdown.map(([k, v, src]) => ({ key: k, cells: [<TextCell key="k" strong={k.startsWith('=')}>{k}</TextCell>, <TextCell key="v" strong={k.startsWith('=')}>{fcfa(v)}</TextCell>, <TextCell key="s" muted={!src}>{src || '—'}</TextCell>], actions: [] }))}
                        />
                        <Block icon="percent" title="Détail de la commission O’CHANTIER">
                            <KeyValues
                                items={[
                                    ['Règle utilisée', `${rule.name} · ${p.ruleVersion}`],
                                    ['Mode', rule.type],
                                    ['Base de calcul', `${fcfa(c.base)} (${rule.base})`],
                                    ['Taux appliqué', `${c.y + c.z} % (promoteur ${c.y} % · ouvrier ${c.z} %)`],
                                    ['Commission minimale / maximale', `${rule.min ? fcfa(rule.min) : 'Aucune'} / ${rule.max ? fcfa(rule.max) : 'Aucune'}`],
                                    ['Montant avant plafond', fcfa(c.raw)],
                                    ['Montant après plafond', fcfa(c.com)],
                                    ['Personne supportant la commission', p.isDeposit ? 'Promoteur' : 'Répartie : promoteur et ouvrier'],
                                    ['Part promoteur / ouvrier', `${fcfa(c.comP)} / ${fcfa(c.comO)}`],
                                    ['Montant encaissé', fcfa(c.comCollected)],
                                    ['Montant restant', fcfa(c.paid ? 0 : c.com)],
                                    ['Montant remboursé / extourné', `${fcfa(c.comRefunded)} / ${fcfa(c.comReversed)}`],
                                    ['Commission nette', fcfa(c.comNet)],
                                    ['Statut', c.comStatus],
                                    ['Validé par', p.validatedBy ? `${p.validatedBy} · ${formatDate(p.validatedAt)}` : '—'],
                                ]}
                            />
                        </Block>
                        <DataTable
                            icon="checklist"
                            title="Parcours de validation"
                            count={`${checklist.length} étapes`}
                            minWidth={800}
                            columns={['Étape', 'Résultat', 'Statut']}
                            empty={{ icon: 'checklist', title: '', text: '' }}
                            rows={checklist.map(([l, ok, d]) => ({ key: l, cells: [<TextCell key="l" strong>{l}</TextCell>, <span key="d">{d}</span>, <StatusBadge key="s" label={ok ? 'Conforme' : 'À traiter'} tone={ok ? 'g' : 'a'} />], actions: [] }))}
                        />
                        <Toolbar
                            actions={
                                c.paid
                                    ? []
                                    : [
                                          { label: 'Ajouter un bonus', icon: 'add', onClick: () => actions.addItem(p.reference, 'bonus') },
                                          { label: 'Ajouter une indemnité', icon: 'add', onClick: () => actions.addItem(p.reference, 'indemnity') },
                                          { label: 'Ajouter une retenue', icon: 'remove', onClick: () => actions.addItem(p.reference, 'deduction') },
                                      ]
                            }
                        />
                        {itemTable('bonus', 'Bonus')}
                        {itemTable('indemnity', 'Indemnités')}
                        {itemTable('deduction', 'Retenues')}
                    </>
                )}

                {tab === 'paiement' && (
                    <>
                        <Toolbar
                            actions={[
                                ...(c.paid && !/Rapproché/.test(p.reconciliation.label) ? [{ label: 'Rapprocher manuellement', icon: 'rule', onClick: () => actions.reconcile(p.reference, `Attendu ${fcfa(c.promoterTotal)} · reçu ${fcfa(c.received)} · écart ${fcfa(c.gap)} · référence externe ${p.externalReference ?? '—'}.`) }] : []),
                                { label: 'Reçu du promoteur', icon: 'receipt', onClick: doc('recu-promoteur') },
                            ]}
                        />
                        <Block icon="payments" title="Paiement du promoteur">
                            <KeyValues
                                items={[
                                    ['Promoteur', p.promoter],
                                    ['Moyen', p.method ?? '—'],
                                    ['Prestataire', p.provider ?? '—'],
                                    ['Numéro ou compte', `${p.account ?? '—'} (masqué)`],
                                    ['Référence externe', p.externalReference ?? '—'],
                                    ['Montant demandé', fcfa(c.promoterTotal)],
                                    ['Montant reçu', fcfa(c.received)],
                                    ['Date d’initiation', formatDate(p.initiatedAt)],
                                    ['Date de confirmation', formatDate(p.paidAt)],
                                    ['Statut', p.payStatus.label],
                                    ['Message du prestataire', p.providerMessage ?? '—'],
                                    ['Tentatives', String(p.attempts)],
                                    ['Données sensibles', 'PIN, code secret, jeton et clé API jamais affichés'],
                                ]}
                            />
                        </Block>
                        <Block icon="rule" title="Rapprochement">
                            <KeyValues
                                items={[
                                    ['Référence externe', p.externalReference ?? '—'],
                                    ['Montant attendu', fcfa(c.promoterTotal)],
                                    ['Montant reçu', fcfa(c.received)],
                                    ['Écart', fcfa(c.gap)],
                                    ['Commission attendue / comptabilisée', `${fcfa(c.com)} / ${fcfa(c.comCollected)}`],
                                    ['Frais externes', fcfa(c.fees)],
                                    ['Statut', p.reconciliation.label],
                                    ['Note', p.reconciliationNote ?? '—'],
                                ]}
                            />
                        </Block>
                    </>
                )}

                {tab === 'reversement' && (
                    <>
                        <Toolbar
                            actions={[
                                { label: 'Reverser', icon: 'send', variant: 'primary', onClick: payout },
                                { label: 'Reçu de reversement', icon: 'receipt', onClick: doc('recu-reversement') },
                            ]}
                        />
                        <Block icon="send" title="Reversement à l’ouvrier">
                            <KeyValues
                                items={[
                                    ['Ouvrier', p.worker ? `${p.worker.name} · ${p.worker.code}` : '—'],
                                    ...payoutMeta,
                                    ['Statut', p.payoutStatus.label],
                                    ['Référence', p.payoutReference ?? '—'],
                                    ['Lot', p.batch ?? '—'],
                                    ['Date d’exécution', formatDate(p.payoutAt)],
                                    ['Résultat', p.payoutMessage ?? (p.payoutStatus.label === 'Réussi' ? 'Crédité' : '—')],
                                ]}
                            />
                        </Block>
                        <Block icon="checklist" title="Contrôles avant reversement">
                            <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-2)' }}>
                                {payoutChecks.map(([l, ok]) => (
                                    <div key={l} style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-3)', padding: 'var(--space-2) var(--space-3)', border: '1px solid var(--c-line-soft)', borderRadius: 'var(--radius-lg)' }}>
                                        <Icon name={ok ? 'check_circle' : 'cancel'} color={ok ? 'var(--c-success-fg)' : 'var(--c-danger-fg)'} />
                                        <span style={{ flex: 1 }}>{l}</span>
                                        <StatusBadge label={ok ? 'Conforme' : 'Bloquant'} tone={ok ? 'g' : 'r'} />
                                    </div>
                                ))}
                            </div>
                        </Block>
                    </>
                )}

                {tab === 'remboursements' && (
                    <>
                        <Toolbar actions={[{ label: 'Demander un remboursement', icon: 'currency_exchange', variant: 'primary', onClick: () => actions.refund(p.reference, c.received) }]} />
                        <TileGrid
                            tiles={[
                                { label: 'Commission initiale', value: fcfa(c.com), icon: 'percent', tone: 'info' },
                                { label: 'Part conservée', value: fcfa(c.comNet), icon: 'account_balance', tone: 'success' },
                                { label: 'Part remboursée', value: fcfa(c.comRefunded), icon: 'currency_exchange', tone: 'orange' },
                                { label: 'Part extournée', value: fcfa(c.comReversed), icon: 'undo', tone: 'danger' },
                                { label: 'Nouvelle commission nette', value: fcfa(c.comNet), icon: 'functions', tone: 'success', sub: `Règle : ${rule.refundRule}` },
                            ]}
                        />
                        <RefundTable refunds={refunds} actions={actions} reference={p.reference} />
                    </>
                )}

                {tab === 'pointages' && (
                    <>
                        <AlertList alerts={[{ tone: 'info', icon: 'lock', title: 'Lecture seule', text: 'Seules les journées validées et clôturées alimentent le calcul définitif ; aucun pointage n’est modifiable depuis Paiements.' }]} />
                        <DataTable
                            icon="schedule"
                            title="Pointages associés"
                            count={attendances.length ? `${attendances.length} journée(s)` : p.source}
                            minWidth={1100}
                            columns={['Référence', 'Date', 'Arrivée', 'Départ', 'Durée nette', 'Heures sup.', 'Journée', 'Retenu pour le calcul']}
                            empty={{ icon: 'inventory_2', title: p.isDeposit ? 'Aucun pointage' : 'Pointages archivés', text: p.isDeposit ? 'Un acompte ne dépend pas des pointages.' : `${p.days} journée(s) clôturée(s) archivée(s) dans Pointages.` }}
                            rows={attendances.map((a) => ({
                                key: a.reference,
                                onOpen: go(`/pointages/${a.reference}`),
                                cells: [
                                    <TextCell key="r" strong>
                                        {a.reference}
                                    </TextCell>,
                                    <TextCell key="d">{formatDate(a.date)}</TextCell>,
                                    <TextCell key="a">{a.arrival ?? '—'}</TextCell>,
                                    <TextCell key="de">{a.departure ?? '—'}</TextCell>,
                                    <TextCell key="n">{a.net}</TextCell>,
                                    <TextCell key="o" muted={!a.overtime}>
                                        {a.overtime ?? '—'}
                                    </TextCell>,
                                    <StatusBadge key="s" label={a.status.label} tone={a.status.tone} />,
                                    <StatusBadge key="c" label={a.counted ? 'Oui' : 'Non'} tone={a.counted ? 'g' : 'a'} />,
                                ],
                                actions: [],
                            }))}
                        />
                    </>
                )}

                {tab === 'documents' && (
                    <>
                        <Toolbar actions={DOCS.map(([key, label]) => ({ label, icon: 'description', onClick: doc(key) }))} />
                        <DataTable
                            icon="folder"
                            title="Documents et justificatifs"
                            count={`${p.documents.length} document(s)`}
                            minWidth={1000}
                            columns={['Référence', 'Type', 'Date', 'Émetteur', 'Bénéficiaire', 'Montant', 'Version', 'Statut']}
                            empty={{ icon: 'folder_off', title: 'Aucun document', text: 'Les documents sont émis à chaque étape.' }}
                            rows={p.documents.map((d) => {
                                const payout = /reversement/i.test(d.type);
                                return {
                                    key: d.reference,
                                    cells: [
                                        <TextCell key="r" strong>
                                            {d.reference}
                                        </TextCell>,
                                        <TextCell key="t">{d.type}</TextCell>,
                                        <TextCell key="d">{formatDate(d.date)}</TextCell>,
                                        <TextCell key="e">{payout ? 'O’CHANTIER' : /Reçu du promoteur|Justificatif/.test(d.type) ? (p.provider ?? '—') : 'O’CHANTIER'}</TextCell>,
                                        <TextCell key="b">{payout ? (p.worker?.name ?? '—') : p.promoter}</TextCell>,
                                        <TextCell key="m">{fcfa(payout ? c.net : /commission/i.test(d.type) ? c.com : c.promoterTotal)}</TextCell>,
                                        <TextCell key="v">{`v${d.version}`}</TextCell>,
                                        <StatusBadge key="s" label={d.status} tone={'b' as AnyTone} />,
                                    ],
                                    actions: [{ icon: 'download', label: 'Télécharger', onClick: doc(payout ? 'recu-reversement' : /commission/i.test(d.type) ? 'releve-commission' : /Facture/.test(d.type) ? 'facture' : /rembours/i.test(d.type) ? 'remboursement' : 'recu-promoteur') }],
                                };
                            })}
                        />
                    </>
                )}

                {tab === 'historique' && <HistoryList activities={activities} />}
            </DetailHeader>
            {actions.dialog}
        </>
    );
}
