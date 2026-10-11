import { PersonCell, TextCell } from '@/components/list/Table';
import Icon from '@/components/ui/Icon';
import StatusBadge from '@/components/ui/StatusBadge';
import { AlertList, Block, DataTable, KeyValues, TileGrid, Toolbar } from '@/components/workers/blocks';
import { HistoryList } from '@/components/workers/tabs';
import { useAdminUi } from '@/contexts/AdminUiContext';
import { formatDate, formatNumber } from '@/lib/format';
import { workerTone } from '@/lib/workerTones';
import type { AnyTone } from '@/types';
import type { SiteDocument, SiteShowProps } from '@/types/sites';
import { router } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { READ_ONLY, type SiteActions, type SiteRef } from './useSiteActions';

export type SiteTabProps = SiteShowProps & { siteRef: SiteRef & { promoter: string }; actions: SiteActions };

const OPERATIONAL = ['in_progress', 'late', 'recruiting', 'ready'];

type CardItem = { key: string; icon: string; kicker: string; title: string; sub?: string; badge: string; tone: AnyTone; onClick?: () => void };

/** Liste de cartes d’un bloc (jalons, équipe, besoins, paiements), avec un « + n autres » et un état vide. */
function CardList({ items, more, empty }: { items: CardItem[]; more?: { count: number; label: string; onClick: () => void }; empty: string }) {
    if (!items.length) return <span style={{ fontSize: 'var(--fs-md)', color: 'var(--c-muted)' }}>{empty}</span>;

    const card = (c: CardItem, body: ReactNode) =>
        c.onClick ? (
            <button key={c.key} type="button" onClick={c.onClick} className="och-hover-card" style={cardStyle}>
                {body}
            </button>
        ) : (
            <div key={c.key} style={cardStyle}>
                {body}
            </div>
        );

    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-2)' }}>
            {items.map((c) =>
                card(
                    c,
                    <>
                        <span style={{ width: 'var(--control-sm)', height: 'var(--control-sm)', flex: 'none', borderRadius: 'var(--radius-md)', display: 'grid', placeItems: 'center', background: 'var(--c-primary-50)' }}>
                            <Icon name={c.icon} color="var(--c-primary-600)" />
                        </span>
                        <span style={{ flex: 1, minWidth: 0 }}>
                            <span style={{ display: 'block', fontSize: 'var(--fs-xs)', color: 'var(--c-muted)', textTransform: 'uppercase', letterSpacing: '.05em' }}>{c.kicker}</span>
                            <b style={{ display: 'block' }}>{c.title}</b>
                            {c.sub && <span style={{ display: 'block', fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>{c.sub}</span>}
                        </span>
                        <StatusBadge label={c.badge} tone={c.tone} />
                    </>,
                ),
            )}
            {more && more.count > 0 && (
                <button type="button" onClick={more.onClick} style={{ border: 0, background: 'transparent', padding: 'var(--space-1) 0', textAlign: 'left', color: 'var(--c-primary-600)', fontWeight: 'var(--fw-semibold)', cursor: 'pointer' }}>
                    + {more.count} {more.label}
                </button>
            )}
        </div>
    );
}

const cardStyle = { width: '100%', display: 'flex', alignItems: 'center', gap: 'var(--space-3)', padding: 'var(--space-3)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-lg)', background: 'var(--c-surface)', cursor: 'pointer', textAlign: 'left', minWidth: 0 } as const;

const MILESTONE_ICONS: Record<string, string> = { Réalisé: 'check_circle', 'En cours': 'pending' };

