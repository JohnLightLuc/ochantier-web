import { ProgressCell, TextCell, LocationCell } from '@/components/list/Table';
import { AlertList, Block, Chips, DataTable, KeyValues, TileGrid, Toolbar, type ToolbarAction } from '@/components/workers/blocks';
import Button from '@/components/ui/Button';
import Icon from '@/components/ui/Icon';
import StatusBadge from '@/components/ui/StatusBadge';
import { useAdminUi } from '@/contexts/AdminUiContext';
import { formatDate } from '@/lib/format';
import type { PayoutLine, RankSummary } from '@/types/sales';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { formatLogin, useSalesAlerts, type SalesTabProps } from './tabs';
import { decimal, fcfa } from './useSalesActions';
import { BarChart, ChartGrid } from './visuals';

const pad = (n: number) => String(n).padStart(2, '0');

const DAY_TYPES: Record<string, [string, string, string]> = {
    Disponible: ['var(--c-success-bg)', 'var(--c-success-fg)', 'Dispo.'],
    'Partiellement disponible': ['var(--c-warning-bg)', 'var(--c-warning-fg)', 'Partiel'],
    'En mission terrain': ['var(--c-info-bg)', 'var(--c-info-fg)', 'Terrain'],
    'En congé': ['var(--c-purple-bg)', 'var(--c-purple-fg)', 'Congé'],
    Indisponible: ['var(--c-danger-bg)', 'var(--c-danger-fg)', 'Indispo.'],
    Repos: ['var(--c-neutral-bg)', 'var(--c-neutral-fg)', 'Repos'],
};

