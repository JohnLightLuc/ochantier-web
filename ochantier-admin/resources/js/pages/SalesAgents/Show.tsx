import { UploadDocumentDialog } from '@/components/promoters/dialogs';
import SalesRelationTabs from '@/components/sales/RelationTabs';
import SalesAgentHeader from '@/components/sales/SalesAgentHeader';
import { AccessTab, OverviewTab, ProfileTab } from '@/components/sales/tabs';
import { useSalesActions } from '@/components/sales/useSalesActions';
import StatCard from '@/components/ui/StatCard';
import type { SalesShowProps, SalesTab } from '@/types/sales';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

const TABS: [SalesTab, string][] = [
    ['', 'Vue d’ensemble'],
    ['profil-documents', 'Profil et documents'],
    ['ouvriers', 'Ouvriers suivis'],
    ['activite', 'Activité terrain'],
    ['zones', 'Zones'],
    ['objectifs', 'Objectifs'],
    ['acces-securite', 'Accès et sécurité'],
];

/** Fiche du commercial : en-tête, indicateurs et onglets. */
export default function SalesAgentShow({ agent, tab, kpis, banner, can, documents, tabCounts, access, documentTypes, relations, fieldOptions }: SalesShowProps) {
    const actions = useSalesActions();
    const [uploading, setUploading] = useState(false);
    const openUpload = () => {
        setUploading(true);
        if (!documentTypes) router.reload({ only: ['documentTypes'] });
    };
    const tabProps = { agent, can, documents, access, actions, onUpload: can.documents && agent.can.edit ? openUpload : undefined };
    const base = `/commerciaux/${agent.id}`;
    const label = TABS.find(([key]) => key === tab)?.[1];

    return (
        <>
            <Head title={`${agent.name}${tab ? ` · ${label}` : ''}`} />
            <div data-component="SalesAgentModule" style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-5)' }}>
                <SalesAgentHeader agent={agent} banner={banner} can={can} actions={actions} />

                <div data-component="StatGrid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(230px,1fr))', gap: 'var(--space-4)' }}>
                    {kpis.map((k) => (
                        <StatCard key={k.label} label={k.label} value={k.value} unit={k.unit} icon={k.icon} tone={k.tone as never} hint={k.hint} />
                    ))}
                </div>

                <section style={{ background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-2) var(--space-5) var(--space-5)', minWidth: 0 }}>
                    <div role="tablist" style={{ display: 'flex', gap: 'var(--space-1)', borderBottom: '1px solid var(--c-line)', marginBottom: 'var(--space-5)', overflowX: 'auto' }}>
                        {TABS.map(([key, text]) => {
                            const on = key === tab;
                            const count = tabCounts[key];
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
                                    {text}
                                    {!!count && (
                                        <span style={{ minWidth: 22, padding: '1px var(--space-1_5)', borderRadius: 'var(--radius-pill)', fontSize: 'var(--fs-xs)', textAlign: 'center', background: on ? 'var(--c-primary)' : 'var(--c-warning-bg)', color: on ? 'var(--c-on-dark)' : 'var(--c-warning-fg)' }}>{count}</span>
                                    )}
                                </Link>
                            );
                        })}
                    </div>

                    {tab === '' && <OverviewTab agent={agent} />}
                    {tab === 'profil-documents' && <ProfileTab {...tabProps} />}
                    {relations && <SalesRelationTabs agent={agent} relations={relations} options={fieldOptions} can={can} actions={actions} />}
                    {tab === 'acces-securite' && <AccessTab {...tabProps} />}
                </section>
            </div>

            {actions.dialog}
            {uploading && <UploadDocumentDialog promoterName={agent.name} types={documentTypes} url={`${base}/documents`} onClose={() => setUploading(false)} />}
        </>
    );
}
