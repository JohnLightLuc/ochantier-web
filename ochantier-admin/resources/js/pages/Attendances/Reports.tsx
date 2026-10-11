import SectionLayout, { queryOf } from '@/components/attendances/SectionLayout';
import { Bars } from '@/components/workers/blocks';
import type { ChartData, SectionProps } from '@/types/attendances';
import { Head } from '@inertiajs/react';

type Props = SectionProps & { charts: ChartData[] };

/** Rapports de pointage : présence, heures travaillées, absences et anomalies sur la période. */
export default function AttendancesReports(props: Props) {
    const q = queryOf(props.filters);

    return (
        <>
            <Head title="Rapports de pointage" />
            <SectionLayout
                {...props}
                search={false}
                toolbar={[
                    { label: 'Rapport de présence (CSV)', icon: 'download', variant: 'primary', onClick: () => (window.location.href = `/pointages/export/rapport?${q}`) },
                    { label: 'Heures par ouvrier (CSV)', icon: 'download', onClick: () => (window.location.href = `/pointages/export/heures-ouvriers?${q}`) },
                ]}
            >
                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,420px),1fr))', gap: 'var(--space-4)' }}>
                    {props.charts.map((c) => (
                        <Bars key={c.title} title={c.title} sub={c.sub} unit={c.unit} data={c.bars} color={c.accent ? 'var(--c-accent-600)' : undefined} />
                    ))}
                </div>
            </SectionLayout>
        </>
    );
}
