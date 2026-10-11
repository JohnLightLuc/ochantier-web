import { PersonCell, ProgressCell, StackCell, TextCell } from '@/components/list/Table';
import Icon from '@/components/ui/Icon';
import StatusBadge from '@/components/ui/StatusBadge';
import { AlertList, Block, DataTable, KeyValues, TileGrid, Toolbar } from '@/components/workers/blocks';
import { HistoryList } from '@/components/workers/tabs';
import { useAdminUi } from '@/contexts/AdminUiContext';
import { formatDate, formatNumber } from '@/lib/format';
import type { AnyTone } from '@/types';
import type { NeedAlert, NeedShowProps } from '@/types/needs';
import { router } from '@inertiajs/react';
import type { NeedActions } from './useNeedActions';

export type NeedTabProps = NeedShowProps & { actions: NeedActions };

/** Bandeaux d’alerte avec leur action (lien, matching, prolongation, actualisation, clôture des candidatures). */
export function useNeedAlerts({ alerts, offers, actionRef, actions }: NeedTabProps) {
    const run = (a: NeedAlert) => {
        const action = a.action;
        if (!action) return undefined;
        if (action.type === 'link') return () => router.visit(action.href);
        if (action.type === 'match') return () => actions.match(actionRef);
        const offer = offers.find((o) => o.id === action.offer);
        if (!offer) return undefined;
        if (action.type === 'extend') return () => actions.extend(actionRef, offer);
        if (action.type === 'refresh') return () => actions.refreshOffer(actionRef, offer);
        return () => actions.closeApplications(actionRef, offer);
    };

    return alerts.map((a) => ({ ...a, onAction: run(a) }));
}

type CardItem = { key: string; icon: string; kicker: string; title: string; sub?: string; badge: string; tone: AnyTone; onClick?: () => void };

function CardList({ items, empty }: { items: CardItem[]; empty: string }) {
    if (!items.length) return <span style={{ fontSize: 'var(--fs-md)', color: 'var(--c-muted)' }}>{empty}</span>;

    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-2)' }}>
            {items.map((c) => {
                const body = (
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
                    </>
                );
                const style = { width: '100%', display: 'flex', alignItems: 'center', gap: 'var(--space-3)', padding: 'var(--space-3)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-lg)', background: 'var(--c-surface)', textAlign: 'left', minWidth: 0 } as const;
                return c.onClick ? (
                    <button key={c.key} type="button" onClick={c.onClick} className="och-hover-card" style={{ ...style, cursor: 'pointer' }}>
                        {body}
                    </button>
                ) : (
                    <div key={c.key} style={style}>
                        {body}
                    </div>
                );
            })}
        </div>
    );
}

/** Règles du module Paramètres appliquées au besoin. */
function RulesBlock({ rules }: Pick<NeedShowProps, 'rules'>) {
    const src = 'Valeur définie dans Paramètres';
    return (
        <Block icon="tune" title="Règles appliquées" action={{ label: 'Paramètres', icon: 'settings', onClick: () => router.visit('/parametres') }}>
            <KeyValues
                items={[
                    ['Délai avant démarrage (alerte effectif)', `${rules.start_alert_days} jours · ${src}`],
                    ['Taux minimum de couverture attendu', `${rules.minimum_coverage} % · ${src}`],
                    ['Délai maximal de traitement', `${rules.processing_hours} heures · ${src}`],
                    ['Seuil de déclenchement d’une relance', `${rules.reminder_days} jours · ${src}`],
                    ['Seuil R % de paiement', `${rules.payment_threshold} % · ${src}`],
                ]}
            />
        </Block>
    );
}

