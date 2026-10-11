import FormDialog from '@/components/overlays/FormDialog';
import { LocationCell, TextCell } from '@/components/list/Table';
import Icon from '@/components/ui/Icon';
import StatusBadge from '@/components/ui/StatusBadge';
import { useAdminUi } from '@/contexts/AdminUiContext';
import { formatDate, formatNumber } from '@/lib/format';
import { workerTone } from '@/lib/workerTones';
import type { WorkerAlert, WorkerDocument, WorkerExperience, WorkerShowProps } from '@/types/workers';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { AlertList, Block, Chips, DataTable, KeyValues, TileGrid } from './blocks';
import type { WorkerActions } from './useWorkerActions';

export type TabProps = WorkerShowProps & { actions: WorkerActions };

/** Convertit les alertes du serveur en bandeaux avec leur action. */
export function useAlerts({ worker, alerts, demo, actions }: TabProps) {
    const ui = useAdminUi();
    return alerts.map((a: WorkerAlert) => ({
        ...a,
        onAction: !a.action
            ? undefined
            : a.action.type === 'link'
              ? () => router.visit((a.action as { href: string }).href)
              : a.action.type === 'document'
                ? () => actions.addDocument(worker, (a.action as { document: string }).document)
                : () => (demo.assignments.length ? actions.unavailability(worker, demo.assignments, true) : ui.toast('Aucune affectation active.', { tone: 'info' })),
    }));
}

