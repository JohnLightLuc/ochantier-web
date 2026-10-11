import SectionLayout, { useApply } from '@/components/attendances/SectionLayout';
import { useAttendanceActions } from '@/components/attendances/useAttendanceActions';
import Pagination from '@/components/list/Pagination';
import { PersonCell, TextCell } from '@/components/list/Table';
import StatusBadge from '@/components/ui/StatusBadge';
import { DataTable } from '@/components/workers/blocks';
import { formatDate } from '@/lib/format';
import { tone } from '@/lib/attendanceTones';
import type { AnomalyRow, Paginated, SectionProps } from '@/types/attendances';
import { Head, router } from '@inertiajs/react';

type Props = SectionProps & { anomalies: Paginated<AnomalyRow> };

const FLOW = ['En cours d’analyse', 'Justification demandée', 'Confirmée', 'Fausse alerte', 'Corrigée', 'Clôturée'];

/** Anomalies de pointage, des plus graves aux moins graves : elles ne suppriment jamais un pointage. */
export default function AttendancesAnomalies(props: Props) {
    const { anomalies, filters, options, period } = props;
    const actions = useAttendanceActions(options);
    const apply = useApply(filters);
    const open = (a: AnomalyRow) => router.visit(`/pointages/${a.reference}/anomalies`);

    return (
        <>
            <Head title="Anomalies de pointage" />
            <SectionLayout {...props}>
                <DataTable
                    icon="report"
                    title={`Anomalies · ${period.label}`}
                    count={`${anomalies.from}–${anomalies.to} sur ${anomalies.total}`}
                    minWidth={1400}
                    columns={['Détection', 'Pointage', 'Ouvrier', 'Chantier', 'Type', 'Niveau', 'Description', 'Statut']}
                    empty={{ icon: 'verified', title: 'Aucune anomalie', text: 'Aucune anomalie ne correspond aux filtres.' }}
                    rows={anomalies.data.map((a) => ({
                        key: String(a.id),
                        onOpen: () => open(a),
                        cells: [
                            <TextCell key="d">{formatDate(a.detectedOn)}</TextCell>,
                            <TextCell key="r" strong>
                                {a.reference}
                            </TextCell>,
                            <PersonCell key="w" name={a.worker.name} sub={a.worker.code} />,
                            <TextCell key="s">{a.site}</TextCell>,
                            <TextCell key="t" strong>
                                {a.type}
                            </TextCell>,
                            <StatusBadge key="l" label={a.level} tone={tone(a.level)} />,
                            <span key="n" style={{ fontSize: 'var(--fs-sm)' }}>
                                {a.note + (a.decision ? ` · ${a.decision}` : '')}
                            </span>,
                            <StatusBadge key="st" label={a.status} tone={tone(a.status)} />,
                        ],
                        actions: [{ icon: 'visibility', label: 'Consulter', onClick: () => open(a) }],
                        menu: FLOW.filter((s) => s !== a.status).map((s) => ({ icon: 'label', label: s, onClick: () => actions.setAnomaly(a.reference, a, s) })),
                    }))}
                />
                {anomalies.lastPage > 1 && <Pagination page={anomalies.page} lastPage={anomalies.lastPage} from={anomalies.from} to={anomalies.to} total={anomalies.total} onPage={(p) => apply({ page: String(p) }, true)} />}
            </SectionLayout>
            {actions.dialog}
        </>
    );
}
