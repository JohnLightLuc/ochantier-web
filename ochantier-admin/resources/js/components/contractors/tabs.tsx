import { TextCell } from '@/components/list/Table';
import { AlertList, Block, Chips, DataTable, KeyValues } from '@/components/workers/blocks';
import { HistoryList } from '@/components/workers/tabs';
import StatusBadge from '@/components/ui/StatusBadge';
import { fcfa, formatDate, formatRating } from '@/lib/format';
import type { ContractorDocument, ContractorShowProps } from '@/types/contractors';
import { router } from '@inertiajs/react';
import type { ContractorActions } from './useContractorActions';

export type ContractorTabProps = ContractorShowProps & { actions: ContractorActions; onView: (d: ContractorDocument) => void };

/** Alertes du serveur avec leur lien éventuel. */
function alertItems({ alerts }: ContractorShowProps) {
    return alerts.map((a) => ({ ...a, text: a.text ?? '', onAction: a.action ? () => router.visit(a.action!.href) : undefined }));
}

/** Vue d’ensemble : alertes, identité, coordonnées, activité, formule et réputation, contrôle du dossier. */
export function OverviewTab(props: ContractorTabProps) {
    const { contractor: c } = props;

    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)' }}>
            <AlertList alerts={alertItems(props)} />
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,360px),1fr))', gap: 'var(--space-4)' }}>
                <Block icon={c.kind.value === 'entreprise' ? 'domain' : 'badge'} title="Identité" count={`${c.kind.label} · contrôle ${c.kind.check}`}>
                    <KeyValues items={[...c.identity, ...c.contact]} />
                </Block>
                <Block icon="workspace_premium" title="Formule & réputation">
                    <KeyValues
                        items={[
                            ['Formule', c.plan.name],
                            ['Membres du compte', c.plan.seats > 1 ? `${c.plan.seats} au plus` : 'Titulaire seul'],
                            ['Abonnement', c.plan.status ? `${c.plan.status}${c.plan.until ? ` · jusqu’au ${c.plan.until}` : ''}` : 'Formule gratuite'],
                            ['Note moyenne', c.rating.count ? `${formatRating(c.rating.average ?? 0)} / 5 · ${c.rating.count} avis` : 'Aucun avis'],
                            ['Chantiers réalisés', String(c.completedSites)],
                            ['Compte', c.account ? `${c.account.status ?? '—'}${c.account.lastLoginAt ? ` · dernière connexion le ${c.account.lastLoginAt}` : ''}` : 'Aucun compte'],
                        ]}
                    />
                </Block>
                <Block full icon="construction" title="Activité">
                    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,260px),1fr))', gap: 'var(--space-4)' }}>
                        <div>
                            <div style={{ fontSize: 'var(--fs-md)', color: 'var(--c-muted)', marginBottom: 'var(--space-2)' }}>Métiers</div>
                            <Chips values={c.trades} empty="Aucun métier renseigné." />
                        </div>
                        <div>
                            <div style={{ fontSize: 'var(--fs-md)', color: 'var(--c-muted)', marginBottom: 'var(--space-2)' }}>Zones d’intervention</div>
                            <Chips values={c.zones} empty="Aucune zone renseignée." />
                        </div>
                    </div>
                    <div style={{ marginTop: 'var(--space-3)' }}>
                        <KeyValues
                            items={[
                                ['Expérience', c.experienceYears !== null ? `${c.experienceYears} an${c.experienceYears > 1 ? 's' : ''}` : null],
                                ['Présentation', c.description],
                            ]}
                        />
                    </div>
                </Block>
                <Block full icon="fact_check" title="Contrôle du dossier" count={c.reviews.length ? `${c.reviews.length} soumission${c.reviews.length > 1 ? 's' : ''}` : undefined}>
                    {!c.reviews.length && <span style={{ fontSize: 'var(--fs-md)', color: 'var(--c-muted)' }}>Le dossier n’a pas encore été soumis (complété à {c.completion.percent} %).</span>}
                    {c.reviews.map((r, index) => (
                        <div key={r.number} style={{ padding: 'var(--space-2_5) 0', borderTop: index ? '1px solid var(--c-line-soft)' : undefined }}>
                            <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)', flexWrap: 'wrap' }}>
                                <b>Soumission n° {r.number}</b>
                                <StatusBadge label={r.status.label} tone={r.status.tone} />
                                <span style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>le {r.submittedAt ?? '—'}</span>
                            </div>
                            <div style={{ marginTop: 'var(--space-1)', fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>
                                {r.decidedAt ? `Décision${r.decidedBy ? ` de ${r.decidedBy}` : ''} le ${r.decidedAt}` : 'En attente de décision'}
                                {r.reason ? ` · ${r.reason}` : ''}
                            </div>
                        </div>
                    ))}
                </Block>
            </div>
        </div>
    );
}