/** Vue d’ensemble : alertes, indicateurs, résumé, missions, événements récents. */
export function OverviewTab(props: TabProps) {
    const { worker: w, documents, demo, activities, actions, options, commissionRate } = props;
    const alerts = useAlerts(props);
    const net = Math.round((w.dailyRate * (100 - commissionRate)) / 100);
    const verified = documents.filter((d) => d.status.value === 'verified').length;
    const lastIn = demo.timesheets.find((t) => t.in);
    const current = demo.assignments.find((a) => a.status === 'En cours');
    const next = demo.assignments.filter((a) => ['Confirmée', 'Acceptée', 'En attente d’acceptation', 'Proposée'].includes(a.status)).sort((a, b) => a.start.localeCompare(b.start))[0];
    const cards = [current && ['Mission actuelle', current], next && ['Prochain chantier', next]].filter(Boolean) as [string, (typeof demo.assignments)[number]][];
    const s = demo.stats;

    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)' }}>
            <AlertList alerts={alerts} />
            <TileGrid
                tiles={[
                    { label: 'Taux de présence', value: `${s.presence} %`, icon: 'how_to_reg', tone: 'success', bar: s.presence },
                    { label: 'Taux d’acceptation des offres', value: `${s.acceptance} %`, icon: 'thumb_up', tone: 'info', bar: s.acceptance },
                    { label: 'Taux d’annulation', value: `${s.cancellation} %`, icon: 'event_busy', tone: 'danger', bar: s.cancellation * 5, barColor: 'var(--c-danger)' },
                    { label: 'Dernier pointage', value: lastIn ? `${lastIn.in} · ${formatDate(lastIn.date)}` : '—', icon: 'schedule', tone: 'neutral', sub: lastIn ? lastIn.site : 'Aucun pointage' },
                ]}
            />
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,360px),1fr))', gap: 'var(--space-4)' }}>
                <Block icon="person" title="Résumé du profil">
                    <KeyValues
                        items={[
                            ['Métier principal', w.trade],
                            ['Principales compétences', w.skills.slice(0, 3).join(', ')],
                            ['Niveau d’expérience', `${w.qualification ?? '—'} · ${w.experienceYears} ans`],
                            ['Coût journalier', `${formatNumber(w.dailyRate)} FCFA · net ${formatNumber(net)} FCFA`],
                            ['Disponibilité actuelle', w.availability.label],
                            ['Statut de vérification', `${w.status.label} · ${verified} / ${documents.length} documents vérifiés`],
                        ]}
                    />
                </Block>
                <Block icon="assignment_ind" title="Missions" action={w.status.value === 'validated' ? { label: 'Affecter', icon: 'add', onClick: () => actions.assign(w, options.sites) } : null}>
                    <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-2)' }}>
                        {cards.map(([kicker, a]) => (
                            <button
                                key={kicker}
                                type="button"
                                data-component="AssignmentCard"
                                onClick={() => router.visit(`/ouvriers/${w.slug}/affectations`)}
                                className="och-hover-card"
                                style={{ width: '100%', display: 'flex', alignItems: 'center', gap: 'var(--space-3)', padding: 'var(--space-3)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-lg)', background: 'var(--c-surface)', cursor: 'pointer', textAlign: 'left' }}
                            >
                                <span style={{ width: 'var(--control-sm)', height: 'var(--control-sm)', flex: 'none', borderRadius: 'var(--radius-md)', display: 'grid', placeItems: 'center', background: 'var(--c-primary-50)' }}>
                                    <Icon name="construction" color="var(--c-primary-600)" />
                                </span>
                                <span style={{ flex: 1, minWidth: 0 }}>
                                    <span style={{ display: 'block', fontSize: 'var(--fs-xs)', color: 'var(--c-muted)', textTransform: 'uppercase', letterSpacing: '.05em' }}>{kicker}</span>
                                    <b style={{ display: 'block' }}>{a.site}</b>
                                    <span style={{ display: 'block', fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>
                                        {a.promoter} · {formatDate(a.start)} → {formatDate(a.end)} · {a.pay}
                                    </span>
                                </span>
                                <StatusBadge label={a.status} tone={workerTone(a.status)} />
                            </button>
                        ))}
                        {!cards.length && <span style={{ fontSize: 'var(--fs-md)', color: 'var(--c-muted)' }}>Aucune mission en cours ni à venir.</span>}
                    </div>
                </Block>
                <Block icon="history" title="Événements récents" action={{ label: 'Tout voir', icon: 'arrow_forward', onClick: () => router.visit(`/ouvriers/${w.slug}/historique`) }}>
                    {activities.slice(0, 5).map((e) => (
                        <div key={e.id} style={{ display: 'flex', gap: 'var(--space-2_5)', padding: 'var(--space-2) 0', borderTop: '1px solid var(--c-line-soft)' }}>
                            <span style={{ width: 'var(--control-xs)', height: 'var(--control-xs)', flex: 'none', borderRadius: 'var(--radius-round)', display: 'grid', placeItems: 'center', background: `var(--c-${e.category.tone}-bg)` }}>
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
                </Block>
            </div>
        </div>
    );
}

/** Identité, pièce d’identité, suivi commercial et pièces justificatives. */
export function IdentityTab(props: TabProps) {
    const { worker: w, documents, actions } = props;
    const ui = useAdminUi();
    const alerts = useAlerts(props);
    const archived = w.status.value === 'archived';
    const verified = documents.filter((d) => d.status.value === 'verified').length;
    const base = `/ouvriers/${w.slug}`;
    const download = (d: WorkerDocument) => (window.location.href = `${base}/documents/${d.id}/telechargement`);

    const preview = (d: WorkerDocument) =>
        ui.openPanel({
            kicker: `Aperçu du document · ${w.name}`,
            title: d.label,
            badges: [{ label: d.status.label, tone: d.status.tone }],
            state: d.hasFile ? undefined : { kind: 'missing', title: 'Document manquant', text: 'Ce document n’a pas encore été déposé.' },
            meta: [
                ['Type', d.kind],
                ['Référence', d.reference ?? '—'],
                ['Fichier', d.fileName ?? '—'],
                ['Ajouté le', formatDate(d.addedAt)],
                ['Expiration', d.expiresAt ? formatDate(d.expiresAt) : 'Sans expiration'],
                ['Statut', d.status.label],
                ...(d.rejectionReason ? ([['Motif du rejet', d.rejectionReason]] as [string, string][]) : []),
                ...(d.internalComment ? ([['Commentaire interne', d.internalComment]] as [string, string][]) : []),
            ],
            actions: [
                ...(d.hasFile ? [{ label: 'Télécharger', icon: 'download', onClick: () => download(d) }] : []),
                ...(d.status.value === 'to_review'
                    ? [
                          { label: 'Rejeter', icon: 'cancel', variant: 'danger-soft' as const, onClick: () => actions.rejectDocument(w, d) },
                          { label: 'Valider', icon: 'check', variant: 'primary' as const, onClick: () => actions.validateDocument(w, d) },
                      ]
                    : []),
                ...(archived ? [] : [{ label: d.hasFile ? 'Remplacer' : 'Ajouter', icon: 'upload_file', variant: (d.hasFile ? 'secondary' : 'primary') as 'secondary' | 'primary', onClick: () => actions.addDocument(w, d.type) }]),
            ],
        });

    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)' }}>
            <AlertList alerts={alerts} />
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,360px),1fr))', gap: 'var(--space-4)' }}>
                <Block full icon="badge" title="Informations personnelles" action={archived ? null : { label: 'Modifier', icon: 'edit', onClick: () => router.visit(`${base}/modifier/1`) }}>
                    <KeyValues
                        items={[
                            ['Prénom', w.firstName],
                            ['Nom', w.lastName],
                            ['Sexe', w.gender],
                            ['Date de naissance', formatDate(w.birthDate)],
                            ['Nationalité', w.nationality],
                            ['Téléphone principal', w.phone],
                            ['Téléphone secondaire', w.phoneSecondary],
                            ['Adresse e-mail', w.email],
                            ['Adresse', w.address],
                            ['Commune', w.commune],
                            ['Ville', w.city],
                            ['Pays', w.country],
                            ['Contact d’urgence', w.emergencyContact ? `${w.emergencyContact} · ${w.emergencyPhone ?? ''}` : null],
                        ]}
                    />
                </Block>
                <Block icon="verified_user" title="Pièce d’identité">
                    <KeyValues
                        items={[
                            ['Type', w.idType],
                            ['Numéro', w.idNumber],
                            ['Délivrée le', formatDate(w.idIssuedAt)],
                            ['Expire le', formatDate(w.idExpiresAt)],
                            ['Documents vérifiés', `${verified} / ${documents.length}`],
                        ]}
                    />
                </Block>
                <Block icon="storefront" title="Informations commerciales">
                    <KeyValues
                        items={[
                            ['Commercial recruteur', w.recruiter],
                            ['Zone commerciale', w.salesZone],
                            ['Date d’enregistrement', formatDate(w.registeredAt)],
                            ['Canal d’acquisition', w.acquisitionChannel],
                            ['Commentaire', w.salesNote],
                        ]}
                    />
                </Block>
            </div>
            <DataTable
                icon="folder_shared"
                title="Documents justificatifs"
                count={`${verified} / ${documents.length} vérifiés`}
                minWidth={980}
                tools={archived ? [] : [{ label: 'Ajouter un document', icon: 'upload_file', variant: 'primary', onClick: () => actions.addDocument(w) }]}
                columns={['Document', 'Type', 'Référence', 'Ajouté le', 'Expiration', 'Statut', 'Commentaire']}
                empty={{ icon: 'folder_shared', title: 'Aucun document', text: '' }}
                rows={documents.map((d) => ({
                    key: String(d.id),
                    onOpen: () => preview(d),
                    cells: [
                        <TextCell strong>{d.label}</TextCell>,
                        <TextCell>{d.kind}</TextCell>,
                        <TextCell muted={!d.reference}>{d.reference ?? '—'}</TextCell>,
                        <TextCell>{formatDate(d.addedAt)}</TextCell>,
                        <TextCell muted={!d.expiresAt}>{d.expiresAt ? formatDate(d.expiresAt) : 'Sans expiration'}</TextCell>,
                        <StatusBadge label={d.status.label} tone={d.status.tone} icon={d.status.value === 'verified' ? 'verified' : undefined} />,
                        <TextCell muted={!(d.internalComment || d.rejectionReason)}>{d.internalComment || d.rejectionReason || '—'}</TextCell>,
                    ],
                    actions: d.hasFile
                        ? [
                              { icon: 'visibility', label: 'Prévisualiser', onClick: () => preview(d) },
                              { icon: 'download', label: 'Télécharger', onClick: () => download(d) },
                              ...(d.status.value === 'to_review'
                                  ? [
                                        { icon: 'check_circle', label: 'Valider', onClick: () => actions.validateDocument(w, d) },
                                        { icon: 'cancel', label: 'Rejeter', onClick: () => actions.rejectDocument(w, d) },
                                    ]
                                  : []),
                          ]
                        : archived
                          ? []
                          : [{ icon: 'upload_file', label: 'Ajouter', onClick: () => actions.addDocument(w, d.type) }],
                    menu:
                        d.hasFile && !archived
                            ? [
                                  { icon: 'sync', label: 'Remplacer', onClick: () => actions.addDocument(w, d.type) },
                                  { icon: 'chat', label: 'Ajouter un commentaire interne', onClick: () => actions.commentDocument(w, d) },
                                  ...(d.status.value === 'verified' ? [{ icon: 'cancel', label: 'Rejeter', danger: true, onClick: () => actions.rejectDocument(w, d) }] : []),
                              ]
                            : [],
                }))}
            />
        </div>
    );
}

