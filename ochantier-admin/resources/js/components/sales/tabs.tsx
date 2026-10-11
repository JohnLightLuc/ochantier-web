import { FilterSelect } from '@/components/list/ListToolbar';
import { LocationCell, PersonCell, ProgressCell, TextCell } from '@/components/list/Table';
import { AlertList, Block, DataTable, KeyValues, TileGrid } from '@/components/workers/blocks';
import { HistoryList } from '@/components/workers/tabs';
import Icon from '@/components/ui/Icon';
import StatusBadge from '@/components/ui/StatusBadge';
import { useAdminUi } from '@/contexts/AdminUiContext';
import { formatDate } from '@/lib/format';
import type { SalesShowProps } from '@/types/sales';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { decimal, fcfa, type SalesActions } from './useSalesActions';
import { BarChart, ChartGrid } from './visuals';

export type SalesTabProps = SalesShowProps & { actions: SalesActions };

/** Convertit les alertes du serveur en bandeaux avec leur action. */
export function useSalesAlerts({ agent, alerts, actions }: SalesTabProps) {
    return alerts.map((a) => ({
        ...a,
        onAction: !a.action ? undefined : a.action.type === 'link' ? () => router.visit((a.action as { href: string }).href) : () => actions.addDocument(agent, (a.action as { document: string }).document),
    }));
}

/** « 2026-09-23 08:41 » → « 23 sept. 2026 · 08:41 ». */
export function formatLogin(value: string | null): string {
    if (!value) return '—';
    const [date, time] = value.split(' ');
    return `${formatDate(date)}${time ? ` · ${time}` : ''}`;
}

