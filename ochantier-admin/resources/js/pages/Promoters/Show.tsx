import { ReasonDialog } from '@/components/promoters/dialogs';
import { DocumentDrawer } from '@/components/promoters/Drawers';
import KycTab from '@/components/promoters/KycTab';
import OverviewTab from '@/components/promoters/OverviewTab';
import PromoterHeader from '@/components/promoters/PromoterHeader';
import { usePromoterActions } from '@/components/promoters/usePromoterActions';
import StatCard from '@/components/ui/StatCard';
import { useAdminUi } from '@/contexts/AdminUiContext';
import type { PromoterDocument, PromoterShowProps, PromoterTab } from '@/types/promoters';
import { Head, Link, router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';

const TABS: [PromoterTab, string][] = [
    ['', 'Vue générale'],
    ['kyc', 'Entreprise & KYC'],
];

const REJECTION_CATEGORIES = ['Document illisible', 'Document expiré', 'Informations incohérentes', 'Mauvais document', 'Autre'];

/** Fiche détaillée d’un promoteur : en-tête, indicateurs, vue générale et contrôle du dossier. */
export default function PromoterShow({ promoter, tab, kpis, tabCounts, documents, alerts, drawer }: PromoterShowProps) {
    const ui = useAdminUi();
    const actions = usePromoterActions();
    const [rejecting, setRejecting] = useState<ReactNode>(null);
    const base = `/promoteurs/${promoter.slug}`;

    const validateDocument = (d: PromoterDocument) =>
        ui.confirm({
            tone: 'success',
            icon: 'verified',
            title: 'Valider ce document ?',
            body: `« ${d.label} » sera marqué comme vérifié.`,
            ok: 'Valider le document',
            onConfirm: () => router.post(`${base}/documents/${d.id}/validation`, {}, { preserveScroll: true }),
        });

    const rejectDocument = (d: PromoterDocument) =>
        setRejecting(
            <ReasonDialog
                title="Rejeter ce document ?"
                body={`Le motif est communiqué au promoteur, qui devra déposer une nouvelle version de « ${d.label} ».`}
                icon="cancel"
                okLabel="Rejeter le document"
                reasonLabel="Motif du rejet *"
                categories={REJECTION_CATEGORIES}
                url={`${base}/documents/${d.id}/rejet`}
                onClose={() => setRejecting(null)}
            />,
        );

    const closeDrawer = () => router.visit(`${base}/${tab}`, { preserveScroll: true });
    const tabLabel = TABS.find(([key]) => key === tab)?.[1] ?? '';

    return (
        <>
            <Head title={`${promoter.name}${tab ? ` · ${tabLabel}` : ''}`} />
            <div data-component="PromoteurModule" style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-5)' }}>
                <PromoterHeader promoter={promoter} documents={documents} actions={actions} />

                <div data-component="StatGrid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(230px,1fr))', gap: 'var(--space-4)' }}>
                    {kpis.map((k) => (
                        <StatCard key={k.label} label={k.label} value={k.value} unit={k.unit} icon={k.icon} tone={k.tone} hint={k.hint} highlight={k.highlight} />
                    ))}
                </div>

                <section style={{ background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-2) var(--space-5) var(--space-5)', minWidth: 0 }}>
                    <div role="tablist" style={{ display: 'flex', gap: 'var(--space-1)', borderBottom: '1px solid var(--c-line)', marginBottom: 'var(--space-5)', overflowX: 'auto' }}>
                        {TABS.map(([key, label]) => {
                            const on = key === tab;
                            const count = key === 'kyc' ? tabCounts.kyc : 0;
                            return (
                                <Link
                                    key={key}
                                    href={key ? `${base}/${key}` : base}
                                    preserveScroll
                                    role="tab"
                                    aria-selected={on}
                                    className="och-hover-tab"
                                    style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)', height: 'var(--control-md)', padding: '0 var(--space-4)', borderBottom: `2.5px solid ${on ? 'var(--c-primary)' : 'transparent'}`, marginBottom: -1, fontWeight: 'var(--fw-semibold)', fontSize: 'var(--fs-base)', color: on ? 'var(--c-primary)' : 'var(--c-muted)', whiteSpace: 'nowrap', textDecoration: 'none' }}
                                >
                                    {label}
                                    {count > 0 && (
                                        <span style={{ minWidth: 22, padding: '1px var(--space-1_5)', borderRadius: 'var(--radius-pill)', fontSize: 'var(--fs-xs)', textAlign: 'center', background: on ? 'var(--c-primary)' : 'var(--c-warning-bg)', color: on ? 'var(--c-on-dark)' : 'var(--c-warning-fg)' }}>{count}</span>
                                    )}
                                </Link>
                            );
                        })}
                    </div>

                    {tab === '' && <OverviewTab promoter={promoter} documents={documents} alerts={alerts} />}
                    {tab === 'kyc' && <KycTab promoter={promoter} documents={documents} onValidate={validateDocument} onReject={rejectDocument} />}
                </section>
            </div>

            {drawer?.kind === 'document' && (
                <DocumentDrawer
                    promoter={promoter}
                    document={drawer.document}
                    onClose={closeDrawer}
                    onValidate={() => validateDocument(drawer.document)}
                    onReject={() => rejectDocument(drawer.document)}
                    onAskUpdate={() => actions.notify(promoter, `Mise à jour requise : ${drawer.document.label}`)}
                />
            )}

            {actions.dialog}
            {rejecting}
        </>
    );
}