const LISTS: [string, 'secondaryTrades' | 'specialties' | 'skills' | 'certifications', string, string][] = [
    ['metiers-secondaires', 'secondaryTrades', 'Métiers secondaires', 'handyman'],
    ['specialites', 'specialties', 'Spécialités', 'category'],
    ['competences', 'skills', 'Compétences', 'psychology'],
    ['certifications', 'certifications', 'Certifications', 'workspace_premium'],
];

/** Métier, tarif, listes de compétences et expériences professionnelles. */
export function SkillsTab({ worker: w, experiences, options, actions }: TabProps) {
    const ui = useAdminUi();
    const archived = w.status.value === 'archived';
    const base = `/ouvriers/${w.slug}`;

    const addValue = (slug: string, title: string, attr: (typeof LISTS)[number][1]) =>
        actions.open(
            <FormDialog
                title={`Ajouter · ${title}`}
                icon="add"
                okLabel="Ajouter"
                url={`${base}/listes/${slug}`}
                onClose={actions.close}
                fields={[
                    attr === 'secondaryTrades'
                        ? { name: 'value', label: 'Métier', type: 'select', required: true, full: true, options: options.trades.filter((t) => t !== w.trade && !w.secondaryTrades.includes(t)) }
                        : { name: 'value', label: title.replace(/s$/, ''), required: true, full: true },
                ]}
            />,
        );

    const removeValue = (slug: string, value: string) =>
        ui.confirm({ tone: 'danger', icon: 'delete', title: `Supprimer « ${value} » ?`, body: 'Cette valeur sera retirée du profil.', ok: 'Supprimer', onConfirm: () => router.delete(`${base}/listes/${slug}`, { data: { value }, preserveScroll: true }) });

    const experienceDialog = (e?: WorkerExperience) =>
        actions.open(
            <FormDialog
                title={e ? 'Modifier l’expérience' : 'Ajouter une expérience'}
                icon="work_history"
                okLabel="Enregistrer"
                url={e ? `${base}/experiences/${e.id}` : `${base}/experiences`}
                method={e ? 'put' : 'post'}
                onClose={actions.close}
                initial={e ? { company: e.company, mission: e.mission, trade: e.trade, site: e.site ?? '', location: e.location ?? '', reference: e.reference ?? '', started_at: e.startedAt, ended_at: e.endedAt ?? '', description: e.description ?? '' } : { trade: w.trade }}
                fields={[
                    { name: 'company', label: 'Entreprise ou promoteur', required: true },
                    { name: 'mission', label: 'Intitulé de la mission', required: true },
                    { name: 'trade', label: 'Métier exercé', type: 'select', required: true, options: options.trades },
                    { name: 'site', label: 'Chantier' },
                    { name: 'location', label: 'Localisation' },
                    { name: 'reference', label: 'Référence professionnelle', placeholder: 'Nom et téléphone' },
                    { name: 'started_at', label: 'Date de début', type: 'date', required: true },
                    { name: 'ended_at', label: 'Date de fin', type: 'date' },
                    { name: 'description', label: 'Description', type: 'textarea' },
                ]}
            />,
        );

    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)' }}>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,360px),1fr))', gap: 'var(--space-4)' }}>
                <Block icon="engineering" title="Métier principal et tarif" action={archived ? null : { label: 'Modifier', icon: 'edit', onClick: () => router.visit(`${base}/modifier/2`) }}>
                    <KeyValues
                        items={[
                            ['Métier principal', w.trade],
                            ['Niveau de qualification', w.qualification],
                            ['Années d’expérience', `${w.experienceYears} ans`],
                            ['Coût journalier estimé', `${formatNumber(w.dailyRate)} FCFA`],
                            ['Mobilité', w.mobility],
                            ['Zones d’intervention', w.zones.join(', ')],
                            ['Missions contractuelles', w.acceptsContracts ? 'Oui' : 'Non'],
                        ]}
                    />
                </Block>
                {LISTS.map(([slug, attr, title, icon]) => (
                    <Block key={slug} icon={icon} title={title} action={archived ? null : { label: 'Ajouter', icon: 'add', onClick: () => addValue(slug, title, attr) }}>
                        <Chips values={w[attr]} onRemove={archived ? undefined : (v) => removeValue(slug, v)} />
                    </Block>
                ))}
            </div>
            <DataTable
                icon="work_history"
                title="Expériences professionnelles"
                count={`${experiences.length} expérience(s)`}
                minWidth={980}
                tools={archived ? [] : [{ label: 'Ajouter une expérience', icon: 'add', variant: 'primary', onClick: () => experienceDialog() }]}
                columns={['Entreprise / promoteur', 'Mission', 'Métier', 'Chantier', 'Localisation', 'Période', 'Référence']}
                empty={{ icon: 'work_history', title: 'Aucune expérience', text: 'Ajoutez les missions passées de l’ouvrier.' }}
                rows={experiences.map((e) => ({
                    key: String(e.id),
                    cells: [
                        <TextCell strong>{e.company}</TextCell>,
                        <TextCell>{e.mission}</TextCell>,
                        <TextCell>{e.trade}</TextCell>,
                        <TextCell>{e.site || '—'}</TextCell>,
                        <LocationCell value={e.location || '—'} />,
                        <TextCell>
                            {formatDate(e.startedAt)} → {e.endedAt ? formatDate(e.endedAt) : 'en cours'}
                        </TextCell>,
                        <TextCell muted={!e.reference}>{e.reference || '—'}</TextCell>,
                    ],
                    actions: archived
                        ? []
                        : [
                              { icon: 'edit', label: 'Modifier', onClick: () => experienceDialog(e) },
                              {
                                  icon: 'delete',
                                  label: 'Supprimer',
                                  onClick: () =>
                                      ui.confirm({ tone: 'danger', icon: 'delete', title: 'Supprimer cette expérience ?', body: `« ${e.mission} » chez ${e.company} sera retirée du profil.`, ok: 'Supprimer', onConfirm: () => router.delete(`${base}/experiences/${e.id}`, { preserveScroll: true }) }),
                              },
                          ],
                }))}
            />
        </div>
    );
}