/** Vue d’ensemble : alertes, indicateurs, répartition par métier, résumé, chronologie, prochaines actions et publication. */
export function OverviewTab(props: NeedTabProps) {
    const { need: n, coverage: c, chronology, lines, offers, rules, actionRef, actions } = props;
    const alerts = useNeedAlerts(props);
    const base = `/besoins/${n.slug}`;
    const closed = !!actionRef.manual && actionRef.manual !== 'suspended';
    const next = [
        ...actions
            .available(actionRef)
            .filter((a) => !a.danger)
            .slice(0, 3)
            .map((a) => ({ key: a.label, icon: a.icon, kicker: 'Action', title: a.label, badge: 'Lancer', tone: 'b' as AnyTone, onClick: a.onClick })),
        ...alerts
            .filter((a) => a.cta && a.onAction)
            .slice(0, 2)
            .map((a, i) => ({ key: `alert-${i}`, icon: a.icon, kicker: 'Alerte', title: a.cta as string, sub: a.title, badge: 'Traiter', tone: 'a' as AnyTone, onClick: a.onAction })),
    ];

    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)' }}>
            <AlertList alerts={alerts} />
            <TileGrid
                tiles={[
                    { label: 'Postes demandés', value: String(c.need), icon: 'groups', tone: 'info' },
                    { label: 'Ouvriers sélectionnés', value: `${c.selected} / ${c.need}`, icon: 'how_to_reg', tone: 'success', bar: c.percent },
                    { label: 'Validés par les ouvriers', value: String(c.validated), icon: 'verified', tone: 'success' },
                    { label: 'Ouvriers manquants', value: String(c.rest), icon: 'person_add', tone: c.rest ? 'orange' : 'success' },
                    { label: 'Taux de couverture', value: `${c.percent} %`, icon: 'donut_large', tone: 'purple', bar: c.percent, sub: c.label },
                    {
                        label: 'Délai avant démarrage',
                        value: n.daysToStart >= 0 ? `J-${n.daysToStart}` : `Démarré (${-n.daysToStart} j)`,
                        icon: 'event_upcoming',
                        tone: n.daysToStart <= rules.start_alert_days && c.percent < rules.minimum_coverage && !closed ? 'danger' : 'info',
                        sub: `Alerte à ${rules.start_alert_days} j · Paramètres`,
                    },
                ]}
            />
            <Block icon="bar_chart" title="Répartition des postes par métier" count="Postes demandés · affectations confirmées">
                <div style={{ display: 'flex', alignItems: 'flex-end', gap: 'var(--space-5)', minHeight: 150, padding: 'var(--space-2) var(--space-2) 0', overflowX: 'auto' }}>
                    {lines.map((l) => (
                        <div key={l.id} title={`${l.trade} · ${l.specialty ?? ''} : ${l.found} confirmée(s) sur ${l.quantity}`} style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 'var(--space-1)', minWidth: 72 }}>
                            <b style={{ fontSize: 'var(--fs-sm)' }}>
                                {l.found} / {l.quantity}
                            </b>
                            <span style={{ width: 40, height: l.height, borderRadius: 'var(--radius-sm) var(--radius-sm) 0 0', background: l.found >= l.quantity ? 'var(--c-primary-500)' : l.found ? 'var(--c-accent-600)' : 'var(--c-line-hover)' }} />
                            <span style={{ fontSize: 'var(--fs-xs)', color: 'var(--c-muted)' }}>{l.trade}</span>
                        </div>
                    ))}
                </div>
            </Block>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,360px),1fr))', gap: 'var(--space-4)' }}>
                <Block icon="summarize" title="Résumé du besoin" action={n.readOnly || actionRef.manual ? null : { label: 'Modifier', icon: 'edit', onClick: () => router.visit(`${base}/modifier`) }}>
                    <KeyValues
                        items={[
                            ['Chantier', n.site.name],
                            ['Promoteur', n.site.promoter],
                            ['Localisation', `${n.district ? `${n.district}, ` : ''}${n.site.commune} · ${n.site.city}`],
                            ['Responsable du chantier', `${n.contact} · ${n.contactPhone}`],
                            ['Responsable administratif', n.admin],
                            ['Métiers recherchés', lines.map((l) => l.trade).join(', ')],
                            ['Postes demandés / affectés / restants', `${c.need} / ${c.selected} / ${c.rest}`],
                            ['Dates', `${formatDate(n.startsOn)} → ${formatDate(n.endsOn)}`],
                            ['Horaires', n.workingHours],
                            ['Durée', `${n.duration} jours`],
                            ['Urgence', n.urgency.label + (n.justification ? ` · ${n.justification}` : '')],
                            ['Budget prévisionnel', `${formatNumber(n.budget)} FCFA`],
                            ['Conditions', n.conditions],
                            ['Statut', actionRef.status.label],
                            ['Publication', `Automatique le ${formatDate(n.createdOn)} · ${offers.length} offre(s)`],
                        ]}
                    />
                </Block>
                <Block icon="timeline" title="Chronologie">
                    <CardList empty="" items={chronology.map((s) => ({ key: s.label, icon: s.status === 'Réalisé' ? 'check_circle' : s.status === 'En cours' ? 'pending' : 'radio_button_unchecked', kicker: s.date ? formatDate(s.date) : '—', title: s.label, badge: s.status, tone: s.tone as AnyTone }))} />
                </Block>
                <Block icon="bolt" title="Prochaines actions">
                    <CardList empty="Aucune action requise." items={next} />
                </Block>
                <Block icon="engineering" title="Publication par métier" action={{ label: 'Métiers et effectifs', icon: 'arrow_forward', onClick: () => router.visit(`${base}/metiers`) }}>
                    <CardList
                        empty="Aucun métier."
                        items={offers.map((o) => ({
                            key: String(o.id),
                            icon: o.status.label === 'Expirée' ? 'timer_off' : 'campaign',
                            kicker: `${o.reference} · ${o.trade} · urgence ${o.urgency.toLowerCase()}`,
                            title: `${o.selected} / ${o.quantity} trouvé(s)${o.rest ? ` · ${o.rest} manquant(s)` : ' · complet'}`,
                            sub: `Limite ${formatDate(o.deadline)}`,
                            badge: o.status.label,
                            tone: o.status.tone,
                            onClick: () => router.visit(`${base}/metiers`),
                        }))}
                    />
                </Block>
                <RulesBlock rules={rules} />
            </div>
        </div>
    );
}