/** Zones et disponibilités : territoire, zones et communes, calendrier, périodes déclarées. */
export function TerritoryTab(props: SalesTabProps) {
    const { agent: a, periods, fieldActivities, options, actions, currentMonth, referenceDate } = props;
    const alerts = useSalesAlerts(props);
    const [month, setMonth] = useState(currentMonth);
    const [year, mon] = month.split('-').map(Number);
    const archived = a.isArchived;
    const closedAccount = ['suspended', 'deactivated', 'archived'].includes(a.status.value);
    const activityDays = new Set(fieldActivities.map((f) => f.date));
    const replacement = periods.find((p) => p.replacement && p.startsOn <= referenceDate && p.endsOn >= referenceDate)?.replacement;
    const daysInMonth = new Date(year, mon, 0).getDate();
    const offset = (new Date(year, mon - 1, 1).getDay() + 6) % 7;
    const move = (n: number) => {
        const d = new Date(year, mon - 1 + n, 1);
        setMonth(`${d.getFullYear()}-${pad(d.getMonth() + 1)}`);
    };
    const remove = (list: 'zones-secondaires' | 'communes' | 'metiers-prioritaires', label: string) => (archived ? undefined : (v: string) => actions.removeValue(a, list, v, label));

    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)' }}>
            <AlertList alerts={alerts} />
            {!archived && (
                <Toolbar
                    actions={[
                        { label: 'Transférer une zone', icon: 'move_down', onClick: () => actions.transferZone(a, options.colleagues) },
                        { label: 'Attribuer une zone', icon: 'add_location_alt', onClick: () => actions.addZone(a, options.communes) },
                        { label: 'Déclarer une indisponibilité', icon: 'event_busy', variant: 'primary', onClick: () => actions.unavailability(a, options.colleagues) },
                    ]}
                />
            )}
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,360px),1fr))', gap: 'var(--space-4)' }}>
                <Block icon="map" title="Territoire">
                    <KeyValues
                        items={[
                            ['Zone principale', a.zone],
                            ['Agence', a.agencyLabel],
                            ['Disponibilité actuelle', a.availability.label],
                            ['Remplacement temporaire', replacement ?? 'Aucun'],
                        ]}
                    />
                </Block>
                <Block icon="add_location" title="Zones secondaires" action={archived ? null : { label: 'Attribuer', icon: 'add', onClick: () => actions.addZone(a, options.communes) }}>
                    <Chips values={a.secondaryZones} onRemove={remove('zones-secondaires', 'Zone secondaire')} empty="Aucune zone secondaire." />
                </Block>
                <Block icon="location_city" title="Communes couvertes">
                    <Chips values={a.communes} onRemove={remove('communes', 'Commune couverte')} empty="Aucune commune." />
                </Block>
                <Block icon="handyman" title="Métiers prioritaires">
                    <Chips values={a.priorityTrades} onRemove={remove('metiers-prioritaires', 'Métier prioritaire')} empty="Aucun métier prioritaire." />
                </Block>
            </div>

            <div data-component="AvailabilityCalendar" style={{ border: '1px solid var(--c-line)', borderRadius: 'var(--radius-xl)', padding: 'var(--space-4)' }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2_5)', flexWrap: 'wrap', marginBottom: 'var(--space-3)' }}>
                    <Button icon="chevron_left" size="sm" variant="secondary" aria-label="Mois précédent" onClick={() => move(-1)} />
                    <b style={{ fontSize: 'var(--fs-xl)', minWidth: 170, textAlign: 'center' }}>{new Date(year, mon - 1, 1).toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' }).replace(/^./, (c) => c.toUpperCase())}</b>
                    <Button icon="chevron_right" size="sm" variant="secondary" aria-label="Mois suivant" onClick={() => move(1)} />
                </div>
                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(7,minmax(0,1fr))', gap: 'var(--space-1_5)' }}>
                    {['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'].map((d) => (
                        <div key={d} style={{ fontSize: 'var(--fs-xs)', fontWeight: 'var(--fw-bold)', color: 'var(--c-muted)', textTransform: 'uppercase', textAlign: 'center', padding: 'var(--space-1) 0' }}>
                            {d}
                        </div>
                    ))}
                    {Array.from({ length: offset }, (_, i) => (
                        <div key={`blank-${i}`} />
                    ))}
                    {Array.from({ length: daysInMonth }, (_, i) => {
                        const iso = `${year}-${pad(mon)}-${pad(i + 1)}`;
                        const period = [...periods].reverse().find((p) => iso >= p.startsOn && iso <= p.endsOn);
                        const active = activityDays.has(iso);
                        const sunday = new Date(`${iso}T12:00:00`).getDay() === 0;
                        const type = period ? period.type.label : sunday ? 'Repos' : closedAccount ? 'Indisponible' : active ? 'En mission terrain' : 'Disponible';
                        const conflict = !!period && active && period.type.value !== 'partial';
                        const [bg, fg, short] = DAY_TYPES[type] ?? DAY_TYPES.Disponible;
                        const label = `${formatDate(iso)} · ${type}${period?.reason ? ` · ${period.reason}` : ''}${conflict ? ' · conflit' : ''}`;
                        return (
                            <button
                                key={iso}
                                type="button"
                                disabled={archived}
                                onClick={() => actions.unavailability(a, options.colleagues)}
                                aria-label={label}
                                title={label}
                                style={{ minHeight: 62, padding: 'var(--space-1_5)', borderRadius: 'var(--radius-md)', border: `1.5px solid ${conflict ? 'var(--c-danger)' : iso === referenceDate ? 'var(--c-primary)' : 'transparent'}`, background: bg, color: fg, textAlign: 'left', cursor: archived ? 'default' : 'pointer', display: 'flex', flexDirection: 'column', justifyContent: 'space-between', gap: 2, minWidth: 0 }}
                            >
                                <span style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                                    <b style={{ fontSize: 'var(--fs-sm)' }}>{i + 1}</b>
                                    {conflict && <Icon name="warning" size="var(--icon-xs)" color="var(--c-danger)" />}
                                </span>
                                <span style={{ fontSize: 'var(--fs-2xs)', lineHeight: 1.2, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{short}</span>
                            </button>
                        );
                    })}
                </div>
                <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2) var(--space-4)', marginTop: 'var(--space-3)' }}>
                    {Object.entries(DAY_TYPES).map(([label, [bg]]) => (
                        <span key={label} style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-1_5)', fontSize: 'var(--fs-sm)', color: 'var(--c-ink-2)' }}>
                            <span style={{ width: 12, height: 12, borderRadius: 'var(--radius-xs)', background: bg }} />
                            {label}
                        </span>
                    ))}
                </div>
            </div>

            <DataTable
                icon="date_range"
                title="Congés, indisponibilités et remplacements temporaires"
                count={`${periods.length} période(s)`}
                minWidth={980}
                columns={['Période', 'Type', 'Motif', 'Remplaçant temporaire', 'Commentaire']}
                empty={{ icon: 'event_available', title: 'Aucune période déclarée', text: 'Le commercial est disponible hors dimanches.' }}
                rows={periods.map((p) => ({
                    key: String(p.id),
                    cells: [
                        <TextCell strong>
                            {formatDate(p.startsOn)} → {formatDate(p.endsOn)}
                        </TextCell>,
                        <StatusBadge label={p.type.label} tone={p.type.tone} />,
                        <TextCell>{p.reason ?? '—'}</TextCell>,
                        <TextCell muted={!p.replacement}>{p.replacement ?? '—'}</TextCell>,
                        <TextCell muted={!p.comment}>{p.comment ?? '—'}</TextCell>,
                    ],
                    actions: archived ? [] : [{ icon: 'delete', label: 'Supprimer', onClick: () => actions.removePeriod(a, p.id) }],
                }))}
            />
        </div>
    );
}