/** Vue d’ensemble : alertes, jalons, équipe, besoins, paiements et informations du chantier. */
export function OverviewTab({ site: s, alerts, milestones, team, offers, payments, siteRef, actions }: SiteTabProps) {
    const base = `/chantiers/${s.slug}`;
    const readOnly = READ_ONLY.includes(s.status.value);

    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)' }}>
            <AlertList alerts={alerts.map((a) => ({ ...a, onAction: a.href ? () => router.visit(a.href as string) : undefined }))} />
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,360px),1fr))', gap: 'var(--space-4)' }}>
                <Block icon="trending_up" title={`Avancement · ${s.progress} %`}>
                    <CardList empty="" items={milestones.map((m) => ({ key: m.label, icon: MILESTONE_ICONS[m.status] ?? 'radio_button_unchecked', kicker: formatDate(m.date), title: m.label, sub: `Seuil ${m.threshold} %`, badge: m.status, tone: m.tone as AnyTone }))} />
                </Block>
                <Block icon="groups" title="Équipe affectée" action={{ label: 'Voir l’équipe', icon: 'arrow_forward', onClick: () => router.visit(`${base}/equipe`) }}>
                    <CardList
                        empty="Aucun ouvrier affecté."
                        items={team.slice(0, 4).map((t) => ({ key: t.id, icon: 'person', kicker: t.trade, title: t.name, sub: `${formatDate(t.start)} → ${formatDate(t.end)}`, badge: t.status, tone: workerTone(t.status), onClick: () => router.visit(`/ouvriers/${t.slug}`) }))}
                        more={{ count: team.length - 4, label: 'autres ouvriers', onClick: () => router.visit(`${base}/equipe`) }}
                    />
                </Block>
                <Block icon="assignment" title="Besoins liés" action={{ label: 'Besoins & offres', icon: 'open_in_new', onClick: () => router.visit(`/besoins?q=${encodeURIComponent(s.name)}`) }}>
                    <CardList
                        empty="Aucun besoin publié."
                        items={offers.slice(0, 4).map((o) => ({ key: o.id, icon: 'assignment', kicker: `${o.trade} · ${o.type}`, title: o.name, sub: `${o.applications} candidat(s) · ${o.budget}`, badge: o.status.label, tone: o.status.tone, onClick: () => router.visit(o.href) }))}
                        more={{ count: offers.length - 4, label: 'autres besoins', onClick: () => router.visit(`/besoins?q=${encodeURIComponent(s.name)}`) }}
                    />
                </Block>
                <Block icon="receipt_long" title="Paiements liés" action={{ label: 'Paiements', icon: 'open_in_new', onClick: () => router.visit(`/paiements?periode=annee&chantier=${encodeURIComponent(s.name)}`) }}>
                    <CardList
                        empty="Aucun paiement lié."
                        items={payments.slice(0, 4).map((p) => ({ key: p.ref, icon: 'payments', kicker: p.type, title: `${formatNumber(p.amount)} FCFA`, sub: `${p.ref} · ${p.date}`, badge: p.status.label, tone: p.status.tone, onClick: () => router.visit(p.href) }))}
                        more={{ count: payments.length - 4, label: 'autres paiements', onClick: () => router.visit(`/paiements?periode=annee&chantier=${encodeURIComponent(s.name)}`) }}
                    />
                </Block>
                <Block full icon="info" title="Informations du chantier" action={readOnly ? null : { label: 'Modifier', icon: 'edit', onClick: () => actions.edit(siteRef) }}>
                    <KeyValues
                        items={[
                            ['Promoteur', s.promoter],
                            ['Responsable', s.manager ? `${s.manager} · ${s.managerPhone ?? ''}` : null],
                            ['Adresse', s.address],
                            ['Catégorie', s.category],
                            ['Horaires', s.workingHours],
                            ['Jours de travail', s.workingDays],
                            ['Consignes générales', s.instructions],
                            ['Conditions d’accès', s.accessConditions],
                            ['EPI obligatoires', s.safetyEquipment],
                            ['Budget prévisionnel', `${formatNumber(s.budget)} FCFA`],
                            ['Description', s.description],
                            ['Soumis le', formatDate(s.submittedOn)],
                            ...(s.correctionRequest ? ([['Correction demandée', s.correctionRequest]] as [string, string][]) : []),
                        ]}
                    />
                </Block>
            </div>
        </div>
    );
}

