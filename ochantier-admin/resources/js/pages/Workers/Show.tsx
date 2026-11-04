import WorkerRelationTabs from '@/components/workers/RelationTabs';
import { IdentityTab, OverviewTab, SkillsTab } from '@/components/workers/tabs';
import { useWorkerActions } from '@/components/workers/useWorkerActions';
import WorkerHeader from '@/components/workers/WorkerHeader';
import { UploadDocumentDialog } from '@/components/promoters/dialogs';
import StatCard from '@/components/ui/StatCard';
import type { WorkerShowProps, WorkerTab } from '@/types/workers';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

const TABS: [WorkerTab, string][] = [
    ['', 'Vue d’ensemble'],
    ['identite-documents', 'Identité et documents'],
    ['metiers-competences', 'Métiers et compétences'],
    ['disponibilites', 'Disponibilités'],
    ['affectations', 'Affectations et missions'],
    ['pointages', 'Pointages'],
    ['paiements', 'Paiements et gains'],
    ['avis', 'Avis et notes'],
    ['remplacements', 'Indisponibilités et remplacements'],
    ['historique', 'Historique'],
];

/** Fiche de l’ouvrier : en-tête, indicateurs, vue d’ensemble, pièces et contrôle du dossier, métiers, onglets en lecture seule. */
export default function WorkerShow(props: WorkerShowProps) {
    const { worker, tab, kpis, tabCounts } = props;
    const actions = useWorkerActions();
    const [uploading, setUploading] = useState(false);
    const openUpload = () => {
        setUploading(true);
        if (!props.documentTypes) router.reload({ only: ['documentTypes'] });
    };
    const tabProps = { ...props, actions, onUpload: props.can.documents && worker.can.edit ? openUpload : undefined };
    const base = `/ouvriers/${worker.slug}`;
    const label = TABS.find(([key]) => key === tab)?.[1];

    return (
        <>
            <Head title={`${worker.name}${tab ? ` · ${label}` : ''}`} />
            <div data-component="WorkerModule" style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-5)' }}>
                <WorkerHeader worker={worker} documents={props.documents} correctionFields={props.correctionFields} can={props.can} actions={actions} />

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
                    {tab === 'identite-documents' && <IdentityTab {...tabProps} />}
                    {tab === 'metiers-competences' && <SkillsTab {...tabProps} />}
                    {props.relations && <WorkerRelationTabs relations={props.relations} />}
                </section>
            </div>

            {actions.dialog}
            {uploading && <UploadDocumentDialog promoterName={worker.name} types={props.documentTypes} url={`${base}/documents`} onClose={() => setUploading(false)} />}
        </>
    );
}
