import SectionLayout, { useApply } from '@/components/attendances/SectionLayout';
import { useAttendanceActions } from '@/components/attendances/useAttendanceActions';
import Pagination from '@/components/list/Pagination';
import { PersonCell, TextCell } from '@/components/list/Table';
import StatusBadge from '@/components/ui/StatusBadge';
import { AlertList, DataTable } from '@/components/workers/blocks';
import { formatDate } from '@/lib/format';
import { tone } from '@/lib/attendanceTones';
import type { CorrectionRow, Paginated, SectionProps } from '@/types/attendances';
import { Head, router } from '@inertiajs/react';

type Props = SectionProps & { corrections: Paginated<CorrectionRow> };

/** Demandes de correction : approbation et application, ou rejet (la valeur d’origine est conservée). */
export default function AttendancesCorrections(props: Props) {
    const { corrections, filters, options, period } = props;
    const actions = useAttendanceActions(options);
    const apply = useApply(filters);
    const open = (c: CorrectionRow) => router.visit(`/pointages/${c.reference}/corrections`);

    return (
        <>
            <Head title="Corrections de pointage" />
            <SectionLayout {...props} before={<AlertList alerts={[{ tone: 'info', icon: 'lock', title: 'Pointages validés non modifiables directement', text: 'Toute modification passe par une demande : ancienne et nouvelle valeurs, motif, auteur, date, recalcul des durées et journal d’audit.' }]} />}>
                <DataTable
                    icon="edit_note"
                    title={`Demandes de correction · ${period.label}`}
                    count={`${corrections.from}–${corrections.to} sur ${corrections.total}`}
                    minWidth={1500}
                    columns={['Demande', 'Pointage', 'Ouvrier', 'Donnée', 'Ancienne valeur', 'Nouvelle valeur', 'Motif', 'Justificatif', 'Demandeur', 'Statut']}
                    empty={{ icon: 'edit_off', title: 'Aucune demande', text: 'Aucune demande de correction sur la période.' }}
                    rows={corrections.data.map((c) => ({
                        key: String(c.id),
                        onOpen: () => open(c),
                        cells: [
                            <TextCell key="d">{`${formatDate(c.requestedAt)} · ${c.requestedAt.slice(11)}`}</TextCell>,
                            <TextCell key="r" strong>
                                {c.reference}
                            </TextCell>,
                            <PersonCell key="w" name={c.worker} sub={c.site} />,
                            <TextCell key="f" strong>
                                {c.field}
                            </TextCell>,
                            <TextCell key="o">{c.oldValue}</TextCell>,
                            <TextCell key="n" strong>
                                {c.newValue}
                            </TextCell>,
                            <span key="m" style={{ fontSize: 'var(--fs-sm)' }}>
                                {c.motif + (c.comment ? ` · ${c.comment}` : '')}
                            </span>,
                            <TextCell key="j" muted={!c.proof}>
                                {c.proof ?? '—'}
                            </TextCell>,
                            <TextCell key="b">{c.requestedBy}</TextCell>,
                            <StatusBadge key="s" label={c.status} tone={tone(c.status)} />,
                        ],
                        actions: ['Soumise', 'En attente'].includes(c.status)
                            ? [
                                  { icon: 'check', label: 'Approuver et appliquer', onClick: () => actions.approveCorrection(c.reference, c) },
                                  { icon: 'close', label: 'Rejeter', onClick: () => actions.rejectCorrection(c.reference, c) },
                              ]
                            : [{ icon: 'visibility', label: 'Consulter', onClick: () => open(c) }],
                    }))}
                />
                {corrections.lastPage > 1 && <Pagination page={corrections.page} lastPage={corrections.lastPage} from={corrections.from} to={corrections.to} total={corrections.total} onPage={(p) => apply({ page: String(p) }, true)} />}
            </SectionLayout>
            {actions.dialog}
        </>
    );
}
