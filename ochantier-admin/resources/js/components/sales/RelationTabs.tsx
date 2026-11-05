import { Bar, Pages, Pills, RelationTable, stack, visit } from '@/components/promoters/RelationTabs';
import { ProgressCell, StackCell, TextCell } from '@/components/list/Table';
import StatusBadge from '@/components/ui/StatusBadge';
import { Block, Chips } from '@/components/workers/blocks';
import { formatDate } from '@/lib/format';
import type { SalesAgent, SalesCan, SalesFieldOptions, SalesRelations } from '@/types/sales';
import { router } from '@inertiajs/react';
import type { SalesActions } from './useSalesActions';

type Props = { agent: SalesAgent; relations: SalesRelations; options: SalesFieldOptions | null; can: SalesCan; actions: SalesActions };

const day = (value: string | null) => (value ? formatDate(value) : '—');

/** Mois proposés pour un objectif : le mois en cours et les cinq suivants. */
function nextMonths(current: string) {
    const [year, month] = current.split('-').map(Number);

    return Array.from({ length: 6 }, (_, i) => {
        const date = new Date(year, month - 1 + i, 1);
        const label = date.toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' });

        return { value: `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`, label: label.charAt(0).toUpperCase() + label.slice(1) };
    });
}

/** Onglets terrain de la fiche commercial : ouvriers suivis, prospects, zones et objectifs. */
export default function SalesRelationTabs({ agent: a, relations, options, can, actions }: Props) {
    const editable = a.status.value !== 'termine';

    if (relations.kind === 'workers') {
        const colleagues = options?.colleagues ?? [];

        return (
            <div style={stack}>
                <Bar count={`${relations.pagination.total} ouvrier(s)`}>
                    <Pills items={relations.filters.map((f) => ({ label: f.label, count: f.count, on: f.value === relations.status, onClick: () => visit({ statut: f.value }) }))} />
                </Bar>
                {can.update && relations.pagination.total > 0 && (
                    <div>
                        <button type="button" onClick={() => actions.transfer(a, colleagues)} style={{ border: 0, background: 'transparent', padding: 0, color: 'var(--c-primary)', fontWeight: 'var(--fw-semibold)', cursor: 'pointer' }}>
                            Transférer tout le portefeuille
                        </button>
                    </div>
                )}
                <RelationTable
                    columns={['Ouvrier', 'Métier', 'Commune', 'Statut', 'Transmis le']}
                    empty={{ icon: 'engineering', title: 'Aucun ouvrier suivi', text: 'Les ouvriers transmis par ce commercial apparaîtront ici.' }}
                    rows={relations.rows.map((w) => ({
                        key: w.id,
                        cells: [<StackCell main={w.name} sub={w.code} strong />, <TextCell muted={!w.trade}>{w.trade ?? '—'}</TextCell>, <TextCell muted={!w.commune}>{w.commune ?? '—'}</TextCell>, <StatusBadge label={w.status.label} tone={w.status.tone} />, <TextCell>{day(w.submittedAt)}</TextCell>],
                        actions: [
                            { icon: 'visibility', label: 'Voir la fiche', onClick: () => router.visit(`/ouvriers/${w.id}`) },
                            ...(can.update ? [{ icon: 'move_down', label: 'Transférer', onClick: () => actions.transfer(a, colleagues, { id: w.id, name: w.name }) }] : []),
                        ],
                    }))}
                />
                <Pages pagination={relations.pagination} />
                {relations.transfers.length > 0 && (
                    <Block icon="swap_horiz" title="Derniers transferts">
                        <RelationTable
                            columns={['Date', 'Ouvrier', 'De', 'Vers', 'Motif', 'Par']}
                            empty={{ icon: 'swap_horiz', title: '', text: '' }}
                            rows={relations.transfers.map((t) => ({
                                key: t.id,
                                cells: [<TextCell>{day(t.at)}</TextCell>, <TextCell strong>{t.worker.full_name}</TextCell>, <TextCell>{t.from ?? '—'}</TextCell>, <TextCell>{t.to ?? '—'}</TextCell>, <TextCell>{t.reason}</TextCell>, <TextCell>{t.by}</TextCell>],
                            }))}
                        />
                    </Block>
                )}
            </div>
        );
    }

    if (relations.kind === 'prospects') {
        return (
            <div style={stack}>
                <Bar count={`${relations.pagination.total} prospect(s)`}>
                    <Pills items={relations.filters.map((f) => ({ label: f.label, count: f.count, on: f.value === relations.status, onClick: () => visit({ statut: f.value }) }))} />
                </Bar>
                <RelationTable
                    columns={['Prospect', 'Métier pressenti', 'Commune', 'Statut', 'Avancement', 'Dernière relance', 'Ajouté le']}
                    empty={{ icon: 'person_search', title: 'Aucun prospect', text: 'Les prospects saisis dans l’application apparaîtront ici.' }}
                    rows={relations.rows.map((p) => ({
                        key: p.id,
                        cells: [<StackCell main={p.name} sub={p.phone ?? undefined} strong />, <TextCell muted={!p.trade}>{p.trade ?? '—'}</TextCell>, <TextCell muted={!p.commune}>{p.commune ?? '—'}</TextCell>, <StatusBadge label={p.status.label} tone={p.status.tone} />, <ProgressCell value={p.percent} />, <TextCell muted={!p.lastFollowupAt}>{day(p.lastFollowupAt)}</TextCell>, <TextCell>{day(p.createdAt)}</TextCell>],
                    }))}
                />
                <Pages pagination={relations.pagination} />
            </div>
        );
    }

    if (relations.kind === 'zones') {
        const secondary = a.zoneList.filter((z) => !z.primary);

        return (
            <div style={stack}>
                <Block icon="location_on" title="Zones attribuées" action={can.update && editable && options ? { label: 'Attribuer', icon: 'add', onClick: () => actions.addZone(a, options.communes) } : null}>
                    <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-3)' }}>
                        <div>
                            <b>Zone principale : </b>
                            {a.zone ?? 'Aucune'}
                        </div>
                        <Chips values={secondary.map((z) => z.name)} empty="Aucune zone secondaire." onRemove={can.update && editable ? (name) => actions.removeZone(a, secondary.find((z) => z.name === name)!) : undefined} />
                    </div>
                </Block>
                <Block icon="edit_location_alt" title="Demandes de changement">
                    <RelationTable
                        columns={['Reçue le', 'Communes demandées', 'Statut', 'Décision']}
                        empty={{ icon: 'edit_location_alt', title: 'Aucune demande', text: 'Les demandes envoyées depuis l’application apparaîtront ici.' }}
                        rows={relations.requests.map((r) => ({
                            key: r.id,
                            cells: [<TextCell>{day(r.createdAt)}</TextCell>, <TextCell>{r.communes.map((c) => c.name).join(', ')}</TextCell>, <StatusBadge label={r.status.label} tone={r.status.tone} />, <StackCell main={r.comment ?? '—'} sub={r.decidedBy ? `${r.decidedBy} · ${day(r.decidedAt)}` : undefined} />],
                            actions:
                                can.validate && r.status.value === 'en_attente'
                                    ? [
                                          ...(editable ? [{ icon: 'check_circle', label: 'Accepter', onClick: () => actions.acceptZoneRequest(a, r) }] : []),
                                          { icon: 'cancel', label: 'Refuser', onClick: () => actions.refuseZoneRequest(a, r) },
                                      ]
                                    : [],
                        }))}
                    />
                </Block>
            </div>
        );
    }

    const months = nextMonths(relations.currentMonth);

    return (
        <Block icon="flag" title="Objectifs mensuels" count="Ouvriers validés" action={can.update && editable ? { label: 'Fixer un objectif', icon: 'add', onClick: () => actions.objective(a, months) } : null}>
            <RelationTable
                columns={['Mois', 'Objectif', 'Réalisé', 'Avancement', 'Commentaire']}
                empty={{ icon: 'flag', title: 'Aucun objectif', text: 'Fixez un objectif pour qu’il apparaisse dans l’application du commercial.' }}
                rows={relations.rows.map((o) => ({
                    key: o.month,
                    cells: [<TextCell strong>{o.label}</TextCell>, <TextCell>{o.target}</TextCell>, <TextCell>{o.achieved}</TextCell>, <ProgressCell value={o.percent} />, <TextCell muted={!o.comment}>{o.comment ?? '—'}</TextCell>],
                    actions: can.update && editable && o.canEdit ? [{ icon: 'edit', label: 'Modifier', onClick: () => actions.objective(a, months, o) }] : [],
                }))}
            />
        </Block>
    );
}
