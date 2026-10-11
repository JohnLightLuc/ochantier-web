import { PersonCell, ProgressCell, TextCell } from '@/components/list/Table';
import DetailHeader from '@/components/matching/DetailHeader';
import RecommendationTable, { readCompare, writeCompare } from '@/components/matching/RecommendationTable';
import { decimal, fcfa, useMatchingActions } from '@/components/matching/useMatchingActions';
import Icon from '@/components/ui/Icon';
import StatusBadge from '@/components/ui/StatusBadge';
import { AlertList, Block, DataTable, KeyValues, TileGrid, Toolbar } from '@/components/workers/blocks';
import { formatDate } from '@/lib/format';
import type { AnyTone } from '@/types';
import type { MatchingConfig, OfferInfo, Profile, SelectionInfo } from '@/types/matching';
import { Head, router } from '@inertiajs/react';
import { useEffect, type ReactNode } from 'react';

type Props = {
    offer: OfferInfo;
    tab: '' | 'resultats' | 'comparaison' | 'selection' | 'exclus';
    counts: { all: number; ok: number; excluded: number; belowExperience: number; belowThreshold: number };
    best: Profile | null;
    top: Profile[];
    results: Profile[];
    excluded: Profile[];
    compare: Profile[];
    candidates: { matchId: string; name: string }[];
    selection: SelectionInfo;
    selectionCount: number;
    sorts: Record<string, string>;
    levels: string[];
    config: MatchingConfig;
    zone: string;
};

const TABS: [string, string][] = [
    ['', 'Vue d’ensemble'],
    ['resultats', 'Résultats'],
    ['comparaison', 'Comparaison'],
    ['selection', 'Sélection et budget'],
    ['exclus', 'Profils exclus'],
];

type Card = { key: string; icon: string; kicker: string; title: string; sub: string; badge: string; tone: AnyTone; onClick?: () => void };

function Cards({ items, empty }: { items: Card[]; empty: string }) {
    if (!items.length) return <span style={{ fontSize: 'var(--fs-md)', color: 'var(--c-muted)' }}>{empty}</span>;
    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-2)' }}>
            {items.map((c) => (
                <button key={c.key} type="button" onClick={c.onClick} disabled={!c.onClick} className={c.onClick ? 'och-hover-card' : undefined} style={{ width: '100%', display: 'flex', alignItems: 'center', gap: 'var(--space-3)', padding: 'var(--space-3)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-lg)', background: 'var(--c-surface)', cursor: c.onClick ? 'pointer' : 'default', textAlign: 'left', color: 'inherit' }}>
                    <span style={{ width: 'var(--control-sm)', height: 'var(--control-sm)', flex: 'none', borderRadius: 'var(--radius-md)', display: 'grid', placeItems: 'center', background: 'var(--c-primary-50)' }}>
                        <Icon name={c.icon} color="var(--c-primary-600)" />
                    </span>
                    <span style={{ flex: 1, minWidth: 0 }}>
                        <span style={{ display: 'block', fontSize: 'var(--fs-xs)', color: 'var(--c-muted)', textTransform: 'uppercase', letterSpacing: '.05em' }}>{c.kicker}</span>
                        <b style={{ display: 'block' }}>{c.title}</b>
                        {c.sub && <span style={{ display: 'block', fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>{c.sub}</span>}
                    </span>
                    <StatusBadge label={c.badge} tone={c.tone} />
                </button>
            ))}
        </div>
    );
}

