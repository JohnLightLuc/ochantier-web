import { ProgressCell, StackCell, TextCell } from '@/components/list/Table';
import { fcfa, useMatchingActions } from '@/components/matching/useMatchingActions';
import StatusBadge from '@/components/ui/StatusBadge';
import { DataTable, TileGrid } from '@/components/workers/blocks';
import { formatDate } from '@/lib/format';
import type { OfferInfo, SelectionInfo } from '@/types/matching';
import { Head, router } from '@inertiajs/react';

type Props = { rows: { offer: OfferInfo; selection: SelectionInfo }[] };

/** Sélections en attente d’une décision humaine. */
export default function MatchingToValidate({ rows }: Props) {
    const actions = useMatchingActions();
    const count = (status: string) => rows.filter((r) => r.selection.status.label === status).length;

    return (
        <>
            <Head title="Matchs à valider" />
            <TileGrid
                tiles={[
                    { label: 'Sélections à traiter', value: String(rows.length), icon: 'hourglass_top', tone: 'warning' },
                    { label: 'Profils sélectionnés', value: String(rows.reduce((a, r) => a + r.selection.main.length, 0)), icon: 'group', tone: 'purple' },
                    { label: 'En attente de validation', value: String(count('En attente de validation')), icon: 'pending_actions', tone: 'warning' },
                    { label: 'Corrections demandées', value: String(count('Correction demandée')), icon: 'rule', tone: 'orange' },
                ]}
            />
            <DataTable
                icon="pending_actions"
                title="Matchs à valider"
                count={`${rows.length} sélection(s)`}
                minWidth={1300}
                columns={['Besoin', 'Chantier', 'Métier', 'Profils', 'Score moyen', 'Budget estimé', 'Statut', 'Par', 'Mise à jour']}
                empty={{ icon: 'task_alt', title: 'Aucun match à valider', text: 'Les sélections soumises apparaîtront ici.' }}
                rows={rows.map(({ offer, selection: s }) => ({
                    key: String(offer.id),
                    onOpen: () => router.visit(`/matching/besoins/${offer.id}/selection`),
                    cells: [
                        <TextCell strong>{offer.reference}</TextCell>,
                        <StackCell main={offer.site.name} sub={offer.site.promoter} />,
                        <TextCell>{offer.trade}</TextCell>,
                        <StackCell main={`${s.main.length} / ${offer.rest}`} sub={s.main.map((p) => p.name).join(', ')} />,
                        <ProgressCell value={s.main.length ? Math.round(s.main.reduce((a, p) => a + p.score, 0) / s.main.length) : 0} />,
                        <TextCell strong={s.budget.over}>{fcfa(s.budget.total)}</TextCell>,
                        <StatusBadge label={s.status.label} tone={s.status.tone} />,
                        <TextCell>{s.author}</TextCell>,
                        <TextCell>{formatDate(s.updatedOn)}</TextCell>,
                    ],
                    actions: [
                        { icon: 'fact_check', label: 'Examiner et valider', onClick: () => actions.validate(offer, s) },
                        { icon: 'forward', label: 'Valider et transmettre', onClick: () => actions.transmit(offer) },
                    ],
                    menu: [
                        { icon: 'rule', label: 'Demander une correction', onClick: () => actions.correction(offer, s) },
                        { icon: 'block', label: 'Rejeter la sélection', danger: true, onClick: () => actions.rejectSelection(offer) },
                    ],
                }))}
            />
            {actions.dialog}
        </>
    );
}