/** Journal complet, groupé par jour et filtrable par catégorie. */
export function HistoryTab({ activities }: TabProps) {
    return <HistoryList activities={activities} />;
}


/** Événement de journal affichable (ouvrier ou commercial). */
export type HistoryEvent = Pick<TabProps['activities'][number], 'id' | 'date' | 'time' | 'author' | 'category' | 'action' | 'oldValue' | 'newValue' | 'comment' | 'origin'>;

/** Journal filtrable par catégorie et groupé par jour. */
export function HistoryList({ activities }: { activities: HistoryEvent[] }) {
    const [filter, setFilter] = useState<string | null>(null);
    const categories = [...new Map(activities.map((e) => [e.category.value, e.category.label])).entries()];
    const list = activities.filter((e) => !filter || e.category.value === filter);
    const groups = list.reduce<{ day: string; items: typeof list }[]>((acc, e) => {
        const last = acc[acc.length - 1];
        if (last && last.day === e.date) last.items.push(e);
        else acc.push({ day: e.date, items: [e] });
        return acc;
    }, []);
    const originIcon: Record<string, string> = { 'Back-office': 'desktop_windows', 'Application mobile': 'smartphone', 'Application commercial': 'badge', 'Application Commercial': 'smartphone', Système: 'memory' };

    return (
        <div>
            <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2)', marginBottom: 'var(--space-4)' }}>
                {[[null, 'Tous'] as [string | null, string], ...categories].map(([value, label]) => {
                    const on = value === filter;
                    return (
                        <button
                            key={label}
                            type="button"
                            aria-pressed={on}
                            onClick={() => setFilter(value)}
                            style={{ height: 'var(--control-xs)', padding: '0 var(--space-3)', borderRadius: 'var(--radius-pill)', border: `1px solid ${on ? 'var(--c-primary)' : 'var(--c-line-strong)'}`, background: on ? 'var(--c-primary)' : 'var(--c-surface)', color: on ? 'var(--c-on-dark)' : 'var(--c-ink-2)', fontWeight: 'var(--fw-semibold)', fontSize: 'var(--fs-sm)', cursor: 'pointer' }}
                        >
                            {label}
                        </button>
                    );
                })}
            </div>
            {groups.map((g) => (
                <div key={g.day} style={{ marginBottom: 'var(--space-4)' }}>
                    <div style={{ fontSize: 'var(--fs-sm)', fontWeight: 'var(--fw-bold)', color: 'var(--c-muted)', textTransform: 'uppercase', letterSpacing: '.06em', marginBottom: 'var(--space-2)' }}>{formatDate(g.day)}</div>
                    {g.items.map((e) => (
                        <div key={e.id} style={{ display: 'grid', gridTemplateColumns: '52px 36px 1fr', gap: 'var(--space-3)', alignItems: 'start', padding: 'var(--space-2_5) 0', borderTop: '1px solid var(--c-line-soft)' }}>
                            <span style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)', fontVariantNumeric: 'tabular-nums', paddingTop: 'var(--space-1_5)' }}>{e.time}</span>
                            <span style={{ width: 'var(--control-sm)', height: 'var(--control-sm)', borderRadius: 'var(--radius-round)', display: 'grid', placeItems: 'center', background: `var(--c-${e.category.tone}-bg)` }}>
                                <Icon name={e.category.icon} color={`var(--c-${e.category.tone}-fg)`} />
                            </span>
                            <div style={{ minWidth: 0 }}>
                                <div style={{ fontSize: 'var(--fs-base)' }}>
                                    <b>{e.author}</b> {e.action}
                                </div>
                                <div style={{ display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: 'var(--space-2)', marginTop: 'var(--space-1)', fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>
                                    <StatusBadge label={e.category.label} tone={e.category.tone as never} />
                                    {(e.oldValue || e.newValue) && (
                                        <span>
                                            {e.oldValue || '—'} → <b style={{ color: 'var(--c-ink)' }}>{e.newValue || '—'}</b>
                                        </span>
                                    )}
                                    <span style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-1)' }}>
                                        <Icon name={originIcon[e.origin] ?? 'info'} size="var(--icon-xs)" />
                                        {e.origin}
                                    </span>
                                </div>
                                {e.comment && <div style={{ marginTop: 'var(--space-1)', fontSize: 'var(--fs-sm)', color: 'var(--c-ink-2)' }}>{e.comment}</div>}
                            </div>
                        </div>
                    ))}
                </div>
            ))}
            {!groups.length && <div style={{ color: 'var(--c-muted)' }}>Aucun événement ne correspond à ce filtre.</div>}
        </div>
    );
}