/** Matching d’un besoin (une offre) : synthèse, résultats, comparaison, sélection et budget, profils exclus. */
export default function MatchingNeed(props: Props) {
    const { offer: o, tab, counts, best, selection: s, config } = props;
    const actions = useMatchingActions();
    const base = `/matching/besoins/${o.id}`;
    const editNeed = () => router.visit(`/besoins/${o.need.slug}/modifier`);
    const statusLabel = o.status.label;

    const banner = !o.eligible
        ? { tone: 'warning', icon: 'block', title: 'Besoin non proposé au Matching.', text: `${statusLabel} · un nouveau matching nécessite une action administrative autorisée.` }
        : statusLabel === 'À relancer'
          ? { tone: 'warning', icon: 'update', title: 'Résultat obsolète.', text: `Dernier calcul le ${formatDate(o.lastRun?.date)} : relancez le matching.` }
          : null;

    let body: ReactNode = null;
    if (tab === '') body = <Overview {...props} actions={actions} editNeed={editNeed} />;
    if (tab === 'resultats')
        body = <RecommendationTable rows={props.results} offerCost={o.cost} levels={props.levels} sorts={props.sorts} actions={actions} title="Profils recommandés" count={(shown) => `${shown} / ${counts.ok} profil(s) éligible(s)`} />;
    if (tab === 'comparaison') body = <Comparison {...props} />;
    if (tab === 'selection') body = <Selection offer={o} selection={s} actions={actions} config={config} />;
    if (tab === 'exclus') body = <Excluded {...props} actions={actions} editNeed={editNeed} />;

    return (
        <>
            <Head title={`Matching · ${o.reference}`} />
            <DetailHeader
                icon="join_inner"
                title={`${o.reference} · ${o.site.name}`}
                badges={[o.status, { label: `Urgence ${o.urgency.label.toLowerCase()}`, tone: o.urgency.tone }]}
                sub={`${o.site.promoter} · ${o.trade} · ${o.specialty ?? '—'}`}
                code={`Dernier matching : ${o.lastRun ? `${formatDate(o.lastRun.date)} · ${o.lastRun.by}` : 'jamais'}`}
                meta={[
                    ['groups', 'Recherchés / affectés / restants', `${o.quantity} / ${o.selected} / ${o.rest}`],
                    ['location_on', 'Lieu', `${o.site.commune} · ${o.need.district ?? ''}`],
                    ['event', 'Démarrage', `${formatDate(o.startsOn)} · ${o.duration} j`],
                    ['payments', 'Budget', `${fcfa(o.cost)}/j · ${o.payType}`],
                ]}
                actions={[
                    ...(o.eligible ? [{ label: 'Relancer le matching', icon: 'play_arrow', onClick: () => router.visit(`/matching/lancer?portee=besoin&cible=${o.id}`) }] : []),
                    ...(props.selectionCount ? [{ label: 'Valider la sélection', icon: 'fact_check', onClick: () => actions.validate(o, s) }] : []),
                ]}
                menu={[
                    { icon: 'assignment', label: 'Voir le besoin', onClick: () => router.visit(`/besoins/${o.need.slug}`) },
                    { icon: 'engineering', label: 'Voir l’offre (métiers)', onClick: () => router.visit(`/besoins/${o.need.slug}/metiers`) },
                    { icon: 'construction', label: 'Voir le chantier', onClick: () => router.visit(`/chantiers/${o.site.slug}`) },
                    { icon: 'event_available', label: 'Suivi dans Affectations & Offres', onClick: () => router.visit(`/affectations?q=${encodeURIComponent(o.need.reference)}`) },
                ]}
                banner={banner}
                kpis={[
                    { label: 'Profils éligibles', value: String(counts.ok), icon: 'group', tone: 'g', hint: `Top ${config.limit} affichés (Paramètres)` },
                    { label: 'Profils exclus', value: String(counts.excluded), icon: 'person_off', tone: 'r', hint: 'Critères bloquants' },
                    { label: 'Sélectionnés', value: `${props.selectionCount} / ${o.rest}`, icon: 'check_circle', tone: 'p', hint: `${s.replacements.length} remplaçant(s) non comptés` },
                    { label: 'Meilleur score', value: `${best?.score ?? 0} / 100`, icon: 'target', tone: 'g', hint: best?.name ?? '—' },
                ]}
                tabs={TABS.map(([k, l]) => [k, l, k === 'exclus' ? counts.excluded : k === 'selection' ? props.selectionCount : undefined])}
                tab={tab}
                base={base}
            >
                {body}
            </DetailHeader>
            {actions.dialog}
        </>
    );
}