/** Ouvre le détail du calcul d’un mois classé. */
function useScoreDrawer({ agent, weights, actions, referenceDate, currentMonth }: SalesTabProps) {
    return (s: RankSummary) => actions.scoreDrawer(agent, s, { value: s.month, label: s.label, closed: s.closed, isCurrent: s.month === currentMonth, reference: referenceDate }, weights, false);
}

/** Objectifs et performances : indicateurs, graphiques, détail du score, objectifs et historique. */
export function ObjectivesTab(props: SalesTabProps) {
    const { agent: a, current: x, previous: px, history, tradeBreakdown, objectives, appreciations, weights, actions } = props;
    const scoreDrawer = useScoreDrawer(props);
    const m = x?.metrics;
    const live = ['active', 'suspended'].includes(a.status.value);
    const toRegister = Math.round(a.monthlyTarget * 1.25);
    const delta = x && px ? Math.round((x.score - px.score) * 10) / 10 : null;

    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)' }}>
            {live && (
                <Toolbar
                    actions={[
                        { label: 'Exporter un rapport', icon: 'download', onClick: () => (window.location.href = `/commerciaux/${a.slug}/rapport`) },
                        { label: 'Ajouter une appréciation', icon: 'rate_review', onClick: () => actions.appreciation(a) },
                        { label: 'Attribuer un bonus', icon: 'workspace_premium', onClick: () => actions.bonus(a) },
                        { label: 'Ajouter un objectif', icon: 'flag', variant: 'primary', onClick: () => actions.objective(a) },
                    ]}
                />
            )}
            <TileGrid
                tiles={
                    x && m
                        ? [
                              { label: 'Ouvriers à enregistrer (mois)', value: String(toRegister), icon: 'flag', tone: 'info' },
                              { label: 'Ouvriers réellement enregistrés', value: String(m.registered), icon: 'engineering', tone: 'success', bar: (m.registered / toRegister) * 100 },
                              { label: 'Dossiers complets', value: String(m.complete), icon: 'task_alt', tone: 'success' },
                              { label: 'Profils validés', value: String(m.validated), icon: 'verified', tone: 'success', bar: m.objective },
                              { label: 'Taux de validation', value: `${m.validationRate} %`, icon: 'percent', tone: 'info', bar: m.validationRate },
                              { label: 'Taux de rejet', value: `${Math.round((m.rejected / m.registered) * 100)} %`, icon: 'cancel', tone: 'danger', bar: (m.rejected / m.registered) * 100, barColor: 'var(--c-danger)' },
                              { label: 'Qualité moyenne', value: `${m.quality} / 100`, icon: 'grade', tone: 'warning', bar: m.quality },
                              { label: 'Délai moyen de traitement', value: m.averageDelay, icon: 'timer', tone: 'neutral' },
                              { label: 'Régularité terrain', value: `${m.activity} / 100`, icon: 'directions_walk', tone: 'info', bar: m.activity },
                              { label: 'Atteinte des objectifs', value: `${m.objective} %`, icon: 'flag', tone: 'orange', bar: m.objective, sub: px ? `Mois précédent : ${px.metrics.objective} %` : undefined },
                              { label: 'Score global', value: `${decimal(x.score)} / 100`, icon: 'insights', tone: 'warning', bar: x.score, sub: delta != null ? `${delta >= 0 ? '+' : ''}${decimal(delta)} pts vs ${px!.label.toLowerCase()}` : undefined },
                              { label: 'Rang', value: `#${x.rank} / ${x.total}`, icon: 'leaderboard', tone: 'orange' },
                          ]
                        : [{ label: 'Performances', value: 'Non classé', icon: 'insights', tone: 'neutral', sub: 'Aucune donnée sur la période' }]
                }
            />
            <ChartGrid>
                <BarChart title="Évolution du score" sub="Score global sur 100" bars={history.map((h) => ({ label: h.short, value: h.summary?.score ?? 0, tip: `${h.label} : ${h.summary ? decimal(h.summary.score) : 'non classé'}` }))} />
                <BarChart title="Répartition par métier" sub="Profils validés" color="var(--c-accent-600)" bars={tradeBreakdown.map(([label, value]) => ({ label, value }))} />
                <BarChart title="Répartition par zone" sub="Ouvriers enregistrés" color="var(--c-info)" bars={a.communes.map((z, i) => ({ label: z, value: Math.round((m?.registered ?? 4) / (1.4 + i * 0.8)) }))} />
            </ChartGrid>
            {x && (
                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,420px),1fr))', gap: 'var(--space-4)' }}>
                    <Block icon="calculate" title={`Détail du score · ${decimal(x.score)} / 100`} full action={{ label: 'Détail complet', icon: 'open_in_new', onClick: () => scoreDrawer(x) }}>
                        <KeyValues items={weights.map((w) => [`${w.label} (${w.weight} %)`, `normalisé ${x.normalized[w.key]} / 100 → +${decimal(Math.round(x.normalized[w.key] * w.weight) / 100)} pts`])} />
                    </Block>
                    {appreciations.length > 0 && (
                        <Block icon="rate_review" title="Appréciations" full>
                            {appreciations.map((ap) => (
                                <div key={ap.id} style={{ padding: 'var(--space-2_5) 0', borderTop: '1px solid var(--c-line-soft)', fontSize: 'var(--fs-md)' }}>
                                    <b>{ap.author}</b> · {ap.text}
                                    <span style={{ display: 'block', fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>{formatDate(ap.date)}</span>
                                </div>
                            ))}
                        </Block>
                    )}
                </div>
            )}
            <DataTable
                icon="flag"
                title="Objectifs"
                count={`${objectives.length} objectif(s)`}
                minWidth={1000}
                columns={['Période', 'Type', 'Cible', 'Réalisé', 'Progression', 'Statut']}
                empty={{ icon: 'flag', title: 'Aucun objectif', text: 'Attribuez un premier objectif.' }}
                rows={objectives.map((o) => ({
                    key: String(o.id),
                    cells: [<TextCell strong>{o.label}</TextCell>, <TextCell>{o.type}</TextCell>, <TextCell>{o.target} profils validés</TextCell>, <TextCell muted={o.done == null}>{o.done ?? '—'}</TextCell>, <ProgressCell value={o.progress} />, <StatusBadge label={o.status.label} tone={o.status.tone} />],
                    actions: o.closed ? [] : o.future ? [{ icon: 'edit', label: 'Modifier l’objectif futur', onClick: () => actions.objective(a, o) }] : [{ icon: 'lock', label: 'Clôturer la période', onClick: () => actions.closeObjective(a, o) }],
                }))}
            />
            <DataTable
                icon="history"
                title="Historique des performances"
                count=""
                columns={['Mois', 'Score', 'Rang', 'Profils validés', 'Objectif atteint', 'Niveau']}
                empty={{ icon: 'history', title: 'Aucun historique', text: 'Aucune période classée.' }}
                rows={[...history]
                    .reverse()
                    .filter((h) => h.summary)
                    .map((h) => {
                        const s = h.summary!;
                        return {
                            key: h.month,
                            onOpen: () => scoreDrawer(s),
                            cells: [<TextCell strong>{h.label}</TextCell>, <TextCell>{decimal(s.score)} / 100</TextCell>, <TextCell>#{s.rank}</TextCell>, <TextCell>{s.metrics.validated}</TextCell>, <ProgressCell value={s.metrics.objective} />, <StatusBadge label={s.performance.label} tone={s.performance.tone} />],
                            actions: [{ icon: 'calculate', label: 'Détail du calcul', onClick: () => scoreDrawer(s) }],
                        };
                    })}
            />
        </div>
    );
}

