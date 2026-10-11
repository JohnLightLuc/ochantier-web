import { AttendanceTable, SectionNav, rowMenu } from '@/components/attendances/blocks';
import { useAttendanceActions } from '@/components/attendances/useAttendanceActions';
import { TextCell } from '@/components/list/Table';
import DetailHeader from '@/components/matching/DetailHeader';
import { DataTable, TileGrid } from '@/components/workers/blocks';
import { formatDate } from '@/lib/format';
import type { WorkerEntityProps } from '@/types/attendances';
import { Head, router } from '@inertiajs/react';

/** Pointages d’un ouvrier : historique des journées et affectations pointées. */
export default function AttendanceWorker({ sections, worker, kpis, tab, tabs, tiles, rows, assignments, options }: WorkerEntityProps) {
    const actions = useAttendanceActions(options);
    const base = `/pointages/ouvriers/${worker.slug}`;

    return (
        <>
            <Head title={`Pointages · ${worker.name}`} />
            <SectionNav sections={sections} />
            <DetailHeader
                icon="engineering"
                title={worker.name}
                badges={[worker.status, { label: worker.availability, tone: 'b' }]}
                sub={`${worker.trade} · ${worker.commune ?? '—'}`}
                code={`${worker.code} · ${worker.phone ?? ''}`}
                meta={[]}
                actions={[
                    { label: 'Fiche ouvrier', icon: 'open_in_new', onClick: () => router.visit(`/ouvriers/${worker.slug}`) },
                    { label: 'Exporter', icon: 'download', onClick: () => (window.location.href = `/pointages/export?refs=${rows.map((r) => r.reference).join(',')}`) },
                ]}
                menu={[{ icon: 'calendar_month', label: 'Calendrier des présences', onClick: () => router.visit('/pointages/calendrier') }]}
                banner={null}
                kpis={kpis}
                tabs={tabs}
                tab={tab}
                base={base}
            >
                {tab === '' && (
                    <>
                        <TileGrid tiles={tiles} />
                        <AttendanceTable icon="history" title="Historique des pointages" count={`${rows.length} journée(s)`} rows={rows} hide={['worker']} menu={(r) => rowMenu(r, actions)} empty={{ icon: 'event_busy', title: 'Aucun pointage', text: 'Aucune journée enregistrée.' }} minWidth={1500} />
                    </>
                )}
                {tab === 'affectations' && (
                    <DataTable
                        icon="assignment_ind"
                        title="Affectations pointées"
                        count={`${assignments.length} affectation(s)`}
                        minWidth={900}
                        columns={['Affectation', 'Chantier', 'Promoteur', 'Horaires', 'Première journée', 'Dernière journée', 'Journées']}
                        empty={{ icon: 'assignment_ind', title: 'Aucune affectation', text: '' }}
                        rows={assignments.map((a) => ({
                            key: a.reference,
                            onOpen: () => router.visit(`/pointages/chantiers/${a.siteSlug}`),
                            cells: [
                                <TextCell key="r" strong>
                                    {a.reference}
                                </TextCell>,
                                <TextCell key="s">{a.site}</TextCell>,
                                <TextCell key="p">{a.promoter}</TextCell>,
                                <TextCell key="h">{a.hours}</TextCell>,
                                <TextCell key="f">{formatDate(a.first)}</TextCell>,
                                <TextCell key="l">{formatDate(a.last)}</TextCell>,
                                <TextCell key="d">{a.days}</TextCell>,
                            ],
                            actions: [],
                        }))}
                    />
                )}
            </DetailHeader>
            {actions.dialog}
        </>
    );
}
