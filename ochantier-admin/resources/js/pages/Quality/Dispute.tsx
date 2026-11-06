import FormDialog from '@/components/overlays/FormDialog';
import { RelationTable, stack } from '@/components/promoters/RelationTabs';
import QualityNav from '@/components/quality/QualityNav';
import Button from '@/components/ui/Button';
import StatusBadge from '@/components/ui/StatusBadge';
import { Block, KeyValues } from '@/components/workers/blocks';
import { fcfa } from '@/lib/format';
import type { DisputeCan, DisputeDetail } from '@/types/disputes';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { PRIORITIES } from './Claims';

type Dialog = 'urgency' | 'mission' | 'public' | 'internal' | 'proof' | 'decide' | 'appeal' | 'appealDecision' | null;

const PARTY_LINKS: Record<string, string> = { promoter_profile: '/promoteurs', worker: '/ouvriers', commercial_profile: '/commerciaux' };

const DECISION_FIELDS = [
    { name: 'decision', label: 'Décision motivée', type: 'textarea' as const, required: true },
    { name: 'refund_amount', label: 'Remboursement au promoteur (FCFA)', type: 'number' as const, hint: 'Crée une demande dans Remboursements, avec sa propre validation.' },
    { name: 'financial_note', label: 'Autre mesure financière à noter', hint: 'Retenue ou ajustement : à faire ensuite dans Paiements.' },
];

