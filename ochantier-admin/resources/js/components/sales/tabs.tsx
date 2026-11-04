import { Block, KeyValues, TileGrid } from '@/components/workers/blocks';
import { formatDate } from '@/lib/format';
import type { SalesShowProps } from '@/types/sales';
import { fcfa } from './useSalesActions';
import { BarChart, ChartGrid } from './visuals';

/** Vue d’ensemble : indicateurs du mois, évolution, métiers recrutés et résumé du compte. */
export function OverviewTab({ agent: a }: Pick<SalesShowProps, 'agent'>) {
    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)' }}>
            <TileGrid
                tiles={[
                    { label: 'Ouvriers enregistrés (total)', value: String(a.workersTotal), icon: 'engineering', tone: 'success' },
                    { label: 'Transmis ce mois', value: String(a.monthRegistered), icon: 'send', tone: 'info' },
                    { label: 'Validés ce mois', value: String(a.monthValidated), icon: 'verified', tone: 'success' },
                    { label: 'Taux de validation', value: a.validationRate != null ? `${a.validationRate} %` : '—', icon: 'percent', tone: 'info', bar: a.validationRate ?? 0 },
                    { label: 'Objectif du mois', value: a.objective ? `${a.objective.achieved} / ${a.objective.target}` : '—', icon: 'flag', tone: 'warning', bar: a.objective?.percent ?? 0 },
                    { label: 'Rang du mois', value: a.ranking ? `${a.ranking.rank} / ${a.ranking.total}` : '—', icon: 'leaderboard', tone: 'orange', sub: a.ranking ? 'Sur les ouvriers validés' : 'Non classé' },
                    { label: 'Commissions du mois', value: fcfa(a.monthCommissions), icon: 'payments', tone: 'success' },
                    { label: 'Commissions à verser', value: fcfa(a.unpaidCommissions), icon: 'account_balance_wallet', tone: 'warning' },
                    { label: 'Dernière connexion', value: a.lastLoginAt ? formatDate(a.lastLoginAt) : '—', icon: 'login', tone: 'neutral' },
                    { label: 'Dernière activité', value: a.lastActivityOn ? formatDate(a.lastActivityOn) : '—', icon: 'directions_walk', tone: 'neutral' },
                ]}
            />
            <ChartGrid>
                <BarChart title="Évolution des recrutements" sub="Ouvriers transmis par mois" bars={a.months.map((m) => ({ label: m.label, value: m.registered, tip: `${m.label} : ${m.registered} transmis · ${m.validated} validés` }))} />
                <BarChart title="Répartition par métier" sub="Ouvriers transmis" color="var(--c-accent-600)" bars={a.trades.map((t) => ({ label: t.label, value: t.count }))} />
            </ChartGrid>
        </div>
    );
}

/** Profil : coordonnées, zones et contrat. */
export function ProfileTab({ agent: a }: Pick<SalesShowProps, 'agent'>) {
    return (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,360px),1fr))', gap: 'var(--space-4)' }}>
            <Block icon="person" title="Coordonnées">
                <KeyValues
                    items={[
                        ['Prénom', a.firstName],
                        ['Nom', a.lastName],
                        ['Téléphone', a.phone],
                        ['Second téléphone', a.secondaryPhone],
                        ['E-mail', a.email],
                        ['Adresse', a.address],
                    ]}
                />
            </Block>
            <Block icon="badge" title="Contrat">
                <KeyValues
                    items={[
                        ['Matricule', a.code],
                        ['Statut du compte', a.status.label],
                        ['Vérification du dossier', a.verification],
                        ['Début', a.startedAt ? formatDate(a.startedAt) : null],
                        ['Fin', a.endedAt ? formatDate(a.endedAt) : null],
                        ['Superviseur', a.supervisor],
                        ['Créé par', a.createdBy],
                    ]}
                />
            </Block>
            <Block icon="location_on" title="Zones attribuées">
                <KeyValues
                    items={[
                        ['Zone principale', a.zone],
                        ['Zones secondaires', a.secondaryZones.join(', ')],
                    ]}
                />
            </Block>
        </div>
    );
}