/** Équipe et pointages : effectif, affectations et pointages détaillés. */
export function TeamTab({ site: s, flags, team, timesheets }: SiteTabProps) {
    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)' }}>
            <Toolbar
                actions={[
                    { label: 'Module Affectations', icon: 'open_in_new', onClick: () => router.visit(`/affectations?q=${encodeURIComponent(s.name)}`) },
                    { label: 'Module Pointages', icon: 'open_in_new', onClick: () => router.visit(`/pointages?periode=mois&chantier=${encodeURIComponent(s.name)}`) },
                ]}
            />
            <TileGrid
                tiles={[
                    { label: 'Ouvriers requis', value: String(s.required), icon: 'groups', tone: 'info' },
                    { label: 'Ouvriers affectés', value: String(s.assigned), icon: 'how_to_reg', tone: 'success', bar: (s.assigned / Math.max(1, s.required)) * 100 },
                    { label: 'Présents aujourd’hui', value: String(s.present), icon: 'today', tone: 'success' },
                    { label: 'Postes vacants', value: String(flags.vacancies), icon: 'person_add', tone: flags.vacancies ? 'orange' : 'success' },
                    { label: 'Retards', value: String(flags.lateTimesheets), icon: 'running_with_errors', tone: 'orange' },
                    { label: 'Absences', value: String(flags.absences), icon: 'person_off', tone: 'danger' },
                ]}
            />
            <DataTable
                icon="groups"
                title="Ouvriers affectés (échantillon)"
                count={`${team.length} affectation(s) détaillée(s) sur ${s.assigned}`}
                minWidth={1100}
                columns={['Ouvrier', 'Métier', 'Période', 'Mode de paiement', 'Statut de l’affectation', 'Disponibilité']}
                empty={{ icon: 'groups', title: 'Aucune affectation détaillée', text: 'Les affectations apparaîtront après validation du matching.' }}
                rows={team.map((t) => {
                    const open = () => router.visit(`/ouvriers/${t.slug}/affectations`);
                    return {
                        key: t.id,
                        onOpen: open,
                        cells: [
                            <PersonCell name={t.name} sub={t.code} />,
                            <TextCell>{t.trade}</TextCell>,
                            <TextCell>
                                {formatDate(t.start)} → {formatDate(t.end)}
                            </TextCell>,
                            <TextCell>{t.pay}</TextCell>,
                            <StatusBadge label={t.status} tone={workerTone(t.status)} />,
                            <StatusBadge label={t.availability.label} tone={t.availability.tone} />,
                        ],
                        actions: [{ icon: 'visibility', label: 'Fiche de l’ouvrier', onClick: open }],
                    };
                })}
            />
            <DataTable
                icon="schedule"
                title="Pointages détaillés (échantillon)"
                count={`${timesheets.length} pointage(s)`}
                minWidth={1100}
                columns={['Date', 'Ouvrier', 'Arrivée', 'Départ', 'Statut', 'Anomalie', 'Validation promoteur']}
                empty={{ icon: 'schedule', title: 'Aucun pointage', text: 'Les pointages apparaîtront au démarrage du chantier.' }}
                rows={timesheets.map((p) => {
                    const open = () => router.visit(`/ouvriers/${p.slug}/pointages`);
                    return {
                        key: p.id,
                        onOpen: open,
                        cells: [
                            <TextCell strong>{formatDate(p.date)}</TextCell>,
                            <PersonCell name={p.name} sub={p.trade} />,
                            <TextCell>{p.in || '—'}</TextCell>,
                            <TextCell>{p.out || '—'}</TextCell>,
                            <StatusBadge label={p.status} tone={workerTone(p.status)} />,
                            <TextCell muted={!p.anomaly}>{p.anomaly || '—'}</TextCell>,
                            <StatusBadge label={p.validation} tone={workerTone(p.validation)} />,
                        ],
                        actions: [{ icon: 'open_in_new', label: 'Pointages de l’ouvrier', onClick: open }],
                    };
                })}
            />
        </div>
    );
}

/** Incidents (signalement, résolution) et dossiers de remplacement des ouvriers. */
export function IncidentsTab({ site: s, incidents, replacements, siteRef, actions }: SiteTabProps) {
    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)' }}>
            <DataTable
                icon="report"
                title="Incidents"
                count={`${incidents.length} incident(s)`}
                minWidth={1000}
                tools={OPERATIONAL.includes(s.status.value) ? [{ label: 'Signaler un incident', icon: 'report', variant: 'primary', onClick: () => actions.incident(siteRef) }] : []}
                columns={['Référence', 'Date', 'Type', 'Gravité', 'Description', 'Déclaré par', 'Statut']}
                empty={{ icon: 'verified_user', title: 'Aucun incident', text: 'Aucun incident signalé sur ce chantier.' }}
                rows={incidents.map((i) => ({
                    key: String(i.id),
                    cells: [
                        <TextCell strong>{i.reference}</TextCell>,
                        <TextCell>{formatDate(i.date)}</TextCell>,
                        <TextCell>{i.type}</TextCell>,
                        <StatusBadge label={i.severity.label} tone={i.severity.tone} />,
                        <span style={{ display: 'block', minWidth: 220 }}>
                            {i.description}
                            {i.resolution && <span style={{ display: 'block', fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>Mesures : {i.resolution}</span>}
                        </span>,
                        <TextCell>{i.reportedBy}</TextCell>,
                        <StatusBadge label={i.status.label} tone={i.status.tone} />,
                    ],
                    actions: i.status.value === 'resolved' ? [] : [{ icon: 'task_alt', label: 'Marquer comme résolu', onClick: () => actions.resolveIncident(siteRef, i) }],
                }))}
            />
            <DataTable
                icon="swap_horiz"
                title="Indisponibilités et remplacements"
                count={`${replacements.length} dossier(s)`}
                minWidth={960}
                columns={['Référence', 'Ouvrier indisponible', 'Période', 'Motif', 'Remplaçant', 'Statut']}
                empty={{ icon: 'swap_horiz', title: 'Aucun remplacement', text: 'Aucune indisponibilité déclarée sur ce chantier.' }}
                rows={replacements.map((r) => {
                    const open = () => router.visit(`/ouvriers/${r.slug}/remplacements`);
                    return {
                        key: r.id,
                        onOpen: open,
                        cells: [
                            <TextCell strong>{r.id}</TextCell>,
                            <PersonCell name={r.name} sub={r.trade} />,
                            <TextCell>
                                {formatDate(r.start)} → {formatDate(r.end)}
                            </TextCell>,
                            <TextCell>{r.reason}</TextCell>,
                            <TextCell muted={!r.replacement}>{r.replacement ?? '—'}</TextCell>,
                            <StatusBadge label={r.status} tone={workerTone(r.status)} />,
                        ],
                        actions: [{ icon: 'open_in_new', label: 'Suivre le remplacement', onClick: open }],
                    };
                })}
            />
        </div>
    );
}