/** Classement du commercial : rang, écarts, meilleurs indicateurs, historique et récompenses. */
export function RankingTab(props: SalesTabProps) {
    const { agent: a, current: x, previous: px, leader, above, history, titles, rewards, weights } = props;
    const diff = px && x ? px.rank - x.rank : null;
    const ranked = weights.map((w) => ({ label: w.label, value: x?.normalized[w.key] ?? 0 })).sort((p, q) => q.value - p.value);
    const titleMonths = titles.map((t) => t.month);
    const allRewards = [...titles.map((t) => ({ key: t.month, label: `Commercial du mois · ${t.label}`, amount: a.pay.bonusCotm })), ...rewards.filter((r) => !titleMonths.includes(r.month)).map((r) => ({ key: `${r.month}-${r.label}`, label: r.label, amount: r.amount }))];

    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)' }}>
            <Toolbar actions={[{ label: 'Voir le classement général', icon: 'leaderboard', variant: 'primary', onClick: () => router.visit('/commerciaux/classement') }]} />
            <TileGrid
                tiles={[
                    { label: 'Rang actuel', value: x ? `#${x.rank}` : '—', icon: 'leaderboard', tone: 'orange' },
                    { label: 'Rang du mois précédent', value: px ? `#${px.rank}` : '—', icon: 'history', tone: 'neutral' },
                    { label: 'Évolution', value: diff == null ? '—' : diff > 0 ? `▲ ${diff}` : diff < 0 ? `▼ ${-diff}` : '=', icon: 'trending_up', tone: 'info' },
                    { label: 'Score global', value: x ? `${decimal(x.score)} / 100` : '—', icon: 'insights', tone: 'warning', bar: x?.score ?? 0 },
                    { label: 'Écart avec le premier', value: x && leader ? (leader.slug === a.slug ? 'En tête' : `−${decimal(Math.round((leader.score - x.score) * 10) / 10)} pts`) : '—', icon: 'emoji_events', tone: 'orange' },
                    { label: 'Écart avec le rang précédent', value: x && above != null ? `−${decimal(Math.round((above - x.score) * 10) / 10)} pts` : '—', icon: 'north', tone: 'info' },
                    { label: 'Titres « Commercial du mois »', value: String(titles.length), icon: 'workspace_premium', tone: 'warning' },
                ]}
            />
            {x && (
                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,360px),1fr))', gap: 'var(--space-4)' }}>
                    <Block icon="thumb_up" title="Meilleurs indicateurs">
                        <KeyValues items={ranked.slice(0, 2).map((i) => [i.label, `${i.value} / 100`])} />
                    </Block>
                    <Block icon="trending_down" title="Indicateurs à améliorer">
                        <KeyValues items={ranked.slice(-2).map((i) => [i.label, `${i.value} / 100`])} />
                    </Block>
                </div>
            )}
            <DataTable
                icon="history"
                title="Historique des classements"
                count=""
                columns={['Mois', 'Rang', 'Score', 'Niveau', 'Commercial du mois']}
                empty={{ icon: 'history', title: 'Aucun classement', text: 'Le commercial n’a pas encore été classé.' }}
                rows={[...history]
                    .reverse()
                    .filter((h) => h.summary)
                    .map((h) => ({
                        key: h.month,
                        cells: [<TextCell strong>{h.label}</TextCell>, <TextCell>#{h.summary!.rank}</TextCell>, <TextCell>{decimal(h.summary!.score)}</TextCell>, <StatusBadge label={h.summary!.performance.label} tone={h.summary!.performance.tone} />, <TextCell strong={h.summary!.isTitle}>{h.summary!.isTitle ? 'Oui' : '—'}</TextCell>],
                        actions: [{ icon: 'leaderboard', label: 'Voir le classement du mois', onClick: () => router.visit(`/commerciaux/classement/${h.month.replace('-', '/')}`) }],
                    }))}
            />
            <DataTable
                icon="workspace_premium"
                title="Récompenses obtenues"
                count=""
                columns={['Récompense', 'Montant']}
                empty={{ icon: 'workspace_premium', title: 'Aucune récompense', text: 'Aucun titre ni bonus exceptionnel pour le moment.' }}
                rows={allRewards.map((r) => ({ key: r.key, cells: [<TextCell strong>{r.label}</TextCell>, <TextCell>{fcfa(r.amount)}</TextCell>], actions: [] }))}
            />
        </div>
    );
}