/** Pièces justificatives : tableau des pièces, visionneuse et décisions pièce par pièce. */
export function DocumentsTab(props: ContractorTabProps) {
    const { contractor: c, documents, actions, can, onView } = props;
    const provided = documents.filter((d) => d.hasFile);
    const confirmed = provided.filter((d) => d.status.value === 'valide').length;
    const decide = (d: ContractorDocument) => can.documents && d.canDecide;

    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)' }}>
            <AlertList alerts={alertItems(props)} />
            <DataTable
                icon="folder_shared"
                title={`Pièces justificatives · ${c.kind.check}`}
                count={`${confirmed} / ${provided.length} confirmées`}
                minWidth={900}
                columns={['Pièce', 'Exigence', 'Numéro', 'Déposée le', 'Expiration', 'Statut', 'Motif']}
                empty={{ icon: 'folder_shared', title: 'Aucune pièce', text: 'Le professionnel n’a encore déposé aucune pièce.' }}
                rows={documents.map((d) => ({
                    key: d.id,
                    onOpen: d.hasFile ? () => onView(d) : undefined,
                    cells: [
                        <TextCell strong>
                            {d.label}
                            {d.required ? ' *' : ''}
                        </TextCell>,
                        <TextCell muted={!d.hint}>{d.hint ?? '—'}</TextCell>,
                        <TextCell muted={!d.reference}>{d.reference ?? '—'}</TextCell>,
                        <TextCell muted={!d.addedAt}>{d.addedAt ? formatDate(d.addedAt) : '—'}</TextCell>,
                        <TextCell muted={!d.expiresAt}>{d.expiresAt ? formatDate(d.expiresAt) : d.hasFile ? 'Sans expiration' : '—'}</TextCell>,
                        <StatusBadge label={d.status.label} tone={d.status.tone} icon={d.status.value === 'valide' ? 'verified' : undefined} />,
                        <TextCell muted={!d.rejectionReason}>{d.rejectionReason ?? '—'}</TextCell>,
                    ],
                    actions: d.hasFile
                        ? [
                              { icon: 'visibility', label: 'Voir la pièce', onClick: () => onView(d) },
                              ...(decide(d) && d.status.value !== 'valide' ? [{ icon: 'check_circle', label: 'Confirmer', onClick: () => actions.confirmDocument(c, d) }] : []),
                          ]
                        : [],
                    menu: decide(d)
                        ? [
                              { icon: 'edit_document', label: 'Demander un complément', onClick: () => actions.askDocument(c, d) },
                              { icon: 'cancel', label: 'Rejeter', danger: true, onClick: () => actions.rejectDocument(c, d) },
                          ]
                        : [],
                }))}
            />
        </div>
    );
}

/** Réponses aux opportunités : propositions de visite et réponses aux offres contractuelles (lecture seule). */
export function ResponsesTab({ responses }: ContractorTabProps) {
    const rows = responses ?? [];
    const open = rows.filter((r) => r.open).length;

    return (
        <DataTable
            icon="forum"
            title="Réponses aux opportunités"
            count={`${open} en cours · ${rows.length} au total`}
            minWidth={900}
            columns={['Opportunité', 'Type', 'Promoteur', 'Montant', 'Statut', 'Date']}
            empty={{ icon: 'forum', title: 'Aucune réponse', text: 'Le professionnel n’a encore répondu à aucune demande de devis ni offre contractuelle.' }}
            rows={rows.map((r) => ({
                key: r.id,
                cells: [
                    <TextCell strong>
                        {r.title}
                        {r.reference ? <span style={{ display: 'block', fontWeight: 'var(--fw-regular)', fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>{r.reference}</span> : null}
                    </TextCell>,
                    <TextCell>{r.kind}</TextCell>,
                    <TextCell muted={!r.promoter}>{r.promoter ?? '—'}</TextCell>,
                    <TextCell muted={r.amount === null}>{r.amount !== null ? `${fcfa(r.amount)} · ${r.amountLabel.toLowerCase()}` : '—'}</TextCell>,
                    <StatusBadge label={r.status.label} tone={r.status.tone} />,
                    <TextCell muted={!r.at}>{r.at ? formatDate(r.at) : '—'}</TextCell>,
                ],
                actions: [],
            }))}
        />
    );
}

/** Historique : soumissions du dossier et décisions du Back Office. */
export function HistoryTab({ history }: ContractorTabProps) {
    const events = history ?? [];

    return events.length ? <HistoryList activities={events} /> : <span style={{ color: 'var(--c-muted)' }}>Aucun événement enregistré pour ce dossier.</span>;
}