/** Lignes de besoin : effectif, rémunération, exigences et couverture par métier. */
export function TradesTab({ need: n, coverage: c, lines, actionRef, actions }: NeedTabProps) {
    const expired = actionRef.status.label === 'Expiré';

    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)' }}>
            {expired && (
                <AlertList
                    alerts={[
                        {
                            tone: 'warning',
                            icon: 'timer_off',
                            title: 'Besoin expiré',
                            text: c.selected ? `${c.selected} ouvrier(s) trouvé(s) restent affectés à ce besoin. Actualisez le besoin pour rechercher les ${c.rest} ouvrier(s) manquant(s).` : 'Actualisez le besoin pour relancer la recherche d’ouvriers.',
                            cta: 'Actualiser le besoin',
                            onAction: () => actions.refreshAll(actionRef),
                        },
                    ]}
                />
            )}
            {!actionRef.manual && <Toolbar actions={[{ label: 'Modifier les lignes', icon: 'edit', onClick: () => router.visit(`/besoins/${n.slug}/modifier`) }]} />}
            <DataTable
                icon="engineering"
                title="Lignes de besoin"
                count={`${lines.length} métier(s) · ${c.selected} / ${c.need} ouvrier(s) trouvé(s)`}
                minWidth={1300}
                columns={['Corps de métier', 'Métier', 'Spécialité', 'Recherchés', 'Expérience', 'Rémunération', 'Exigences', 'Trouvés', 'Manquants', 'Couverture']}
                empty={{ icon: 'engineering', title: 'Aucune ligne', text: '' }}
                rows={lines.map((l) => ({
                    key: String(l.id),
                    cells: [
                        <TextCell>{l.group}</TextCell>,
                        <TextCell strong>{l.trade}</TextCell>,
                        <TextCell>{l.specialty || '—'}</TextCell>,
                        <TextCell>{l.quantity}</TextCell>,
                        <TextCell>{l.experience}</TextCell>,
                        <StackCell main={l.amount} sub={l.payType} />,
                        <span style={{ display: 'block', minWidth: 180 }}>{l.requirements}</span>,
                        <TextCell>{l.found}</TextCell>,
                        <TextCell strong={l.rest > 0} muted={!l.rest}>
                            {l.rest || '—'}
                        </TextCell>,
                        <ProgressCell value={Math.min(100, l.percent)} />,
                    ],
                    actions: l.canMatch ? [{ icon: 'join_inner', label: `Lancer le matching pour ${l.rest} ${l.trade.toLowerCase()}${l.rest > 1 ? '(s)' : ''}`, onClick: () => actions.match(actionRef) }] : [],
                }))}
            />
        </div>
    );
}