/** Fiche d’un litige : dossier, échanges, preuves, décision et recours. */
export default function QualityDispute({ dispute: d, can }: { dispute: DisputeDetail; can: DisputeCan }) {
    const [dialog, setDialog] = useState<Dialog>(null);
    const base = `/avis-litiges/litiges/${d.id}`;
    const close = () => setDialog(null);
    const post = (path: string) => router.post(`${base}/${path}`, {}, { preserveScroll: true });
    const a = d.actions;
    const f = d.financial;

    return (
        <>
            <Head title={d.reference} />

            <div style={{ display: 'flex', justifyContent: 'flex-end', flexWrap: 'wrap', gap: 'var(--space-2)', marginTop: -6 }}>
                <QualityNav current="" />
                {can.update && a.urgency && <Button label="Changer l’urgence" icon="flag" variant="secondary" onClick={() => setDialog('urgency')} />}
                {can.update && a.linkMission && <Button label={d.mission ? 'Changer de mission' : 'Rattacher une mission'} icon="link" variant="secondary" onClick={() => setDialog('mission')} />}
                {can.update && a.take && <Button label={d.owner ? 'Reprendre le dossier' : 'Prendre en charge'} icon="assignment_ind" variant="secondary" onClick={() => post('prise-en-charge')} />}
                {can.update && a.appeal && <Button label="Ouvrir un recours" icon="balance" variant="secondary" onClick={() => setDialog('appeal')} />}
                {can.update && a.close && <Button label="Clore" icon="inventory_2" onClick={() => post('cloture')} />}
                {can.decide && a.requestRefund && <Button label={`Demander le remboursement de ${fcfa(f.refundAmount ?? 0)}`} icon="undo" onClick={() => post('remboursement')} />}
                {can.decide && a.decide && <Button label="Rendre la décision" icon="gavel" onClick={() => setDialog('decide')} />}
                {can.approve && a.decideAppeal && <Button label="Décider du recours" icon="gavel" onClick={() => setDialog('appealDecision')} />}
            </div>

            <div style={stack}>
                <Block icon="gavel" title={d.reference}>
                    <KeyValues
                        items={[
                            ['Statut', <StatusBadge label={d.status.label} tone={d.status.tone as never} />],
                            ['Urgence', <StatusBadge label={d.urgency.label} tone={d.urgency.tone as never} />],
                            ['Catégorie', d.category],
                            ['Description', d.description],
                            ...d.parties.map((p): [string, React.ReactNode] => [p.role.label, <Link href={`${PARTY_LINKS[p.type]}/${p.id}`}>{`${p.name} · ${p.type_label}`}</Link>]),
                            ['Chantier', d.site ? <Link href={`/chantiers/${d.site.id}`}>{d.site.title}</Link> : null],
                            ['Mission', d.mission ? <Link href={`/paiements/regularisations/${d.mission.id}`}>{d.mission.reference}</Link> : 'Aucune : ce litige ne bloque aucun paiement'],
                            ['Responsable', d.owner ?? 'À attribuer'],
                            ['Ouvert le', d.createdAt],
                            ['Réclamation d’origine', d.claim ? <Link href={`/avis-litiges/reclamations/${d.claim.id}`}>{d.claim.reference}</Link> : null],
                            ['Recours de', d.appealOf ? <Link href={`/avis-litiges/litiges/${d.appealOf.id}`}>{d.appealOf.reference}</Link> : null],
                            ['Recours ouvert', d.appeal ? <Link href={`/avis-litiges/litiges/${d.appeal.id}`}>{`${d.appeal.reference} · ${d.appeal.status.label}`}</Link> : null],
                        ]}
                    />
                </Block>

                {d.decision && (
                    <Block icon="balance" title="Décision">
                        <KeyValues
                            items={[
                                ['Décision', d.decision],
                                ['Issue du recours', d.outcome ? (d.outcome === 'confirmee' ? 'Décision confirmée' : 'Décision annulée') : null],
                                ['Rendue par', d.decidedBy],
                                ['Rendue le', d.decidedAt],
                                ['Recours possible jusqu’au', d.appeal ? null : d.appealDeadline],
                                ['Remboursement', f.refundAmount ? fcfa(f.refundAmount) : null],
                                ['Demande de remboursement', f.refund ? <Link href={`/paiements/remboursements/${f.refund.id}`}>{`${f.refund.reference} · ${f.refund.status.label}`}</Link> : f.refundPending ? 'En attente de la régularisation de la mission' : null],
                                ['Autre mesure à appliquer', f.note],
                            ]}
                        />
                    </Block>
                )}

                <Block icon="forum" title="Échanges" count={`${d.messages.length}`} action={can.update && a.message ? { label: 'Écrire aux parties', icon: 'send', onClick: () => setDialog('public') } : null}>
                    {can.update && a.message && (
                        <div style={{ marginBottom: 'var(--space-3)' }}>
                            <Button label="Note interne" icon="lock" size="sm" variant="secondary" onClick={() => setDialog('internal')} />
                        </div>
                    )}
                    <RelationTable
                        columns={['Date', 'Auteur', 'Visibilité', 'Message']}
                        empty={{ icon: 'forum', title: 'Aucun échange', text: 'Les messages avec les parties et les notes internes apparaissent ici.' }}
                        rows={d.messages.map((m) => ({ key: m.id, cells: [m.date ?? '—', m.author, m.internal ? 'Interne' : 'Parties', m.body] }))}
                    />
                </Block>

                <Block icon="attach_file" title="Preuves" count={`${d.proofs.length}`} action={can.update && a.proof ? { label: 'Ajouter', icon: 'add', onClick: () => setDialog('proof') } : null}>
                    <RelationTable
                        columns={['Date', 'Document', 'Déposé par']}
                        empty={{ icon: 'attach_file', title: 'Aucune preuve', text: 'Les pièces de l’équipe et du promoteur apparaissent ici.' }}
                        rows={d.proofs.map((p) => ({
                            key: p.id,
                            cells: [
                                p.date ?? '—',
                                p.url ? (
                                    <a href={p.url} target="_blank" rel="noreferrer">
                                        {p.label ?? 'Ouvrir'}
                                    </a>
                                ) : (
                                    (p.label ?? '—')
                                ),
                                p.from_team ? 'Équipe' : 'Partie',
                            ],
                        }))}
                    />
                </Block>

                <Block icon="history" title="Journal">
                    <RelationTable columns={['Date', 'Action', 'Détail', 'Par']} empty={{ icon: 'history', title: 'Aucune action', text: 'Le dossier n’a pas encore été traité.' }} rows={d.history.map((h) => ({ key: h.id, cells: [h.date ?? '—', h.label, h.reason ?? '—', h.by ?? '—'] }))} />
                </Block>
            </div>

            {dialog === 'urgency' && <FormDialog title="Changer l’urgence" icon="flag" okLabel="Enregistrer" url={`${base}/urgence`} initial={{ urgency: d.urgency.value }} onClose={close} fields={[{ name: 'urgency', label: 'Urgence', type: 'select', required: true, full: true, options: PRIORITIES }]} />}
            {dialog === 'mission' && <FormDialog title="Rattacher une mission" body="Ses paiements sont bloqués tant que le litige est ouvert." icon="link" tone="warning" okLabel="Rattacher" url={`${base}/mission`} onClose={close} fields={[{ name: 'mission_reference', label: 'Référence de la mission', required: true, full: true }]} />}
            {dialog === 'public' && <FormDialog title="Écrire aux parties" body="Le promoteur et le commercial le lisent dans leur application. Un ouvrier n’en a pas : prévenez-le vous-même." icon="send" okLabel="Envoyer" url={`${base}/messages`} extra={{ internal: '0' }} onClose={close} fields={[{ name: 'body', label: 'Message', type: 'textarea', required: true }]} />}
            {dialog === 'internal' && <FormDialog title="Note interne" body="Les parties ne la voient jamais." icon="lock" okLabel="Enregistrer" url={`${base}/messages`} extra={{ internal: '1' }} onClose={close} fields={[{ name: 'body', label: 'Note', type: 'textarea', required: true }]} />}
            {dialog === 'proof' && (
                <FormDialog
                    title="Ajouter une preuve"
                    icon="attach_file"
                    okLabel="Ajouter"
                    url={`${base}/preuves`}
                    onClose={close}
                    fields={[
                        { name: 'label', label: 'Libellé', full: true },
                        { name: 'file', label: 'Fichier JPG, PNG ou PDF', type: 'file', required: true },
                    ]}
                />
            )}
            {dialog === 'decide' && <FormDialog title="Rendre la décision" body="Le dossier cesse de bloquer les paiements, et les parties ont 15 jours pour former un recours." icon="gavel" tone="warning" okLabel="Rendre la décision" url={`${base}/decision`} onClose={close} fields={DECISION_FIELDS} />}
            {dialog === 'appeal' && <FormDialog title="Ouvrir un recours" body="Un nouveau dossier est créé. Les paiements restent bloqués et les remboursements liés sont suspendus jusqu’à sa décision." icon="balance" tone="warning" okLabel="Ouvrir le recours" url={`${base}/recours`} onClose={close} fields={[{ name: 'reason', label: 'Motif du recours', type: 'textarea', required: true }]} />}
            {dialog === 'appealDecision' && (
                <FormDialog
                    title="Décider du recours"
                    body="Décision définitive. Si elle annule la première et qu’un remboursement a été demandé, refusez-le dans Remboursements."
                    icon="gavel"
                    tone="warning"
                    okLabel="Rendre la décision"
                    url={`${base}/recours/decision`}
                    initial={{ outcome: 'confirmee' }}
                    onClose={close}
                    fields={[
                        {
                            name: 'outcome',
                            label: 'Issue',
                            type: 'select',
                            required: true,
                            full: true,
                            options: [
                                { value: 'confirmee', label: 'Décision confirmée' },
                                { value: 'annulee', label: 'Décision annulée' },
                            ],
                        },
                        ...DECISION_FIELDS,
                    ]}
                />
            )}
        </>
    );
}