/** Gains et paiements : totaux, lignes de gain, calcul détaillé et changements de statut. */
export function EarningsTab({ agent: a, current, payouts, actions, currentMonth, titles }: SalesTabProps) {
    const ui = useAdminUi();
    const base = `/commerciaux/${a.slug}`;
    const live = ['active', 'suspended'].includes(a.status.value);
    const monthGain = current?.gain ?? 0;
    const sum = (statuses: string[]) => payouts.filter((l) => statuses.includes(l.status.label)).reduce((t, l) => t + l.net, 0);
    const monthly = payouts.filter((l) => !l.adjustmentId);
    const [year, mon] = currentMonth.split('-').map(Number);
    const nextPayment = mon === 12 ? `${year + 1}-01-05` : `${year}-${pad(mon + 1)}-05`;
    const titleMonths = titles.map((t) => t.month);

    const detail = (l: PayoutLine) =>
        ui.openPanel({
            kicker: `Gain commercial · ${a.name}`,
            title: l.ref,
            badges: [{ label: l.status.label, tone: l.status.tone }],
            text: l.reason ?? undefined,
            meta: [
                ['Période', l.period],
                ['Type de rémunération', l.type],
                ['Profils concernés', String(l.profiles)],
                ['Montant de base (fixe)', fcfa(l.fixed)],
                ['Commissions', `${fcfa(l.commissions)}${l.profiles ? ` (${a.pay.perRegistered} × enregistrés + ${a.pay.perValidated} × validés)` : ''}`],
                ['Bonus', fcfa(l.bonus)],
                ['Retenues', `− ${fcfa(l.deduction)}`],
                ['Montant net', fcfa(l.net)],
                ['Moyen de paiement', l.method],
                ['Date de paiement', l.paidAt ? formatDate(l.paidAt) : '—'],
            ],
            actions: [{ label: 'Générer le justificatif', icon: 'receipt', variant: 'primary', onClick: () => (window.location.href = `${base}/gains/${l.ref}/justificatif`) }],
        });

    const menu = (l: PayoutLine) => [
        { icon: 'receipt', label: 'Générer un justificatif', onClick: () => (window.location.href = `${base}/gains/${l.ref}/justificatif`) },
        ...(['En calcul', 'En attente de validation'].includes(l.status.label) ? [{ icon: 'verified', label: 'Valider le gain', onClick: () => actions.payout(a, l.ref, 'valider') }] : []),
        ...(['Validé', 'Programmé', 'En attente de validation', 'En calcul'].includes(l.status.label) ? [{ icon: 'pause_circle', label: 'Suspendre le paiement', danger: true, onClick: () => actions.suspendPayout(a, l.ref) }] : []),
        ...(l.status.label === 'Suspendu' ? [{ icon: 'play_circle', label: 'Reprendre le paiement', onClick: () => actions.payout(a, l.ref, 'reprendre') }] : []),
    ];

    const tools: ToolbarAction[] = [
        { label: 'Exporter le relevé', icon: 'download', onClick: () => (window.location.href = `${base}/gains/releve`) },
        { label: 'Ajouter une retenue', icon: 'money_off', onClick: () => actions.deduction(a) },
        { label: 'Ajouter un bonus', icon: 'workspace_premium', variant: 'primary', onClick: () => actions.bonus(a) },
    ];

    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)' }}>
            {live && <Toolbar actions={tools} />}
            <TileGrid
                tiles={[
                    { label: 'Gain de la semaine', value: fcfa(Math.round(monthGain / 4)), icon: 'today', tone: 'success' },
                    { label: 'Gain du mois', value: fcfa(monthGain), icon: 'calendar_month', tone: 'success' },
                    { label: 'Gains cumulés', value: fcfa(payouts.filter((l) => l.net > 0).reduce((t, l) => t + l.net, 0)), icon: 'savings', tone: 'info' },
                    { label: 'Gains en attente', value: fcfa(sum(['En calcul', 'En attente de validation'])), icon: 'hourglass_top', tone: 'warning' },
                    { label: 'Gains validés', value: fcfa(sum(['Validé', 'Programmé'])), icon: 'verified', tone: 'info' },
                    { label: 'Gains payés', value: fcfa(sum(['Payé', 'Corrigé'])), icon: 'task_alt', tone: 'success' },
                    { label: 'Bonus d’objectif', value: fcfa(monthly.filter((l) => l.bonus - (titleMonths.includes(l.month) ? a.pay.bonusCotm : 0) >= a.pay.bonusObjective).length * a.pay.bonusObjective), icon: 'flag', tone: 'orange' },
                    { label: 'Bonus Commercial du mois', value: fcfa(monthly.filter((l) => titleMonths.includes(l.month)).length * a.pay.bonusCotm), icon: 'emoji_events', tone: 'orange' },
                    { label: 'Retenues', value: fcfa(payouts.reduce((t, l) => t + l.deduction, 0)), icon: 'money_off', tone: 'danger' },
                    { label: 'Ajustements', value: fcfa(payouts.filter((l) => l.status.label === 'Corrigé').length * 5000), icon: 'tune', tone: 'purple', sub: 'Corrections sur périodes clôturées' },
                    { label: 'Prochain paiement', value: formatDate(nextPayment), icon: 'event', tone: 'neutral', sub: `${a.pay.method} · ${a.phone}` },
                ]}
            />
            <DataTable
                icon="receipt_long"
                title="Gains et paiements"
                count={`${payouts.length} ligne(s)`}
                minWidth={1500}
                columns={['Référence', 'Période', 'Type', 'Profils', 'Base', 'Commissions', 'Bonus', 'Retenues', 'Montant net', 'Statut', 'Date de paiement', 'Moyen']}
                empty={{ icon: 'receipt_long', title: 'Aucun gain', text: 'Les gains apparaîtront après les premiers profils validés.' }}
                rows={payouts.map((l) => ({
                    key: l.ref,
                    onOpen: () => detail(l),
                    cells: [
                        <TextCell strong>{l.ref}</TextCell>,
                        <TextCell>{l.period}</TextCell>,
                        <TextCell>{l.type}</TextCell>,
                        <TextCell muted={!l.profiles}>{l.profiles || '—'}</TextCell>,
                        <TextCell>{fcfa(l.fixed)}</TextCell>,
                        <TextCell>{fcfa(l.commissions)}</TextCell>,
                        <TextCell>{fcfa(l.bonus)}</TextCell>,
                        <TextCell muted={!l.deduction}>{l.deduction ? `− ${fcfa(l.deduction)}` : '—'}</TextCell>,
                        <TextCell strong>{fcfa(l.net)}</TextCell>,
                        <StatusBadge label={l.status.label} tone={l.status.tone} />,
                        <TextCell muted={!l.paidAt}>{l.paidAt ? formatDate(l.paidAt) : '—'}</TextCell>,
                        <TextCell>{l.method}</TextCell>,
                    ],
                    actions: [{ icon: 'calculate', label: 'Consulter le calcul', onClick: () => detail(l) }],
                    menu: menu(l),
                }))}
            />
        </div>
    );
}

