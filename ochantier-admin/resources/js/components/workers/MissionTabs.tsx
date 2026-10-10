import FormDialog from '@/components/overlays/FormDialog';
import { ProgressCell, TextCell } from '@/components/list/Table';
import Icon from '@/components/ui/Icon';
import type { MenuItem } from '@/components/ui/Menu';
import StatusBadge from '@/components/ui/StatusBadge';
import { useAdminUi } from '@/contexts/AdminUiContext';
import { formatDate, formatNumber } from '@/lib/format';
import { workerTone } from '@/lib/workerTones';
import type { Assignment, Payout, Timesheet } from '@/types/workers';
import { DataTable, TileGrid, postAction } from './blocks';
import type { TabProps } from './tabs';

const money = (n: number) => `${formatNumber(n)} FCFA`;

/** Consultation rapide d’un chantier d’affectation. */
function useSitePanel() {
    const ui = useAdminUi();
    return (a: Assignment) =>
        ui.openPanel({
            kicker: 'Détail du chantier',
            title: a.site,
            badges: [{ label: a.status, tone: workerTone(a.status) }],
            progress: a.progress,
            meta: [
                ['Promoteur', a.promoter],
                ['Besoin / offre', a.offer],
                ['Type de mission', a.type],
                ['Période', `${formatDate(a.start)} → ${formatDate(a.end)}`],
                ['Mode de paiement', a.pay],
            ],
        });
}

/** Affectations et missions, avec leurs transitions. */
export function AssignmentsTab({ worker: w, demo, options, actions }: TabProps) {
    const ui = useAdminUi();
    const openSite = useSitePanel();
    const validated = w.status.value === 'validated';
    const base = `/ouvriers/${w.slug}/affectations`;
    const go = (a: Assignment, action: string) => postAction(`${base}/${a.id}/${action}`);

    const menu = (a: Assignment): MenuItem[] => {
        const items: MenuItem[] = [{ icon: 'construction', label: 'Consulter le chantier', onClick: () => openSite(a) }];
        if (['Proposée', 'En attente d’acceptation'].includes(a.status)) {
            items.push({ icon: 'thumb_up', label: 'Marquer comme acceptée', onClick: () => go(a, 'accepter') }, { icon: 'thumb_down', label: 'Marquer comme refusée', onClick: () => go(a, 'refuser') });
        }
        if (['Proposée', 'En attente d’acceptation', 'Acceptée', 'À valider'].includes(a.status)) {
            items.push({
                icon: 'verified',
                label: 'Confirmer l’affectation',
                onClick: () => ui.confirm({ tone: 'success', icon: 'verified', title: 'Confirmer cette affectation ?', body: `${w.name} sera confirmé sur ${a.site} du ${formatDate(a.start)} au ${formatDate(a.end)}.`, ok: 'Confirmer', onConfirm: () => go(a, 'confirmer') }),
            });
        }
        if (a.status === 'En cours') items.push({ icon: 'pause_circle', label: 'Suspendre la mission', onClick: () => go(a, 'suspendre') });
        if (['En cours', 'Confirmée', 'Acceptée'].includes(a.status)) items.push({ icon: 'swap_horiz', label: 'Déclencher un remplacement', onClick: () => actions.unavailability(w, demo.assignments, true) });
        if (!['Terminée', 'Annulée', 'Remplacée', 'Refusée'].includes(a.status)) {
            items.push({
                icon: 'remove_circle',
                label: 'Retirer l’affectation',
                danger: true,
                onClick: () =>
                    actions.open(
                        <FormDialog title="Retirer cette affectation ?" body="Le promoteur et l’ouvrier seront notifiés." icon="remove_circle" tone="danger" okLabel="Retirer" url={`${base}/${a.id}/retirer`} onClose={actions.close} fields={[{ name: 'reason', label: 'Motif du retrait', type: 'textarea', required: true }]} />,
                    ),
            });
        }
        return items;
    };

    return (
        <DataTable
            icon="assignment_ind"
            title="Affectations et missions"
            count={`${demo.assignments.length} affectation(s)`}
            minWidth={1500}
            tools={validated ? [{ label: 'Proposer une affectation', icon: 'add', variant: 'primary', onClick: () => actions.assign(w, options.sites) }] : []}
            columns={['Chantier', 'Promoteur', 'Besoin / offre', 'Métier', 'Type', 'Début', 'Fin', 'Paiement', 'Montant', 'Statut', 'Progression']}
            empty={{ icon: 'assignment_ind', title: 'Aucune affectation', text: validated ? 'Proposez une première affectation à cet ouvrier.' : 'Le profil doit être validé avant toute affectation.' }}
            rows={demo.assignments.map((a) => ({
                key: a.id,
                onOpen: () => openSite(a),
                cells: [
                    <TextCell strong>{a.site}</TextCell>,
                    <TextCell>{a.promoter}</TextCell>,
                    <TextCell>{a.offer}</TextCell>,
                    <TextCell>{a.trade}</TextCell>,
                    <StatusBadge label={a.type} tone={workerTone(a.type)} />,
                    <TextCell>{formatDate(a.start)}</TextCell>,
                    <TextCell>{formatDate(a.end)}</TextCell>,
                    <TextCell>{a.pay}</TextCell>,
                    <TextCell strong>{formatNumber(a.amount) + (a.type === 'Contractuelle' ? ' FCFA' : ' FCFA/j')}</TextCell>,
                    <StatusBadge label={a.status} tone={workerTone(a.status)} />,
                    <ProgressCell value={a.progress} />,
                ],
                actions: [{ icon: 'visibility', label: 'Consulter le chantier', onClick: () => openSite(a) }],
                menu: menu(a),
            }))}
        />
    );
}