/** Ouvriers trouvés pour le besoin, suivis dans Affectations & Offres. */
export function AssignmentsTab({ need: n, coverage: c, assignments, actionRef, actions }: NeedTabProps) {
    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)' }}>
            <Toolbar actions={[{ label: 'Ouvrir dans Affectations & Offres', icon: 'open_in_new', variant: 'primary', onClick: () => actions.follow(actionRef) }]} />
            <TileGrid
                tiles={[
                    { label: 'Sélectionnés / recherchés', value: `${c.selected} / ${c.need}`, icon: 'how_to_reg', tone: 'success', bar: c.percent },
                    { label: 'À contacter', value: String(c.toContact), icon: 'call', tone: 'warning' },
                    { label: 'Contactés', value: String(c.contacted), icon: 'mark_chat_read', tone: 'info' },
                    { label: 'Validés', value: String(c.validated), icon: 'verified', tone: 'success' },
                    { label: 'Refusés', value: String(c.refused), icon: 'person_off', tone: 'danger' },
                    { label: 'Ouvriers manquants', value: String(c.rest), icon: 'person_add', tone: c.rest ? 'orange' : 'success' },
                ]}
            />
            <DataTable
                icon="event_available"
                title="Ouvriers trouvés pour ce besoin"
                count={`${assignments.length} affectation(s) · ${assignments.filter((a) => a.status.label === 'Validé').length} validée(s)`}
                minWidth={1300}
                columns={['Référence', 'Ouvrier', 'Métier', 'Chantier', 'Période', 'Statut', 'Affectée le', 'Confirmation ouvrier', 'Confirmation promoteur', 'Démarrage', 'Rémunération']}
                empty={{ icon: 'event_available', title: 'Aucune affectation', text: 'Les ouvriers trouvés par le matching ou ajoutés manuellement apparaîtront ici.' }}
                rows={assignments.map((a) => ({
                    key: String(a.id),
                    onOpen: () => actions.follow(actionRef),
                    cells: [
                        <TextCell strong>{a.reference}</TextCell>,
                        <PersonCell name={a.worker} sub={a.workerSlug ? 'Fiche ouvrier' : a.source} />,
                        <TextCell>{a.trade}</TextCell>,
                        <TextCell>{n.site.name}</TextCell>,
                        <TextCell>
                            {formatDate(n.startsOn)} → {formatDate(n.endsOn)}
                        </TextCell>,
                        <StatusBadge label={a.status.label} tone={a.status.tone} />,
                        <TextCell>{formatDate(a.selectedOn)}</TextCell>,
                        <StatusBadge label={a.workerConfirmation.label} tone={a.workerConfirmation.tone} />,
                        <StatusBadge label={a.promoterConfirmation.label} tone={a.promoterConfirmation.tone} />,
                        <TextCell>{formatDate(n.startsOn)}</TextCell>,
                        <TextCell>{a.pay}</TextCell>,
                    ],
                    actions: [{ icon: 'open_in_new', label: 'Ouvrir dans le module Affectations', onClick: () => actions.follow(actionRef) }],
                    menu: a.workerSlug ? [{ icon: 'person', label: 'Fiche de l’ouvrier', onClick: () => router.visit(`/ouvriers/${a.workerSlug}`) }] : [],
                }))}
            />
        </div>
    );
}