/** Vue d’ensemble : alertes, indicateurs du mois, graphiques, résumé, actions prioritaires et activités récentes. */
export function OverviewTab(props: SalesTabProps) {
    const { agent: a, current, previous, objectives, history, tradeBreakdown, documents, fieldActivities, activities, workers } = props;
    const alerts = useSalesAlerts(props);
    const base = `/commerciaux/${a.slug}`;
    const m = current?.metrics;
    const weekly = objectives.find((o) => o.type === 'Hebdomadaire');
    const weekValue = m ? Math.round(m.validated / 4.3) : 0;
    const monthGain = current?.gain ?? 0;
    const pendingDocs = documents.filter((d) => d.status.value !== 'verified').length;

    const cards = [
        m?.incomplete ? { icon: 'rule', kicker: 'Dossiers', title: `${m.incomplete} dossier(s) incomplet(s)`, sub: 'À compléter avant soumission', badge: 'À traiter', tone: 'a', href: `${base}/ouvriers` } : null,
        fieldActivities.some((f) => f.status.label === 'À vérifier') ? { icon: 'directions_walk', kicker: 'Terrain', title: 'Activités à vérifier', sub: 'Justificatifs à contrôler', badge: 'À vérifier', tone: 'a', href: `${base}/activite` } : null,
        pendingDocs ? { icon: 'folder_shared', kicker: 'Documents', title: `${pendingDocs} document(s) à traiter`, sub: 'Vérification du dossier commercial', badge: 'À traiter', tone: 'o', href: `${base}/profil-documents` } : null,
        current ? { icon: 'leaderboard', kicker: 'Classement', title: `Rang ${current.rank} · ${decimal(current.score)} / 100`, sub: current.performance.label, badge: 'Voir', tone: 'b', href: `${base}/classement` } : null,
    ].filter((c): c is NonNullable<typeof c> => !!c);

    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)' }}>
            <AlertList alerts={alerts} />
            <TileGrid
                tiles={[
                    { label: 'Ouvriers enregistrés (total)', value: String(a.recruitedTotal || workers.length), icon: 'engineering', tone: 'success' },
                    { label: 'Profils validés (mois)', value: String(m?.validated ?? 0), icon: 'verified', tone: 'success' },
                    { label: 'Profils rejetés (mois)', value: String(m?.rejected ?? 0), icon: 'cancel', tone: 'danger' },
                    { label: 'Dossiers incomplets', value: String(m?.incomplete ?? 0), icon: 'rule', tone: 'warning' },
                    { label: 'Taux de validation', value: m ? `${m.validationRate} %` : '—', icon: 'percent', tone: 'info', bar: m?.validationRate ?? 0 },
                    { label: 'Objectif de la semaine', value: weekly ? `${weekValue} / ${weekly.target}` : '—', icon: 'flag', tone: 'info', bar: weekly ? (weekValue / weekly.target) * 100 : 0 },
                    { label: 'Objectif du mois', value: m ? `${m.validated} / ${a.monthlyTarget}` : '—', icon: 'flag', tone: 'warning', bar: m?.objective ?? 0 },
                    { label: 'Score global · rang', value: current ? `${decimal(current.score)} · #${current.rank}` : '—', icon: 'leaderboard', tone: 'orange', sub: current ? (previous ? `Rang précédent : #${previous.rank}` : 'Premier classement') : 'Non classé' },
                    { label: 'Gain de la semaine', value: fcfa(Math.round(monthGain / 4)), icon: 'payments', tone: 'success' },
                    { label: 'Gain du mois', value: fcfa(monthGain), icon: 'account_balance_wallet', tone: 'success' },
                    { label: 'Dernière connexion', value: formatLogin(a.access.lastLogin), icon: 'login', tone: 'neutral' },
                    { label: 'Dernière activité terrain', value: a.lastActivityOn ? formatDate(a.lastActivityOn) : '—', icon: 'directions_walk', tone: 'neutral' },
                ]}
            />
            <ChartGrid>
                <BarChart title="Évolution des recrutements" sub="Ouvriers enregistrés par mois" bars={history.map((h) => ({ label: h.short, value: h.summary?.metrics.registered ?? 0, tip: `${h.label} : ${h.summary ? `${h.summary.metrics.registered} enregistrés · ${h.summary.metrics.validated} validés` : 'non classé'}` }))} />
                <BarChart title="Répartition par métier" sub="Ouvriers suivis" color="var(--c-accent-600)" bars={tradeBreakdown.map(([label, value]) => ({ label, value }))} />
            </ChartGrid>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,360px),1fr))', gap: 'var(--space-4)' }}>
                <Block icon="person" title="Résumé du profil">
                    <KeyValues
                        items={[
                            ['Statut du compte', a.status.label],
                            ['Invitation', a.invitation.label],
                            ['Disponibilité', a.availability.label],
                            ['Agence', a.agencyLabel],
                            ['Zones attribuées', [a.zone, ...a.secondaryZones].join(', ')],
                            ['Superviseur', a.supervisor ?? '—'],
                            ['Type', `${a.type} · ${a.contract ?? '—'}`],
                        ]}
                    />
                </Block>
                <Block icon="bolt" title="Actions prioritaires">
                    {cards.length ? (
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-2)' }}>
                            {cards.map((c) => (
                                <button key={c.kicker} type="button" onClick={() => router.visit(c.href)} className="och-hover-card" style={{ width: '100%', display: 'flex', alignItems: 'center', gap: 'var(--space-3)', padding: 'var(--space-3)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-lg)', background: 'var(--c-surface)', cursor: 'pointer', textAlign: 'left', fontFamily: 'var(--font-sans)' }}>
                                    <span style={{ width: 'var(--control-sm)', height: 'var(--control-sm)', flex: 'none', borderRadius: 'var(--radius-md)', display: 'grid', placeItems: 'center', background: 'var(--c-primary-50)' }}>
                                        <Icon name={c.icon} color="var(--c-primary-600)" />
                                    </span>
                                    <span style={{ flex: 1, minWidth: 0 }}>
                                        <span style={{ display: 'block', fontSize: 'var(--fs-xs)', color: 'var(--c-muted)' }}>{c.kicker}</span>
                                        <b style={{ display: 'block', fontSize: 'var(--fs-md)' }}>{c.title}</b>
                                        <span style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-ink-2)' }}>{c.sub}</span>
                                    </span>
                                    <StatusBadge label={c.badge} tone={c.tone as never} />
                                </button>
                            ))}
                        </div>
                    ) : (
                        <span style={{ color: 'var(--c-muted)' }}>Aucune action prioritaire.</span>
                    )}
                </Block>
                <Block icon="history" title="Activités récentes" action={{ label: 'Tout voir', icon: 'arrow_forward', onClick: () => router.visit(`${base}/historique`) }}>
                    <div style={{ display: 'flex', flexDirection: 'column' }}>
                        {activities.slice(0, 5).map((e) => (
                            <div key={e.id} style={{ display: 'flex', gap: 'var(--space-3)', padding: 'var(--space-2_5) 0', borderTop: '1px solid var(--c-line-soft)' }}>
                                <span style={{ width: 32, height: 32, flex: 'none', borderRadius: 'var(--radius-round)', display: 'grid', placeItems: 'center', background: `var(--c-${e.category.tone}-bg)` }}>
                                    <Icon name={e.category.icon} size="var(--icon-xs)" color={`var(--c-${e.category.tone}-fg)`} />
                                </span>
                                <span style={{ flex: 1, minWidth: 0, fontSize: 'var(--fs-md)' }}>
                                    <b>{e.author}</b> {e.action}
                                    {e.newValue ? ` · ${e.newValue}` : ''}
                                    <span style={{ display: 'block', fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>
                                        {formatDate(e.date)} · {e.time}
                                    </span>
                                </span>
                            </div>
                        ))}
                    </div>
                </Block>
            </div>
        </div>
    );
}

