import FormDialog from '@/components/overlays/FormDialog';
import { RelationTable, stack } from '@/components/promoters/RelationTabs';
import Button from '@/components/ui/Button';
import StatusBadge from '@/components/ui/StatusBadge';
import { Block, KeyValues } from '@/components/workers/blocks';
import type { RatingDetail } from '@/types/ratings';
import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';

type Can = { update: boolean; decide: boolean };

type Decision = { path: string; title: string; body: string; icon: string; tone: 'warning' | 'danger'; ok: string; extra?: Record<string, string> };

/** Fiche d’un avis : contenu, versions, modération. La note et le texte de l’auteur ne sont jamais modifiés. */
export default function QualityRating({ rating: r, can }: { rating: RatingDetail; can: Can }) {
    const [decision, setDecision] = useState<Decision | null>(null);
    const base = `/avis-litiges/avis/${r.id}`;
    const a = r.actions;

    return (
        <>
            <Head title={`Avis · ${r.target.name}`} />

            <div style={{ display: 'flex', justifyContent: 'flex-end', flexWrap: 'wrap', gap: 'var(--space-2)', marginTop: -6 }}>
                {can.update && <Button label={r.suspected ? 'Lever le doute' : 'Marquer comme suspect'} icon="policy" variant="secondary" onClick={() => setDecision(r.suspected ? { path: 'suspicion', title: 'Lever le doute ?', body: 'L’avis reste dans son état actuel.', icon: 'policy', tone: 'warning', ok: 'Lever le doute', extra: { suspected: '0' } } : { path: 'suspicion', title: 'Marquer cet avis comme suspect ?', body: 'L’avis reste publié tant qu’aucune décision n’est prise.', icon: 'policy', tone: 'warning', ok: 'Marquer', extra: { suspected: '1' } })} />}
                {can.update && a.report && <Button label="Signaler" icon="flag" variant="secondary" onClick={() => setDecision({ path: 'signalement', title: 'Signaler cet avis ?', body: 'Il remonte en tête de liste pour être modéré. Il reste publié.', icon: 'flag', tone: 'warning', ok: 'Signaler' })} />}
                {can.decide && a.keep && <Button label="Maintenir" icon="check_circle" variant="secondary" onClick={() => setDecision({ path: 'maintien', title: 'Maintenir cet avis ?', body: 'Le signalement est clos et l’avis reste publié.', icon: 'check_circle', tone: 'warning', ok: 'Maintenir' })} />}
                {can.decide && a.hide && <Button label="Masquer" icon="visibility_off" variant="danger-soft" onClick={() => setDecision({ path: 'masquage', title: 'Masquer cet avis ?', body: 'Il sort de la moyenne de l’ouvrier. Le promoteur est notifié et voit le motif.', icon: 'visibility_off', tone: 'danger', ok: 'Masquer' })} />}
                {can.decide && a.restore && <Button label="Rétablir" icon="visibility" onClick={() => setDecision({ path: 'retablissement', title: 'Rétablir cet avis ?', body: 'Il revient dans la moyenne de l’ouvrier. Le promoteur est notifié.', icon: 'visibility', tone: 'warning', ok: 'Rétablir' })} />}
            </div>

            <div style={stack}>
                <Block icon="reviews" title={r.target.name}>
                    <KeyValues
                        items={[
                            ['Statut', <StatusBadge label={r.status.label} tone={r.status.tone as never} />],
                            ['Modération', r.moderation ? <StatusBadge label={r.moderation.label} tone={r.moderation.tone as never} /> : null],
                            ['Dernier motif', r.moderation?.reason],
                            ['Décidé par', r.moderation?.by],
                            ['Vérification', r.verification],
                            ['Note', `${r.score} / 5`],
                            ['Satisfaction', r.tag],
                            ['Commentaire', r.comment],
                            ['Type', r.scope],
                            ['Ouvrier', r.target.kind === 'worker' && r.target.id ? <Link href={`/ouvriers/${r.target.id}`}>{r.target.name}</Link> : null],
                            ['Chantier', r.site ? <Link href={`/chantiers/${r.site.id}`}>{r.site.title}</Link> : null],
                            ['Promoteur', r.promoter.id ? <Link href={`/promoteurs/${r.promoter.id}`}>{r.promoter.name}</Link> : r.promoter.name],
                            ['Auteur', r.author],
                            ['Publié le', r.publishedAt],
                            ['Signalé le', r.reportedAt],
                        ]}
                    />
                </Block>

                <Block icon="history" title="Versions précédentes" count={`${r.versions.length}`}>
                    <RelationTable columns={['Version', 'Date', 'Note', 'Satisfaction', 'Commentaire']} empty={{ icon: 'history', title: 'Aucune modification', text: 'L’auteur n’a pas modifié cet avis.' }} rows={r.versions.map((v) => ({ key: String(v.version), cells: [String(v.version), v.date ?? '—', `${v.score} / 5`, v.satisfaction_tag ?? '—', v.comment ?? '—'] }))} />
                </Block>

                <Block icon="shield" title="Journal de modération">
                    <RelationTable columns={['Date', 'Décision', 'Motif', 'Par']} empty={{ icon: 'shield', title: 'Aucune décision', text: 'Cet avis n’a pas encore été modéré.' }} rows={r.history.map((h) => ({ key: h.id, cells: [h.date ?? '—', h.label, h.reason ?? '—', h.by ?? '—'] }))} />
                </Block>
            </div>

            {decision && <FormDialog title={decision.title} body={decision.body} icon={decision.icon} tone={decision.tone} okLabel={decision.ok} url={`${base}/${decision.path}`} extra={decision.extra} onClose={() => setDecision(null)} fields={[{ name: 'reason', label: 'Motif', type: 'textarea', required: true }]} />}
        </>
    );
}