/** Documents et preuves : aperçu, téléchargement, validation, rejet, dépôt. */
export function DocumentsTab({ site: s, documents, siteRef, actions }: SiteTabProps) {
    const ui = useAdminUi();
    const readOnly = READ_ONLY.includes(s.status.value);
    const verified = documents.filter((d) => d.status.value === 'verified').length;
    const download = (d: SiteDocument) => (window.location.href = `/chantiers/${s.slug}/documents/${d.id}/telechargement`);

    const preview = (d: SiteDocument) =>
        ui.openPanel({
            kicker: `Aperçu du document · ${s.name}`,
            title: d.label,
            badges: [{ label: d.status.label, tone: d.status.tone }],
            state: d.hasFile ? undefined : { kind: 'missing', title: 'Document manquant', text: 'Ce document n’a pas encore été déposé.' },
            meta: [
                ['Catégorie', d.kind],
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
                          { label: 'Rejeter', icon: 'cancel', variant: 'danger-soft' as const, onClick: () => actions.rejectDocument(siteRef, d) },
                          { label: 'Valider', icon: 'check', variant: 'primary' as const, onClick: () => actions.validateDocument(siteRef, d) },
                      ]
                    : []),
                ...(readOnly ? [] : [{ label: d.hasFile ? 'Remplacer' : 'Ajouter', icon: 'upload_file', variant: (d.hasFile ? 'secondary' : 'primary') as 'secondary' | 'primary', onClick: () => actions.addDocument(siteRef, d.type) }]),
            ],
        });

    return (
        <DataTable
            icon="folder_shared"
            title="Documents et preuves"
            count={`${verified} / ${documents.length} vérifiés`}
            minWidth={980}
            tools={readOnly ? [] : [{ label: 'Ajouter un document', icon: 'upload_file', variant: 'primary', onClick: () => actions.addDocument(siteRef) }]}
            columns={['Document', 'Catégorie', 'Référence', 'Ajouté le', 'Expiration', 'Statut']}
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
                ],
                actions: d.hasFile
                    ? [
                          { icon: 'visibility', label: 'Prévisualiser', onClick: () => preview(d) },
                          { icon: 'download', label: 'Télécharger', onClick: () => download(d) },
                          ...(d.status.value === 'to_review'
                              ? [
                                    { icon: 'check_circle', label: 'Valider', onClick: () => actions.validateDocument(siteRef, d) },
                                    { icon: 'cancel', label: 'Rejeter', onClick: () => actions.rejectDocument(siteRef, d) },
                                ]
                              : []),
                      ]
                    : readOnly
                      ? []
                      : [{ icon: 'upload_file', label: 'Ajouter', onClick: () => actions.addDocument(siteRef, d.type) }],
                menu:
                    d.hasFile && !readOnly
                        ? [
                              { icon: 'sync', label: 'Remplacer', onClick: () => actions.addDocument(siteRef, d.type) },
                              { icon: 'chat', label: 'Ajouter un commentaire interne', onClick: () => actions.commentDocument(siteRef, d) },
                              ...(d.status.value === 'verified' ? [{ icon: 'cancel', label: 'Rejeter', danger: true, onClick: () => actions.rejectDocument(siteRef, d) }] : []),
                          ]
                        : [],
            }))}
        />
    );
}

/** Journal complet du chantier, groupé par jour et filtrable par catégorie. */
export function HistoryTab({ activities }: SiteTabProps) {
    return <HistoryList activities={activities} />;
}