/** Profil et documents : informations personnelles et professionnelles, pièces justificatives. */
export function ProfileTab(props: SalesTabProps) {
    const { agent: a, documents, actions } = props;
    const alerts = useSalesAlerts(props);
    const base = `/commerciaux/${a.slug}`;
    const ui = useAdminUi();
    const edit = (step: number) => (a.isArchived ? null : { label: 'Modifier', icon: 'edit', onClick: () => router.visit(`${base}/modifier/${step}`) });

    const preview = (d: (typeof documents)[number]) =>
        ui.openPanel({
            kicker: `Document · ${d.kind}`,
            title: d.label,
            badges: [{ label: d.status.label, tone: d.status.tone }],
            meta: [
                ['Référence', d.reference ?? '—'],
                ['Fichier', d.fileName ?? '—'],
                ['Ajouté le', formatDate(d.addedAt)],
                ['Expiration', d.expiresAt ? formatDate(d.expiresAt) : 'Sans expiration'],
                ['Motif de rejet', d.rejectionReason ?? '—'],
                ['Commentaire interne', d.internalComment ?? '—'],
            ],
            actions: [
                { label: 'Télécharger', icon: 'download', onClick: () => (window.location.href = `${base}/documents/${d.id}/telechargement`) },
                ...(d.status.value === 'to_review' ? [{ label: 'Valider', icon: 'check_circle', variant: 'primary' as const, onClick: () => actions.validateDocument(a, d) }] : []),
            ],
        });

    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)' }}>
            <AlertList alerts={alerts} />
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,420px),1fr))', gap: 'var(--space-4)' }}>
                <Block icon="badge" title="Informations personnelles" action={edit(1)}>
                    <KeyValues
                        items={[
                            ['Prénom', a.firstName],
                            ['Nom', a.lastName],
                            ['Sexe', a.gender ?? '—'],
                            ['Date de naissance', formatDate(a.birthDate)],
                            ['Nationalité', a.nationality ?? '—'],
                            ['Téléphone principal', a.phone],
                            ['Téléphone secondaire', a.phoneSecondary ?? '—'],
                            ['E-mail', a.email],
                            ['Adresse', a.address ?? '—'],
                            ['Commune · ville', `${a.commune ?? '—'} · ${a.city ?? '—'}`],
                            ['Contact d’urgence', [a.emergencyContact, a.emergencyPhone].filter(Boolean).join(' · ') || '—'],
                        ]}
                    />
                </Block>
                <Block icon="work" title="Informations professionnelles" action={edit(2)}>
                    <KeyValues
                        items={[
                            ['Identifiant', a.code],
                            ['Fonction', a.jobTitle ?? '—'],
                            ['Type', a.type],
                            ['Agence', a.agencyLabel],
                            ['Zone principale', a.zone],
                            ['Zones secondaires', a.secondaryZones.join(', ') || '—'],
                            ['Responsable', a.supervisor ?? '—'],
                            ['Prise de fonction', formatDate(a.startedAt)],
                            ['Contrat', a.contract ?? '—'],
                            ['Objectifs', `${a.weeklyTarget} / semaine · ${a.monthlyTarget} / mois`],
                            ['Spécialités métiers', a.specialties.join(', ') || '—'],
                        ]}
                    />
                </Block>
            </div>
            <DataTable
                icon="folder_shared"
                title="Documents justificatifs"
                count={`${documents.filter((d) => d.status.value === 'verified').length} / ${documents.length} vérifiés`}
                minWidth={980}
                tools={a.isArchived ? [] : [{ label: 'Ajouter un document', icon: 'upload_file', variant: 'primary', onClick: () => actions.addDocument(a) }]}
                columns={['Document', 'Type', 'Référence', 'Ajouté le', 'Expiration', 'Statut', 'Commentaire']}
                empty={{ icon: 'folder_shared', title: 'Aucun document', text: 'Ajoutez les pièces du dossier.' }}
                rows={documents.map((d) => ({
                    key: String(d.id),
                    onOpen: d.hasFile ? () => preview(d) : undefined,
                    cells: [
                        <TextCell strong>{d.label}</TextCell>,
                        <TextCell>{d.kind}</TextCell>,
                        <TextCell muted={!d.reference}>{d.reference ?? '—'}</TextCell>,
                        <TextCell>{formatDate(d.addedAt)}</TextCell>,
                        <TextCell muted={!d.expiresAt}>{d.expiresAt ? formatDate(d.expiresAt) : 'Sans expiration'}</TextCell>,
                        <StatusBadge label={d.status.label} tone={d.status.tone} icon={d.status.value === 'verified' ? 'verified' : undefined} />,
                        <TextCell muted={!(d.internalComment || d.rejectionReason)}>{d.internalComment || d.rejectionReason || '—'}</TextCell>,
                    ],
                    actions: !d.hasFile
                        ? [{ icon: 'upload_file', label: 'Ajouter', onClick: () => actions.addDocument(a, d.type) }]
                        : [
                              { icon: 'visibility', label: 'Prévisualiser', onClick: () => preview(d) },
                              { icon: 'download', label: 'Télécharger', onClick: () => (window.location.href = `${base}/documents/${d.id}/telechargement`) },
                              ...(d.status.value === 'to_review'
                                  ? [
                                        { icon: 'check_circle', label: 'Valider', onClick: () => actions.validateDocument(a, d) },
                                        { icon: 'cancel', label: 'Rejeter', onClick: () => actions.rejectDocument(a, d) },
                                    ]
                                  : []),
                          ],
                    menu: d.hasFile
                        ? [
                              { icon: 'sync', label: 'Remplacer', onClick: () => actions.addDocument(a, d.type) },
                              { icon: 'chat', label: 'Ajouter un commentaire interne', onClick: () => actions.commentDocument(a, d) },
                          ]
                        : [],
                }))}
            />
        </div>
    );
}

