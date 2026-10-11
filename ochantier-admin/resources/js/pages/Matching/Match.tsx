import { ProgressCell, TextCell } from '@/components/list/Table';
import DetailHeader from '@/components/matching/DetailHeader';
import { readCompare, writeCompare } from '@/components/matching/RecommendationTable';
import { criterionTone, decimal, fcfa, useMatchingActions } from '@/components/matching/useMatchingActions';
import Icon from '@/components/ui/Icon';
import StatusBadge from '@/components/ui/StatusBadge';
import { AlertList, Block, DataTable, KeyValues, TileGrid, Toolbar } from '@/components/workers/blocks';
import { HistoryList, type HistoryEvent } from '@/components/workers/tabs';
import { useAdminUi } from '@/contexts/AdminUiContext';
import { formatDate } from '@/lib/format';
import type { MatchingConfig, OfferInfo, Profile, SelectionInfo } from '@/types/matching';
import { Head, router } from '@inertiajs/react';
import type { ReactNode } from 'react';

type Props = {
    match: Profile;
    offer: OfferInfo;
    selection: SelectionInfo;
    tab: string;
    assignment: { reference: string; status: string; score: number | null; warnings: string | null; workerConfirmation: string; promoterConfirmation: string; pay: string; startsOn: string; endsOn: string; started: boolean } | null;
    depositPaid: boolean;
    recalculations: { id: number; date: string; time: string; old_score: number; new_score: number; reason: string; old_weights: string; new_weights: string; author: string; algorithm: string }[];
    audit: HistoryEvent[];
    cycleTime: string | null;
    config: MatchingConfig;
};

const TABS: [string, string][] = [
    ['', 'Vue d’ensemble'],
    ['score', 'Détail du score'],
    ['besoin', 'Besoin et critères'],
    ['profil', 'Profil de l’ouvrier'],
    ['validation', 'Validation'],
    ['affectation', 'Affectation'],
    ['resultat', 'Résultat réel'],
    ['historique', 'Historique'],
];

