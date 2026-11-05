import { RelationTable, Timeline, stack } from '@/components/promoters/RelationTabs';
import { StackCell, TextCell } from '@/components/list/Table';
import StatusBadge from '@/components/ui/StatusBadge';
import { Block, KeyValues } from '@/components/workers/blocks';
import { formatDate, formatNumber } from '@/lib/format';
import type { Offer, OfferRelations } from '@/types/offers';
import { router } from '@inertiajs/react';

const day = (value: string | null) => (value ? formatDate(value) : '—');
const fcfa = (n: number) => `${formatNumber(n)} FCFA`;

/** Vue d’ensemble : informations et métiers demandés. */
export function OverviewTab({ offer: o }: { offer: Offer }) {
    return (
        <div style={stack}>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,360px),1fr))', gap: 'var(--space-4)' }}>
                <Block icon="info" title="Informations">
                    <KeyValues
                        items={[
                            ['Besoin', o.title],
                            ['Type', o.type],
                            ['Urgence', o.urgency],
                            ['Description', o.description],
                            ['Livrables', o.deliverables],
                        ]}
                    />
                </Block>
                <Block icon="construction" title="Chantier" action={{ label: 'Voir le chantier', icon: 'open_in_new', onClick: () => router.visit(`/chantiers/${o.site.id}`) }}>
                    <KeyValues
                        items={[
                            ['Chantier', o.site.label],
                            ['Référence', o.site.reference],
                            ['Commune', o.site.commune],
                            ['Promoteur', o.promoter.name],
                        ]}
                    />
                </Block>
            </div>
            <Block icon="engineering" title="Métiers et effectifs" count={`${o.assigned} / ${o.required} pourvus`}>
                <RelationTable
                    columns={['Métier', 'Pourvus', 'Niveau', 'Expérience', 'Période', 'Budget indicatif', 'Statut']}
                    empty={{ icon: 'engineering', title: 'Aucun métier', text: '' }}
                    rows={o.needs.map((n) => ({
                        key: n.id,
                        cells: [<StackCell main={n.trade ?? '—'} sub={n.reference} strong />, <TextCell>{`${n.assigned} / ${n.headcount}`}</TextCell>, <TextCell muted={!n.level}>{n.level ?? '—'}</TextCell>, <TextCell muted={n.experience == null}>{n.experience != null ? `${n.experience} ans` : '—'}</TextCell>, <TextCell>{`${day(n.startsOn)} → ${day(n.endsOn)}`}</TextCell>, <TextCell muted={!n.budget}>{n.budget ? `${fcfa(n.budget)} / jour` : '—'}</TextCell>, <TextCell>{n.status}</TextCell>],
                    }))}
                />
            </Block>
        </div>
    );
}

/** Onglets de la fiche : propositions et ouvriers, documents, historique. */
export default function OfferRelationTabs({ relations }: { relations: OfferRelations }) {
    if (relations.kind === 'workers') {
        return (
            <div style={stack}>
                <Block icon="send" title="Propositions au promoteur" count={String(relations.proposals.length)}>
                    <RelationTable
                        columns={['Référence', 'Envoyée le', 'Ouvriers', 'Montant', 'Acompte', 'Statut', 'Décision']}
                        empty={{ icon: 'send', title: 'Aucune proposition', text: 'Les propositions issues du matching apparaîtront ici.' }}
                        rows={relations.proposals.map((p) => ({
                            key: p.id,
                            cells: [<TextCell strong>{p.reference}</TextCell>, <TextCell>{day(p.publishedAt)}</TextCell>, <TextCell>{`${p.proposed} / ${p.requested}${p.partial ? ' (partielle)' : ''}`}</TextCell>, <TextCell>{fcfa(p.total)}</TextCell>, <TextCell>{fcfa(p.deposit)}</TextCell>, <StatusBadge label={p.status.label} tone={p.status.tone} />, <StackCell main={p.refusal ?? '—'} sub={p.decidedAt ? day(p.decidedAt) : undefined} />],
                        }))}
                    />
                </Block>
                <Block icon="groups" title="Ouvriers affectés" count={String(relations.assigned.length)}>
                    <RelationTable
                        columns={['Ouvrier', 'Métier', 'Période', 'Statut']}
                        empty={{ icon: 'groups', title: 'Aucun ouvrier affecté', text: 'Les ouvriers sont affectés après le paiement de l’acompte.' }}
                        rows={relations.assigned.map((a) => ({
                            key: a.id,
                            cells: [<TextCell strong>{a.name}</TextCell>, <TextCell>{a.trade ?? '—'}</TextCell>, <TextCell>{`${day(a.startsOn)} → ${day(a.endsOn)}`}</TextCell>, <TextCell>{a.status}</TextCell>],
                            actions: [{ icon: 'visibility', label: 'Voir la fiche', onClick: () => router.visit(`/ouvriers/${a.workerId}`) }],
                        }))}
                    />
                </Block>
            </div>
        );
    }

    if (relations.kind === 'documents') {
        return (
            <Block icon="folder_shared" title="Documents" count={String(relations.rows.length)}>
                <RelationTable
                    columns={['Document', 'Fichier', 'Ajouté le']}
                    empty={{ icon: 'folder_shared', title: 'Aucun document', text: 'Les documents du besoin apparaîtront ici.' }}
                    rows={relations.rows.map((d) => ({ key: d.id, cells: [<TextCell strong>{d.label}</TextCell>, <TextCell>{d.files.map((f) => f.name).join(', ') || '—'}</TextCell>, <TextCell>{day(d.addedAt)}</TextCell>], actions: d.files.map((f) => ({ icon: 'open_in_new', label: `Ouvrir ${f.name}`, onClick: () => window.open(f.url, '_blank', 'noopener') })) }))}
                />
            </Block>
        );
    }

    return <Timeline rows={relations.rows} pagination={relations.pagination} filters={[]} active={null} param="origine" emptyText="Aucun événement sur ce besoin." />;
}