const LEVELS: [string, (y: number) => boolean][] = [
    ['Débutant (moins de 3 ans)', (y) => y < 3],
    ['Confirmé (3 à 6 ans)', (y) => y >= 3 && y <= 6],
    ['Expert (7 ans et +)', (y) => y >= 7],
];

/** Ouvriers enregistrés par le commercial : indicateurs, filtres et portefeuille. */
export function WorkersTab({ agent: a, workers, current, referenceDate }: SalesTabProps) {
    const ui = useAdminUi();
    const [f, setF] = useState<Record<string, string>>({});
    const m = current?.metrics;
    const weekAgo = new Date(new Date(referenceDate).getTime() - 7 * 864e5).toISOString().slice(0, 10);
    const monthStart = `${referenceDate.slice(0, 7)}-01`;
    const uniq = (values: (string | null)[]) => [...new Set(values.filter(Boolean) as string[])].sort().map((v) => ({ value: v, label: v }));

    const list = workers.filter(
        (w) =>
            (!f.trade || w.trade === f.trade) &&
            (!f.status || w.status.label === f.status) &&
            (!f.availability || w.availability.label === f.availability) &&
            (!f.commune || w.commune === f.commune) &&
            (!f.level || LEVELS.find(([l]) => l === f.level)![1](w.experience)) &&
            (!f.quality || (f.quality === 'Complet' ? w.complete : !w.complete)) &&
            (!f.period || (f.period === 'week' ? (w.registeredAt ?? '') >= weekAgo : f.period === 'month' ? (w.registeredAt ?? '') >= monthStart : (w.registeredAt ?? '') < monthStart)),
    );
    const filter = (key: string, label: string, options: { value: string; label: string }[]) => <FilterSelect key={key} label={label} value={f[key] ?? ''} options={options} onChange={(v) => setF((x) => ({ ...x, [key]: v }))} />;
    const submit = (w: (typeof workers)[number]) =>
        ui.confirm({ icon: 'send', title: 'Soumettre pour validation ?', body: `${w.name} sera transmis à l’équipe de vérification.`, ok: 'Soumettre', onConfirm: () => router.post(`/commerciaux/${a.slug}/ouvriers/${w.slug}/soumission`, {}, { preserveScroll: true }) });

    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)' }}>
            <TileGrid
                tiles={[
                    { label: 'Enregistrés cette semaine', value: String(m ? Math.round(m.registered / 4.3) : 0), icon: 'today', tone: 'info' },
                    { label: 'Enregistrés ce mois', value: String(m?.registered ?? 0), icon: 'calendar_month', tone: 'info' },
                    { label: 'Dossiers complets', value: String(m?.complete ?? 0), icon: 'task_alt', tone: 'success' },
                    { label: 'Dossiers incomplets', value: String(m?.incomplete ?? 0), icon: 'rule', tone: 'warning' },
                    { label: 'Profils validés', value: String(m?.validated ?? 0), icon: 'verified', tone: 'success' },
                    { label: 'Profils rejetés', value: String(m?.rejected ?? 0), icon: 'cancel', tone: 'danger' },
                    { label: 'Taux de validation moyen', value: m ? `${m.validationRate} %` : '—', icon: 'percent', tone: 'info', bar: m?.validationRate ?? 0 },
                ]}
            />
            <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2_5)' }}>
                {filter('trade', 'Métier', uniq(workers.map((w) => w.trade)))}
                {filter('status', 'Statut', uniq(workers.map((w) => w.status.label)))}
                {filter('availability', 'Disponibilité', uniq(workers.map((w) => w.availability.label)))}
                {filter('period', 'Enregistrement', [{ value: 'week', label: 'Cette semaine' }, { value: 'month', label: 'Ce mois' }, { value: 'before', label: 'Avant ce mois' }])}
                {filter('commune', 'Localisation', uniq(workers.map((w) => w.commune)))}
                {filter('level', 'Expérience', LEVELS.map(([l]) => ({ value: l, label: l })))}
                {filter('quality', 'Qualité du dossier', ['Complet', 'Incomplet'].map((v) => ({ value: v, label: v })))}
            </div>
            <DataTable
                icon="engineering"
                title="Portefeuille d’ouvriers"
                count={`${list.length} ouvrier(s) suivi(s)`}
                minWidth={1600}
                tools={[
                    { label: 'Exporter la liste', icon: 'download', onClick: () => (window.location.href = '/ouvriers/export') },
                    { label: 'Enregistrer un ouvrier', icon: 'add', variant: 'primary', onClick: () => router.visit('/ouvriers/nouveau') },
                ]}
                columns={['Ouvrier', 'Métier principal', 'Téléphone', 'Localisation', 'Expérience', 'Coût journalier', 'Enregistré le', 'Qualité du dossier', 'Statut du profil', 'Validation', 'Disponibilité']}
                empty={workers.length ? { icon: 'engineering', title: 'Aucun résultat', text: 'Modifiez les filtres.' } : { icon: 'engineering', title: 'Aucun ouvrier enregistré', text: 'Les ouvriers créés par ce commercial apparaîtront ici.' }}
                rows={list.map((w) => ({
                    key: w.slug,
                    onOpen: () => router.visit(`/ouvriers/${w.slug}`),
                    cells: [
                        <PersonCell name={w.name} sub={w.code} />,
                        <TextCell>{w.trade}</TextCell>,
                        <TextCell>{w.phone}</TextCell>,
                        <LocationCell value={w.commune ?? '—'} />,
                        <TextCell>{w.experience} ans</TextCell>,
                        <TextCell muted={!w.dailyRate}>{w.dailyRate ? fcfa(w.dailyRate) : '—'}</TextCell>,
                        <TextCell>{formatDate(w.registeredAt)}</TextCell>,
                        <ProgressCell value={w.quality} />,
                        <StatusBadge label={w.status.label} tone={w.status.tone} />,
                        <StatusBadge label={w.validation.label} tone={w.validation.tone} />,
                        <StatusBadge label={w.availability.label} tone={w.availability.tone} />,
                    ],
                    actions: [
                        { icon: 'visibility', label: 'Consulter la fiche', onClick: () => router.visit(`/ouvriers/${w.slug}`) },
                        { icon: 'edit', label: 'Modifier', onClick: () => router.visit(`/ouvriers/${w.slug}/modifier`) },
                    ],
                    menu: [
                        { icon: 'rule', label: 'Compléter le dossier', onClick: () => router.visit(`/ouvriers/${w.slug}/modifier/4`) },
                        { icon: 'upload_file', label: 'Ajouter un document', onClick: () => router.visit(`/ouvriers/${w.slug}/identite-documents`) },
                        { icon: 'send', label: 'Soumettre pour validation', onClick: () => (w.canSubmit ? submit(w) : ui.toast(`Ce profil est déjà ${w.status.label.toLowerCase()}.`, { tone: 'error' })) },
                    ],
                }))}
            />
        </div>
    );
}

