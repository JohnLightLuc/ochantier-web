import { FilterSelect } from '@/components/list/ListToolbar';
import { Bars, TileGrid } from '@/components/workers/blocks';
import type { Success } from '@/types/matching';
import { Head } from '@inertiajs/react';
import { useState } from 'react';

const PERIODS = [
    { value: '7', label: '7 derniers jours' },
    { value: '30', label: '30 derniers jours' },
    { value: '90', label: '90 derniers jours' },
    { value: '365', label: '12 derniers mois' },
];
const FACTOR: Record<string, number> = { '7': 0.12, '30': 0.4, '90': 1, '365': 3.6 };

/** Indicateurs de performance du Matching ; les volumes suivent la période choisie. */
export default function MatchingAnalyses({ success: s }: { success: Success }) {
    const [period, setPeriod] = useState('90');
    const n = (x: number) => Math.max(0, Math.round(x * FACTOR[period]));
    const selectionRate = Math.round((s.selections / Math.max(1, s.recommendations)) * 100);

    return (
        <>
            <Head title="Analyses du Matching" />
            <div style={{ display: 'flex' }}>
                <FilterSelect label="Période" value={period} options={PERIODS} onChange={(v) => setPeriod(v || '90')} />
            </div>
            <TileGrid
                tiles={[
                    { label: 'Besoins traités', value: String(n(69)), icon: 'assignment', tone: 'info' },
                    { label: 'Profils analysés', value: String(n(1153)), icon: 'groups', tone: 'info' },
                    { label: 'Recommandations produites', value: String(n(s.recommendations)), icon: 'recommend', tone: 'purple' },
                    { label: 'Taux de sélection', value: `${selectionRate} %`, icon: 'check_box', tone: 'purple', bar: selectionRate },
                    { label: 'Taux de validation', value: `${s.acceptance} %`, icon: 'verified', tone: 'success', bar: s.acceptance },
                    { label: 'Taux de confirmation', value: `${s.confirmation} %`, icon: 'how_to_reg', tone: 'success', bar: s.confirmation },
                    { label: 'Taux de démarrage', value: `${s.start} %`, icon: 'play_circle', tone: 'success', bar: s.start },
                    { label: 'Taux de réussite', value: `${s.rate} %`, icon: 'target', tone: 'success', bar: s.rate, sub: `${s.started} démarrés / ${s.confirmed} confirmés (premier pointage validé)` },
                    { label: 'Fin de mission réussie', value: `${s.completion} %`, icon: 'flag', tone: 'success', bar: s.completion },
                    { label: 'Délai moyen de Matching', value: '1,4 j', icon: 'avg_time', tone: 'info' },
                    { label: 'Délai moyen d’affectation', value: '2,8 j', icon: 'schedule', tone: 'info' },
                    { label: 'Refus des ouvriers', value: '9 %', icon: 'person_off', tone: 'danger' },
                    { label: 'Refus des promoteurs', value: '4 %', icon: 'domain_disabled', tone: 'danger' },
                    { label: 'Taux d’annulation', value: '3 %', icon: 'cancel', tone: 'neutral' },
                ]}
            />
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,420px),1fr))', gap: 'var(--space-4)' }}>
                <Bars title="Évolution du taux de réussite" sub="Démarrages effectifs / affectations confirmées" unit=" %" data={[['Avr.', 81], ['Mai', 84], ['Juin', 86], ['Juil.', 83], ['Août', 88], ['Sept.', s.rate]]} />
                <Bars title="Répartition par métier" sub="Recommandations" data={[['Maçon', n(64)], ['Électricien', n(51)], ['Plombier', n(33)], ['Peintre', n(28)], ['Manœuvre', n(47)], ['Coffreur', n(22)]]} />
                <Bars title="Répartition par zone" sub="Affectations issues du Matching" data={[['Abidjan Est', n(21)], ['Abidjan Centre', n(12)], ['Abidjan Nord-Ouest', n(17)], ['Abidjan Sud', n(14)], ['Intérieur', n(4)]]} />
                <Bars title="Performance par chantier" sub="Taux de réussite" unit=" %" data={[['Tour Horizon', 91], ['Oliviers', 88], ['Belle Rive', 79], ['Complexe SA', 85], ['Koumassi', 72]]} />
                <Bars title="Performance par promoteur" sub="Taux de réussite" unit=" %" data={[['SICOGI', 90], ['Groupement ADO', 82], ['Promoteurs associés', 77]]} />
                <Bars title="Scores moyens" sub="Par métier" data={[['Maçon', 78], ['Électricien', 74], ['Plombier', 76], ['Peintre', 81], ['Manœuvre', 83]]} />
                <Bars title="Conversion vers l’affectation" sub="Du cycle au démarrage" data={[['Recommandés', n(s.recommendations)], ['Sélectionnés', n(s.selections)], ['Validés', n(s.validations)], ['Confirmés', n(s.confirmed)], ['Démarrés', n(s.started)]]} />
                <Bars title="Causes de rejet" sub="Recommandations rejetées" color="var(--c-accent-600)" data={[['Coût', n(14)], ['Distance', n(9)], ['Disponibilité', n(11)], ['Expérience', n(6)], ['Refus promoteur', n(4)]]} />
                <Bars title="Causes d’échec après validation" sub="Affectations non démarrées" color="var(--c-accent-600)" data={[['Refus ouvrier', n(5)], ['Absence J1', n(3)], ['Annulation', n(2)], ['Conflit planning', n(1)]]} />
            </div>
        </>
    );
}