function Overview({ offer: o, counts, top, excluded, actions, editNeed }: Props & { actions: ReturnType<typeof useMatchingActions>; editNeed: () => void }) {
    const base = `/matching/besoins/${o.id}`;
    const causes = [...new Set(excluded.flatMap((x) => x.blocking.map((b) => b.criterion)))];

    return (
        <>
            {counts.ok === 0 && (
                <>
                    <AlertList alerts={[{ tone: 'danger', icon: 'person_off', title: 'Aucun profil compatible', text: `Causes : ${causes.join(', ') || 'aucun ouvrier du métier'}. Toute modification se fait dans le module propriétaire de la donnée.`, cta: 'Profils exclus', onAction: () => router.visit(`${base}/exclus`) }]} />
                    <Toolbar
                        actions={[
                            { label: 'Élargir la zone (Paramètres)', icon: 'settings', onClick: () => router.visit('/parametres') },
                            { label: 'Ajuster le besoin', icon: 'edit', onClick: editNeed },
                            { label: 'Relancer le besoin', icon: 'autorenew', onClick: () => router.visit(`/besoins/${o.need.slug}/metiers`) },
                            { label: 'Notifier les commerciaux', icon: 'campaign', onClick: () => actions.notifySales(o) },
                            { label: 'Profils proches du seuil', icon: 'person_search', onClick: () => router.visit(`${base}/exclus`) },
                        ]}
                    />
                </>
            )}
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,380px),1fr))', gap: 'var(--space-4)' }}>
                <Block icon="assignment" title="Besoin et critères">
                    <KeyValues
                        items={[
                            ['Référence', `${o.need.reference} · ${o.reference}`],
                            ['Chantier', `${o.site.name} · ${o.site.type}`],
                            ['Promoteur', o.site.promoter],
                            ['Métier / spécialité', `${o.trade} · ${o.specialty ?? '—'}`],
                            ['Niveau demandé', o.experience],
                            ['Période', `${formatDate(o.startsOn)} → ${formatDate(o.endsOn)}`],
                            ['Horaires', o.workingHours],
                            ['Type de mission', o.payType + (o.negotiable ? ' · négociable' : '')],
                            ['Budget', `${fcfa(o.cost)}/j`],
                            ['Couverture', o.coverage],
                            ['Responsable', o.need.admin],
                        ]}
                    />
                </Block>
                <Block icon="emoji_events" title="Meilleurs profils">
                    <Cards empty="Aucun profil compatible." items={top.map((x) => ({ key: x.key, icon: 'person', kicker: `${x.level.label} · ${decimal(x.km)} km`, title: `${x.name} · ${x.score} / 100`, sub: `${fcfa(x.cost)}/j`, badge: x.status.label, tone: x.status.tone, onClick: () => router.visit(`/matching/${x.matchId}`) }))} />
                </Block>
            </div>
        </>
    );
}

