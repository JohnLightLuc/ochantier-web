import { TableFrame, TextCell, tdStyle, thStyle } from '@/components/list/Table';
import Button from '@/components/ui/Button';
import StatusBadge from '@/components/ui/StatusBadge';
import { fcfa } from '@/lib/format';
import { Head, router } from '@inertiajs/react';

export type ClosingMonth = {
    month: string;
    status: { value: string; label: string; tone: string };
    version: number;
    revenue: number | null;
    preparedBy: string | null;
    preparedAt: string | null;
    closedBy: string | null;
    closedAt: string | null;
    reopenReason: string | null;
};

const COLUMNS = ['Mois', 'Statut', 'Revenu figé', 'Préparé par', 'Clôturé par', 'Clôturé le'];

/** Clôtures mensuelles : les douze derniers mois terminés. */
export default function FinanceClosings({ months }: { months: ClosingMonth[] }) {
    return (
        <>
            <Head title="Clôtures" />

            <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: -6 }}>
                <Button label="Vue financière" icon="monitoring" variant="secondary" onClick={() => router.visit('/finance-reporting')} />
            </div>

            <section data-component="ListPanel" style={{ background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-5) var(--space-6)' }}>
                <TableFrame minWidth={760}>
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
                        {months.map((m) => (
                            <tr key={m.month} onClick={() => router.visit(`/finance-reporting/clotures/${m.month}`)} className="och-hover-soft" style={{ cursor: 'pointer', background: 'var(--c-surface)' }}>
                                <td style={tdStyle}>
                                    <TextCell strong>{m.month}</TextCell>
                                </td>
                                <td style={tdStyle}>
                                    <StatusBadge label={m.status.label} tone={m.status.tone as never} />
                                </td>
                                <td style={tdStyle}>
                                    <TextCell>{m.revenue !== null && m.status.value === 'cloturee' ? fcfa(m.revenue) : '—'}</TextCell>
                                </td>
                                <td style={tdStyle}>
                                    <TextCell muted>{m.preparedBy ?? '—'}</TextCell>
                                </td>
                                <td style={tdStyle}>
                                    <TextCell muted>{m.closedBy ?? '—'}</TextCell>
                                </td>
                                <td style={tdStyle}>
                                    <TextCell muted>{m.closedAt ?? '—'}</TextCell>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </TableFrame>
            </section>
        </>
    );
}
