import { FilterSelect, SearchInput } from '@/components/list/ListToolbar';
import Pagination from '@/components/list/Pagination';
import { StackCell, TableFrame, TextCell, tdStyle, thStyle } from '@/components/list/Table';
import FormDialog from '@/components/overlays/FormDialog';
import PlanCard from '@/components/subscriptions/PlanCard';
import Button from '@/components/ui/Button';
import type { Option } from '@/components/ui/Field';
import Icon from '@/components/ui/Icon';
import StatCard from '@/components/ui/StatCard';
import StateBlock from '@/components/ui/StateBlock';
import StatusBadge from '@/components/ui/StatusBadge';
import { useAdminUi } from '@/contexts/AdminUiContext';
import { fcfa, formatDate } from '@/lib/format';
import type { Plan, SubscriptionCan, SubscriptionRow } from '@/types/subscriptions';
import { Head, Link, router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';

type Filters = { q?: string; formule?: string; statut?: string; page?: string };

type Props = {
    tab: 'abonnes' | 'formules';
    subscriptions: { data: SubscriptionRow[]; currentPage: number; lastPage: number; total: number; from: number; to: number };
    filters: Filters;
    filterOptions: { formule: Option[]; statut: Option[] };
    kpis: { paying: number; promoters: number; revenue: number; late: number; suspended: number };
    plans: Plan[];
    can: SubscriptionCan;
};

const COLUMNS = ['Promoteur', 'Formule', 'Utilisateurs', 'Montant', 'Statut', 'Prochaine échéance'];

/** Abonnements : abonnés (recherche, filtres par formule et statut) et catalogue des formules. */
export default function SubscriptionsIndex({ tab, subscriptions, filters, filterOptions, kpis, plans, can }: Props) {
    const ui = useAdminUi();
    const [dialog, setDialog] = useState<ReactNode>(null);
    const close = () => setDialog(null);
    const apply = (changes: Filters) => {
        const next: Filters = { ...filters, page: undefined, ...changes };
        router.get('/abonnements', Object.fromEntries(Object.entries(next).filter(([, v]) => v)), { preserveState: true, preserveScroll: true, replace: true });
    };
    const exportUrl = `/abonnements/export?${new URLSearchParams(Object.entries(filters).filter(([k, v]) => v && k !== 'page') as [string, string][]).toString()}`;
    const live = plans.filter((p) => p.active).length;

    const planForm = (p?: Plan) =>
        setDialog(
            <FormDialog
                title={p ? `Modifier la formule ${p.name}` : 'Nouvelle formule'}
                body={p ? 'Un nouveau prix s’applique à la prochaine facture, et les abonnés en sont notifiés.' : 'La formule est proposée tout de suite aux promoteurs, dans l’application.'}
                icon={p ? 'edit' : 'add'}
                okLabel={p ? 'Enregistrer' : 'Créer la formule'}
                url={p ? `/abonnements/formules/${p.id}` : '/abonnements/formules'}
                method={p ? 'put' : 'post'}
                onClose={close}
                initial={p ? { name: p.name, price_amount: String(p.price), max_users: String(p.maxUsers), is_featured: p.popular ? '1' : '0', features: p.features.join('\n') } : { is_featured: '0' }}
                fields={[
                    { name: 'name', label: 'Nom de la formule', required: true },
                    { name: 'price_amount', label: 'Prix mensuel (FCFA)', type: 'number', required: true },
                    { name: 'max_users', label: 'Utilisateurs maximum', type: 'number', required: true },
                    { name: 'is_featured', label: 'Mise en avant', type: 'select', required: true, options: [{ value: '0', label: 'Non' }, { value: '1', label: 'Oui · « Le plus choisi »' }] },
                    { name: 'features', label: 'Avantages', type: 'textarea', required: true, hint: 'Un avantage par ligne.' },
                ]}
            />,
        );

    const retire = (p: Plan) =>
        ui.confirm({
            tone: 'warning',
            icon: 'visibility_off',
            title: `Retirer la formule ${p.name} ?`,
            body: `Elle ne sera plus proposée aux promoteurs. ${p.subscribers ? `Ses ${p.subscribers} abonné(s) la gardent et continuent d’être renouvelés.` : 'Aucun promoteur n’y est abonné.'}`,
            ok: 'Retirer',
            onConfirm: () => router.post(`/abonnements/formules/${p.id}/retrait`, {}, { preserveScroll: true }),
        });

    return (
        <>
            <Head title="Abonnements" />

            {can.export && (
                <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: -6 }}>
                    <Button label="Exporter" icon="download" variant="secondary" onClick={() => (window.location.href = exportUrl)} />
                </div>
            )}

            <div data-component="StatGrid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(230px,1fr))', gap: 'var(--space-4)' }}>
                <StatCard label="Abonnés payants" value={String(kpis.paying)} unit={`sur ${kpis.promoters} promoteurs`} icon="workspace_premium" tone="g" />
                <StatCard label="Revenu mensuel récurrent" value={fcfa(kpis.revenue)} icon="payments" tone="b" />
                <StatCard label="En retard" value={String(kpis.late)} icon="error" tone="r" highlight={kpis.late > 0} />
                <StatCard label="Suspendus" value={String(kpis.suspended)} icon="pause_circle" tone="a" />
            </div>

            <div role="tablist" style={{ display: 'flex', gap: 'var(--space-1)', borderBottom: '1px solid var(--c-line)' }}>
                {(
                    [
                        ['abonnes', 'Abonnés', 'group', subscriptions.total],
                        ['formules', 'Formules', 'workspace_premium', live],
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
                <section data-component="ListPanel" style={{ background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-5) var(--space-6)' }}>
                    <div style={{ display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: 'var(--space-2_5)', marginBottom: 'var(--space-3)' }}>
                        <SearchInput value={filters.q ?? ''} placeholder="Rechercher par référence, promoteur ou responsable" onChange={(q) => apply({ q })} />
                        <FilterSelect label="Formule" value={filters.formule ?? ''} options={filterOptions.formule} onChange={(formule) => apply({ formule })} />
                        <FilterSelect label="Statut" value={filters.statut ?? ''} options={filterOptions.statut} onChange={(statut) => apply({ statut })} />
                    </div>

                    <TableFrame minWidth={900}>
                        <thead>
                            <tr style={{ background: 'var(--c-surface-3)' }}>
                                {COLUMNS.map((label) => (
                                    <th key={label} style={thStyle}>
                                        {label}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {subscriptions.data.map((s) => (
                                <tr key={s.id} onClick={() => router.visit(`/abonnements/${s.id}`)} className="och-hover-soft" style={{ cursor: 'pointer', background: 'var(--c-surface)' }}>
                                    <td style={tdStyle}>
                                        <StackCell main={s.promoter.name} sub={s.reference} strong />
                                    </td>
                                    <td style={tdStyle}>
                                        <StackCell main={s.plan.name} sub={s.pending ? `Passage à ${s.pending.name} prévu` : ''} />
                                    </td>
                                    <td style={tdStyle}>
                                        <TextCell>{`${s.seats} / ${s.plan.maxUsers}`}</TextCell>
                                    </td>
                                    <td style={tdStyle}>
                                        <TextCell strong>{fcfa(s.plan.price)}</TextCell>
                                    </td>
                                    <td style={tdStyle}>
                                        <StatusBadge label={s.status.label} tone={s.status.tone as never} />
                                    </td>
                                    <td style={tdStyle}>
                                        <TextCell muted>{s.nextDue ? formatDate(s.nextDue) : s.periodEnd && s.status.value === 'active' ? `Fin le ${formatDate(s.periodEnd)}` : '—'}</TextCell>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </TableFrame>

                    {subscriptions.data.length === 0 && (
                        <div style={{ paddingTop: 'var(--space-4)' }}>
                            <StateBlock kind="nodata" icon="workspace_premium" title="Aucun abonnement" text="Un compte Solo n’a pas d’abonnement : il apparaît ici dès qu’il souscrit une formule." />
                        </div>
                    )}

                    <Pagination page={subscriptions.currentPage} lastPage={subscriptions.lastPage} from={subscriptions.from} to={subscriptions.to} total={subscriptions.total} onPage={(page) => apply({ page: String(page) })} />
                </section>
            )}

            {tab === 'formules' && (
                <>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-3)', flexWrap: 'wrap' }}>
                        <span style={{ flex: 1, color: 'var(--c-muted)', fontSize: 'var(--fs-md)' }}>La formule Solo, gratuite, ne peut pas être modifiée.</span>
                        {can.update && <Button label="Nouvelle formule" icon="add" variant="primary" onClick={() => planForm()} />}
                    </div>
                    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,300px),1fr))', gap: 'var(--space-4)' }}>
                        {plans.map((p) => (
                            <PlanCard key={p.id} plan={p}>
                                {!p.free && (
                                    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(2,1fr)', gap: 'var(--space-2)', padding: 'var(--space-3)', borderRadius: 'var(--radius-lg)', background: 'var(--c-surface-2)' }}>
                                        {[
                                            ['Abonnés', String(p.subscribers)],
                                            ['Revenu / mois', fcfa(p.revenue)],
                                        ].map(([l, v]) => (
                                            <div key={l}>
                                                <div style={{ fontSize: 'var(--fs-xs)', color: 'var(--c-muted)' }}>{l}</div>
                                                <b>{v}</b>
                                            </div>
                                        ))}
                                    </div>
                                )}
                                {!p.free && (
                                    <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2)' }}>
                                        <Button label="Voir les abonnés" icon="group" size="sm" variant="secondary" onClick={() => router.get('/abonnements', { formule: p.code })} />
                                        {can.update && <Button label="Modifier" icon="edit" size="sm" variant="secondary" onClick={() => planForm(p)} />}
                                        {can.update && p.active && <Button label="Retirer" icon="visibility_off" size="sm" variant="secondary" onClick={() => retire(p)} />}
                                        {can.update && !p.active && <Button label="Proposer à nouveau" icon="visibility" size="sm" variant="secondary" onClick={() => router.post(`/abonnements/formules/${p.id}/remise`, {}, { preserveScroll: true })} />}
                                    </div>
                                )}
                            </PlanCard>
                        ))}
                    </div>
                </>
            )}
            {dialog}
        </>
    );
}
