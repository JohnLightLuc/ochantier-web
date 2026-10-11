import RecommendationTable from '@/components/matching/RecommendationTable';
import { useMatchingActions } from '@/components/matching/useMatchingActions';
import type { ResultRow } from '@/types/matching';
import { Head } from '@inertiajs/react';

type Props = { rows: ResultRow[]; sorts: Record<string, string>; levels: string[] };

/** Recommandations actives de tous les besoins éligibles. */
export default function MatchingResults({ rows, sorts, levels }: Props) {
    const actions = useMatchingActions();
    const needs = new Set(rows.map((r) => r.offerId)).size;

    return (
        <>
            <Head title="Résultats du Matching" />
            <RecommendationTable
                rows={rows}
                withNeed
                levels={levels}
                sorts={sorts}
                actions={actions}
                title="Recommandations actives"
                count={(shown, total) => `${shown} / ${total} recommandation(s) · ${needs} besoin(s)`}
                tools={[{ label: 'Exporter', icon: 'download', onClick: () => (window.location.href = '/matching/resultats/export') }]}
            />
            {actions.dialog}
        </>
    );
}
