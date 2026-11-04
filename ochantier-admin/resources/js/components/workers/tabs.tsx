import { TextCell } from '@/components/list/Table';
import Icon from '@/components/ui/Icon';
import StatusBadge from '@/components/ui/StatusBadge';
import { useAdminUi } from '@/contexts/AdminUiContext';
import { formatDate, formatNumber, formatRating } from '@/lib/format';
import type { WorkerActivity, WorkerDocument, WorkerReview, WorkerShowProps } from '@/types/workers';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { AlertList, Block, Chips, DataTable, KeyValues, TileGrid } from './blocks';
import type { WorkerActions } from './useWorkerActions';

export type TabProps = WorkerShowProps & { actions: WorkerActions };

/** Alertes du serveur avec leur lien éventuel. */
function useAlerts({ alerts }: TabProps) {
    return alerts.map((a) => ({ ...a, text: a.text ?? '', onAction: a.action ? () => router.visit(a.action!.href) : undefined }));
}

const percent = (value: number | null) => (value === null ? '—' : `${Math.round(value)} %`);

/** Contrôle du dossier : soumission en cours, décision et corrections demandées. */
function ReviewBlock({ review, history }: { review: WorkerReview | null; history: WorkerReview[] }) {
    return (
        <Block icon="fact_check" title="Contrôle du dossier" count={history.length ? `${history.length + 1} transmissions` : undefined}>
            {!review && <span style={{ fontSize: 'var(--fs-md)', color: 'var(--c-muted)' }}>Aucune transmission enregistrée.</span>}
            {[review, ...history].filter((r): r is WorkerReview => !!r).map((r, index) => (
                <div key={r.number} style={{ padding: 'var(--space-2_5) 0', borderTop: index ? '1px solid var(--c-line-soft)' : undefined }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)', flexWrap: 'wrap' }}>
                        <b>Transmission n° {r.number}</b>
                        <StatusBadge label={r.status.label} tone={r.status.tone} />
                        <span style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>le {r.submittedAt ?? '—'}</span>
                    </div>
                    <div style={{ marginTop: 'var(--space-1)', fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>
                        {r.decidedBy ? `Décision de ${r.decidedBy} le ${r.decidedAt}` : r.assignedTo ? `Pris en charge par ${r.assignedTo}` : 'En attente de prise en charge'}
                        {r.reason ? ` · ${r.reason}` : ''}
                    </div>
                    {r.corrections.length > 0 && (
                        <ul style={{ margin: 'var(--space-1_5) 0 0', paddingLeft: 'var(--space-5)', fontSize: 'var(--fs-md)' }}>
                            {r.corrections.map((c, i) => (
                                <li key={i} style={{ color: c.resolved ? 'var(--c-muted)' : undefined }}>
                                    <b>{c.target}</b> : {c.reason}
                                    {c.resolved ? ' (corrigé)' : ''}
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            ))}
        </Block>
    );
}

/** Vue d’ensemble : alertes, indicateurs, résumé du profil, contrôle du dossier, recrutement. */
export function OverviewTab(props: TabProps) {
    const { worker: w, documents } = props;
    const alerts = useAlerts(props);
    const provided = documents.filter((d) => d.hasFile);
    const verified = provided.filter((d) => d.status.value === 'valide').length;
    const s = w.stats;

    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)' }}>
            <AlertList alerts={alerts} />
            <TileGrid
                tiles={[
                    { label: 'Taux de présence', value: percent(s.attendance_rate), icon: 'how_to_reg', tone: 'success', bar: s.attendance_rate ?? 0 },
                    { label: 'Taux d’acceptation des offres', value: percent(s.acceptance_rate), icon: 'thumb_up', tone: 'info', bar: s.acceptance_rate ?? 0 },
                    { label: 'Remplacements', value: String(s.replacement_count), icon: 'swap_horiz', tone: 'warning', sub: 'Depuis l’inscription' },
                    { label: 'Dernière mission', value: s.last_assignment_at ? formatDate(s.last_assignment_at) : '—', icon: 'schedule', tone: 'neutral', sub: `${s.missions_completed} mission(s) réalisée(s)` },
                ]}
            />
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,360px),1fr))', gap: 'var(--space-4)' }}>
                <Block icon="person" title="Résumé du profil">
                    <KeyValues
                        items={[
                            ['Métier principal', w.trade],
                            ['Niveau', `${w.level ?? '—'} · ${w.experienceYears} ans d’expérience`],
                            ['Principales compétences', w.skills.slice(0, 3).join(', ') || '—'],
                            ['Coût journalier', w.dailyRate ? `${formatNumber(w.dailyRate)} FCFA` : 'Non renseigné'],
                            ['Note', w.reviewsCount ? `${formatRating(w.rating)} / 5 · ${w.reviewsCount} avis` : 'Aucun avis'],
                            ['Disponibilité', w.status.value === 'valide' ? w.availability.label : '—'],
                            ['Vérification', `${w.status.label} · ${verified} / ${provided.length} pièces vérifiées`],
                            ['Validé', w.validatedAt ? `le ${w.validatedAt}${w.validatedBy ? ` par ${w.validatedBy}` : ''}` : '—'],
                        ]}
                    />
                </Block>
                <ReviewBlock review={w.review.current} history={w.review.history} />
                <Block icon="storefront" title="Recrutement">
                    <KeyValues
                        items={[
                            ['Commercial référent', w.recruiter ? `${w.recruiter}${w.recruiterPhone ? ` · ${w.recruiterPhone}` : ''}` : '—'],
                            ['Commercial recruteur', w.originRecruiter ?? '—'],
                            ['Canal d’acquisition', w.acquisitionChannel ?? '—'],
                            ['Transmis le', formatDate(w.registeredAt)],
                        ]}
                    />
                </Block>
                {w.sanction && (
                    <Block icon={w.sanction.type === 'exclusion' ? 'person_off' : 'block'} title={w.sanction.label}>
                        <KeyValues
                            items={[
                                ['Motif', w.sanction.reason ?? '—'],
                                ['Depuis le', w.sanction.startsAt ?? '—'],
                                ['Jusqu’au', w.sanction.type === 'exclusion' ? 'Définitive' : (w.sanction.endsAt ?? 'Réactivation manuelle')],
                                ['Décidée par', w.sanction.decidedBy ?? '—'],
                            ]}
                        />
                    </Block>
                )}
            </div>
        </div>
    );
}

/** Identité, coordonnées et pièces du dossier, avec leur contrôle. */
export function IdentityTab(props: TabProps) {
    const { worker: w, documents, actions, can } = props;
    const ui = useAdminUi();
    const alerts = useAlerts(props);
    const provided = documents.filter((d) => d.hasFile);
    const verified = provided.filter((d) => d.status.value === 'valide').length;
    const identity = documents.find((d) => d.key === 'identity');
    const decide = (d: WorkerDocument) => can.documents && d.canDecide;
    const open = (url: string) => window.open(url, '_blank', 'noopener');

    const preview = (d: WorkerDocument) =>
        ui.openPanel({
            kicker: `Pièce du dossier · ${w.name}`,
            title: d.label,
            badges: [{ label: d.status.label, tone: d.status.tone }],
            state: d.hasFile ? undefined : { kind: 'missing', title: 'Pièce non déposée', text: 'Le commercial n’a pas encore ajouté cette pièce au dossier.' },
            meta: [
                ['Type', d.kind],
                ['Numéro', d.reference ?? '—'],
                ['Fichier', d.fileName ?? '—'],
                ['Ajoutée le', formatDate(d.addedAt)],
                ['Expiration', d.expiresAt ? formatDate(d.expiresAt) : 'Sans expiration'],
                ...(d.rejectionReason ? ([['Motif du rejet', d.rejectionReason]] as [string, string][]) : []),
            ],
            actions: [
                ...d.files.map((f) => ({ label: f.side === 'verso' ? 'Ouvrir le verso' : d.files.length > 1 ? 'Ouvrir le recto' : 'Ouvrir le fichier', icon: 'open_in_new', onClick: () => open(f.url) })),
                ...(decide(d)
                    ? [
                          { label: 'Rejeter', icon: 'cancel', variant: 'danger-soft' as const, onClick: () => actions.rejectDocument(w, d) },
                          { label: 'Valider', icon: 'check', variant: 'primary' as const, onClick: () => actions.validateDocument(w, d) },
                      ]
                    : []),
            ],
        });

    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)' }}>
            <AlertList alerts={alerts} />
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,360px),1fr))', gap: 'var(--space-4)' }}>
                <Block full icon="badge" title="Informations personnelles">
                    <KeyValues
                        items={[
                            ['Prénom', w.firstName],
                            ['Nom', w.lastName],
                            ['Sexe', w.gender],
                            ['Date de naissance', w.birthDate ? `${formatDate(w.birthDate)}${w.birthPlace ? ` à ${w.birthPlace}` : ''}` : null],
                            ['Nationalité', w.nationality],
                            ['Téléphone principal', w.phone],
                            ['Téléphone secondaire', w.phoneSecondary],
                            ['Adresse', w.address],
                            ['Quartier', w.neighborhood],
                            ['Commune', w.commune],
                            ['Contact d’urgence', w.emergencyContact ? `${w.emergencyContact}${w.emergencyPhone ? ` · ${w.emergencyPhone}` : ''}` : null],
                        ]}
                    />
                </Block>
                <Block icon="verified_user" title="Pièce d’identité">
                    <KeyValues
                        items={[
                            ['Type', identity?.hasFile ? identity.kind : 'Non déposée'],
                            ['Numéro', identity?.reference ?? '—'],
                            ['Expire le', identity?.expiresAt ? formatDate(identity.expiresAt) : '—'],
                            ['Statut', identity?.status.label ?? '—'],
                            ['Pièces vérifiées', `${verified} / ${provided.length}`],
                            ['Cohérence de l’identité', w.identityMismatch ? 'Incohérence signalée' : 'Aucune anomalie'],
                        ]}
                    />
                </Block>
            </div>
            <DataTable
                icon="folder_shared"
                title="Pièces du dossier"
                count={`${verified} / ${provided.length} vérifiées`}
                minWidth={900}
                columns={['Pièce', 'Type', 'Numéro', 'Ajoutée le', 'Expiration', 'Statut', 'Motif du rejet']}
                empty={{ icon: 'folder_shared', title: 'Aucune pièce', text: '' }}
                rows={documents.map((d) => ({
                    key: d.id,
                    onOpen: () => preview(d),
                    cells: [
                        <TextCell strong>
                            {d.label}
                            {d.required ? ' *' : ''}
                        </TextCell>,
                        <TextCell muted={!d.hasFile}>{d.hasFile ? d.kind : '—'}</TextCell>,
                        <TextCell muted={!d.reference}>{d.reference ?? '—'}</TextCell>,
                        <TextCell muted={!d.addedAt}>{d.addedAt ? formatDate(d.addedAt) : '—'}</TextCell>,
                        <TextCell muted={!d.expiresAt}>{d.expiresAt ? formatDate(d.expiresAt) : d.hasFile ? 'Sans expiration' : '—'}</TextCell>,
                        <StatusBadge label={d.status.label} tone={d.status.tone} icon={d.status.value === 'valide' ? 'verified' : undefined} />,
                        <TextCell muted={!d.rejectionReason}>{d.rejectionReason ?? '—'}</TextCell>,
                    ],
                    actions: d.hasFile
                        ? [
                              { icon: 'visibility', label: 'Voir la pièce', onClick: () => preview(d) },
                              ...(decide(d)
                                  ? [
                                        { icon: 'check_circle', label: 'Valider', onClick: () => actions.validateDocument(w, d) },
                                        { icon: 'cancel', label: 'Rejeter', onClick: () => actions.rejectDocument(w, d) },
                                    ]
                                  : []),
                          ]
                        : [],
                }))}
            />
        </div>
    );
}

