import FormDialog from '@/components/overlays/FormDialog';
import PlanCard from '@/components/subscriptions/PlanCard';
import Avatar from '@/components/ui/Avatar';
import Button from '@/components/ui/Button';
import Icon from '@/components/ui/Icon';
import StatusBadge from '@/components/ui/StatusBadge';
import { Block, KeyValues } from '@/components/workers/blocks';
import { useAdminUi } from '@/contexts/AdminUiContext';
import { formatDate, formatNumber } from '@/lib/format';
import type { SubscriptionShowProps } from '@/types/subscriptions';
import { Head, router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';

const INVOICE_TONES: Record<string, 'success' | 'danger' | 'warning'> = { Payée: 'success', Échouée: 'danger' };

/** Fiche d’un abonnement : formule et changement de formule, collaborateurs, factures, actions et journal. */
export default function SubscriptionShow({ subscription: s, plans, users, invoices, activities, roles }: SubscriptionShowProps) {
    const ui = useAdminUi();
    const [pick, setPick] = useState(s.plan.key);
    const [dialog, setDialog] = useState<ReactNode>(null);
    const close = () => setDialog(null);
    const base = `/abonnements/${s.slug}`;
    const post = (path: string, data: Record<string, string> = {}) => router.post(`${base}/${path}`, data, { preserveScroll: true });
    const reason = (title: string, body: string, ok: string, path: string, tone: 'warning' | 'danger', icon: string) =>
        setDialog(<FormDialog title={title} body={body} icon={icon} tone={tone} okLabel={ok} url={`${base}/${path}`} onClose={close} fields={[{ name: 'reason', label: 'Motif', type: 'textarea', required: true }]} />);

    const target = plans.find((p) => p.key === pick) ?? s.plan;
    const changing = pick !== s.plan.key;
    const up = target.price > s.plan.price;
    const tooMany = users.length > target.maxUsers;
    const blocked = s.locked || tooMany;
    const changeText = !changing
        ? ''
        : s.locked
          ? 'Réactivez l’abonnement avant de changer de formule.'
          : tooMany
            ? `Ce compte a ${users.length} utilisateurs. Retirez-en ${users.length - target.maxUsers} avant de passer à ${target.name} (${target.maxUsers} maximum).`
            : up
              ? `Activation immédiate. ${s.plan.solo ? `${formatNumber(target.price)} FCFA facturés aujourd’hui` : 'Montant au prorata facturé aujourd’hui'}, puis ${formatNumber(target.price)} FCFA par mois.`
              : `Le changement prendra effet à la prochaine échéance (${formatDate(s.nextDue)}).`;
    const full = users.length >= s.plan.maxUsers;

    const apply = () =>
        ui.confirm({
            tone: up ? 'success' : 'warning',
            icon: up ? 'upgrade' : 'south',
            title: `Passer à la formule ${target.name} ?`,
            body: up ? `Activation immédiate pour ${s.promoter}. Le promoteur est notifié par SMS et e-mail.` : `Le changement prendra effet le ${formatDate(s.nextDue)}. Les avantages de la formule ${s.plan.name} restent actifs jusque-là.`,
            ok: 'Confirmer',
            onConfirm: () => router.post(`${base}/formule`, { plan: target.key }, { preserveScroll: true, onSuccess: () => setPick(target.key) }),
        });

    const actions = [
        ...(s.statusLabel === 'Impayé'
            ? [
                  { label: 'Relancer le paiement', icon: 'send', variant: 'primary' as const, onClick: () => post('relance') },
                  { label: 'Marquer comme payé', icon: 'check_circle', variant: 'secondary' as const, onClick: () => ui.confirm({ tone: 'success', icon: 'check_circle', title: 'Confirmer le paiement ?', body: 'La facture en échec passe à « Payée » et l’échéance suivante est recalculée.', ok: 'Confirmer', onConfirm: () => post('paiement') }) },
              ]
            : []),
        ...(s.statusLabel === 'Essai' ? [{ label: 'Prolonger l’essai de 7 jours', icon: 'more_time', variant: 'secondary' as const, onClick: () => post('essai') }] : []),
        ...(s.statusLabel === 'Suspendu' ? [{ label: 'Réactiver', icon: 'play_circle', variant: 'primary' as const, onClick: () => ui.confirm({ tone: 'success', icon: 'play_circle', title: 'Réactiver l’abonnement ?', body: 'Les collaborateurs retrouvent leur accès immédiatement.', ok: 'Réactiver', onConfirm: () => post('reactivation') }) }] : []),
        ...(!s.plan.solo && ['Actif', 'Impayé', 'Essai'].includes(s.statusLabel) ? [{ label: 'Suspendre', icon: 'pause_circle', variant: 'secondary' as const, onClick: () => reason('Suspendre l’abonnement ?', 'Les collaborateurs perdent l’accès. Le propriétaire conserve son compte avec les droits Solo.', 'Suspendre', 'suspension', 'warning', 'pause_circle') }] : []),
        ...(!s.plan.solo && s.statusLabel !== 'Résilié' ? [{ label: 'Résilier', icon: 'cancel', variant: 'secondary' as const, onClick: () => reason('Résilier l’abonnement ?', 'Le compte repasse en formule Solo : seul le propriétaire conserve l’accès. Les données des chantiers sont conservées.', 'Résilier', 'resiliation', 'danger', 'cancel') }] : []),
        ...(s.statusLabel === 'Résilié'
            ? [{ label: 'Réactiver une formule', icon: 'restart_alt', variant: 'primary' as const, onClick: () => router.post(`${base}/restauration`, {}, { preserveScroll: true, onSuccess: () => setPick(s.previousPlan && s.previousPlan !== 'solo' ? s.previousPlan : 'equipe') }) }]
            : []),
    ];

    const invite = () =>
        setDialog(
            <FormDialog
                title="Inviter un collaborateur"
                body={`Le collaborateur reçoit un lien d’activation et rejoint le compte de ${s.promoter}.`}
                icon="person_add"
                okLabel="Envoyer l’invitation"
                url={`${base}/utilisateurs`}
                onClose={close}
                initial={{ channel: 'SMS' }}
                fields={[
                    { name: 'name', label: 'Nom complet', required: true, full: true },
                    { name: 'channel', label: 'Canal', type: 'select', required: true, options: ['SMS', 'E-mail'] },
                    { name: 'role', label: 'Rôle', type: 'select', required: true, options: roles },
                    { name: 'contact', label: 'Téléphone ou e-mail', required: true, full: true },
                ]}
            />,
        );

    return (
        <>
            <Head title={`Abonnement · ${s.promoter}`} />
            <section style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-5)', alignItems: 'center', background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-5)' }}>
                <Avatar name={s.promoter} kind="team" />
                <div style={{ flex: '1 1 320px', minWidth: 0 }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2_5)', flexWrap: 'wrap' }}>
                        <h2 style={{ margin: 0, fontSize: 'var(--fs-3xl)' }}>{s.promoter}</h2>
                        <StatusBadge label={s.status.label} tone={s.status.tone} />
                    </div>
                    <div style={{ color: 'var(--c-ink-2)', marginTop: 'var(--space-1)' }}>
                        {s.owner} · {s.phone} · {s.email}
                    </div>
                </div>
                <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2)' }}>
                    {actions.map((a) => (
                        <Button key={a.label} label={a.label} icon={a.icon} variant={a.variant} onClick={a.onClick} />
                    ))}
                    <Button label="Fiche promoteur" icon="open_in_new" variant="secondary" onClick={() => router.visit(`/promoteurs/${s.slug}`)} />
                </div>
            </section>

            {s.pending && (
                <div role="status" style={{ display: 'flex', gap: 'var(--space-3)', alignItems: 'center', padding: 'var(--space-3) var(--space-4)', borderRadius: 'var(--radius-lg)', background: 'var(--c-info-bg)', color: 'var(--c-info-fg)' }}>
                    <Icon name="schedule" />
                    <span style={{ flex: 1 }}>
                        Passage à la formule <b>{s.pending.name}</b> programmé le {formatDate(s.pending.at)}.
                    </span>
                    <Button label="Annuler le changement" size="sm" variant="secondary" onClick={() => router.delete(`${base}/formule-programmee`, { preserveScroll: true })} />
                </div>
            )}

            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,420px),1fr))', gap: 'var(--space-4)' }}>
                <Block icon="workspace_premium" title={`Formule ${s.plan.name}`} count={s.plan.price ? `${formatNumber(s.plan.price)} FCFA / mois` : 'Gratuit'}>
                    <div style={{ color: 'var(--c-muted)', marginBottom: 'var(--space-3)' }}>{s.plan.description}</div>
                    <div style={{ fontWeight: 'var(--fw-semibold)' }}>
                        {users.length} / {s.plan.maxUsers} utilisateur{s.plan.maxUsers > 1 ? 's' : ''}
                    </div>
                    <div style={{ height: 6, borderRadius: 'var(--radius-pill)', background: 'var(--c-line-soft)', overflow: 'hidden', margin: 'var(--space-1_5) 0 var(--space-3)' }}>
                        <div style={{ height: '100%', width: `${Math.min(100, (users.length / s.plan.maxUsers) * 100)}%`, background: 'var(--c-primary-500)' }} />
                    </div>
                    <KeyValues
                        items={[
                            ['Montant', `${s.plan.priceLabel}${s.plan.price ? ' / mois' : ''}`],
                            ['Abonné depuis', formatDate(s.since)],
                            [s.statusLabel === 'Essai' ? 'Fin de l’essai' : 'Prochaine échéance', <span key="n" style={{ color: s.statusLabel === 'Impayé' ? 'var(--c-danger-fg)' : undefined }}>{formatDate(s.nextDue)}</span>],
                            ['Cycle', s.plan.price ? 'Mensuel' : '—'],
                            ['Moyen de paiement', s.paymentMethod ?? '—'],
                        ]}
                    />
                </Block>
                <Block icon="history" title="Journal">
                    <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-2)' }}>
                        {activities.map((a) => (
                            <div key={a.id} style={{ display: 'flex', gap: 'var(--space-3)', fontSize: 'var(--fs-md)' }}>
                                <span style={{ width: 90, flex: 'none', color: 'var(--c-muted)' }}>{formatDate(a.date)}</span>
                                <span>{a.text}</span>
                            </div>
                        ))}
                    </div>
                </Block>
            </div>

            <Block icon="swap_vert" title="Changer de formule" count="Montée immédiate (prorata) · descente à la prochaine échéance">
                <div role="radiogroup" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,260px),1fr))', gap: 'var(--space-4)', marginTop: 'var(--space-2)' }}>
                    {plans.map((p) => (
                        <PlanCard key={p.key} plan={p} selected={p.key === pick} current={p.key === s.plan.key} onSelect={() => setPick(p.key)} />
                    ))}
                </div>
                {changing && (
                    <div style={{ display: 'flex', gap: 'var(--space-3)', alignItems: 'center', flexWrap: 'wrap', marginTop: 'var(--space-4)', padding: 'var(--space-3) var(--space-4)', borderRadius: 'var(--radius-lg)', background: blocked ? 'var(--c-danger-bg)' : 'var(--c-primary-50)', color: blocked ? 'var(--c-danger-fg)' : 'var(--c-primary)' }}>
                        <Icon name={blocked ? 'error' : up ? 'bolt' : 'schedule'} />
                        <span style={{ flex: 1, minWidth: 200 }}>{changeText}</span>
                        <Button label="Annuler" size="sm" variant="secondary" onClick={() => setPick(s.plan.key)} />
                        {!blocked && <Button label={up ? `Activer ${target.name}` : `Programmer ${target.name}`} size="sm" variant="primary" onClick={apply} />}
                    </div>
                )}
            </Block>

            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,420px),1fr))', gap: 'var(--space-4)' }}>
                <Block icon="group" title="Utilisateurs du compte" count={`${users.length} / ${s.plan.maxUsers}`} action={!full && !s.locked ? { label: 'Inviter', icon: 'person_add', onClick: invite } : null}>
                    {(full || s.locked) && (
                        <div style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)', marginBottom: 'var(--space-3)' }}>
                            {s.locked ? `Abonnement ${s.statusLabel.toLowerCase()} : les invitations sont bloquées.` : s.plan.solo ? 'La formule Solo est limitée au propriétaire. Passez à Équipe pour inviter des collaborateurs.' : `Limite de ${s.plan.maxUsers} utilisateurs atteinte. Passez à une formule supérieure pour en ajouter.`}
                        </div>
                    )}
                    <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-2)' }}>
                        {users.map((u) => (
                            <div key={u.id} style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-3)', padding: 'var(--space-2) var(--space-3)', border: '1px solid var(--c-line-soft)', borderRadius: 'var(--radius-lg)' }}>
                                <Avatar name={u.name} />
                                <div style={{ flex: 1, minWidth: 0 }}>
                                    <div style={{ fontWeight: 'var(--fw-semibold)' }}>{u.name}</div>
                                    <div style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>
                                        {u.role} · {u.via}
                                    </div>
                                </div>
                                <StatusBadge label={u.status} tone={u.status === 'Actif' ? 'success' : 'warning'} />
                                {!u.owner && (
                                    <button type="button" aria-label={`Retirer ${u.name}`} title="Retirer" className="och-hover-icon" onClick={() => ui.confirm({ tone: 'danger', icon: 'person_remove', title: `Retirer ${u.name} ?`, body: `Ce collaborateur perd immédiatement l’accès au compte de ${s.promoter}.`, ok: 'Retirer', onConfirm: () => router.delete(`${base}/utilisateurs/${u.id}`, { preserveScroll: true }) })} style={{ border: 0, background: 'transparent', cursor: 'pointer', color: 'var(--c-ink-2)', display: 'grid', placeItems: 'center' }}>
                                        <Icon name="person_remove" />
                                    </button>
                                )}
                            </div>
                        ))}
                    </div>
                </Block>
                <Block icon="receipt_long" title="Factures" count={`${invoices.length} facture(s)`}>
                    {invoices.length === 0 ? (
                        <span style={{ color: 'var(--c-muted)' }}>Aucune facture.</span>
                    ) : (
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-2)' }}>
                            {invoices.map((i) => (
                                <div key={i.id} style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-3)', padding: 'var(--space-2) var(--space-3)', border: '1px solid var(--c-line-soft)', borderRadius: 'var(--radius-lg)' }}>
                                    <div style={{ flex: 1 }}>
                                        <div style={{ fontWeight: 'var(--fw-semibold)' }}>{i.label}</div>
                                        <div style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>
                                            {i.reference} · {formatDate(i.date)}
                                        </div>
                                    </div>
                                    <b>{formatNumber(i.amount)} FCFA</b>
                                    <StatusBadge label={i.status} tone={INVOICE_TONES[i.status] ?? 'warning'} />
                                </div>
                            ))}
                        </div>
                    )}
                </Block>
            </div>
            {dialog}
        </>
    );
}
