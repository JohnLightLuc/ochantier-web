import { AttendanceTable, PresenceGrid, SectionNav, rowMenu } from '@/components/attendances/blocks';
import { useAttendanceActions } from '@/components/attendances/useAttendanceActions';
import { FilterSelect } from '@/components/list/ListToolbar';
import { ProgressCell, TextCell } from '@/components/list/Table';
import DetailHeader from '@/components/matching/DetailHeader';
import { AlertList, DataTable, TileGrid } from '@/components/workers/blocks';
import { formatDate } from '@/lib/format';
import type { GridRow, SiteEntityProps } from '@/types/attendances';
import { Head, router } from '@inertiajs/react';

type MonthDay = { date: string; expected: number; present: number; absent: number; late: number; incomplete: number; anomalies: number; net: string; closure: number };

/** Pointages d’un chantier : liste journalière, vue hebdomadaire et vue mensuelle. */
export default function AttendanceSite({ sections, site, kpis, trades, trade, tab, tabs, data, options }: SiteEntityProps) {
    const actions = useAttendanceActions(options);
    const base = `/pointages/chantiers/${site.slug}`;
    const visit = (params: Record<string, string | undefined>) => router.get(tab ? `${base}/${tab}` : base, Object.fromEntries(Object.entries({ metier: trade || undefined, date: data.day, ...params }).filter(([, v]) => v)) as Record<string, string>, { preserveScroll: true, preserveState: true });
    const rows = data.rows ?? [];

    return (
        <>
            <Head title={`Pointages · ${site.name}`} />
            <SectionNav sections={sections} />
            <DetailHeader
                icon="construction"
                title={site.name}
                badges={[site.status, { label: site.promoter, tone: 'n' }]}
                sub={`${site.commune} · ${site.city} · ${site.hours}`}
                code={`${site.code} · ${site.manager ?? ''}`}
                meta={[]}
                actions={[
                    { label: 'Fiche chantier', icon: 'open_in_new', onClick: () => router.visit(`/chantiers/${site.slug}`) },
                    { label: 'Exporter', icon: 'download', onClick: () => (window.location.href = `/pointages/export?periode=mois&chantier=${encodeURIComponent(site.name)}`) },
                ]}
                menu={[{ icon: 'smartphone', label: 'Application Promoteur', onClick: () => router.visit(`/pointages/application?chantier=${site.slug}`) }]}
                banner={null}
                kpis={kpis}
                tabs={tabs}
                tab={tab}
                base={base}
            >
                <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2)' }}>
                    <FilterSelect label="Tous les métiers" value={trade} options={trades.map((t) => ({ value: t, label: t }))} onChange={(v) => visit({ metier: v || undefined })} />
                    {tab === '' && <FilterSelect label="Date" value={data.day ?? ''} options={(data.dates ?? []).map((d) => ({ value: d, label: formatDate(d) }))} onChange={(v) => visit({ date: v || undefined })} />}
                </div>

                {tab === '' && (
                    <>
                        {site.blocked && <AlertList alerts={[{ tone: 'danger', icon: 'block', title: `Chantier ${site.status.label.toLowerCase()}`, text: 'Aucun nouveau pointage n’est possible.' }]} />}
                        {data.tiles && <TileGrid tiles={data.tiles} />}
                        <AttendanceTable
                            icon="today"
                            title={`Liste journalière · ${formatDate(data.day)}`}
                            count={`${rows.length} ouvrier(s)`}
                            rows={rows}
                            hide={['site']}
                            menu={(r) => rowMenu(r, actions)}
                            empty={{ icon: 'event_busy', title: 'Aucun pointage', text: 'Aucun ouvrier attendu ce jour.' }}
                            minWidth={1500}
                        />
                    </>
                )}

                {tab === 'semaine' && <PresenceGrid title="Vue hebdomadaire" days={(data.days as string[]) ?? []} grid={(data.grid as GridRow[]) ?? []} legend="P = présent · P… = en cours · A = absent · ? = incomplet · · = à venir" />}

                {tab === 'mois' && (
                    <DataTable
                        icon="calendar_month"
                        title={`Vue mensuelle · ${data.monthLabel}`}
                        count={`${(data.days ?? []).length} jour(s) ouvré(s) avec pointages`}
                        minWidth={1100}
                        columns={['Jour', 'Attendus', 'Présents', 'Absences', 'Retards', 'Incomplets', 'Anomalies', 'Heures nettes', 'Clôture']}
                        empty={{ icon: 'event_busy', title: 'Aucune donnée', text: 'Aucun pointage ce mois-ci.' }}
                        rows={((data.days ?? []) as MonthDay[]).map((d) => ({
                            key: d.date,
                            onOpen: () => router.get(base, { date: d.date, ...(trade ? { metier: trade } : {}) }),
                            cells: [
                                <TextCell key="d" strong>
                                    {formatDate(d.date)}
                                </TextCell>,
                                <TextCell key="e">{d.expected}</TextCell>,
                                <TextCell key="p">{d.present}</TextCell>,
                                <TextCell key="a">{d.absent}</TextCell>,
                                <TextCell key="l">{d.late}</TextCell>,
                                <TextCell key="i">{d.incomplete}</TextCell>,
                                <TextCell key="an">{d.anomalies}</TextCell>,
                                <TextCell key="n">{d.net}</TextCell>,
                                <ProgressCell key="c" value={d.closure} />,
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