/** Fiche d’un match : score détaillé, besoin, profil, validation, affectation, résultat réel et historique. */
export default function MatchingMatch({ match: p, offer: o, selection: s, tab, assignment: a, depositPaid, recalculations, audit, cycleTime, config }: Props) {
    const ui = useAdminUi();
    const actions = useMatchingActions();
    const id = p.matchId.toUpperCase();
    const base = `/matching/${p.matchId}`;
    const on = p.selected;
    const started = !!a?.started;

    const compare = () => {
        const ids = readCompare(o.id);
        const next = ids.includes(p.matchId) ? ids : [...ids, p.matchId].slice(-4);
        writeCompare(o.id, next);
        router.visit(`/matching/besoins/${o.id}/comparaison?ids=${next.join(',')}`);
    };

    const banner = p.blocked
        ? { tone: 'danger', icon: 'block', title: 'Profil non éligible.', text: p.blocking.map((b) => `${b.criterion} : ${b.found}`).join(' · ') }
        : o.obsolete
          ? { tone: 'warning', icon: 'update', title: 'Résultat obsolète.', text: `Calculé il y a plus de ${config.obsolete_days} jours.`, cta: 'Recalculer', onCta: () => actions.recalculate(p, config.weights_version) }
          : null;

    let body: ReactNode = null;
    if (tab === '')
        body = (
            <>
                <AlertList alerts={p.warnings.map((w) => ({ tone: 'warning', icon: 'warning', title: 'Avertissement', text: w }))} />
                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,380px),1fr))', gap: 'var(--space-4)' }}>
                    <Block icon="summarize" title="Synthèse">
                        <KeyValues
                            items={[
                                ['Référence du match', id],
                                ['Besoin', `${o.need.reference} · ${o.reference}`],
                                ['Chantier', o.site.name],
                                ['Promoteur', o.site.promoter],
                                ['Métier', `${o.trade} · ${o.specialty ?? '—'}`],
                                ['Ouvrier', `${p.name} · ${p.code}`],
                                ['Score', `${p.score} / 100 · ${p.level.label}`],
                                ['Statut', p.status.label],
                                ['Date de calcul', formatDate(p.computedOn)],
                                ['Responsable', o.need.admin],
                            ]}
                        />
                    </Block>
                    <Block icon="bar_chart" title="Critères">
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-2)' }}>
                            {p.criteria.map((c) => (
                                <button key={c.key} type="button" onClick={() => router.visit(`${base}/score`)} className="och-hover-card" style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-3)', padding: 'var(--space-2_5) var(--space-3)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-lg)', background: 'var(--c-surface)', cursor: 'pointer', textAlign: 'left' }}>
                                    <Icon name={c.n >= 80 ? 'check_circle' : c.n >= 55 ? 'remove_circle' : 'cancel'} color={`var(--c-${c.n >= 80 ? 'success' : c.n >= 55 ? 'warning' : 'danger'}-fg)`} />
                                    <span style={{ flex: 1, minWidth: 0 }}>
                                        <span style={{ display: 'block', fontSize: 'var(--fs-xs)', color: 'var(--c-muted)' }}>
                                            {c.weight} % · {c.n}/100
                                        </span>
                                        <b style={{ display: 'block' }}>{c.label}</b>
                                        <span style={{ display: 'block', fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>{c.why}</span>
                                    </span>
                                    <StatusBadge label={`+${decimal(c.contribution)} pts`} tone={criterionTone(c.n)} />
                                </button>
                            ))}
                        </div>
                    </Block>
                </div>
            </>
        );
    if (tab === 'score')
        body = (
            <>
                <TileGrid
                    tiles={[
                        { label: 'Score global', value: `${p.score} / 100`, icon: 'target', tone: 'success', bar: p.score },
                        { label: 'Niveau', value: p.level.label, icon: 'military_tech', tone: 'success' },
                        { label: 'Algorithme', value: config.algorithm, icon: 'memory', tone: 'info' },
                        { label: 'Pondérations', value: config.weights_version, icon: 'balance', tone: 'purple', sub: `Total ${config.sum} % · Paramètres` },
                    ]}
                />
                <DataTable
                    icon="calculate"
                    title="Détail du score · calcul complet"
                    count={`Score = Σ (score normalisé × pondération) = ${p.criteria.map((c) => `${c.n}×${c.weight}%`).join(' + ')} = ${p.raw}`}
                    minWidth={1600}
                    columns={['Critère', 'Donnée utilisée', 'Valeur attendue', 'Valeur du profil', 'Score /100', 'Pondération', 'Contribution', 'Statut', 'Explication', 'Source', 'Mise à jour']}
                    empty={{ icon: 'calculate', title: 'Aucun critère', text: '' }}
                    rows={p.criteria.map((c) => ({
                        key: c.key,
                        cells: [
                            <TextCell strong>{c.label}</TextCell>,
                            <TextCell>{c.data}</TextCell>,
                            <TextCell>{c.expected}</TextCell>,
                            <TextCell>{c.value}</TextCell>,
                            <ProgressCell value={c.n} />,
                            <TextCell>{c.weight} %</TextCell>,
                            <TextCell strong>+{decimal(c.contribution)} pts</TextCell>,
                            <StatusBadge label={c.level} tone={criterionTone(c.n)} />,
                            <TextCell>{c.why}</TextCell>,
                            <TextCell>{c.source}</TextCell>,
                            <TextCell>{formatDate(c.updatedOn)}</TextCell>,
                        ],
                        actions: [],
                    }))}
                />
                <Block icon="info" title="Contexte du calcul">
                    <KeyValues
                        items={[
                            ['Critères bloquants', p.blocking.map((b) => b.criterion).join(', ') || 'Aucun'],
                            ['Avertissements', p.warnings.join(' · ') || 'Aucun'],
                            ['Date et heure du calcul', `${formatDate(p.computedOn)} · ${cycleTime ?? '06:00'}`],
                            ['Version de l’algorithme', config.algorithm],
                            ['Version des pondérations', `${config.weights_version} (depuis le ${formatDate(config.weights_date)})`],
                            ['Déclenché par', p.computedBy],
                            ['Seuils', Object.entries(config.thresholds).map(([l, v]) => `${l} ≥ ${v}`).join(' · ')],
                        ]}
                    />
                </Block>
            </>
        );
    if (tab === 'besoin')
        body = (
            <Block icon="assignment" title="Besoin et critères" action={{ label: 'Voir le besoin', icon: 'open_in_new', onClick: () => router.visit(`/besoins/${o.need.slug}`) }}>
                <KeyValues
                    items={[
                        ['Besoin', `${o.need.reference} · ${o.reference}`],
                        ['Chantier', `${o.site.name} · ${o.site.commune} · ${o.need.district ?? ''}`],
                        ['Métier / spécialité', `${o.trade} · ${o.specialty ?? '—'}`],
                        ['Niveau demandé', o.experience],
                        ['Période', `${formatDate(o.startsOn)} → ${formatDate(o.endsOn)}`],
                        ['Horaires', o.workingHours],
                        ['Budget', `${fcfa(o.cost)}/j · ${o.payType}`],
                        ['Exigences', o.need.requirements.join(', ')],
                        ['Postes restants', String(o.rest)],
                    ]}
                />
            </Block>
        );
    if (tab === 'profil')
        body = (
            <Block icon="person" title="Profil de l’ouvrier" action={{ label: 'Fiche ouvrier', icon: 'open_in_new', onClick: () => router.visit(p.workerSlug ? `/ouvriers/${p.workerSlug}` : `/ouvriers?q=${encodeURIComponent(p.name)}`) }}>
                <KeyValues
                    items={[
                        ['Identifiant', p.code],
                        ['Métier', `${p.trade} · ${p.specialty ?? '—'}`],
                        ['Expérience', `${p.experience} ans`],
                        ['Commune de départ', `${p.commune} (mise à jour ${formatDate(p.locatedOn)} · fiabilité ${p.locationReliability.toLowerCase()})`],
                        ['Distance / temps', `${decimal(p.km)} km · ${p.minutes} min jusqu’à ${o.site.name}`],
                        ['Coût journalier', fcfa(p.cost)],
                        ['Disponibilité', `${p.availability} · dès le ${formatDate(p.availableOn)}`],
                        ['Profil vérifié', p.verified ? 'Oui' : 'Non'],
                        ['Qualité du dossier', `${p.dossier} %`],
                        ['Certifications', p.certs.join(', ') || 'Aucune'],
                        ['Note', `${decimal(p.rating)} / 5 · ${p.reviews} avis`],
                        ['Fiabilité', `${p.acceptance} % d’acceptation · ${p.presence} % de présence`],
                    ]}
                />
            </Block>
        );
    if (tab === 'validation')
        body = (
            <>
                {on && !a && (
                    <Toolbar
                        actions={[
                            { label: 'Valider la sélection', icon: 'fact_check', variant: 'primary', onClick: () => actions.validate(o, s) },
                            { label: 'Demander une correction', icon: 'rule', onClick: () => actions.correction(o, s) },
                        ]}
                    />
                )}
                <Block icon="fact_check" title="Validation">
                    <KeyValues
                        items={[
                            ['Statut de la sélection', on ? s.status.label : a ? 'Transmise aux Affectations' : p.rejection ? 'Rejetée' : 'Non sélectionné'],
                            ['Par', s.author],
                            ['Mise à jour', formatDate(s.updatedOn)],
                            ['Correction demandée', s.note],
                            ['Rejet', p.rejection],
                            ['Dérogation', p.derogation],
                        ]}
                    />
                </Block>
            </>
        );
    if (tab === 'affectation')
        body = (
            <Block icon="event_available" title="Proposition d’affectation" action={a ? { label: 'Ouvrir dans Affectations', icon: 'open_in_new', onClick: () => router.visit(`/affectations?q=${encodeURIComponent(o.need.reference)}`) } : null}>
                <KeyValues
                    items={
                        a
                            ? [
                                  ['Référence', a.reference],
                                  ['Statut (module Affectations)', a.status],
                                  ['Confirmation de l’ouvrier', a.workerConfirmation],
                                  ['Confirmation du promoteur', a.promoterConfirmation],
                                  ['Score transmis', `${a.score ?? p.score} / 100`],
                                  ['Avertissements transmis', a.warnings || 'Aucun'],
                                  ['Conditions financières', a.pay],
                                  ['Période', `${formatDate(a.startsOn)} → ${formatDate(a.endsOn)}`],
                              ]
                            : [
                                  ['Statut', 'Aucune proposition : le profil n’a pas été transmis'],
                                  ['Règle', 'Seul le module Affectations confirme l’affectation définitive'],
                              ]
                    }
                />
            </Block>
        );
    if (tab === 'resultat')
        body = (
            <>
                <TileGrid
                    tiles={[
                        { label: 'Recommandation validée', value: a ? 'Oui' : 'Non', icon: 'fact_check', tone: a ? 'success' : 'neutral' },
                        { label: 'Affectation créée', value: a ? a.reference : 'Non', icon: 'event_available', tone: a ? 'success' : 'neutral' },
                        { label: 'Ouvrier confirmé', value: a?.status === 'Validé' ? 'Oui' : 'Non', icon: 'how_to_reg', tone: a?.status === 'Validé' ? 'success' : 'neutral' },
                        { label: 'Promoteur / admin confirmé', value: depositPaid ? 'Oui (acompte)' : 'Non', icon: 'verified', tone: depositPaid ? 'success' : 'neutral' },
                        { label: 'Mission démarrée', value: started ? 'Oui · premier pointage' : 'Non', icon: 'play_circle', tone: started ? 'success' : 'neutral' },
                    ]}
                />
                <AlertList alerts={[{ tone: started ? 'success' : 'info', icon: started ? 'task_alt' : 'info', title: started ? 'Match réussi' : 'Match non encore réussi', text: 'Un match est réussi uniquement si la recommandation est validée, l’affectation créée et confirmée par l’ouvrier et le promoteur, et la mission démarrée (premier pointage validé).' }]} />
            </>
        );
    if (tab === 'historique')
        body = (
            <>
                <DataTable
                    icon="history"
                    title="Recalculs"
                    count={`${recalculations.length} recalcul(s) · historique conservé`}
                    minWidth={1100}
                    columns={['Date', 'Ancien score', 'Nouveau score', 'Raison', 'Anciennes pondérations', 'Nouvelles pondérations', 'Auteur', 'Algorithme']}
                    empty={{ icon: 'history', title: 'Aucun recalcul', text: 'Les recalculs conservent l’ancien score.' }}
                    rows={recalculations.map((r) => ({
                        key: String(r.id),
                        cells: [
                            <TextCell>
                                {formatDate(r.date)} · {r.time}
                            </TextCell>,
                            <TextCell>{r.old_score} / 100</TextCell>,
                            <TextCell strong>{r.new_score} / 100</TextCell>,
                            <TextCell>{r.reason}</TextCell>,
                            <TextCell>{r.old_weights}</TextCell>,
                            <TextCell>{r.new_weights}</TextCell>,
                            <TextCell>{r.author}</TextCell>,
                            <TextCell>{r.algorithm}</TextCell>,
                        ],
                        actions: [],
                    }))}
                />
                <Block icon="policy" title="Journal">
                    <HistoryList activities={audit} />
                </Block>
            </>
        );

    return (
        <>
            <Head title={`Match ${id}`} />
            <DetailHeader
                icon="person_search"
                title={`${id} · ${p.name}`}
                badges={[p.status, { label: `${p.level.label} · ${p.score} / 100`, tone: p.level.tone }]}
                sub={`${o.reference} · ${o.site.name} · ${o.site.promoter} · ${o.trade}`}
                code={`Calcul du ${formatDate(p.computedOn)} · ${p.computedBy}`}
                meta={[
                    ['person', 'Ouvrier', p.code],
                    ['target', 'Score', `${p.score} / 100`],
                    ['event', 'Calcul', formatDate(p.computedOn)],
                    ['badge', 'Responsable', o.need.admin ?? '—'],
                ]}
                actions={[
                    ...(!p.blocked && !a ? [{ label: on ? 'Désélectionner' : 'Sélectionner', icon: on ? 'remove' : 'add', onClick: () => (!on && s.main.length >= o.rest ? ui.toast(`Nombre d’ouvriers recherchés atteint (${s.main.length} / ${o.rest}) : désélectionnez d’abord un ouvrier.`, { tone: 'error' }) : actions.toggle(p)) }] : []),
                    ...(on && !a ? [{ label: 'Valider', icon: 'fact_check', onClick: () => actions.validate(o, s) }] : []),
                ]}
                menu={[
                    { icon: 'assignment', label: 'Voir le besoin', onClick: () => router.visit(`/besoins/${o.need.slug}`) },
                    { icon: 'engineering', label: 'Voir l’offre', onClick: () => router.visit(`/besoins/${o.need.slug}/metiers`) },
                    { icon: 'person', label: 'Voir l’ouvrier', onClick: () => router.visit(p.workerSlug ? `/ouvriers/${p.workerSlug}` : `/ouvriers?q=${encodeURIComponent(p.name)}`) },
                    { icon: 'compare', label: 'Comparer', onClick: compare },
                    { icon: 'fact_check', label: 'Demander une vérification', onClick: () => actions.verification(p) },
                    { icon: 'forward', label: 'Transmettre aux Affectations', onClick: () => (on ? actions.validate(o, s) : ui.toast('Sélectionnez d’abord le profil.', { tone: 'error' })) },
                    { icon: 'calculate', label: 'Recalculer', onClick: () => actions.recalculate(p, config.weights_version) },
                    { icon: 'block', label: 'Rejeter', danger: true, onClick: () => actions.reject(p) },
                    { icon: 'archive', label: 'Archiver', onClick: () => actions.archive(p) },
                ]}
                banner={banner}
                kpis={[
                    { label: 'Score global', value: `${p.score} / 100`, icon: 'target', tone: 'g', hint: p.level.label },
                    { label: 'Distance', value: `${decimal(p.km)} km`, icon: 'near_me', tone: 'b', hint: `${p.minutes} min depuis ${p.commune}` },
                    { label: 'Coût journalier', value: fcfa(p.cost), icon: 'payments', tone: p.cost <= o.cost ? 'g' : 'a', hint: `Budget ${fcfa(o.cost)}/j` },
                    { label: 'Avertissements', value: String(p.warnings.length), icon: 'warning', tone: p.warnings.length ? 'a' : 'g', hint: p.blocked ? 'Non éligible' : 'Éligible' },
                ]}
                tabs={TABS.map(([k, l]) => [k, l, k === 'historique' ? recalculations.length : undefined])}
                tab={tab}
                base={base}
            >
                {body}
            </DetailHeader>
            {actions.dialog}
        </>
    );
}