/** Durée travaillée, pause déjeuner d’une heure déduite, en minutes. */
function minutes(t: Timesheet): number | null {
    if (!t.in || !t.out) return null;
    const [a, b] = t.in.split(':').map(Number);
    const [c, d] = t.out.split(':').map(Number);
    return c * 60 + d - a * 60 - b - 60;
}

const hours = (m: number) => `${Math.floor(m / 60)} h ${String(m % 60).padStart(2, '0')}`;

const ANOMALIES = ['Retard', 'Départ anticipé', 'Absence', 'Photo non conforme', 'Signature manquante', 'Localisation incohérente'];

function TimeCell({ value, late, missing }: { value: string; late?: boolean; missing?: boolean }) {
    return (
        <div>
            <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-1_5)', whiteSpace: 'nowrap', fontVariantNumeric: 'tabular-nums', fontWeight: 'var(--fw-semibold)', color: value ? 'var(--c-ink)' : 'var(--c-muted)' }}>
                {value || '—'}
                {value && <Icon name={late ? 'cancel' : 'check_circle'} size="var(--icon-xs)" color={late ? 'var(--c-danger)' : 'var(--c-success)'} />}
            </div>
            {(late || missing) && (
                <span style={{ display: 'inline-block', marginTop: 'var(--space-0_5)', padding: '1px var(--space-1_5)', borderRadius: 'var(--radius-sm)', background: 'var(--c-danger-bg)', color: 'var(--c-danger-fg)', fontSize: 'var(--fs-xs)', fontWeight: 'var(--fw-semibold)' }}>{late ? 'Retard' : 'Non pointé'}</span>
            )}
        </div>
    );
}

function ProofCell({ photo, signature }: { photo: 'ok' | 'ko' | 'none'; signature: 'ok' | 'none' }) {
    const mark = { ok: ['check_circle', 'var(--c-success)'], ko: ['cancel', 'var(--c-danger)'], none: ['remove', 'var(--c-neutral)'] } as const;
    return (
        <div style={{ display: 'flex', gap: 'var(--space-3)', fontSize: 'var(--fs-sm)', color: 'var(--c-ink-2)', whiteSpace: 'nowrap' }}>
            <span style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-0_5)' }}>
                Photo
                <Icon name={mark[photo][0]} size="var(--icon-xs)" color={mark[photo][1]} />
            </span>
            <span style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-0_5)' }}>
                Signature
                <Icon name={mark[signature][0]} size="var(--icon-xs)" color={mark[signature][1]} />
            </span>
        </div>
    );
}

