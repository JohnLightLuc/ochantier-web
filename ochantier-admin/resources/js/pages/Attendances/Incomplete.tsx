import { AttendanceTable, rowMenu } from '@/components/attendances/blocks';
import SectionLayout, { queryOf, useApply } from '@/components/attendances/SectionLayout';
import { useAttendanceActions } from '@/components/attendances/useAttendanceActions';
import type { AttendanceRow, Paginated, SectionProps } from '@/types/attendances';
import { Head, router } from '@inertiajs/react';

type Props = SectionProps & { refs: string[]; attendances: Paginated<AttendanceRow> };

/** Pointages incomplets : arrivée ou départ non enregistré, non validé, preuve ou localisation manquante, journée non clôturée. */
export default function AttendancesIncomplete(props: Props) {
    const { refs, attendances, filters, options, period } = props;
    const actions = useAttendanceActions(options);
    const apply = useApply(filters);

    return (
        <>
            <Head title="Pointages incomplets" />
            <SectionLayout
                {...props}
                toolbar={[
                    { label: `Relancer tous les promoteurs (${refs.length})`, icon: 'notifications_active', variant: 'primary', onClick: () => refs.length && actions.remind(refs) },
                    { label: 'Exporter', icon: 'download', onClick: () => (window.location.href = `/pointages/export/incomplets?${queryOf(filters)}`) },
                ]}
            >
                <AttendanceTable
                    icon="pending_actions"
                    title={`Pointages incomplets · ${period.label}`}
                    count={`${attendances.from}–${attendances.to} sur ${attendances.total}`}
                    rows={attendances.data}
                    page={attendances}
                    onPage={(p) => apply({ page: String(p) }, true)}
                    lastColumn={{ label: 'Motifs', value: (r: AttendanceRow) => r.incomplete.join(' · ') }}
                    actions={(r) => [
                        { icon: 'visibility', label: 'Consulter', onClick: () => router.visit(`/pointages/${r.reference}`) },
                        { icon: 'notifications_active', label: 'Relancer le promoteur', onClick: () => actions.remind([r.reference]) },
                    ]}
                    menu={(r) => rowMenu(r, actions, [{ icon: 'gavel', label: 'Régulariser avec autorisation', onClick: () => actions.regularize(r.reference) }])}
                    empty={{ icon: 'task_alt', title: 'Aucun pointage incomplet', text: 'Tous les pointages de la période sont complets.' }}
                />
            </SectionLayout>
            {actions.dialog}
        </>
    );
}