/** Documents et médias : aperçu, téléchargement, remplacement, validation et suppression. */
export function DocumentsTab({ need: n, documents, documentTypes, actions }: NeedTabProps) {
    const ui = useAdminUi();
    const canAdd = !n.readOnly;
    const base = `/besoins/${n.slug}`;
    const download = (id: number) => (window.location.href = `${base}/documents/${id}/telechargement`);
    const preview = (d: NeedTabProps['documents'][number]) =>
        ui.openPanel({
            kicker: `Aperçu · ${d.type}`,
            title: d.fileName ?? d.type,
            badges: [{ label: d.status.label, tone: d.status.tone }],
            meta: [
                ['Type', d.type],
                ['Ajouté le', formatDate(d.addedOn)],
                ['Auteur', d.author],
                ['Statut', d.status.label],
            ],
            actions: [{ label: 'Télécharger', icon: 'download', onClick: () => download(d.id) }],
        });
    const validated = documents.filter((d) => d.status.value === 'validated').length;

    return (
        <DataTable
            icon="folder_open"
            title="Documents et médias"
            count={`${validated} / ${documents.length} validés`}
            minWidth={1000}
            tools={canAdd ? [{ label: 'Ajouter un document', icon: 'upload_file', variant: 'primary', onClick: () => actions.addDocument(n, documentTypes) }] : []}
            columns={['Document', 'Fichier', 'Ajouté le', 'Auteur', 'Statut']}
            empty={{ icon: 'folder_open', title: 'Aucun document', text: 'Ajoutez le cahier des charges, le plan ou les consignes de sécurité.' }}
            rows={documents.map((d) => {
                const missing = d.status.value === 'missing';
                return {
                    key: String(d.id),
                    onOpen: missing ? undefined : () => preview(d),
                    cells: [
                        <TextCell strong>{d.type}</TextCell>,
                        <TextCell muted={!d.fileName}>{d.fileName ?? 'Non fourni'}</TextCell>,
                        <TextCell>{formatDate(d.addedOn)}</TextCell>,
                        <TextCell>{d.author}</TextCell>,
                        <StatusBadge label={d.status.label} tone={d.status.tone} />,
                    ],
                    actions: missing
                        ? canAdd
                            ? [{ icon: 'upload_file', label: 'Ajouter', onClick: () => actions.addDocument(n, documentTypes, d.type) }]
                            : []
                        : [
                              { icon: 'visibility', label: 'Prévisualiser', onClick: () => preview(d) },
                              { icon: 'download', label: 'Télécharger', onClick: () => download(d.id) },
                          ],
                    menu:
                        missing || !canAdd
                            ? []
                            : [
                                  { icon: 'sync', label: 'Remplacer', onClick: () => actions.addDocument(n, documentTypes, d.type) },
                                  ...(d.status.value === 'to_review' ? [{ icon: 'check_circle', label: 'Valider le document', onClick: () => router.post(`${base}/documents/${d.id}/validation`, {}, { preserveScroll: true }) }] : []),
                                  ...(d.canDelete
                                      ? [
                                            {
                                                icon: 'delete',
                                                label: 'Supprimer',
                                                danger: true,
                                                onClick: () =>
                                                    ui.confirm({ tone: 'danger', icon: 'delete', title: `Supprimer « ${d.fileName} » ?`, body: 'Le document sera retiré du besoin. L’action est tracée dans l’historique.', ok: 'Supprimer', onConfirm: () => router.delete(`${base}/documents/${d.id}`, { preserveScroll: true }) }),
                                            },
                                        ]
                                      : []),
                              ],
                };
            })}
        />
    );
}

/** Journal du besoin, groupé par jour et filtrable par catégorie. */
export function HistoryTab({ activities }: NeedTabProps) {
    return <HistoryList activities={activities} />;
}
