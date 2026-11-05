import { TextCell } from '@/components/list/Table';
import StatusBadge from '@/components/ui/StatusBadge';
import { useAdminUi } from '@/contexts/AdminUiContext';
import { Block, DataTable, KeyValues, TileGrid } from '@/components/workers/blocks';
import { formatDate } from '@/lib/format';
import type { SalesShowProps } from '@/types/sales';
import type { WorkerDocument } from '@/types/workers';
import { fcfa, type SalesActions } from './useSalesActions';
import { BarChart, ChartGrid } from './visuals';

/** Vue d’ensemble : indicateurs du mois, évolution, métiers recrutés et résumé du compte. */
export function OverviewTab({ agent: a }: Pick<SalesShowProps, 'agent'>) {
    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)' }}>
            <TileGrid
                tiles={[
                    { label: 'Ouvriers enregistrés (total)', value: String(a.workersTotal), icon: 'engineering', tone: 'success' },
                    { label: 'Transmis ce mois', value: String(a.monthRegistered), icon: 'send', tone: 'info' },
                    { label: 'Validés ce mois', value: String(a.monthValidated), icon: 'verified', tone: 'success' },
                    { label: 'Taux de validation', value: a.validationRate != null ? `${a.validationRate} %` : '—', icon: 'percent', tone: 'info', bar: a.validationRate ?? 0 },
                    { label: 'Objectif du mois', value: a.objective ? `${a.objective.achieved} / ${a.objective.target}` : '—', icon: 'flag', tone: 'warning', bar: a.objective?.percent ?? 0 },
                    { label: 'Rang du mois', value: a.ranking ? `${a.ranking.rank} / ${a.ranking.total}` : '—', icon: 'leaderboard', tone: 'orange', sub: a.ranking ? 'Sur les ouvriers validés' : 'Non classé' },
                    { label: 'Commissions du mois', value: fcfa(a.monthCommissions), icon: 'payments', tone: 'success' },
                    { label: 'Commissions à verser', value: fcfa(a.unpaidCommissions), icon: 'account_balance_wallet', tone: 'warning' },
                    { label: 'Dernière connexion', value: a.lastLoginAt ? formatDate(a.lastLoginAt) : '—', icon: 'login', tone: 'neutral' },
                    { label: 'Dernière activité', value: a.lastActivityOn ? formatDate(a.lastActivityOn) : '—', icon: 'directions_walk', tone: 'neutral' },
                ]}
            />
            <ChartGrid>
                <BarChart title="Évolution des recrutements" sub="Ouvriers transmis par mois" bars={a.months.map((m) => ({ label: m.label, value: m.registered, tip: `${m.label} : ${m.registered} transmis · ${m.validated} validés` }))} />
                <BarChart title="Répartition par métier" sub="Ouvriers transmis" color="var(--c-accent-600)" bars={a.trades.map((t) => ({ label: t.label, value: t.count }))} />
            </ChartGrid>
        </div>
    );
}

type TabProps = Pick<SalesShowProps, 'agent' | 'can' | 'documents' | 'access'> & { actions: SalesActions; onUpload?: () => void };

