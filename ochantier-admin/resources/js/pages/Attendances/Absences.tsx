import SectionLayout, { queryOf, useApply } from '@/components/attendances/SectionLayout';
import { useAttendanceActions } from '@/components/attendances/useAttendanceActions';
import Pagination from '@/components/list/Pagination';
import { PersonCell, StackCell, TextCell } from '@/components/list/Table';
import StatusBadge from '@/components/ui/StatusBadge';
import { AlertList, DataTable } from '@/components/workers/blocks';
import { formatDate } from '@/lib/format';
import { tone } from '@/lib/attendanceTones';
import type { AbsenceRow, Paginated, SectionProps } from '@/types/attendances';
import { Head, router } from '@inertiajs/react';

type Props = SectionProps & { absences: Paginated<AbsenceRow> };

/** Absences déclarées par les promoteurs : validation, justification et régularisation. */
export default function AttendancesAbsences(props: Props) {
    const { absences, filters, options, period } = props;
    const actions = useAttendanceActions(options);
    const apply = useApply(filters);
    const open = (r: AbsenceRow) => router.visit(`/pointages/${r.reference}`);

    return (
        <>
            <Head title="Absences" />
            <SectionLayout
                {...props}
                toolbar={[{ label: 'Exporter', icon: 'download', onClick: () => (window.location.href = `/pointages/export/absences?${queryOf(filters)}`) }]}
                before={<AlertList alerts={[{ tone: 'info', icon: 'info', title: 'Absence définitive après validation', text: 'Une absence déclarée par le promoteur reste en attente tant qu’elle n’est pas validée. Un ouvrier absent ne peut pas être pointé présent le même jour.' }]} />}
            >
                <DataTable
                    icon="person_off"
                    title={`Absences · ${period.label}`}
                    count={`${absences.from}–${absences.to} sur ${absences.total}`}
                    minWidth={1300}
                    columns={['Date', 'Ouvrier', 'Chantier', 'Motif', 'Commentaire', 'Justificatif', 'Déclarée par', 'Statut']}
                    empty={{ icon: 'event_available', title: 'Aucune absence', text: 'Aucune absence sur la période.' }}
                    rows={absences.data.map((r) => ({
                        key: r.reference,
                        onOpen: () => open(r),
                        cells: [
                            <TextCell key="d" strong>
                                {formatDate(r.date)}
                            </TextCell>,
                            <PersonCell key="w" name={r.worker.name} sub={`${r.worker.code} · ${r.worker.trade}`} />,
                            <StackCell key="s" main={r.site.name} sub={r.site.promoter} />,
                            <TextCell key="m" strong>
                                {r.absence.motif}
                            </TextCell>,
                            <TextCell key="c" muted={!r.absence.comment}>
                                {r.absence.comment ?? '—'}
                            </TextCell>,
                            <TextCell key="j" muted={!r.absence.proof}>
                                {r.absence.proof ?? 'Aucun'}
                            </TextCell>,
                            <TextCell key="b">{`${r.absence.by} · ${r.absence.at.slice(11)}`}</TextCell>,
                            <StatusBadge key="st" label={r.absence.status} tone={tone(r.absence.status, 'a')} />,
                        ],
                        actions: [{ icon: 'visibility', label: 'Consulter', onClick: () => open(r) }],
                        menu: [
                            ...r.decision.options.filter((o) => o !== r.absence.status).map((o) => ({ icon: 'rule', label: o, onClick: () => actions.decide(r.reference, r.worker.name, r.decision, o) })),
                            { icon: 'notifications_active', label: 'Relancer le promoteur', onClick: () => actions.remind([r.reference]) },
                        ],
                    }))}
                />
                {absences.lastPage > 1 && <Pagination page={absences.page} lastPage={absences.lastPage} from={absences.from} to={absences.to} total={absences.total} onPage={(p) => apply({ page: String(p) }, true)} />}
            </SectionLayout>
            {actions.dialog}
        </>
    );
}