/** Activité terrain : filtres, détail avec justificatif, validation et signalement d’anomalie. */
export function FieldTab({ agent: a, fieldActivities, actions }: SalesTabProps) {
    const ui = useAdminUi();
    const [f, setF] = useState<Record<string, string>>({});
    const base = `/commerciaux/${a.slug}`;
    const list = fieldActivities.filter((x) => (!f.type || x.type === f.type) && (!f.status || x.status.label === f.status));
    const validate = (id: number) => router.post(`${base}/activites/${id}/validation`, {}, { preserveScroll: true });

    const detail = (x: (typeof fieldActivities)[number]) =>
        ui.openPanel({
            kicker: `Activité terrain · ${formatDate(x.date)} · ${x.time}`,
            title: x.type,
            badges: [{ label: x.status.label, tone: x.status.tone }],
            state: x.hasProof ? { kind: 'success', title: 'Justificatif photo', text: `Photo géolocalisée · ${x.location}` } : { kind: 'nodata', title: 'Aucun justificatif', text: 'Aucune photo ni document joint.' },
            meta: [
                ['Ouvrier concerné', x.worker?.name ?? '—'],
                ['Localisation', x.location],
                ['Commentaire', x.comment],
                ['Auteur', a.name],
            ],
            actions: [
                ...(x.worker ? [{ label: 'Fiche de l’ouvrier', icon: 'open_in_new', onClick: () => router.visit(`/ouvriers/${x.worker!.slug}`) }] : []),
                {
                    label: 'Signaler une anomalie',
                    icon: 'report',
                    variant: 'danger-soft' as const,
                    onClick: () => {
                        ui.closePanel();
                        actions.flagActivity(a, x.id, `${x.type} · ${formatDate(x.date)}`);
                    },
                },
            ],
        });

    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)' }}>
            <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2_5)' }}>
                <FilterSelect label="Type d’activité" value={f.type ?? ''} options={[...new Set(fieldActivities.map((x) => x.type))].map((v) => ({ value: v, label: v }))} onChange={(v) => setF((x) => ({ ...x, type: v }))} />
                <FilterSelect label="Statut" value={f.status ?? ''} options={['Validée', 'À vérifier', 'Anomalie signalée'].map((v) => ({ value: v, label: v }))} onChange={(v) => setF((x) => ({ ...x, status: v }))} />
            </div>
            <DataTable
                icon="directions_walk"
                title="Activités terrain"
                count={`${list.length} activité(s)`}
                minWidth={1300}
                tools={fieldActivities.length ? [{ label: 'Exporter', icon: 'download', onClick: () => (window.location.href = `${base}/activites/export`) }] : []}
                columns={['Date et heure', 'Type', 'Ouvrier concerné', 'Localisation', 'Commentaire', 'Justificatif', 'Statut']}
                empty={fieldActivities.length ? { icon: 'directions_walk', title: 'Aucun résultat', text: 'Modifiez les filtres.' } : { icon: 'directions_walk', title: 'Aucune activité terrain', text: 'Les activités apparaîtront après activation du compte.' }}
                rows={list.map((x) => ({
                    key: String(x.id),
                    onOpen: () => detail(x),
                    cells: [
                        <TextCell strong>
                            {formatDate(x.date)} · {x.time}
                        </TextCell>,
                        <TextCell>{x.type}</TextCell>,
                        <TextCell muted={!x.worker}>{x.worker?.name ?? '—'}</TextCell>,
                        <LocationCell value={x.location} />,
                        <TextCell>{x.comment}</TextCell>,
                        <span style={{ display: 'inline-flex', alignItems: 'center', gap: 'var(--space-1)', color: x.hasProof ? 'var(--c-success-fg)' : 'var(--c-muted)', fontSize: 'var(--fs-sm)' }}>
                            <Icon name={x.hasProof ? 'photo_camera' : 'hide_image'} size="var(--icon-xs)" />
                            {x.hasProof ? 'Photo jointe' : 'Aucun'}
                        </span>,
                        <StatusBadge label={x.status.label} tone={x.status.tone} />,
                    ],
                    actions: [{ icon: 'visibility', label: 'Détail', onClick: () => detail(x) }],
                    menu: [{ icon: 'report', label: 'Signaler une anomalie', onClick: () => actions.flagActivity(a, x.id, `${x.type} · ${formatDate(x.date)}`) }, ...(x.status.label !== 'Validée' ? [{ icon: 'verified', label: 'Valider l’activité', onClick: () => validate(x.id) }] : [])],
                }))}
            />
        </div>
    );
}

export function HistoryTab({ activities }: SalesTabProps) {
    return <HistoryList activities={activities} />;
}
