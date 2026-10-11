import { PresenceGrid } from '@/components/attendances/blocks';
import SectionLayout from '@/components/attendances/SectionLayout';
import type { GridRow, SectionProps } from '@/types/attendances';
import { Head } from '@inertiajs/react';

type Props = SectionProps & { days: string[]; grid: GridRow[] };

/** Calendrier des présences : un ouvrier par ligne, un jour par colonne. */
export default function AttendancesCalendar(props: Props) {
    return (
        <>
            <Head title="Calendrier des présences" />
            <SectionLayout {...props}>
                <PresenceGrid title={`Calendrier des présences · ${props.period.label}`} days={props.days} grid={props.grid} legend="P = présent · P… = en cours · A = absent · ? = incomplet · R = retard · · = à venir" />
            </SectionLayout>
        </>
    );
}