function Comparison({ offer: o, compare: X, candidates }: Props) {
    const ids = X.map((x) => x.matchId);
    const base = `/matching/besoins/${o.id}/comparaison`;
    const visit = (list: string[]) => {
        writeCompare(o.id, list);
        router.get(base, list.length ? { ids: list.join(',') } : {}, { preserveScroll: true, replace: true });
    };

    useEffect(() => {
        const stored = readCompare(o.id);
        if (!new URLSearchParams(window.location.search).get('ids') && stored.length >= 2) router.get(base, { ids: stored.join(',') }, { preserveScroll: true, replace: true });
    }, [o.id, base]);

    const crit = (x: Profile, k: string) => x.criteria.find((c) => c.key === k)?.n ?? 0;
    const balanced = X.reduce<{ x: Profile; sd: number } | null>((acc, x) => {
        const sd = Math.sqrt(x.criteria.reduce((q, c) => q + (c.n - x.score) ** 2, 0) / 8);
        return !acc || sd < acc.sd ? { x, sd } : acc;
    }, null)?.x;
    const row = (label: string, f: (x: Profile) => number | string, best?: 'max' | 'min', fmt?: (v: number | string, x: Profile) => string) => {
        const values = X.map(f);
        const numbers = values.filter((v): v is number => typeof v === 'number');
        const target = best === 'max' ? Math.max(...numbers) : best === 'min' ? Math.min(...numbers) : null;
        return {
            key: label,
            cells: [<TextCell strong>{label}</TextCell>, ...X.map((x, i) => (best && values[i] === target && X.length > 1 ? <StatusBadge label={`${fmt ? fmt(values[i], x) : values[i]} · meilleur`} tone="g" /> : <span>{fmt ? fmt(values[i], x) : String(values[i])}</span>))],
            actions: [],
        };
    };

    return (
        <>
            <Toolbar
                actions={[
                    { label: 'Réinitialiser (top 3)', icon: 'restart_alt', onClick: () => visit([]) },
                    ...(X.length < 4
                        ? candidates
                              .filter((c) => !ids.includes(c.matchId))
                              .slice(0, 2)
                              .map((c) => ({ label: `+ ${c.name}`, icon: 'person_add', onClick: () => visit([...ids, c.matchId]) }))
                        : []),
                ]}
            />
            {X.length < 2 && <AlertList alerts={[{ tone: 'warning', icon: 'compare', title: 'Comparaison', text: 'Ajoutez au moins deux profils (maximum quatre).' }]} />}
            <DataTable
                icon="compare"
                title="Comparer les profils"
                count={`${X.length} / 4 profil(s)${balanced ? ` · profil le plus équilibré : ${balanced.name}` : ''}`}
                minWidth={260 + X.length * 220}
                columns={['Critère', ...X.map((x) => x.name)]}
                empty={{ icon: 'compare', title: 'Aucun profil', text: 'Ajoutez des profils depuis les résultats.' }}
                rows={[
                    row('Score global', (x) => x.score, 'max', (v) => `${v} / 100`),
                    row('Niveau', (x) => x.level.label),
                    row('Métier · spécialité', (x) => `${x.trade} · ${x.specialty ?? '—'}`),
                    row('Expérience (ans)', (x) => x.experience, 'max'),
                    row('Proximité (km)', (x) => x.km, 'min', (v) => `${decimal(Number(v))} km`),
                    row('Disponibilité', (x) => crit(x, 'dispo'), 'max', (v, x) => `${x.availability} (${v})`),
                    row('Coût journalier', (x) => x.cost, 'min', (v) => fcfa(Number(v))),
                    row('Certification', (x) => x.certs.join(', ') || 'Aucune'),
                    row('Qualité du dossier', (x) => x.dossier, 'max', (v) => `${v} %`),
                    row('Note', (x) => x.rating, 'max', (v) => `${decimal(Number(v))} / 5`),
                    row('Fiabilité (acceptation)', (x) => x.acceptance, 'max', (v) => `${v} %`),
                    row('Missions précédentes', (x) => x.missions, 'max'),
                    row('Taux de présence', (x) => x.presence, 'max', (v) => `${v} %`),
                    row('Incidents', (x) => x.incidents, 'min'),
                    row('Critères bloquants', (x) => x.blocking.map((b) => b.criterion).join(', ') || 'Aucun'),
                    row('Avertissements', (x) => x.warnings.join(' · ') || 'Aucun'),
                    { key: 'balanced', cells: [<TextCell strong>Profil le plus équilibré</TextCell>, ...X.map((x) => (x === balanced ? <StatusBadge label="Recommandé" tone="p" /> : <TextCell muted>—</TextCell>))], actions: [] },
                ]}
            />
        </>
    );
}

