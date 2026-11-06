import FormDialog from '@/components/overlays/FormDialog';
import { RelationTable, stack } from '@/components/promoters/RelationTabs';
import QualityNav from '@/components/quality/QualityNav';
import Button from '@/components/ui/Button';
import StatusBadge from '@/components/ui/StatusBadge';
import { Block, KeyValues } from '@/components/workers/blocks';
import type { ClaimDetail } from '@/types/claims';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { PRIORITIES } from './Claims';

const PARTY_LINKS: Record<string, string> = { promoter_profile: '/promoteurs', worker: '/ouvriers', commercial_profile: '/commerciaux' };

const party = (p: ClaimDetail['claimant'] | null) => (p ? <Link href={`${PARTY_LINKS[p.type]}/${p.id}`}>{`${p.name} · ${p.type_label}`}</Link> : null);

/** Fiche d’une réclamation : demande, réponse au demandeur et journal. */
export default function QualityClaim({ claim: c, can }: { claim: ClaimDetail; can: { update: boolean } }) {
    const [dialog, setDialog] = useState<'priority' | 'resolve' | 'reject' | null>(null);
    const base = `/avis-litiges/reclamations/${c.id}`;
    const close = () => setDialog(null);
    const post = (path: string) => router.post(`${base}/${path}`, {}, { preserveScroll: true });
    const a = c.actions;
    const silent = c.claimant.type === 'worker';

    return (
        <>
            <Head title={c.reference} />

            <div style={{ display: 'flex', justifyContent: 'flex-end', flexWrap: 'wrap', gap: 'var(--space-2)', marginTop: -6 }}>
                <QualityNav current="" />
                {can.update && a.reject && <Button label="Rejeter" icon="cancel" variant="danger-soft" onClick={() => setDialog('reject')} />}
                {can.update && a.priority && <Button label="Changer la priorité" icon="flag" variant="secondary" onClick={() => setDialog('priority')} />}
                {can.update && a.take && <Button label={c.owner ? 'Reprendre le dossier' : 'Prendre en charge'} icon="assignment_ind" variant="secondary" onClick={() => post('prise-en-charge')} />}
                {can.update && a.resolve && <Button label="Résoudre" icon="check_circle" onClick={() => setDialog('resolve')} />}
                {can.update && a.close && <Button label="Clore" icon="inventory_2" onClick={() => post('cloture')} />}
            </div>

            <div style={stack}>
                <Block icon="support_agent" title={c.reference}>
                    <KeyValues
                        items={[
                            ['Statut', <StatusBadge label={c.status.label} tone={c.status.tone as never} />],
                            ['Priorité', <StatusBadge label={c.priority.label} tone={c.priority.tone as never} />],
                            ['Catégorie', c.category],
                            ['Objet', c.subject],
                            ['Description', c.description],
                            ['Demandeur', party(c.claimant)],
                            ['Autre partie', party(c.otherParty)],
                            ['Chantier', c.site ? <Link href={`/chantiers/${c.site.id}`}>{c.site.title}</Link> : null],
                            ['Responsable', c.owner ?? 'À attribuer'],
                            ['Déposée le', c.createdAt],
                            ['Réponse attendue', c.dueAt],
                            ['Réponse au demandeur', c.resolution],
                        ]}
                    />
                </Block>

                <Block icon="history" title="Journal">
                    <RelationTable columns={['Date', 'Action', 'Détail', 'Par']} empty={{ icon: 'history', title: 'Aucune action', text: 'La réclamation n’a pas encore été traitée.' }} rows={c.history.map((h) => ({ key: h.id, cells: [h.date ?? '—', h.label, h.reason ?? '—', h.by ?? '—'] }))} />
                </Block>
            </div>

            {dialog === 'priority' && <FormDialog title="Changer la priorité" icon="flag" okLabel="Enregistrer" url={`${base}/priorite`} initial={{ priority: c.priority.value }} onClose={close} fields={[{ name: 'priority', label: 'Priorité', type: 'select', required: true, full: true, options: PRIORITIES }]} />}
            {dialog === 'resolve' && <FormDialog title="Résoudre la réclamation" body={silent ? 'Un ouvrier n’a pas d’application : prévenez-le vous-même de cette réponse.' : 'Le demandeur reçoit cette réponse.'} icon="check_circle" okLabel="Résoudre" url={`${base}/resolution`} onClose={close} fields={[{ name: 'reason', label: 'Réponse au demandeur', type: 'textarea', required: true }]} />}
            {dialog === 'reject' && <FormDialog title="Rejeter la réclamation ?" body={silent ? 'Un ouvrier n’a pas d’application : prévenez-le vous-même de ce motif.' : 'Le demandeur reçoit ce motif.'} icon="cancel" tone="danger" okLabel="Rejeter" url={`${base}/rejet`} onClose={close} fields={[{ name: 'reason', label: 'Motif', type: 'textarea', required: true }]} />}
        </>
    );
}