/** Profil et documents : coordonnées, contrat, zones et pièces, avec leur contrôle. */
export function ProfileTab({ agent: a, can, documents, actions, onUpload }: TabProps) {
    const ui = useAdminUi();
    const provided = documents.filter((d) => d.hasFile);
    const verified = provided.filter((d) => d.status.value === 'valide').length;
    const decide = (d: WorkerDocument) => can.documents && d.canDecide;

    const preview = (d: WorkerDocument) =>
        ui.openPanel({
            kicker: `Pièce du dossier · ${a.name}`,
            title: d.label,
            badges: [{ label: d.status.label, tone: d.status.tone }],
            state: d.hasFile ? undefined : { kind: 'missing', title: 'Pièce non déposée', text: 'Ajoutez cette pièce au dossier du commercial.' },
            meta: [
                ['Numéro', d.reference ?? '—'],
                ['Fichier', d.fileName ?? '—'],
                ['Ajoutée le', formatDate(d.addedAt)],
                ['Expiration', d.expiresAt ? formatDate(d.expiresAt) : 'Sans expiration'],
                ...(d.rejectionReason ? ([['Motif du rejet', d.rejectionReason]] as [string, string][]) : []),
            ],
            actions: [
                ...d.files.map((f) => ({ label: f.side === 'verso' ? 'Ouvrir le verso' : d.files.length > 1 ? 'Ouvrir le recto' : 'Ouvrir le fichier', icon: 'open_in_new', onClick: () => window.open(f.url, '_blank', 'noopener') })),
                ...(decide(d)
                    ? [
                          { label: 'Rejeter', icon: 'cancel', variant: 'danger-soft' as const, onClick: () => actions.rejectDocument(a, d) },
                          { label: 'Valider', icon: 'check', variant: 'primary' as const, onClick: () => actions.validateDocument(a, d) },
                      ]
                    : []),
            ],
        });

    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)' }}>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,360px),1fr))', gap: 'var(--space-4)' }}>
                <Block icon="person" title="Coordonnées">
                    <KeyValues
                        items={[
                            ['Prénom', a.firstName],
                            ['Nom', a.lastName],
                            ['Téléphone', a.phone],
                            ['Second téléphone', a.secondaryPhone],
                            ['E-mail', a.email],
                            ['Adresse', a.address],
                        ]}
                    />
                </Block>
                <Block icon="badge" title="Contrat">
                    <KeyValues
                        items={[
                            ['Matricule', a.code],
                            ['Statut du compte', a.status.label],
                            ['Vérification du dossier', a.verification],
                            ['Début', a.startedAt ? formatDate(a.startedAt) : null],
                            ['Fin', a.endedAt ? formatDate(a.endedAt) : null],
                            ['Superviseur', a.supervisor],
                            ['Créé par', a.createdBy],
                        ]}
                    />
                </Block>
            </div>
            <DataTable
                icon="folder_shared"
                title="Pièces du dossier"
                count={`${verified} / ${provided.length} vérifiées`}
                minWidth={760}
                tools={onUpload ? [{ label: 'Ajouter une pièce', icon: 'upload_file', variant: 'primary', onClick: onUpload }] : []}
                columns={['Pièce', 'Numéro', 'Ajoutée le', 'Expiration', 'Statut', 'Motif du rejet']}
                empty={{ icon: 'folder_shared', title: 'Aucune pièce', text: '' }}
                rows={documents.map((d) => ({
                    key: d.id,
                    onOpen: () => preview(d),
                    cells: [
                        <TextCell strong>
                            {d.label}
                            {d.required ? ' *' : ''}
                        </TextCell>,
                        <TextCell muted={!d.reference}>{d.reference ?? '—'}</TextCell>,
                        <TextCell muted={!d.addedAt}>{d.addedAt ? formatDate(d.addedAt) : '—'}</TextCell>,
                        <TextCell muted={!d.expiresAt}>{d.expiresAt ? formatDate(d.expiresAt) : d.hasFile ? 'Sans expiration' : '—'}</TextCell>,
                        <StatusBadge label={d.status.label} tone={d.status.tone} icon={d.status.value === 'valide' ? 'verified' : undefined} />,
                        <TextCell muted={!d.rejectionReason}>{d.rejectionReason ?? '—'}</TextCell>,
                    ],
                    actions: [
                        { icon: 'visibility', label: 'Voir la pièce', onClick: () => preview(d) },
                        ...(decide(d)
                            ? [
                                  { icon: 'check_circle', label: 'Valider', onClick: () => actions.validateDocument(a, d) },
                                  { icon: 'cancel', label: 'Rejeter', onClick: () => actions.rejectDocument(a, d) },
                              ]
                            : []),
                    ],
                }))}
            />
        </div>
    );
}

/** Accès et sécurité : lien d’activation ou de réinitialisation, connexion et sessions ouvertes. */
export function AccessTab({ agent: a, can, access, actions }: TabProps) {
    const link = a.accessLink;
    const sessions = access?.sessions ?? [];

    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)' }}>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,360px),1fr))', gap: 'var(--space-4)' }}>
                <Block
                    icon="link"
                    title="Lien d’accès"
                    action={can.update && link.state === 'en_cours' ? { label: 'Révoquer', icon: 'link_off', onClick: () => actions.revokeLink(a) } : undefined}
                >
                    <KeyValues
                        items={[
                            ['État', link.label],
                            ['Envoyé le', link.sentAt ? formatDate(link.sentAt) : null],
                            ['Expire le', link.expiresAt && link.state === 'en_cours' ? formatDate(link.expiresAt) : null],
                            ['Utilisé le', link.usedAt ? formatDate(link.usedAt) : null],
                        ]}
                    />
                </Block>
                <Block icon="login" title="Connexion">
                    <KeyValues
                        items={[
                            ['Mot de passe', a.passwordSet ? 'Choisi' : 'Pas encore choisi'],
                            ['Téléphone vérifié', access?.phoneVerified ? 'Oui' : 'Non'],
                            ['Dernière connexion', access?.lastLoginAt ? formatDate(access.lastLoginAt) : null],
                            ['Échecs de connexion', String(access?.failedLogins ?? 0)],
                            ['Verrouillé jusqu’au', access?.lockedUntil ? formatDate(access.lockedUntil) : null],
                        ]}
                    />
                </Block>
            </div>
            <DataTable
                icon="devices"
                title="Sessions ouvertes"
                count={String(sessions.length)}
                minWidth={560}
                tools={can.update && sessions.length ? [{ label: 'Tout déconnecter', icon: 'logout', variant: 'secondary', onClick: () => actions.revokeSession(a) }] : []}
                columns={['Appareil', 'Plateforme', 'Dernière activité']}
                empty={{ icon: 'devices', title: 'Aucune session ouverte', text: '' }}
                rows={sessions.map((s) => ({
                    key: s.id,
                    cells: [<TextCell strong>{s.device}</TextCell>, <TextCell muted={!s.platform}>{s.platform ?? '—'}</TextCell>, <TextCell muted={!s.lastActivity}>{s.lastActivity ? formatDate(s.lastActivity) : '—'}</TextCell>],
                    actions: can.update ? [{ icon: 'logout', label: 'Fermer la session', onClick: () => actions.revokeSession(a, s) }] : [],
                }))}
            />
        </div>
    );
}