function Selection({ offer: o, selection: s, actions, config }: { offer: OfferInfo; selection: SelectionInfo; actions: ReturnType<typeof useMatchingActions>; config: MatchingConfig }) {
    const b = s.budget;
    const X = s.main;
    const conflicts = X.filter((x) => x.conflict).length;
    const alerts = [
        ...(X.length > o.rest ? [{ tone: 'danger', icon: 'error', title: 'Sursélection', text: `${X.length} profils pour ${o.rest} poste(s) : réduisez, désignez des remplaçants ou demandez une dérogation.`, cta: 'Traiter', onAction: () => actions.validate(o, s) }] : []),
        ...(b.over ? [{ tone: 'danger', icon: 'money_off', title: 'Budget dépassé', text: `Écart de ${fcfa(-b.gap)}.` }] : []),
        ...(b.negotiable ? [{ tone: 'warning', icon: 'handshake', title: 'Rémunération négociable', text: 'Les montants ne sont pas définitifs.' }] : []),
    ];

    return (
        <>
            <TileGrid
                tiles={[
                    { label: 'Requis', value: String(o.rest), icon: 'groups', tone: 'info' },
                    { label: 'Sélectionnés', value: String(X.length), icon: 'check_circle', tone: 'purple', bar: o.rest ? (X.length / o.rest) * 100 : 0 },
                    { label: 'Restants', value: String(Math.max(0, o.rest - X.length)), icon: 'person_add', tone: 'warning' },
                    { label: 'Excédentaires', value: String(Math.max(0, X.length - o.rest)), icon: 'error', tone: X.length > o.rest ? 'danger' : 'neutral' },
                    { label: 'Budget estimé', value: fcfa(b.total), icon: 'savings', tone: b.over ? 'danger' : 'warning', bar: b.percent, barColor: b.over ? 'var(--c-danger)' : undefined, sub: `${b.percent} % du budget · ${b.note}` },
                    { label: 'Budget disponible', value: fcfa(b.available), icon: 'account_balance_wallet', tone: 'info', sub: `Tolérance ${config.tolerance} % (Paramètres)` },
                    { label: 'Écart', value: `${b.gap >= 0 ? '+' : ''}${fcfa(b.gap)}`, icon: b.gap >= 0 ? 'trending_up' : 'trending_down', tone: b.gap >= 0 ? 'success' : 'danger' },
                    { label: 'Conflits', value: String(conflicts), icon: 'warning', tone: conflicts ? 'danger' : 'neutral' },
                ]}
            />
            <AlertList alerts={alerts} />
            <Toolbar
                actions={[
                    { label: 'Valider la sélection', icon: 'fact_check', variant: 'primary', onClick: () => actions.validate(o, s) },
                    { label: 'Réinitialiser la sélection', icon: 'restart_alt', onClick: () => actions.reset(o) },
                ]}
            />
            <DataTable
                icon="check_circle"
                title={`Profils sélectionnés · ${s.status.label}`}
                count={`${X.length} profil(s) · ${fcfa(b.total)}`}
                minWidth={1100}
                columns={['Ouvrier', 'Score', 'Coût journalier', 'Coût sur la durée', 'Conflit', 'Avertissements']}
                empty={{ icon: 'person_search', title: 'Aucun profil sélectionné', text: 'Sélectionnez des profils depuis les résultats.' }}
                rows={X.map((x) => ({
                    key: x.key,
                    onOpen: () => router.visit(`/matching/${x.matchId}`),
                    cells: [
                        <PersonCell name={x.name} sub={x.code} />,
                        <ProgressCell value={x.score} />,
                        <TextCell>{fcfa(x.cost)}</TextCell>,
                        <TextCell strong>{fcfa(b.per.find((y) => y.key === x.key)?.total ?? 0)}</TextCell>,
                        <TextCell muted={!x.conflict}>{x.conflict || '—'}</TextCell>,
                        <span style={{ display: 'block', minWidth: 200, color: x.warnings.length ? 'inherit' : 'var(--c-muted)' }}>{x.warnings.join(' · ') || '—'}</span>,
                    ],
                    actions: [
                        { icon: 'remove', label: 'Désélectionner', onClick: () => actions.toggle(x) },
                        { icon: 'swap_horiz', label: 'Passer en remplaçant', onClick: () => actions.replacement(x, 'Remplaçant principal') },
                    ],
                }))}
            />
            <DataTable
                icon="swap_horiz"
                title="Profils de remplacement"
                count={`${s.replacements.length} profil(s) · non comptés comme affectés, confirmation requise avant activation`}
                minWidth={900}
                columns={['Ouvrier', 'Rôle', 'Score', 'Coût journalier', 'Disponibilité']}
                empty={{ icon: 'swap_horiz', title: 'Aucun remplaçant', text: 'Désignez des remplaçants depuis la fiche d’un profil.' }}
                rows={s.replacements.map((r) => ({
                    key: r.key,
                    cells: [<PersonCell name={r.name} sub={r.code} />, <StatusBadge label={r.role} tone="n" />, <ProgressCell value={r.score} />, <TextCell>{fcfa(r.cost)}</TextCell>, <TextCell>{r.availability}</TextCell>],
                    actions: [
                        { icon: 'add', label: 'Activer (sélectionner)', onClick: () => actions.activate(r) },
                        { icon: 'close', label: 'Retirer', onClick: () => actions.replacement(r, null) },
                    ],
                }))}
            />
        </>
    );
}

