import { StackCell, TableFrame, TextCell, tdStyle, thStyle } from '@/components/list/Table';
import QualityNav from '@/components/quality/QualityNav';
import StatCard from '@/components/ui/StatCard';
import StateBlock from '@/components/ui/StateBlock';
import StatusBadge from '@/components/ui/StatusBadge';
import type { DisputeRow } from '@/types/disputes';
import { Head, router } from '@inertiajs/react';

type Props = {
    ratings: { published: number; reported: number; suspected: number; average: number | null } | null;
    claims: { open: number; overdue: number } | null;
    disputes: { open: number; appeals: number; unassigned: number; refundsPending: number; latest: DisputeRow[] } | null;
};

/** Vue d’ensemble d’Avis & litiges : ce qui attend une action, dans chaque file. */
export default function QualityOverview({ ratings, claims, disputes }: Props) {
    return (
        <>
            <Head title="Avis & litiges" />

            <div style={{ display: 'flex', justifyContent: 'flex-end', flexWrap: 'wrap', gap: 'var(--space-2)', marginTop: -6 }}>
                <QualityNav current="/avis-litiges" />
            </div>

            <div data-component="StatGrid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(200px,1fr))', gap: 'var(--space-4)' }}>
                {ratings && <StatCard label="Note moyenne" value={ratings.average !== null ? `${String(ratings.average).replace('.', ',')} / 5` : '—'} unit={`${ratings.published} avis`} icon="star" tone="a" />}
                {ratings && <StatCard label="Avis à modérer" value={String(ratings.reported)} icon="flag" tone="r" highlight={ratings.reported > 0} />}
                {claims && <StatCard label="Réclamations ouvertes" value={String(claims.open)} unit={claims.overdue ? `dont ${claims.overdue} en retard` : undefined} icon="support_agent" tone="b" highlight={claims.overdue > 0} />}
                {disputes && <StatCard label="Litiges en cours" value={String(disputes.open)} unit={disputes.unassigned ? `dont ${disputes.unassigned} sans responsable` : undefined} icon="gavel" tone="o" />}
                {disputes && <StatCard label="Recours" value={String(disputes.appeals)} icon="balance" tone="a" />}
                {disputes && <StatCard label="Remboursements à demander" value={String(disputes.refundsPending)} icon="undo" tone="g" />}
            </div>

            {disputes && (
                <section data-component="ListPanel" style={{ background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-5) var(--space-6)' }}>
                    <h3 style={{ margin: '0 0 var(--space-3)' }}>Litiges prioritaires</h3>
                    {disputes.latest.length > 0 ? (
                        <TableFrame minWidth={760}>
                            <thead>
                                <tr style={{ background: 'var(--c-surface-3)' }}>
                                    {['Référence', 'Dossier', 'Urgence', 'Responsable', 'Statut'].map((label) => (
                                        <th key={label} style={thStyle}>
                                            {label}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {disputes.latest.map((d) => (
                                    <tr key={d.id} onClick={() => router.visit(`/avis-litiges/litiges/${d.id}`)} className="och-hover-soft" style={{ cursor: 'pointer', background: 'var(--c-surface)' }}>
                                        <td style={tdStyle}>
                                            <TextCell>{d.reference}</TextCell>
                                        </td>
                                        <td style={tdStyle}>
                                            <StackCell main={d.category} sub={d.site?.title ?? ''} strong />
                                        </td>
                                        <td style={tdStyle}>
                                            <StatusBadge label={d.urgency.label} tone={d.urgency.tone as never} />
                                        </td>
                                        <td style={tdStyle}>
                                            <TextCell muted>{d.owner ?? 'À attribuer'}</TextCell>
                                        </td>
                                        <td style={tdStyle}>
                                            <StatusBadge label={d.status.label} tone={d.status.tone as never} />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </TableFrame>
                    ) : (
                        <StateBlock kind="nodata" icon="gavel" title="Aucun litige" text="Les litiges ouverts ici ou depuis une réclamation apparaissent dans cette liste." />
                    )}
                </section>
            )}
        </>
    );
}
