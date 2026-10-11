import { FilterSelect, SearchInput } from '@/components/list/ListToolbar';
import Pagination from '@/components/list/Pagination';
import { TableFrame, tdStyle, thStyle } from '@/components/list/Table';
import FormDialog from '@/components/overlays/FormDialog';
import PlanCard from '@/components/subscriptions/PlanCard';
import Avatar from '@/components/ui/Avatar';
import Button from '@/components/ui/Button';
import Icon from '@/components/ui/Icon';
import StatCard from '@/components/ui/StatCard';
import StateBlock from '@/components/ui/StateBlock';
import StatusBadge from '@/components/ui/StatusBadge';
import { AlertList } from '@/components/workers/blocks';
import { useAdminUi } from '@/contexts/AdminUiContext';
import { formatDate, formatNumber } from '@/lib/format';
import type { AnyTone } from '@/types';
import type { Plan, SubscriptionsIndexProps } from '@/types/subscriptions';
import { Head, Link, router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';

const PLAN_TONES: Record<string, [string, string]> = { solo: ['var(--c-neutral-bg)', 'var(--c-neutral-fg)'], equipe: ['var(--c-primary-100)', 'var(--c-primary-600)'], pro: ['var(--c-warning-bg)', 'var(--c-warning-fg)'] };
const planTone = (key: string) => PLAN_TONES[key] ?? ['var(--c-info-bg)', 'var(--c-info-fg)'];

/** Abonnements : abonnés (recherche, filtres par formule et statut) et formules proposées aux promoteurs. */
export default function SubscriptionsIndex({ tab, kpis, filters, statuses, plans, counts, subscriptions }: SubscriptionsIndexProps) {
    const ui = useAdminUi();
    const [dialog, setDialog] = useState<ReactNode>(null);
    const close = () => setDialog(null);
    const visit = (params: Record<string, string | undefined>) => router.get('/abonnements', Object.fromEntries(Object.entries({ ...filters, ...params }).filter(([, v]) => v)) as Record<string, string>, { preserveState: true, preserveScroll: true, replace: true });
    const hasFilters = !!(filters.q || filters.formule || filters.statut);

    const planForm = (p?: Plan) =>
        setDialog(
            <FormDialog
                title={p ? `Modifier la formule ${p.name}` : 'Nouvelle formule'}
                body={p ? 'Le nouveau prix s’applique aux prochaines échéances. Les abonnés sont informés 30 jours avant.' : 'La formule devient disponible immédiatement pour les promoteurs, dans l’application et ici.'}
                icon={p ? 'edit' : 'add'}
                okLabel={p ? 'Enregistrer' : 'Créer la formule'}
                url={p ? `/abonnements/formules/${p.key}` : '/abonnements/formules'}
                method={p ? 'put' : 'post'}
                onClose={close}
                initial={p ? { name: p.name, price: String(p.price), max_users: String(p.maxUsers), popular: p.popular ? '1' : '0', features: p.features.join('\n') } : { popular: '0' }}
                fields={[
                    { name: 'name', label: 'Nom de la formule', required: true },
                    { name: 'price', label: 'Prix mensuel (FCFA)', type: 'number', required: true },
                    { name: 'max_users', label: 'Utilisateurs maximum', type: 'number', required: true },
                    { name: 'popular', label: 'Mise en avant', type: 'select', required: true, options: [{ value: '0', label: 'Non' }, { value: '1', label: 'Oui · « Le plus choisi »' }] },
                    { name: 'features', label: 'Avantages', type: 'textarea', required: true, hint: 'Un avantage par ligne, affichés dans cet ordre sur la carte de la formule.' },
                ]}
            />,
        );

    const deletePlan = (p: Plan) => {
        const others = plans.filter((x) => x.key !== p.key);
        if (!p.subscribers) {
            return ui.confirm({ tone: 'danger', icon: 'delete', title: `Supprimer la formule ${p.name} ?`, body: 'Aucun promoteur n’y est abonné. La formule ne sera plus proposée.', ok: 'Supprimer', onConfirm: () => router.delete(`/abonnements/formules/${p.key}`, { preserveScroll: true }) });
        }
        setDialog(
            <FormDialog
                title={`Supprimer la formule ${p.name} ?`}
                body={`${p.subscribers} promoteur(s) utilisent cette formule. Choisissez la formule vers laquelle les migrer : ils seront notifiés par SMS et e-mail.`}
                icon="delete"
                tone="danger"
                okLabel="Migrer et supprimer"
                url={`/abonnements/formules/${p.key}`}
                method="delete"
                onClose={close}
                fields={[{ name: 'to', label: 'Migrer les abonnés vers', type: 'select', required: true, full: true, options: others.map((x) => ({ value: x.key, label: `${x.name} · ${x.priceLabel} · ${x.maxUsers} util.` })) }]}
            />,
        );
    };

    return (
        <>
            <Head title="Abonnements" />
            <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: -6 }}>
                <Button label="Exporter" icon="download" variant="secondary" onClick={() => (window.location.href = '/abonnements/export')} />
            </div>
            <div data-component="StatGrid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(230px,1fr))', gap: 'var(--space-4)' }}>
                {kpis.map((k) => (
                    <StatCard key={k.label} label={k.label} value={k.value} unit={k.unit} icon={k.icon} tone={k.tone as AnyTone} hint={k.hint} compare=" " highlight={k.highlight} />
                ))}
            </div>

            <div role="tablist" style={{ display: 'flex', gap: 'var(--space-1)', borderBottom: '1px solid var(--c-line)' }}>
                {(
                    [
                        ['abonnes', 'Abonnés', 'group', counts.subscriptions],
                        ['formules', 'Formules', 'workspace_premium', counts.plans],
                    ] as const
                ).map(([key, label, icon, count]) => {
                    const on = key === tab;
                    return (
                        <Link key={key} href={key === 'formules' ? '/abonnements?onglet=formules' : '/abonnements'} role="tab" aria-selected={on} className="och-hover-tab" style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)', height: 'var(--control-md)', padding: '0 var(--space-4)', borderBottom: `2.5px solid ${on ? 'var(--c-primary)' : 'transparent'}`, marginBottom: -1, fontWeight: 'var(--fw-semibold)', color: on ? 'var(--c-primary)' : 'var(--c-muted)', textDecoration: 'none' }}>
                            <Icon name={icon} size="var(--icon-sm)" />
                            {label}
                            <span style={{ minWidth: 22, padding: '1px var(--space-1_5)', borderRadius: 'var(--radius-pill)', fontSize: 'var(--fs-xs)', textAlign: 'center', background: on ? 'var(--c-primary)' : 'var(--c-neutral-bg)', color: on ? 'var(--c-surface)' : 'var(--c-neutral-fg)' }}>{count}</span>
                        </Link>
                    );
                })}
            </div>

            {tab === 'abonnes' && (
                <section style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)', background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-5)', minWidth: 0 }}>
                    <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2)', alignItems: 'center' }}>
                        <SearchInput value={filters.q ?? ''} placeholder="Rechercher un promoteur ou un responsable…" onChange={(q) => visit({ q: q || undefined, page: undefined })} />
                        <FilterSelect label="Toutes les formules" value={filters.formule ?? ''} options={plans.map((p) => ({ value: p.key, label: p.name }))} onChange={(v) => visit({ formule: v || undefined, page: undefined })} />
                        <FilterSelect label="Tous les statuts" value={filters.statut ?? ''} options={Object.keys(statuses).map((s) => ({ value: s, label: s }))} onChange={(v) => visit({ statut: v || undefined, page: undefined })} />
                        {hasFilters && <Button label="Réinitialiser" size="sm" variant="secondary" onClick={() => router.get('/abonnements')} />}
                    </div>
                    {subscriptions.data.length ? (
                        <TableFrame minWidth={980}>
                            <thead>
                                <tr style={{ background: 'var(--c-surface-3)' }}>
                                    {['Promoteur', 'Formule', 'Utilisateurs', 'Montant', 'Statut', 'Prochaine échéance', ''].map((c, i) => (
                                        <th key={i} style={thStyle}>
                                            {c}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {subscriptions.data.map((r) => {
                                    const [bg, fg] = planTone(r.plan.key);
                                    return (
                                        <tr key={r.slug} onClick={() => router.visit(`/abonnements/${r.slug}`)} className="och-hover-soft" style={{ cursor: 'pointer', background: 'var(--c-surface)' }}>
                                            <td style={tdStyle}>
                                                <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2_5)' }}>
                                                    <Avatar name={r.promoter} kind="team" />
                                                    <div>
                                                        <div style={{ fontWeight: 'var(--fw-semibold)' }}>{r.promoter}</div>
                                                        <div style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>{r.owner}</div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td style={tdStyle}>
                                                <span style={{ padding: '2px var(--space-2_5)', borderRadius: 'var(--radius-pill)', background: bg, color: fg, fontWeight: 'var(--fw-semibold)', fontSize: 'var(--fs-sm)' }}>{r.plan.name}</span>
                                            </td>
                                            <td style={{ ...tdStyle, minWidth: 120 }}>
                                                <div style={{ fontWeight: 'var(--fw-semibold)' }}>
                                                    {r.users} / {r.maxUsers}
                                                </div>
                                                <div style={{ height: 5, borderRadius: 'var(--radius-pill)', background: 'var(--c-line-soft)', overflow: 'hidden', marginTop: 4 }}>
                                                    <div style={{ height: '100%', width: `${Math.min(100, (r.users / r.maxUsers) * 100)}%`, background: r.users >= r.maxUsers ? 'var(--c-accent)' : 'var(--c-primary-500)' }} />
                                                </div>
                                            </td>
                                            <td style={tdStyle}>
                                                <div style={{ fontWeight: 'var(--fw-semibold)' }}>{r.amount}</div>
                                                <div style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>{r.period}</div>
                                            </td>
                                            <td style={tdStyle}>
                                                <StatusBadge label={r.status.label} tone={r.status.tone} />
                                            </td>
                                            <td style={tdStyle}>
                                                <div style={{ color: r.status.label === 'Impayé' ? 'var(--c-danger-fg)' : 'var(--c-ink-2)' }}>{formatDate(r.nextDue)}</div>
                                                {r.note && <div style={{ fontSize: 'var(--fs-xs)', color: 'var(--c-muted)' }}>{r.note}</div>}
                                            </td>
                                            <td style={{ ...tdStyle, textAlign: 'right' }}>
                                                <Icon name="chevron_right" color="var(--c-muted)" />
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </TableFrame>
                    ) : (
                        <StateBlock kind="empty" icon="search_off" title="Aucun abonnement ne correspond" text="Modifiez la recherche ou les filtres." />
                    )}
                    <Pagination page={subscriptions.page} lastPage={subscriptions.lastPage} from={subscriptions.from} to={subscriptions.to} total={subscriptions.total} onPage={(p) => visit({ page: String(p) })} />
                </section>
            )}

            {tab === 'formules' && (
                <>
                    <AlertList alerts={[{ tone: 'info', icon: 'info', title: 'Pourquoi un abonnement ?', text: 'Sans abonnement, seul le propriétaire utilise le compte. Avec une formule équipe, le promoteur invite ses collaborateurs par SMS ou e-mail et leur attribue un rôle : chef de chantier, technicien, comptable ou observateur. Les modifications de prix s’appliquent aux prochaines échéances.' }]} />
                    <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-3)', flexWrap: 'wrap' }}>
                        <span style={{ flex: 1, color: 'var(--c-muted)', fontSize: 'var(--fs-md)' }}>{plans.length} formules proposées aux promoteurs · la formule Solo (gratuite) ne peut pas être supprimée</span>
                        <Button label="Nouvelle formule" icon="add" variant="primary" onClick={() => planForm()} />
                    </div>
                    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,300px),1fr))', gap: 'var(--space-4)' }}>
                        {plans.map((p) => (
                            <PlanCard key={p.key} plan={p}>
                                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3,1fr)', gap: 'var(--space-2)', padding: 'var(--space-3)', borderRadius: 'var(--radius-lg)', background: 'var(--c-surface-2)' }}>
                                    {[
                                        ['Abonnés', String(p.subscribers ?? 0)],
                                        ['Revenu / mois', p.mrr != null ? `${formatNumber(p.mrr)} FCFA` : '—'],
                                        ['Utilisateurs moy.', `${p.averageUsers} / ${p.maxUsers}`],
                                    ].map(([l, v]) => (
                                        <div key={l}>
                                            <div style={{ fontSize: 'var(--fs-xs)', color: 'var(--c-muted)' }}>{l}</div>
                                            <b>{v}</b>
                                        </div>
                                    ))}
                                </div>
                                <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2)' }}>
                                    <Button label="Voir les abonnés" icon="group" size="sm" variant="secondary" onClick={() => router.get('/abonnements', { formule: p.key })} />
                                    {!p.solo && <Button label="Modifier" icon="edit" size="sm" variant="secondary" onClick={() => planForm(p)} />}
                                    {!p.solo && <Button label="Supprimer" icon="delete" size="sm" variant="secondary" onClick={() => deletePlan(p)} />}
                                </div>
                            </PlanCard>
                        ))}
                    </div>
                </>
            )}
            {dialog}
        </>
    );
}
