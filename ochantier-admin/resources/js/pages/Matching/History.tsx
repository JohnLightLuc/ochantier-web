import { FilterSelect } from '@/components/list/ListToolbar';
import { ProgressCell, TextCell } from '@/components/list/Table';
import StatusBadge from '@/components/ui/StatusBadge';
import { Block, DataTable } from '@/components/workers/blocks';
import { HistoryList, type HistoryEvent } from '@/components/workers/tabs';
import { formatDate } from '@/lib/format';
import type { Badge } from '@/types';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

type Cycle = { id: number; reference: string; date: string; time: string; scope: string; needs: number; workers: number; recommendations: number; selections: number; validations: number; assignments: number; successes: number; failures: number; duration: number; initiator: string; algorithm: string; zone: string; trade: string; status: Badge };
type Notification = { id: number; type: string; recipient: string; channel: string; date: string; time: string; content: string; action: string; status: Badge };

type Props = { cycles: Cycle[]; notifications: Notification[]; audit: HistoryEvent[]; filterOptions: { scopes: string[] }; today: string };

const days = (from: string, to: string) => Math.round((new Date(`${to}T12:00:00`).getTime() - new Date(`${from}T12:00:00`).getTime()) / 864e5);

/** Cycles de Matching, notifications et journal d’audit. */
export default function MatchingHistory({ cycles, notifications, audit, filterOptions, today }: Props) {
    const [f, setF] = useState<Record<string, string>>({});
    const set = (k: string) => (v: string) => setF((x) => ({ ...x, [k]: v }));
    const opts = (values: string[]) => [...new Set(values)].map((v) => ({ value: v, label: v }));
    const list = cycles.filter(
        (c) =>
            (!f.per || days(c.date, today) <= Number(f.per)) &&
            (!f.st || c.status.label === f.st) &&
            (!f.by || c.initiator === f.by) &&
            (!f.algo || c.algorithm === f.algo) &&
            (!f.met || c.trade === f.met) &&
            (!f.zone || c.zone === f.zone) &&
            (!f.pm || c.scope.includes(f.pm)) &&
            (!f.res || (f.res === 'Avec réussites' ? c.successes > 0 : c.successes === 0)),
    );

    return (
        <>
            <Head title="Historique des matchings" />
            <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2)' }}>
                <FilterSelect label="Période" value={f.per ?? ''} options={[{ value: '7', label: '7 derniers jours' }, { value: '30', label: '30 derniers jours' }, { value: '90', label: '90 derniers jours' }]} onChange={set('per')} />
                <FilterSelect label="Chantier / promoteur" value={f.pm ?? ''} options={opts(filterOptions.scopes)} onChange={set('pm')} />
                <FilterSelect label="Métier" value={f.met ?? ''} options={opts(cycles.map((c) => c.trade))} onChange={set('met')} />
                <FilterSelect label="Zone" value={f.zone ?? ''} options={opts(cycles.map((c) => c.zone))} onChange={set('zone')} />
                <FilterSelect label="Statut" value={f.st ?? ''} options={opts(['Terminé', 'Partiellement terminé', 'Échec', 'Annulé'])} onChange={set('st')} />
                <FilterSelect label="Initiateur" value={f.by ?? ''} options={opts(cycles.map((c) => c.initiator))} onChange={set('by')} />
                <FilterSelect label="Version" value={f.algo ?? ''} options={opts(cycles.map((c) => c.algorithm))} onChange={set('algo')} />
                <FilterSelect label="Résultat" value={f.res ?? ''} options={opts(['Avec réussites', 'Sans réussite'])} onChange={set('res')} />
            </div>
            <DataTable
                icon="history"
                title="Cycles de Matching"
                count={`${list.length} cycle(s)`}
                minWidth={1700}
                columns={['Cycle', 'Date', 'Périmètre', 'Besoins', 'Ouvriers', 'Recommandations', 'Sélections', 'Validations', 'Affectations', 'Réussis', 'Échecs', 'Taux', 'Durée', 'Initiateur', 'Version', 'Statut']}
                empty={{ icon: 'history', title: 'Aucun cycle', text: 'Aucun cycle ne correspond aux filtres.' }}
                rows={list.map((c) => ({
                    key: String(c.id),
                    cells: [
                        <TextCell strong>{c.reference}</TextCell>,
                        <TextCell>
                            {formatDate(c.date)} · {c.time}
                        </TextCell>,
                        <span style={{ display: 'block', minWidth: 200 }}>{c.scope}</span>,
                        ...[c.needs, c.workers, c.recommendations, c.selections, c.validations, c.assignments, c.successes, c.failures].map((n) => <TextCell>{n}</TextCell>),
                        <ProgressCell value={c.assignments ? Math.round((c.successes / c.assignments) * 100) : 0} />,
                        <TextCell>{c.duration} s</TextCell>,
                        <TextCell>{c.initiator}</TextCell>,
                        <TextCell>{c.algorithm}</TextCell>,
                        <StatusBadge label={c.status.label} tone={c.status.tone} />,
                    ],
                    actions: ['Terminé', 'Partiellement terminé'].includes(c.status.label) ? [{ icon: 'list', label: 'Consulter les résultats', onClick: () => router.visit('/matching/resultats') }] : [],
                }))}
            />
            <DataTable
                icon="notifications"
                title="Notifications du Matching"
                count={`${notifications.length} notification(s)`}
                minWidth={1200}
                columns={['Type', 'Destinataire', 'Canal', 'Date', 'Contenu', 'Action associée', 'Statut']}
                empty={{ icon: 'notifications', title: 'Aucune notification', text: '' }}
                rows={notifications.map((n) => ({
                    key: String(n.id),
                    cells: [
                        <TextCell strong>{n.type}</TextCell>,
                        <TextCell>{n.recipient}</TextCell>,
                        <TextCell>{n.channel}</TextCell>,
                        <TextCell>
                            {formatDate(n.date)} · {n.time}
                        </TextCell>,
                        <span style={{ display: 'block', minWidth: 240 }}>{n.content}</span>,
                        <TextCell>{n.action}</TextCell>,
                        <StatusBadge label={n.status.label} tone={n.status.tone} />,
                    ],
                    actions: [],
                }))}
            />
            <Block icon="policy" title="Journal d’audit" count={`${audit.length} événement(s)`}>
                {audit.length ? <HistoryList activities={audit} /> : <span style={{ color: 'var(--c-muted)' }}>Aucune action enregistrée pour le moment.</span>}
            </Block>
        </>
    );
}
