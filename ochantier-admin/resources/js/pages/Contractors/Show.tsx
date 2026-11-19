import ContractorHeader from '@/components/contractors/ContractorHeader';
import DocumentViewer from '@/components/contractors/DocumentViewer';
import { DocumentsTab, HistoryTab, OverviewTab, ResponsesTab } from '@/components/contractors/tabs';
import { useContractorActions } from '@/components/contractors/useContractorActions';
import StatCard from '@/components/ui/StatCard';
import type { ContractorDocument, ContractorShowProps, ContractorTab } from '@/types/contractors';
import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';

const TABS: [ContractorTab, string][] = [
    ['', 'Vue d’ensemble'],
    ['pieces', 'Pièces justificatives'],
    ['reponses', 'Réponses aux opportunités'],
    ['historique', 'Historique'],
];

/** Fiche d’un entrepreneur ou technicien : en-tête, indicateurs, vue d’ensemble, pièces KYB/KYC, réponses, historique. */
export default function ContractorShow(props: ContractorShowProps) {
    const { contractor, tab, kpis, tabCounts, documents, can } = props;
    const actions = useContractorActions();
    const viewable = documents.filter((d) => d.hasFile);
    const [viewing, setViewing] = useState<string | null>(null);
    const viewIndex = viewing ? viewable.findIndex((d) => d.id === viewing) : -1;
    const onView = (d: ContractorDocument) => setViewing(d.id);
    const tabProps = { ...props, actions, onView };
    const base = `/entrepreneurs/${contractor.id}`;
    const label = TABS.find(([key]) => key === tab)?.[1];

    return (
        <>
            <Head title={`${contractor.name}${tab ? ` · ${label}` : ''}`} />
            <div data-component="ContractorModule" style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-5)' }}>
                <ContractorHeader contractor={contractor} documents={documents} can={can} actions={actions} />

                <div data-component="StatGrid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(230px,1fr))', gap: 'var(--space-4)' }}>
                    {kpis.map((k) => (
                        <StatCard key={k.label} label={k.label} value={k.value} unit={k.unit ?? undefined} icon={k.icon} tone={k.tone} hint={k.hint} />
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

                    {tab === '' && <OverviewTab {...tabProps} />}
                    {tab === 'pieces' && <DocumentsTab {...tabProps} />}
                    {tab === 'reponses' && <ResponsesTab {...tabProps} />}
                    {tab === 'historique' && <HistoryTab {...tabProps} />}
                </section>
            </div>

            {viewIndex >= 0 && (
                <DocumentViewer
                    contractorName={contractor.name}
                    documents={viewable}
                    index={viewIndex}
                    canDecide={can.documents && contractor.can.reviewDocuments}
                    onIndex={(i) => setViewing(viewable[i]?.id ?? null)}
                    onConfirm={(d) => actions.confirmDocument(contractor, d)}
                    onAsk={(d) => actions.askDocument(contractor, d)}
                    onReject={(d) => actions.rejectDocument(contractor, d)}
                    onClose={() => setViewing(null)}
                />
            )}
            {actions.dialog}
        </>
    );
}