/** Pointages journaliers : preuves, anomalies, corrections, validation. */
export function TimesheetsTab({ worker: w, demo, actions }: TabProps) {
    const ui = useAdminUi();
    const list = demo.timesheets;
    const base = `/ouvriers/${w.slug}/pointages`;
    const total = list.reduce((sum, t) => sum + (minutes(t) ?? 0), 0);

    const flag = (t: Timesheet) =>
        actions.open(
            <FormDialog
                title="Signaler une anomalie"
                body={`${formatDate(t.date)} · ${t.site}`}
                icon="report"
                tone="warning"
                okLabel="Signaler"
                url={`${base}/${t.id}/anomalie`}
                onClose={actions.close}
                fields={[
                    { name: 'type', label: 'Type d’anomalie', type: 'select', required: true, full: true, options: ANOMALIES },
                    { name: 'comment', label: 'Commentaire', type: 'textarea', required: true },
                ]}
            />,
        );

    const correct = (t: Timesheet) =>
        actions.open(
            <FormDialog
                title="Corriger le pointage"
                body={`${formatDate(t.date)} · ${t.site}`}
                icon="edit_calendar"
                okLabel="Enregistrer la correction"
                url={`${base}/${t.id}/correction`}
                onClose={actions.close}
                initial={{ in: t.in || '07:30', out: t.out || '17:00' }}
                fields={[
                    { name: 'in', label: 'Heure d’arrivée', type: 'time', required: true },
                    { name: 'out', label: 'Heure de départ', type: 'time', required: true },
                    { name: 'justification', label: 'Justification', type: 'textarea', required: true, placeholder: 'Ex. attestation du chef de chantier' },
                ]}
            />,
        );

    const proofs = (t: Timesheet) => {
        const m = minutes(t);
        ui.openPanel({
            kicker: `Preuves de pointage · ${formatDate(t.date)}`,
            title: `${w.name} · ${t.site}`,
            badges: [{ label: t.status, tone: workerTone(t.status) }],
            text: t.in ? (t.photoOk ? `Photo géolocalisée à l’arrivée (${t.in}) et signature enregistrées.` : `Photo d’arrivée floue (${t.in}) : à vérifier.`) : 'Aucune preuve d’arrivée.',
            meta: [
                ['Chantier', t.site],
                ['Arrivée', t.in || '—'],
                ['Départ', t.out || '—'],
                ['Durée calculée', m == null ? '—' : hours(m)],
                ['Validation du promoteur', t.validation],
                ...(t.anomaly ? ([['Anomalie', t.anomaly]] as [string, string][]) : []),
                ...(t.correction ? ([['Correction', t.correction]] as [string, string][]) : []),
            ],
            actions: [
                { label: 'Signaler une anomalie', icon: 'report', onClick: () => flag(t) },
                { label: 'Corriger', icon: 'edit_calendar', variant: 'primary', onClick: () => correct(t) },
            ],
        });
    };

    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)' }}>
            <TileGrid
                tiles={[
                    { label: 'Jours pointés', value: String(list.filter((t) => t.in).length), icon: 'event_available', tone: 'success', sub: 'sur les 10 derniers jours ouvrés' },
                    { label: 'Heures travaillées', value: hours(total), icon: 'schedule', tone: 'info', sub: 'pause déjeuner déduite (1 h)' },
                    { label: 'Retards', value: String(list.filter((t) => t.status === 'En retard').length), icon: 'running_with_errors', tone: 'orange' },
                    { label: 'Absences', value: String(list.filter((t) => t.status === 'Absence').length), icon: 'person_off', tone: 'danger' },
                    { label: 'À vérifier ou contestés', value: String(list.filter((t) => ['À vérifier', 'Contesté', 'Incomplet'].includes(t.status)).length), icon: 'rule', tone: 'warning' },
                ]}
            />
            <DataTable
                icon="schedule"
                title="Pointages journaliers"
                count={`${list.length} jour(s)`}
                minWidth={1400}
                tools={list.length ? [{ label: 'Exporter les pointages', icon: 'download', onClick: () => (window.location.href = `${base}/export`) }] : []}
                columns={['Date', 'Chantier', 'Arrivée', 'Preuves arrivée', 'Départ', 'Preuves départ', 'Durée', 'Statut', 'Anomalie', 'Validation promoteur']}
                empty={{ icon: 'schedule', title: 'Aucun pointage', text: 'Les pointages apparaîtront dès la première mission.' }}
                rows={list.map((t) => {
                    const m = minutes(t);
                    return {
                        key: t.id,
                        onOpen: () => proofs(t),
                        cells: [
                            <TextCell strong>{formatDate(t.date)}</TextCell>,
                            <TextCell>{t.site}</TextCell>,
                            <TimeCell value={t.in} late={t.status === 'En retard'} />,
                            <ProofCell photo={t.in ? (t.photoOk ? 'ok' : 'ko') : 'none'} signature={t.in ? 'ok' : 'none'} />,
                            <TimeCell value={t.out} missing={!!t.in && !t.out} />,
                            <ProofCell photo={t.out ? 'ok' : 'none'} signature={t.out ? 'ok' : 'none'} />,
                            <TextCell>{m == null ? '—' : hours(m)}</TextCell>,
                            <StatusBadge label={t.status} tone={workerTone(t.status)} />,
                            <TextCell muted={!t.anomaly}>{t.anomaly || '—'}</TextCell>,
                            <StatusBadge label={t.validation} tone={workerTone(t.validation)} />,
                        ],
                        actions: [{ icon: 'photo_library', label: 'Consulter les preuves', onClick: () => proofs(t) }],
                        menu: [
                            { icon: 'report', label: 'Signaler une anomalie', onClick: () => flag(t) },
                            { icon: 'edit_calendar', label: 'Corriger avec justification', onClick: () => correct(t) },
                            ...(t.status !== 'Validé' ? [{ icon: 'verified', label: 'Valider le pointage', onClick: () => postAction(`${base}/${t.id}/validation`) }] : []),
                        ],
                    };
                })}
            />
        </div>
    );
}

