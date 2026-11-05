import { RelationTable, Timeline, stack } from '@/components/promoters/RelationTabs';
import { StackCell, TextCell } from '@/components/list/Table';
import StatusBadge from '@/components/ui/StatusBadge';
import { Block, TileGrid } from '@/components/workers/blocks';
import { formatDate, formatNumber } from '@/lib/format';
import type { Site, SiteCan, SiteRelations } from '@/types/sites';
import { router } from '@inertiajs/react';
import type { SiteActions } from './useSiteActions';

type Props = { site: Site; relations: SiteRelations; can: SiteCan; actions: SiteActions };

const day = (value: string | null) => (value ? formatDate(value) : '—');

/** Onglets de la fiche chantier : équipe et pointages, incidents, documents et historique. */
export default function SiteRelationTabs({ site: s, relations, can, actions }: Props) {
    if (relations.kind === 'team') {
        const t = relations.today;

        return (
            <div style={stack}>
                <TileGrid
                    tiles={[
                        { label: t.label, value: `${t.present} / ${t.total}`, icon: 'how_to_reg', tone: 'success', sub: t.isOpen ? 'Présents' : (t.closedMessage ?? 'Pointage fermé') },
                        { label: 'Absents', value: String(t.absent), icon: 'person_off', tone: 'danger' },
                        { label: 'À pointer', value: String(t.pending), icon: 'pending_actions', tone: 'warning' },
                        { label: 'Journée', value: t.validated ? 'Validée' : 'Non validée', icon: 'verified', tone: 'info' },
                    ]}
                />
                <Block icon="groups" title="Équipe" count={`${relations.members.length} ouvrier(s)`}>
                    <RelationTable
                        columns={['Ouvrier', 'Poste', 'Depuis', 'Tarif journalier', 'Statut', 'Remplacement']}
                        empty={{ icon: 'groups', title: 'Aucune équipe', text: 'L’équipe apparaîtra après le paiement de l’acompte.' }}
                        rows={relations.members.map((m) => ({
                            key: m.id,
                            cells: [<StackCell main={m.name} sub={m.replaces ? `Remplace ${m.replaces}` : m.trade} strong />, <TextCell>{m.role}</TextCell>, <TextCell>{day(m.since)}</TextCell>, <TextCell>{`${formatNumber(m.dailyRate)} FCFA`}</TextCell>, <TextCell>{m.status}</TextCell>, <TextCell muted={!m.replacement}>{m.replacement ?? '—'}</TextCell>],
                            actions: [{ icon: 'visibility', label: 'Voir la fiche', onClick: () => router.visit(`/ouvriers/${m.workerId}`) }],
                        }))}
                    />
                </Block>
                <Block icon="calendar_month" title="Journées passées" count="30 dernières">
                    <RelationTable
                        columns={['Jour', 'Présents', 'Taux de présence', 'Validation']}
                        empty={{ icon: 'calendar_month', title: 'Aucune journée', text: 'Les journées pointées apparaîtront ici.' }}
                        rows={relations.days.map((d) => ({ key: d.date, cells: [<TextCell strong>{d.label}</TextCell>, <TextCell>{`${d.present} / ${d.total}`}</TextCell>, <TextCell>{`${d.rate} %`}</TextCell>, <StatusBadge label={d.validated ? 'Validée' : 'À valider'} tone={d.validated ? 'g' : 'a'} />] }))}
                    />
                </Block>
            </div>
        );
    }

    if (relations.kind === 'incidents') {
        return (
            <Block icon="report" title="Incidents" count={String(relations.rows.length)} action={can.update && !s.archived ? { label: 'Déclarer', icon: 'add', onClick: () => actions.incident(s, relations.types, relations.severities) } : null}>
                <RelationTable
                    columns={['Référence', 'Date', 'Type', 'Gravité', 'Description', 'Statut', 'Résolution']}
                    empty={{ icon: 'report', title: 'Aucun incident', text: 'Les incidents déclarés apparaîtront ici.' }}
                    rows={relations.rows.map((i) => ({
                        key: i.id,
                        cells: [<TextCell strong>{i.reference}</TextCell>, <TextCell>{day(i.occurredAt)}</TextCell>, <TextCell>{i.type}</TextCell>, <StatusBadge label={i.severity.label} tone={i.severity.tone} />, <TextCell>{i.description}</TextCell>, <StatusBadge label={i.status.label} tone={i.status.tone} />, <StackCell main={i.resolution ?? '—'} sub={i.resolvedBy ? `${i.resolvedBy} · ${day(i.resolvedAt)}` : undefined} />],
                        actions: can.update
                            ? [
                                  ...(i.status.value === 'ouvert' ? [{ icon: 'play_circle', label: 'Prendre en charge', onClick: () => actions.startIncident(s, i.id) }] : []),
                                  ...(['ouvert', 'en_traitement'].includes(i.status.value) ? [{ icon: 'check_circle', label: 'Résoudre', onClick: () => actions.resolveIncident(s, i.id, i.reference) }] : []),
                              ]
                            : [],
                    }))}
                />
            </Block>
        );
    }

    if (relations.kind === 'documents') {
        return (
            <Block icon="folder_shared" title="Documents du chantier" count={String(relations.rows.length)}>
                <RelationTable
                    columns={['Document', 'Fichier', 'Ajouté le']}
                    empty={{ icon: 'folder_shared', title: 'Aucun document', text: 'Les documents déposés par le promoteur apparaîtront ici.' }}
                    rows={relations.rows.map((d) => ({
                        key: d.id,
                        cells: [<TextCell strong>{d.label}</TextCell>, <TextCell>{d.files.map((f) => f.name).join(', ') || '—'}</TextCell>, <TextCell>{day(d.addedAt)}</TextCell>],
                        actions: d.files.map((f) => ({ icon: 'open_in_new', label: `Ouvrir ${f.name}`, onClick: () => window.open(f.url, '_blank', 'noopener') })),
                    }))}
                />
            </Block>
        );
    }

    return <Timeline rows={relations.rows} pagination={relations.pagination} filters={[]} active={null} param="origine" emptyText="Aucun événement sur ce chantier." />;
}