function Excluded({ offer: o, counts, excluded, config, zone, actions, editNeed }: Props & { actions: ReturnType<typeof useMatchingActions>; editNeed: () => void }) {
    const count = (f: (criterion: string) => boolean) => excluded.filter((x) => x.blocking.some((b) => f(b.criterion))).length;
    const causes: [string, string, number, string][] = [
        ['Aucun ouvrier du métier', 'engineering', counts.all ? 0 : 1, `Aucun profil inscrit pour ${o.trade.toLowerCase()}`],
        ['Aucun ouvrier disponible', 'event_busy', count((c) => c === 'Disponibilité'), 'En congé ou indisponibles sur la période'],
        ['Conflits de planning', 'calendar_month', count((c) => c === 'Conflit de planning'), 'Mission en cours sur la période'],
        ['Distance excessive', 'near_me_disabled', count((c) => c === 'Distance maximale'), `Au-delà de ${config.radius_max} km (Paramètres)`],
        ['Budget insuffisant', 'money_off', count((c) => c === 'Rémunération maximale'), `Tarif au-delà de ${config.tolerance_max} % du budget`],
        ['Documents ou certifications manquants', 'description', count((c) => c === 'Document obligatoire' || c === 'Identité validée'), 'Pièce expirée ou identité non validée'],
        ['Profils suspendus ou litige', 'block', count((c) => c === 'Statut du compte' || c === 'Litige bloquant'), 'Interdiction absolue'],
        ['Déjà retenus ou refus', 'person_remove', count((c) => c === 'Déjà retenu' || c === 'Refus de la mission'), 'Déjà transmis ou ont refusé'],
        ['Expérience insuffisante', 'school', counts.belowExperience, `Profils éligibles sous le niveau ${o.experience.toLowerCase()}`],
        ['Seuil trop restrictif', 'tune', counts.belowThreshold, 'Profils sous le seuil « Match faible »'],
    ];

    return (
        <>
            <TileGrid
                tiles={[
                    { label: 'Profils analysés', value: String(counts.all), icon: 'groups', tone: 'info' },
                    { label: 'Profils éligibles', value: String(counts.ok), icon: 'check_circle', tone: counts.ok ? 'success' : 'danger' },
                    { label: 'Profils exclus', value: String(counts.excluded), icon: 'person_off', tone: 'danger', bar: counts.all ? (counts.excluded / counts.all) * 100 : 0, barColor: 'var(--c-danger)' },
                    { label: 'Postes restants', value: String(o.rest), icon: 'person_add', tone: 'warning' },
                ]}
            />
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,380px),1fr))', gap: 'var(--space-4)' }}>
                <Block icon="troubleshoot" title="Causes identifiées">
                    <Cards
                        empty="Aucune cause bloquante détectée."
                        items={causes
                            .filter((c) => c[2] > 0)
                            .sort((a, b) => b[2] - a[2])
                            .map(([title, icon, n, sub]) => ({ key: title, icon, kicker: `${n} profil${n > 1 ? 's' : ''}`, title, sub, badge: counts.all ? `${Math.round((n / counts.all) * 100)} %` : '—', tone: 'r' }))}
                    />
                </Block>
                <Block icon="build" title="Actions possibles">
                    <Cards
                        empty=""
                        items={(
                            [
                                ['Élargir la zone', 'travel_explore', `Rayon ${config.radius} / ${config.radius_max} km · Paramètres`, () => router.visit('/parametres')],
                                ['Ajuster la période', 'date_range', `${formatDate(o.startsOn)} → ${formatDate(o.endsOn)} · module Besoins`, editNeed],
                                ['Augmenter le budget', 'payments', `${fcfa(o.cost)}/j · module Besoins`, editNeed],
                                ['Réduire l’expérience minimale', 'school', `Niveau actuel : ${o.experience} · module Besoins`, editNeed],
                                ['Relancer le besoin', 'autorenew', 'Actualiser la publication', () => router.visit(`/besoins/${o.need.slug}/metiers`)],
                                ['Notifier les commerciaux', 'campaign', `Recrutement ciblé ${zone}`, () => actions.notifySales(o)],
                                ['Relancer le matching plus tard', 'schedule', 'Nouveau calcul après mise à jour des profils', () => router.visit(`/matching?besoin=${o.need.slug}`)],
                            ] as [string, string, string, () => void][]
                        ).map(([title, icon, sub, onClick]) => ({ key: title, icon, kicker: 'Action', title, sub, badge: 'Ouvrir', tone: 'b', onClick }))}
                    />
                </Block>
            </div>
            <AlertList alerts={[{ tone: 'info', icon: 'lock', title: 'Profils non éligibles', text: 'Exclus avant calcul du score. Une dérogation exige une justification, une confirmation et est inscrite au journal d’audit ; elle ne peut contourner une interdiction absolue.' }]} />
            <DataTable
                icon="person_off"
                title="Profils exclus"
                count={`${excluded.length} profil(s)`}
                minWidth={1400}
                columns={['Ouvrier', 'Critère bloquant', 'Valeur attendue', 'Valeur constatée', 'Contrôle', 'Action corrective', 'Type']}
                empty={{ icon: 'check_circle', title: 'Aucun profil exclu', text: 'Tous les profils analysés sont éligibles.' }}
                rows={excluded.map((x) => {
                    const y = x.blocking[0];
                    return {
                        key: x.key,
                        cells: [
                            <PersonCell name={x.name} sub={`${x.code}${x.blocking.length > 1 ? ` · ${x.blocking.length} critères` : ''}`} />,
                            <TextCell strong>{x.blocking.map((b) => b.criterion).join(', ')}</TextCell>,
                            <TextCell>{y.expected}</TextCell>,
                            <TextCell>{y.found}</TextCell>,
                            <TextCell>{formatDate(y.date)}</TextCell>,
                            <TextCell>{y.fix}</TextCell>,
                            <StatusBadge label={x.absolute ? 'Interdiction absolue' : 'Dérogeable'} tone={x.absolute ? 'r' : 'a'} />,
                        ],
                        actions: x.absolute ? [] : [{ icon: 'gpp_maybe', label: 'Demander une dérogation', onClick: () => actions.derogation(x) }],
                        menu: x.workerSlug ? [{ icon: 'person', label: 'Fiche de l’ouvrier', onClick: () => router.visit(`/ouvriers/${x.workerSlug}`) }] : [],
                    };
                })}
            />
        </>
    );
}
