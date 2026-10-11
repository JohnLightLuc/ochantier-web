import { useAttendanceActions } from '@/components/attendances/useAttendanceActions';
import { TextCell } from '@/components/list/Table';
import DetailHeader from '@/components/matching/DetailHeader';
import Dialog from '@/components/overlays/Dialog';
import type { MenuItem } from '@/components/ui/Menu';
import StatusBadge from '@/components/ui/StatusBadge';
import { AlertList, Block, DataTable, KeyValues, TileGrid, Toolbar, type Tile } from '@/components/workers/blocks';
import { HistoryList } from '@/components/workers/tabs';
import { tone } from '@/lib/attendanceTones';
import { formatDate, formatNumber } from '@/lib/format';
import type { AttendanceEvent, AttendanceShowProps, Correction, Proof } from '@/types/attendances';
import { Head, router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';

const fcfa = (n: number) => `${formatNumber(n)} FCFA`;
const stamp = (v: string | null | undefined) => (v ? `${formatDate(v.slice(0, 10))} ${v.slice(11)}` : '—');
const ANOMALY_FLOW = ['À vérifier', 'En cours d’analyse', 'Justification demandée', 'Confirmée', 'Fausse alerte', 'Corrigée', 'Clôturée'];

/** Fiche d’une journée de pointage : arrivée, départ, temps de présence, preuves, anomalies, corrections, impact financier, historique. */
export default function AttendanceShow({ attendance: p, calc: c, anomalies, corrections, activities, tab, tabs, rules, options }: AttendanceShowProps) {
    const actions = useAttendanceActions(options);
    const [preview, setPreview] = useState<Proof | null>(null);
    const base = `/pointages/${p.reference}`;
    const src = ' · Valeur définie dans Paramètres';
    const L = c.labels;
    const go = (path: string) => () => router.visit(path);

    const headerActions = [
        { label: 'Demander une correction', icon: 'edit_note', onClick: () => actions.correction(p.reference) },
        ...(c.closed
            ? [{ label: 'Rouvrir', icon: 'lock_open', onClick: () => actions.reopen(p.reference) }, ...(p.transmission === 'Non transmise' ? [{ label: 'Transmettre aux Paiements', icon: 'send', onClick: () => actions.transmit(p.reference) }] : [])]
            : [{ label: c.closable ? 'Clôturer' : 'Clôturer exceptionnellement', icon: 'lock', onClick: () => actions.closeDays([{ reference: p.reference, closable: c.closable, closed: c.closed, blockers: c.blockers }], !c.closable) }]),
    ];
    const menu: MenuItem[] = [
        { icon: 'person', label: 'Voir l’ouvrier', onClick: go(`/ouvriers/${p.worker.slug}`) },
        { icon: 'construction', label: 'Voir le chantier', onClick: go(`/chantiers/${p.site.slug}`) },
        { icon: 'assignment_ind', label: 'Voir l’affectation', onClick: go(`/ouvriers/${p.worker.slug}/affectations`) },
        { icon: 'apartment', label: 'Voir le promoteur', onClick: go(p.site.promoterSlug ? `/promoteurs/${p.site.promoterSlug}` : `/promoteurs?q=${encodeURIComponent(p.site.promoter)}`) },
        { icon: 'report', label: 'Signaler une anomalie', onClick: () => actions.reportAnomaly(p.reference) },
        { icon: 'gavel', label: 'Régulariser', onClick: () => actions.regularize(p.reference) },
        { icon: 'notifications_active', label: 'Relancer le promoteur', onClick: () => actions.remind([p.reference]) },
        { icon: 'sticky_note_2', label: 'Note interne', onClick: () => actions.note(p.reference) },
        { icon: 'download', label: 'Exporter', onClick: () => (window.location.href = `/pointages/export?refs=${p.reference}`) },
        { icon: 'history', label: 'Pointages de l’ouvrier', onClick: go(`/pointages/ouvriers/${p.worker.slug}`) },
        { icon: 'calendar_view_week', label: 'Pointages du chantier', onClick: go(`/pointages/chantiers/${p.site.slug}`) },
    ];
    const banner = p.absence
        ? { tone: 'warning', icon: 'person_off', title: `Absence · ${p.absence.status.toLowerCase()}.`, text: p.absence.motif + (p.absence.comment ? ` · ${p.absence.comment}` : '') }
        : c.closed
          ? { tone: 'info', icon: 'lock', title: `${c.closureStatus}.`, text: `Par ${p.closure.by ?? 'Système'} · ${p.transmission.toLowerCase()}. Toute modification passe par une demande de correction ou une réouverture autorisée.` }
          : c.blockers.length && c.past
            ? { tone: 'warning', icon: 'lock_clock', title: 'Journée non clôturable.', text: c.blockers.join(' · ') }
            : null;

    const correctionTable = (filter: (x: Correction) => boolean) => {
        const list = corrections.filter(filter);
        return (
            <DataTable
                icon="edit_note"
                title="Corrections"
                count={`${list.length} demande(s)`}
                minWidth={1200}
                columns={['Date', 'Donnée', 'Ancienne valeur', 'Nouvelle valeur', 'Motif', 'Justificatif', 'Demandeur', 'Décision', 'Impact', 'Statut']}
                empty={{ icon: 'edit_off', title: 'Aucune correction', text: 'Aucune demande de correction.' }}
                rows={list.map((x) => ({
                    key: String(x.id),
                    cells: [
                        <TextCell key="d">{stamp(x.requestedAt)}</TextCell>,
                        <TextCell key="f" strong>
                            {x.field}
                        </TextCell>,
                        <TextCell key="o">{x.oldValue}</TextCell>,
                        <TextCell key="n" strong>
                            {x.newValue}
                        </TextCell>,
                        <TextCell key="m">{x.motif + (x.comment ? ` · ${x.comment}` : '')}</TextCell>,
                        <TextCell key="j">{x.proof ?? '—'}</TextCell>,
                        <TextCell key="b">{x.requestedBy}</TextCell>,
                        <TextCell key="de" muted={!x.decision}>
                            {x.decision ?? '—'}
                        </TextCell>,
                        <TextCell key="i" muted={!x.impact}>
                            {x.impact ?? '—'}
                        </TextCell>,
                        <StatusBadge key="s" label={x.status} tone={tone(x.status)} />,
                    ],
                    actions: ['Soumise', 'En attente'].includes(x.status)
                        ? [
                              { icon: 'check', label: 'Approuver et appliquer', onClick: () => actions.approveCorrection(p.reference, x) },
                              { icon: 'close', label: 'Rejeter', onClick: () => actions.rejectCorrection(p.reference, x) },
                          ]
                        : [],
                }))}
            />
        );
    };

    const eventItems = (e: AttendanceEvent, isArrival: boolean): [string, ReactNode][] => {
        const photo = e.proofs.filter((x) => x.type.startsWith('Photo'));
        const signature = e.proofs.find((x) => x.type === 'Signature électronique');
        const validated = ['Validée', 'Validé', 'Corrigée', 'Corrigé'].includes(e.status);
        return [
            ['Heure prévue', isArrival ? p.plannedStart : p.plannedEnd + (p.night ? ' (J+1)' : '')],
            ['Heure serveur enregistrée', e.time ?? '—'],
            ['Heure validée', validated ? e.time : 'Non validée'],
            ['Statut', <StatusBadge key="s" label={e.status} tone={tone(e.status)} />],
            ['Enregistré par', e.by ?? '—'],
            ['Date d’enregistrement', stamp(e.at)],
            ['Validé par', e.validated_by ?? '—'],
            ['Date de validation', stamp(e.validated_at)],
            ['Localisation du promoteur', e.location ? `${e.location.status} · ${e.location.meters} m du chantier · précision ${e.location.accuracy} m · ${e.location.source ?? 'position GPS du promoteur'}` : 'Indisponible'],
            ['Rayon autorisé', `${rules.radius} m (tolérance ${rules.zone_tolerance} m)${src}`],
            ['Photo', photo.map((x) => `${x.file} · ${x.status}`).join(', ') || 'Aucune'],
            ['Signature électronique', signature ? `Signée par ${e.by} · ${signature.status}` : 'Absente'],
            ['Session', 'Application Promoteur · session authentifiée'],
            ['Note (description)', e.comment ?? '—'],
            ...(isArrival
                ? [['Retard', L.late ? `${L.late} · ${p.decisions.find((d) => d.key === 'lateness')?.status ?? 'Retard constaté'}` : 'Dans la tolérance'] as [string, string]]
                : ([
                      ['Arrivée validée associée', c.arrivalValid ? p.arrival.time : 'Aucune'],
                      ['Départ anticipé', L.early ? `${L.early} · ${p.decisions.find((d) => d.key === 'early_leave')?.status ?? 'Départ anticipé'}` : 'Départ normal'],
                      ['Heures supplémentaires', L.overtime ? `${L.overtime} · ${p.decisions.find((d) => d.key === 'overtime')?.status ?? 'Détectées'}` : 'Aucune'],
                  ] as [string, string][])),
        ];
    };

    const decisionTools = (keys: string[]) =>
        p.decisions
            .filter((d) => keys.includes(d.key))
            .flatMap((d) => d.options.map((o) => ({ label: `${d.label} : ${o}`, icon: 'rule', onClick: () => actions.decide(p.reference, p.worker.name, d, o) })));

    const step = (ok: boolean, pending: boolean) => (ok ? 'Effectué' : pending ? 'En attente' : 'À venir');
    const chronology: [string, string, string, string][] = [
        ['Ouvrier attendu', `${formatDate(p.date)} ${p.plannedStart}`, `Affectation ${p.assignment}`, 'Effectué'],
        ['Arrivée enregistrée', p.arrivalEvent.at?.slice(11) ?? '', p.arrivalEvent.by ?? '', step(!!p.arrival.time, false)],
        ['Arrivée validée', p.arrivalEvent.validated_at?.slice(11) ?? '', p.arrivalEvent.validated_by ?? '', step(c.arrivalValid, c.arrivalPending)],
        ['Départ enregistré', p.departureEvent.at?.slice(11) ?? '', p.departureEvent.by ?? '', step(!!p.departure.time, c.arrivalValid)],
        ['Départ validé', p.departureEvent.validated_at?.slice(11) ?? '', p.departureEvent.validated_by ?? '', step(c.departureValid, c.departurePending)],
        ['Durée calculée', L.net, 'Système', step(c.net !== null, false)],
        ['Journée clôturée', p.closure.at ?? '', p.closure.by ?? '', step(c.closed, c.closable)],
        ['Données transmises', '', p.transmission === 'Non transmise' ? '' : 'Module Paiements', step(p.transmission !== 'Non transmise', c.closed)],
        ...(p.absence ? [['Absence déclarée', p.absence.at.slice(11), p.absence.by, p.absence.status] as [string, string, string, string]] : []),
    ];

    const proofs = [
        ...p.arrivalEvent.proofs.map((x) => ({ event: 'arrivee' as const, label: 'Arrivée', e: p.arrivalEvent, x })),
        ...p.departureEvent.proofs.map((x) => ({ event: 'depart' as const, label: 'Départ', e: p.departureEvent, x })),
    ];

    const timeTiles: Tile[] = [
        { label: 'Arrivée prévue', value: p.plannedStart, icon: 'schedule', tone: 'neutral' },
        { label: 'Arrivée validée', value: c.arrivalValid ? (p.arrival.time ?? '—') : '—', icon: 'login', tone: c.arrivalValid ? 'success' : 'neutral' },
        { label: 'Retard', value: L.late ?? 'Aucun', icon: 'running_with_errors', tone: L.late ? 'orange' : 'success' },
        { label: 'Départ prévu', value: p.plannedEnd, icon: 'schedule', tone: 'neutral' },
        { label: 'Départ validé', value: c.departureValid ? (p.departure.time ?? '—') : '—', icon: 'logout', tone: c.departureValid ? 'success' : 'neutral' },
        { label: 'Départ anticipé', value: L.early ?? 'Non', icon: 'directions_run', tone: L.early ? 'orange' : 'success' },
        { label: 'Durée brute', value: L.gross, icon: 'hourglass_top', tone: 'info' },
        { label: 'Pauses', value: L.pause, icon: 'coffee', tone: 'neutral' },
        { label: 'Durée nette', value: L.net, icon: 'timer', tone: 'success', sub: `Arrondi ${rules.rounding} min` },
        { label: 'Durée normale', value: L.plan, icon: 'event', tone: 'neutral' },
        { label: 'Heures supplémentaires', value: L.overtime ?? 'Aucune', icon: 'more_time', tone: L.overtime ? 'purple' : 'neutral' },
        { label: 'Temps non rémunéré', value: L.unpaid ?? 'Aucun', icon: 'money_off', tone: 'neutral' },
    ];

    const overtimeDecision = p.decisions.find((d) => d.key === 'overtime');
    const financeTiles: Tile[] = [
        { label: 'Journée', value: p.absence ? 'Absence' : c.arrivalValid && c.departureValid ? 'Complète' : 'Incomplète', icon: 'event', tone: p.absence ? 'danger' : c.arrivalValid && c.departureValid ? 'success' : 'warning' },
        { label: 'Durée nette', value: L.net, icon: 'timer', tone: 'info' },
        { label: 'Rémunération journalière', value: fcfa(c.cost), icon: 'payments', tone: 'info' },
        { label: 'Montant estimatif', value: c.amount === null ? 'Non calculable' : fcfa(c.amount), icon: 'calculate', tone: 'success', sub: 'Prorata de la durée nette' },
        { label: 'Retard', value: L.late ?? 'Aucun', icon: 'schedule', tone: L.late ? 'orange' : 'neutral' },
        { label: 'Départ anticipé', value: L.early ?? 'Non', icon: 'logout', tone: L.early ? 'orange' : 'neutral' },
        { label: 'Heures sup. validées', value: overtimeDecision && /Validées|Transmises|Autorisées/.test(overtimeDecision.status) && L.overtime ? L.overtime : 'Aucune', icon: 'more_time', tone: 'purple' },
        { label: 'Retenue potentielle', value: c.lateFlag || c.early ? 'Selon Paramètres · non appliquée' : 'Aucune', icon: 'remove_circle', tone: 'neutral' },
        { label: 'Prime potentielle', value: c.prime ? `${fcfa(c.prime)} (majoration ${rules.overtime_rate} %)` : 'Aucune', icon: 'add_circle', tone: c.prime ? 'success' : 'neutral' },
        { label: 'Transmission', value: p.transmission, icon: 'send', tone: p.transmission === 'Non transmise' ? 'neutral' : 'info' },
    ];

    return (
        <>
            <Head title={`${p.reference} · ${p.worker.name}`} />
            <DetailHeader
                icon="schedule"
                title={`${p.reference} · ${p.worker.name}`}
                badges={[p.status, p.tag]}
                sub={`${p.worker.trade} · ${p.site.name} · ${p.site.promoter}`}
                code={`${p.assignment} · ${formatDate(p.date)}${p.night ? ' · service de nuit' : ''}`}
                meta={[
                    ['login', 'Arrivée', `${p.arrival.time ?? '—'} · ${p.arrival.status}`],
                    ['logout', 'Départ', `${p.departure.time ?? '—'} · ${p.departure.status}`],
                    ['timer', 'Présence nette', L.net],
                    ['lock', 'Clôture', c.closureStatus],
                ]}
                actions={headerActions}
                menu={menu}
                banner={banner}
                kpis={[
                    { label: 'Présence nette', value: L.net, icon: 'timer', tone: 'b', hint: `Prévue ${L.plan}` },
                    { label: 'Retard', value: L.late ?? 'Aucun', icon: 'schedule', tone: L.late ? 'o' : 'g', hint: `Tolérance ${rules.late_tolerance} min` },
                    { label: 'Heures sup.', value: L.overtime ?? 'Aucune', icon: 'more_time', tone: L.overtime ? 'p' : 'n', hint: `Seuil ${rules.overtime_threshold} min` },
                    { label: 'Anomalies ouvertes', value: String(c.openAnomalies.length), icon: 'report', tone: c.openAnomalies.length ? 'r' : 'g', hint: `${c.pendingCorrections.length} correction(s) en attente` },
                ]}
                tabs={tabs}
                tab={tab}
                base={base}
            >
                {tab === '' && (
                    <>
                        <AlertList
                            alerts={[
                                ...c.openAnomalies.map((a) => ({ tone: ['Critique', 'Élevée'].includes(a.level) ? 'danger' : 'warning', icon: 'report', title: `${a.type} · ${a.level}`, text: a.note, cta: 'Traiter', onAction: go(`${base}/anomalies`) })),
                                ...c.pendingCorrections.map((x) => ({ tone: 'warning', icon: 'edit_note', title: 'Correction en attente', text: `${x.field} : ${x.old_value} → ${x.new_value}`, cta: 'Examiner', onAction: go(`${base}/corrections`) })),
                            ]}
                        />
                        <Block icon="summarize" title="Résumé de la journée">
                            <KeyValues
                                items={[
                                    ['Planning prévu', `${p.plannedStart} – ${p.plannedEnd}${p.night ? ` (service traversant minuit, rattaché au ${formatDate(p.date)})` : ''}`],
                                    ['Arrivée', `${p.arrival.time ?? '—'} · ${p.arrival.status}`],
                                    ['Départ', `${p.departure.time ?? '—'} · ${p.departure.status}`],
                                    ['Durée brute', L.gross],
                                    ['Pauses', L.pause],
                                    ['Durée nette', L.net],
                                    ['Retard', L.late ?? 'Aucun'],
                                    ['Départ anticipé', L.early ?? 'Non'],
                                    ['Heures supplémentaires', L.overtime ?? 'Aucune'],
                                    ['Absence', p.absence ? `${p.absence.motif} · ${p.absence.status}` : 'Non'],
                                    ['Preuves', `${proofs.length} fichier(s)`],
                                    ['Localisation', p.arrivalEvent.location?.status ?? 'Indisponible'],
                                    ['Clôture', c.closureStatus + (p.closure.reason ? ` · ${p.closure.reason}` : '')],
                                    ['Transmission', p.transmission],
                                ]}
                            />
                        </Block>
                        <DataTable
                            icon="timeline"
                            title="Chronologie"
                            count="Séquence obligatoire"
                            minWidth={800}
                            columns={['Étape', 'Horodatage', 'Auteur', 'Statut']}
                            empty={{ icon: 'timeline', title: 'Aucune étape', text: '' }}
                            rows={chronology.map(([label, at, by, st]) => ({
                                key: label,
                                cells: [
                                    <TextCell key="l" strong>
                                        {label}
                                    </TextCell>,
                                    <TextCell key="a" muted={!at}>
                                        {at || '—'}
                                    </TextCell>,
                                    <TextCell key="b" muted={!by}>
                                        {by || '—'}
                                    </TextCell>,
                                    <StatusBadge key="s" label={st} tone={st === 'Effectué' ? 'g' : st === 'En attente' ? 'a' : tone(st)} />,
                                ],
                                actions: [],
                            }))}
                        />
                    </>
                )}

                {(tab === 'arrivee' || tab === 'depart') && (
                    <>
                        <Toolbar actions={decisionTools(tab === 'arrivee' ? ['lateness', 'absence'] : ['early_leave', 'overtime'])} />
                        <Block icon={tab === 'arrivee' ? 'login' : 'logout'} title={tab === 'arrivee' ? 'Arrivée' : 'Départ'}>
                            <KeyValues items={eventItems(tab === 'arrivee' ? p.arrivalEvent : p.departureEvent, tab === 'arrivee')} />
                        </Block>
                        {correctionTable((x) => (tab === 'arrivee' ? /arrivée/.test(x.field) : /départ|pause/i.test(x.field)))}
                    </>
                )}

                {tab === 'temps' && (
                    <>
                        <TileGrid tiles={timeTiles} />
                        <Block icon="rule" title="Règles appliquées">
                            <KeyValues
                                items={[
                                    ['Durée brute', 'Départ validé − arrivée validée'],
                                    ['Durée nette', 'Durée brute − pauses validées'],
                                    ['Tolérance de retard', `${rules.late_tolerance} min${src}`],
                                    ['Pause', `${rules.pause} min · ${rules.pause_excluded ? 'exclue' : 'incluse'}${src}`],
                                    ['Arrondi', `${rules.rounding} min${src}`],
                                    ['Seuil heures supplémentaires', `${rules.overtime_threshold} min${src}`],
                                    ['Tolérance départ anticipé', `${rules.early_tolerance} min${src}`],
                                    ['Durée minimale validable', `${L.min}${src}`],
                                    ['Journée traversant minuit', `${rules.night_rule}${src}`],
                                ]}
                            />
                        </Block>
                        <DataTable
                            icon="coffee"
                            title="Pauses et interruptions"
                            count={`${p.pauses.length} interruption(s)`}
                            minWidth={900}
                            columns={['Type', 'Début', 'Fin', 'Durée', 'Auteur', 'Validation', 'Impact']}
                            empty={{ icon: 'coffee', title: 'Aucune pause', text: 'Aucune interruption enregistrée.' }}
                            rows={p.pauses.map((x, i) => ({
                                key: String(i),
                                cells: [
                                    <TextCell key="t" strong>
                                        {x.type}
                                    </TextCell>,
                                    <TextCell key="s">{x.start}</TextCell>,
                                    <TextCell key="e">{x.end}</TextCell>,
                                    <TextCell key="d">{`${Math.floor(x.minutes / 60)} h ${String(x.minutes % 60).padStart(2, '0')}`}</TextCell>,
                                    <TextCell key="b">{x.by ?? '—'}</TextCell>,
                                    <StatusBadge key="v" label={x.validated ? 'Validée' : 'En attente de validation'} tone={x.validated ? 'g' : 'a'} />,
                                    <TextCell key="i">{rules.pause_excluded ? `−${x.minutes} min sur la durée nette` : 'Aucun'}</TextCell>,
                                ],
                                actions: [],
                            }))}
                        />
                    </>
                )}

                {tab === 'preuves' && (
                    <>
                        <AlertList
                            alerts={[
                                { tone: 'info', icon: 'policy', title: 'Une photo n’est jamais la seule source de vérité', text: 'Elle est croisée avec l’heure serveur, la localisation, la session authentifiée et la validation du promoteur.' },
                                ...(c.proofMissing ? [{ tone: 'warning', icon: 'image_not_supported', title: 'Preuve obligatoire manquante', text: 'La photo de présence est exigée (Paramètres).', cta: 'Relancer le promoteur', onAction: () => actions.remind([p.reference]) }] : []),
                            ]}
                        />
                        <DataTable
                            icon="photo_library"
                            title="Preuves de pointage"
                            count={`${proofs.length} preuve(s)`}
                            minWidth={1300}
                            columns={['Événement', 'Type', 'Fichier', 'Horodatage', 'Auteur', 'Position', 'Session', 'Empreinte', 'Version', 'Statut']}
                            empty={{ icon: 'hide_image', title: 'Aucune preuve', text: 'Aucune preuve transmise.' }}
                            rows={proofs.map(({ event, label, e, x }) => ({
                                key: `${event}-${x.id}`,
                                cells: [
                                    <TextCell key="e">{label}</TextCell>,
                                    <TextCell key="t" strong>
                                        {x.type}
                                    </TextCell>,
                                    <TextCell key="f">{x.file}</TextCell>,
                                    <TextCell key="a">{stamp(x.at)}</TextCell>,
                                    <TextCell key="b">{x.by}</TextCell>,
                                    <TextCell key="p">{e.location?.status ?? '—'}</TextCell>,
                                    <TextCell key="s">Application Promoteur</TextCell>,
                                    <TextCell key="h">{x.hash}</TextCell>,
                                    <TextCell key="v">{`v${x.version}`}</TextCell>,
                                    <StatusBadge key="st" label={x.status} tone={tone(x.status)} />,
                                ],
                                actions: [{ icon: 'visibility', label: 'Prévisualiser', onClick: () => setPreview(x) }],
                                menu: [
                                    { icon: 'verified', label: 'Vérifier', onClick: () => actions.proof(p.reference, event, x, 'verification') },
                                    { icon: 'block', label: 'Rejeter', danger: true, onClick: () => actions.proof(p.reference, event, x, 'rejet') },
                                    { icon: 'sync', label: 'Demander un remplacement', onClick: () => actions.proof(p.reference, event, x, 'remplacement') },
                                    { icon: 'sticky_note_2', label: 'Commentaire interne', onClick: () => actions.proof(p.reference, event, x, 'commentaire') },
                                ],
                            }))}
                        />
                    </>
                )}

                {tab === 'anomalies' && (
                    <>
                        <Toolbar actions={[{ label: 'Signaler une anomalie', icon: 'report', variant: 'primary', onClick: () => actions.reportAnomaly(p.reference) }]} />
                        <DataTable
                            icon="report"
                            title="Anomalies"
                            count={`${anomalies.length} anomalie(s)`}
                            minWidth={1100}
                            columns={['Détection', 'Type', 'Niveau', 'Description', 'Décision', 'Statut']}
                            empty={{ icon: 'verified', title: 'Aucune anomalie', text: 'Aucune anomalie détectée pour ce pointage.' }}
                            rows={anomalies.map((a) => ({
                                key: String(a.id),
                                cells: [
                                    <TextCell key="d">{formatDate(a.detectedOn)}</TextCell>,
                                    <TextCell key="t" strong>
                                        {a.type}
                                    </TextCell>,
                                    <StatusBadge key="l" label={a.level} tone={tone(a.level)} />,
                                    <TextCell key="n">{a.note}</TextCell>,
                                    <TextCell key="de" muted={!a.decision}>
                                        {a.decision ?? '—'}
                                    </TextCell>,
                                    <StatusBadge key="s" label={a.status} tone={tone(a.status)} />,
                                ],
                                actions: [],
                                menu: ANOMALY_FLOW.filter((s) => s !== a.status).map((s) => ({ icon: 'label', label: s, onClick: () => actions.setAnomaly(p.reference, a, s) })),
                            }))}
                        />
                    </>
                )}

                {tab === 'corrections' && (
                    <>
                        <Toolbar actions={[{ label: 'Nouvelle demande de correction', icon: 'edit_note', variant: 'primary', onClick: () => actions.correction(p.reference) }]} />
                        {correctionTable(() => true)}
                    </>
                )}

                {tab === 'impact-financier' && (
                    <>
                        <AlertList alerts={[{ tone: 'info', icon: 'account_balance', title: 'Estimation uniquement', text: `Le module Pointages ne déclenche aucun paiement, ne modifie pas le portefeuille et n’applique aucune retenue : les données sont transmises au module Paiements ${String(rules.transmission).toLowerCase()} (Paramètres).` }]} />
                        <TileGrid tiles={financeTiles} />
                    </>
                )}

                {tab === 'historique' && (
                    <>
                        <HistoryList activities={activities} />
                        {p.notes.length > 0 && (
                            <DataTable
                                icon="sticky_note_2"
                                title="Notes internes"
                                count={`${p.notes.length} note(s)`}
                                minWidth={700}
                                columns={['Date', 'Auteur', 'Note']}
                                empty={{ icon: 'sticky_note_2', title: 'Aucune note', text: '' }}
                                rows={p.notes.map((n, i) => ({ key: String(i), cells: [<TextCell key="d">{stamp(n.date)}</TextCell>, <TextCell key="b">{n.by}</TextCell>, <span key="t">{n.text}</span>], actions: [] }))}
                            />
                        )}
                    </>
                )}
            </DetailHeader>

            {preview && (
                <Dialog title={preview.type} body={`Preuve · ${p.reference}`} icon="image" okLabel="Fermer" onClose={() => setPreview(null)} onSubmit={() => setPreview(null)}>
                    <KeyValues
                        items={[
                            ['Fichier', preview.file],
                            ['Horodatage', stamp(preview.at)],
                            ['Auteur', preview.by],
                            ['Empreinte', preview.hash],
                            ['Statut', preview.status],
                            ['Commentaire interne', preview.comment ?? '—'],
                        ]}
                    />
                    <p style={{ margin: 'var(--space-3) 0 0', fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>Aperçu du fichier non disponible : les médias sont conservés par l’Application Promoteur.</p>
                </Dialog>
            )}
            {actions.dialog}
        </>
    );
}