const JOURNEY: [string, string, (twoFactor: boolean) => string][] = [
    ['how_to_reg', 'Première activation', () => 'Lien e-mail → création du mot de passe → connexion'],
    ['login', 'Connexion', () => 'Téléphone + mot de passe personnel'],
    ['password', 'Mot de passe oublié', () => 'Code ou lien de récupération envoyé par e-mail'],
    ['phonelink_lock', 'Code OTP', (on) => (on ? 'Actif pour ce commercial' : 'Désactivé pour ce commercial')],
    ['lock_clock', 'Verrouillage temporaire', () => '15 minutes après 5 tentatives incorrectes'],
    ['devices', 'Déconnexion de toutes les sessions', () => 'Depuis cet onglet ou l’application'],
    ['settings_backup_restore', 'Réactivation après suspension', () => 'Connexion à nouveau autorisée, sans nouveau mot de passe'],
];

/** Accès et sécurité : invitation, identifiants, autorisations, parcours d’authentification et sessions. */
export function AccessTab({ agent: a, options, actions }: SalesTabProps) {
    const ui = useAdminUi();
    const base = `/commerciaux/${a.slug}`;
    const access = a.access;
    const archived = a.isArchived;
    const pre = a.isPreActivation;
    const status = a.status.value;

    const toolbar: ToolbarAction[] = [
        ...(pre
            ? [
                  { label: 'Révoquer le lien', icon: 'link_off', onClick: () => actions.revokeLink(a) },
                  { label: 'Générer un nouveau lien', icon: 'add_link', onClick: () => actions.newLink(a) },
                  { label: 'Copier le lien', icon: 'link', onClick: () => actions.copyLink(a) },
                  { label: 'Modifier l’adresse e-mail', icon: 'alternate_email', onClick: () => actions.editEmail(a) },
              ]
            : []),
        ...(!archived ? [{ label: 'Modifier le rôle', icon: 'manage_accounts', onClick: () => actions.role(a, options) }] : []),
        ...(status === 'active' ? [{ label: 'Réinitialiser le mot de passe', icon: 'lock_reset', onClick: () => actions.resetAccess(a) }, { label: 'Suspendre l’accès', icon: 'block', variant: 'danger-soft' as const, onClick: () => actions.suspend(a) }] : []),
        ...(status === 'suspended' || status === 'deactivated' ? [{ label: 'Réactiver l’accès', icon: 'settings_backup_restore', onClick: () => actions.reactivate(a) }] : []),
        { label: 'Supprimer le compte', icon: 'delete_forever', variant: 'danger-soft', onClick: () => actions.destroy(a) },
        ...(!archived ? [{ label: 'Modifier les accès', icon: 'admin_panel_settings', variant: 'primary' as const, onClick: () => router.visit(`${base}/modifier/4`) }] : []),
    ];

    const toggles: { key: string; label: string; on: boolean; sub: string; onToggle: () => void }[] = [
        ...Object.entries(options.permissions).map(([key, label]) => ({ key, label, on: !!access.permissions[key], sub: access.permissions[key] ? 'Autorisé' : 'Non autorisé', onToggle: () => actions.permission(a, key, label) })),
        { key: 'two_factor', label: 'Authentification à deux facteurs', on: access.twoFactor, sub: access.twoFactor ? `Code OTP par SMS au ${a.phone}` : 'Désactivée', onToggle: () => actions.twoFactor(a) },
    ];

    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)' }}>
            {pre && <AlertList alerts={[{ tone: 'info', icon: 'link', title: `Lien d’activation · ${a.invitation.label}`, text: access.expiresAt ? `Valable jusqu’au ${formatDate(access.expiresAt)}.` : 'Aucun lien valide.', cta: 'Aperçu de l’e-mail', onAction: () => actions.emailPreview(a) }]} />}
            <Toolbar actions={toolbar} />
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,420px),1fr))', gap: 'var(--space-4)' }}>
                <Block icon="forward_to_inbox" title="Invitation et activation" action={pre ? { label: status === 'draft' ? 'Envoyer' : 'Renvoyer', icon: 'send', onClick: () => actions.invite(a) } : null}>
                    <KeyValues
                        items={[
                            ['Statut de l’invitation', a.invitation.label],
                            ['Adresse de réception', a.email],
                            ['Date d’envoi', formatDate(access.sentAt)],
                            ['Consultée le', formatDate(access.viewedAt)],
                            ['Activée le', formatDate(access.activatedAt)],
                            ['Expiration du lien', access.expiresAt ? `${formatDate(access.expiresAt)} (72 h)` : '—'],
                        ]}
                    />
                </Block>
                <Block icon="key" title="Identifiants de connexion">
                    <KeyValues
                        items={[
                            ['Identifiant de connexion', `${a.phone} (téléphone principal)`],
                            ['Identifiant commercial', a.code],
                            ['Mot de passe', 'Créé par le commercial · jamais visible'],
                            ['Dernier changement', formatDate(access.passwordChangedAt)],
                            ['Dernière connexion', formatLogin(access.lastLogin)],
                            ['Tentatives échouées', `${access.failedAttempts}${access.failedAttempts ? ' · verrouillage après 5 tentatives' : ''}`],
                            ['Rôle · niveau', `${access.role} · ${access.level}`],
                            ['Modules autorisés', access.modules.join(', ') || '—'],
                            ['Zones de données', access.dataZones.join(', ') || '—'],
                            ['Validité de l’accès', access.duration],
                        ]}
                    />
                </Block>
                <Block icon="admin_panel_settings" title="Autorisations" full>
                    <div data-component="PermissionMatrix" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,300px),1fr))', gap: 'var(--space-2)' }}>
                        {toggles.map((t) => (
                            <button
                                key={t.key}
                                type="button"
                                role="switch"
                                aria-checked={t.on}
                                disabled={archived}
                                onClick={t.onToggle}
                                className="och-hover-card"
                                style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-3)', padding: 'var(--space-3)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-lg)', background: 'var(--c-surface)', cursor: archived ? 'default' : 'pointer', textAlign: 'left', fontFamily: 'var(--font-sans)' }}
                            >
                                <span style={{ flex: 1, minWidth: 0 }}>
                                    <b style={{ display: 'block', fontSize: 'var(--fs-md)' }}>{t.label}</b>
                                    <span style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>{t.sub}</span>
                                </span>
                                <span style={{ position: 'relative', width: 46, height: 26, flex: 'none', borderRadius: 'var(--radius-pill)', background: t.on ? 'var(--c-primary-600)' : 'var(--c-line-hover)' }}>
                                    <span style={{ position: 'absolute', top: 3, left: t.on ? 23 : 3, width: 20, height: 20, borderRadius: 'var(--radius-round)', background: 'var(--c-surface)', boxShadow: 'var(--shadow-xs)' }} />
                                </span>
                            </button>
                        ))}
                    </div>
                </Block>
                <Block icon="smartphone" title="Parcours d’authentification de l’Application Commercial" full>
                    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,260px),1fr))', gap: 'var(--space-2)' }}>
                        {JOURNEY.map(([icon, title, sub]) => (
                            <button key={title} type="button" onClick={() => ui.toast('Écran de l’Application Commercial — prévu dans le module dédié.', { tone: 'info' })} className="och-hover-card" style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-3)', padding: 'var(--space-3)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-lg)', background: 'var(--c-surface)', cursor: 'pointer', textAlign: 'left', fontFamily: 'var(--font-sans)' }}>
                                <Icon name={icon} color="var(--c-primary-600)" />
                                <span style={{ flex: 1, minWidth: 0 }}>
                                    <b style={{ display: 'block', fontSize: 'var(--fs-md)' }}>{title}</b>
                                    <span style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>{sub(access.twoFactor)}</span>
                                </span>
                                <StatusBadge label="Prévu" tone="b" />
                            </button>
                        ))}
                    </div>
                </Block>
            </div>
            <DataTable
                icon="devices"
                title="Sessions actives"
                count={`${access.sessions.length} session(s)`}
                tools={access.sessions.length ? [{ label: 'Déconnecter tous les appareils', icon: 'logout', variant: 'danger-soft', onClick: () => actions.revokeSession(a) }] : []}
                columns={['Appareil', 'Localisation', 'Adresse IP', 'Dernière activité']}
                empty={{ icon: 'devices', title: 'Aucune session active', text: 'Le commercial n’est connecté sur aucun appareil.' }}
                rows={access.sessions.map((s) => ({
                    key: s.id,
                    cells: [<TextCell strong>{s.device}</TextCell>, <LocationCell value={s.location} />, <TextCell>{s.ip}</TextCell>, <TextCell>{s.last}</TextCell>],
                    actions: [{ icon: 'logout', label: 'Révoquer la session', onClick: () => actions.revokeSession(a, s) }],
                }))}
            />
        </div>
    );
}