/** Métiers, niveau, tarif, conditions de travail, compétences et références. */
export function SkillsTab({ worker: w }: TabProps) {
    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)' }}>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,360px),1fr))', gap: 'var(--space-4)' }}>
                <Block icon="engineering" title="Métier principal et tarif">
                    <KeyValues
                        items={[
                            ['Métier principal', w.trade],
                            ['Niveau', w.level],
                            ['Années d’expérience', `${w.experienceYears} ans`],
                            ['Coût journalier estimé', w.dailyRate ? `${formatNumber(w.dailyRate)} FCFA` : null],
                        ]}
                    />
                </Block>
                <Block icon="directions_walk" title="Conditions de travail">
                    <KeyValues items={w.work} />
                </Block>
                <Block icon="handyman" title="Métiers secondaires">
                    <Chips values={w.secondaryTrades} />
                </Block>
                <Block icon="category" title="Spécialités">
                    <Chips values={w.specialties} />
                </Block>
                <Block icon="psychology" title="Compétences">
                    <Chips values={w.skills} />
                </Block>
            </div>
            <DataTable
                icon="work_history"
                title="Références professionnelles"
                count={`${w.references.length} référence(s)`}
                minWidth={760}
                columns={['Chantier ou projet', 'Rôle', 'Employeur', 'Période']}
                empty={{ icon: 'work_history', title: 'Aucune référence', text: 'Le commercial n’a déclaré aucune expérience passée.' }}
                rows={w.references.map((r, i) => ({
                    key: String(i),
                    cells: [<TextCell strong>{r.project ?? '—'}</TextCell>, <TextCell>{r.role ?? '—'}</TextCell>, <TextCell>{r.employer ?? '—'}</TextCell>, <TextCell>{r.period}</TextCell>],
                    actions: [],
                }))}
            />
        </div>
    );
}

/** Événement de journal affichable (ouvrier ou commercial). */
export type HistoryEvent = WorkerActivity;

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