/** Rémunération : barème, gains par statut et historique des versements. */
export function PayoutsTab({ worker: w, demo, commissionRate }: TabProps) {
    const ui = useAdminUi();
    const list = demo.payouts;
    const base = `/ouvriers/${w.slug}/paiements`;
    const sum = (statuses: string[]) => list.filter((p) => statuses.includes(p.status)).reduce((s, p) => s + p.net, 0);
    const net = Math.round((w.dailyRate * (100 - commissionRate)) / 100);
    const mode = (demo.assignments.find((a) => a.status === 'En cours') ?? demo.assignments[0])?.pay ?? 'Hebdomadaire';

    const detail = (p: Payout) =>
        ui.openPanel({
            kicker: `Paiement ouvrier · ${w.name}`,
            title: p.ref,
            badges: [{ label: p.status, tone: workerTone(p.status) }],
            meta: [
                ['Chantier', p.site],
                ['Période', `${formatDate(p.from)} → ${formatDate(p.to)}`],
                ['Jours validés', String(p.days)],
                ['Montant brut', money(p.gross)],
                [`Commission O’CHANTIER (${commissionRate} %)`, `− ${money(p.commission)}`],
                ['Montant net', money(p.net)],
                ['Moyen de paiement', `${p.method} · ${w.phone}`],
                ['Date de paiement', formatDate(p.paidAt)],
            ],
            actions: [
                { label: 'Télécharger le relevé', icon: 'download', variant: 'primary', onClick: () => (window.location.href = `${base}/${p.id}/releve`) },
                ...(p.status === 'Échoué' ? [{ label: 'Relancer le versement', icon: 'refresh', onClick: () => postAction(`${base}/${p.id}/relance`) }] : []),
                ...(p.status === 'En attente de validation' ? [{ label: 'Valider', icon: 'verified', onClick: () => postAction(`${base}/${p.id}/validation`) }] : []),
            ],
        });

    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)' }}>
            <TileGrid
                tiles={[
                    { label: 'Coût journalier de référence', value: money(w.dailyRate), icon: 'payments', tone: 'info' },
                    { label: 'Commission O’CHANTIER (Z)', value: `${commissionRate} % · ${money(Math.round((w.dailyRate * commissionRate) / 100))}`, icon: 'percent', tone: 'orange', sub: 'Règle définie dans Paramètres' },
                    { label: 'Montant net journalier', value: money(net), icon: 'account_balance_wallet', tone: 'success' },
                    { label: 'Mode de paiement', value: mode, icon: 'event_repeat', tone: 'neutral', sub: list[0]?.method ?? '—' },
                    { label: 'Gains en attente', value: money(sum(['À calculer', 'En attente de validation', 'Validé'])), icon: 'hourglass_top', tone: 'warning' },
                    { label: 'Gains disponibles', value: money(sum(['Programmé', 'En cours'])), icon: 'savings', tone: 'info' },
                    { label: 'Gains déjà versés', value: money(sum(['Payé'])), icon: 'task_alt', tone: 'success' },
                    { label: 'Retenues', value: money(sum(['Suspendu', 'Contesté', 'Échoué'])), icon: 'money_off', tone: 'danger' },
                    { label: 'Remboursements', value: money(sum(['Remboursé'])), icon: 'undo', tone: 'purple' },
                ]}
            />
            <DataTable
                icon="receipt_long"
                title="Historique des opérations"
                count={`${list.length} opération(s)`}
                minWidth={1300}
                columns={['Référence', 'Chantier', 'Période', 'Jours validés', 'Montant brut', 'Commission', 'Montant net', 'Statut', 'Date de paiement', 'Moyen']}
                empty={{ icon: 'receipt_long', title: 'Aucun paiement', text: 'Aucune opération financière pour cet ouvrier.' }}
                rows={list.map((p) => ({
                    key: p.id,
                    onOpen: () => detail(p),
                    cells: [
                        <TextCell strong>{p.ref}</TextCell>,
                        <TextCell>{p.site}</TextCell>,
                        <TextCell>
                            {formatDate(p.from)} → {formatDate(p.to)}
                        </TextCell>,
                        <TextCell>{p.days}</TextCell>,
                        <TextCell>{money(p.gross)}</TextCell>,
                        <TextCell muted>− {money(p.commission)}</TextCell>,
                        <TextCell strong>{money(p.net)}</TextCell>,
                        <StatusBadge label={p.status} tone={workerTone(p.status)} />,
                        <TextCell muted={!p.paidAt}>{formatDate(p.paidAt)}</TextCell>,
                        <TextCell>{p.method}</TextCell>,
                    ],
                    actions: [{ icon: 'visibility', label: 'Voir le détail', onClick: () => detail(p) }],
                }))}
            />
        </div>
    );
}
