import ActivityChart, { SERIES, type SeriesKey } from '@/components/dashboard/ActivityChart';
import ActivityFeed from '@/components/dashboard/ActivityFeed';
import FinanceChart from '@/components/dashboard/FinanceChart';
import MatchingCard from '@/components/dashboard/MatchingCard';
import PriorityList from '@/components/dashboard/PriorityList';
import RecentSites from '@/components/dashboard/RecentSites';
import Button from '@/components/ui/Button';
import Icon from '@/components/ui/Icon';
import StatCard from '@/components/ui/StatCard';
import { useAdminUi } from '@/contexts/AdminUiContext';
import type { ActivityRange, DashboardProps, FinanceRange, Site, WorkerProfile } from '@/types/dashboard';
import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

/** Vue d’ensemble : KPI, activité, priorités, chantiers, matching, finances, journal. */
export default function Dashboard({ kpis, activity, priorities, recentSites, siteDistribution, matching, finance, feed, updatedAt }: DashboardProps) {
    const ui = useAdminUi();
    const online = useOnlineStatus();
    const [loading, setLoading] = useState<{ activity?: boolean; finance?: boolean; all?: boolean }>({});

    const reload = (key: 'activity' | 'finance' | 'all', options: Parameters<typeof router.reload>[0] = {}) =>
        router.reload({
            ...options,
            onStart: () => setLoading((l) => ({ ...l, [key]: true })),
            onFinish: () => setLoading((l) => ({ ...l, [key]: false })),
        });

    const changeActivityRange = (range: ActivityRange, from?: string, to?: string) =>
        reload('activity', { only: ['activity'], data: range === 'custom' ? { periode: range, du: from, au: to } : { periode: range } });

    const changeFinanceRange = (range: FinanceRange) => reload('finance', { only: ['finance'], data: { flux: range } });

    const refresh = () => {
        if (!online) return ui.toast('Actualisation impossible : connexion perdue.', { tone: 'error' });
        reload('all', { onSuccess: () => ui.toast('Données mises à jour.') });
    };

    const openSite = (site: Site) =>
        ui.openPanel({
            kicker: 'Détail du chantier',
            title: site.name,
            badges: [{ label: site.status, tone: site.tone }],
            progress: site.progress,
            meta: [
                ['Promoteur', site.promoter],
                ['Localisation', site.location],
                ['Type de construction', site.type],
                ['Période', site.period],
                ['Ouvriers affectés', site.workers],
            ],
            actions: [
                { label: 'Ouvrir dans Chantiers', icon: 'open_in_new', onClick: () => router.visit(`/chantiers?q=${encodeURIComponent(site.name)}`) },
                ...(site.href ? [{ label: 'Voir la fiche complète', icon: 'arrow_forward', variant: 'primary' as const, onClick: () => router.visit(site.href!) }] : []),
            ],
        });

    const openWorker = (worker: WorkerProfile) =>
        ui.openPanel({
            kicker: 'Profil ouvrier',
            title: worker.name,
            badges: [
                { label: worker.availability, tone: worker.availability === 'Disponible' ? 'g' : 'a' },
                { label: worker.verification, tone: worker.verification === 'Vérifié' ? 'g' : 'a' },
            ],
            meta: [
                ['Métier', worker.job],
                ['Expérience', worker.experience],
                ['Localisation', worker.location],
                ['Coût journalier', worker.rate],
                ['Note moyenne', worker.rating],
                ['Compatibilité', `${worker.score} %`],
            ],
            actions: [
                { label: 'Voir dans Ouvriers', icon: 'open_in_new', onClick: () => router.visit(`/ouvriers?q=${encodeURIComponent(worker.name)}`) },
                { label: 'Proposer une affectation', icon: 'join_inner', variant: 'primary', onClick: () => router.visit('/matching') },
            ],
        });

    // Clic sur un point du graphique : le chiffre de cette date, et le module Chantiers pour le détail.
    const openPoint = (series: SeriesKey, index: number) => {
        if (!activity) return;
        const value = activity[series][index];

        ui.openPanel({
            kicker: `Activité des chantiers · ${activity.dates[index]}`,
            title: `${SERIES[series].name} : ${value}`,
            state: value ? undefined : { kind: 'nodata', title: 'Aucun chantier', text: 'Aucun chantier à cette date.' },
            actions: [{ label: 'Voir dans Chantiers', icon: 'open_in_new', onClick: () => router.visit('/chantiers') }],
        });
    };

    return (
        <>
            <Head title="Vue d’ensemble" />
            <div data-component="Dashboard" className="och-dash" style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-5)' }}>
                {!online && (
                    <div role="alert" style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-3)', flexWrap: 'wrap', padding: 'var(--space-3) var(--space-4)', borderRadius: 'var(--radius-lg)', background: 'var(--c-danger-bg)', color: 'var(--c-danger-fg)' }}>
                        <Icon name="wifi_off" />
                        <span style={{ flex: 1, minWidth: 240, fontSize: 'var(--fs-md)' }}>
                            <b>Connexion perdue.</b> Les données affichées datent de {updatedAt}. Les actions sont temporairement indisponibles.
                        </span>
                    </div>
                )}

                <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'flex-end', gap: 'var(--space-3)', flexWrap: 'wrap', marginTop: 'calc(-1 * var(--space-2))' }}>
                    <span style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-1_5)', fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>
                        <span style={{ width: 8, height: 8, borderRadius: 'var(--radius-round)', background: online ? 'var(--c-success)' : 'var(--c-danger)' }} />
                        {online ? `Mis à jour à ${updatedAt}` : `Hors ligne · dernière mise à jour à ${updatedAt}`}
                    </span>
                    <Button label="Actualiser" icon="refresh" size="sm" variant="secondary" onClick={refresh} disabled={loading.all} />
                </div>

                <div data-component="StatGrid" className="och-kpis">
                    {kpis.map((k) => (
                        <Link
                            key={k.label}
                            href={k.href}
                            aria-label={`${k.label} : ${k.value}${k.unit ? ' ' + k.unit : ''}. Ouvrir le module filtré`}
                            className="och-hover-lift"
                            style={{ display: 'block', minWidth: 0, borderRadius: 'var(--radius-2xl)', color: 'inherit', textDecoration: 'none' }}
                        >
                            <StatCard label={k.label} value={k.value} unit={k.unit} icon={k.icon} tone={k.tone} highlight={k.highlight} />
                        </Link>
                    ))}
                </div>

                <div className="och-dash-grid">
                    {activity && <ActivityChart activity={activity} loading={!!(loading.activity || loading.all)} onRange={changeActivityRange} onPoint={openPoint} onReport={() => router.visit('/rapports')} />}
                    <PriorityList priorities={priorities} />
                    {siteDistribution && <RecentSites sites={recentSites} distribution={siteDistribution} onOpen={openSite} />}
                    {matching && <MatchingCard
                        matching={matching}
                        onWorker={openWorker}
                        onSeeAll={() =>
                            ui.openPanel({
                                kicker: 'Matching intelligent',
                                title: 'Suggestions récentes',
                                text: `Profils proposés par le moteur de matching pour les besoins en attente. Taux moyen de pertinence : ${matching.relevance} %.`,
                                listTitle: `${matching.profiles.length} profils suggérés`,
                                list: matching.profiles.map((w) => ({ icon: 'person', tone: 'g', name: w.name, sub: `${w.job} · pour ${w.site}`, badge: `${w.score} %`, badgeTone: 'g', onClick: () => openWorker(w) })),
                                actions: [{ label: 'Ouvrir le module Matching', icon: 'join_inner', variant: 'primary', onClick: () => router.visit('/matching') }],
                            })
                        }
                        onLaunch={() => router.visit('/matching')}
                    />}
                    {finance && <FinanceChart finance={finance} loading={!!(loading.finance || loading.all)} onRange={changeFinanceRange} onPayments={() => router.visit('/paiements')} />}
                    <ActivityFeed
                        feed={feed}
                        onSeeAll={() =>
                            ui.openPanel({
                                kicker: 'Journal d’activité',
                                title: 'Toute l’activité',
                                text: 'Actions réalisées sur la plateforme au cours des 15 derniers jours.',
                                list: feed.map((f) => ({ icon: f.icon, tone: f.tone, name: `${f.title} · ${f.target}`, sub: `${f.user} · ${f.when}`, onClick: () => router.visit(f.href) })),
                            })
                        }
                    />
                </div>
            </div>
        </>
    );
}

function useOnlineStatus(): boolean {
    const [online, setOnline] = useState(() => navigator.onLine);

    useEffect(() => {
        const up = () => setOnline(true);
        const down = () => setOnline(false);
        window.addEventListener('online', up);
        window.addEventListener('offline', down);
        return () => {
            window.removeEventListener('online', up);
            window.removeEventListener('offline', down);
        };
    }, []);

    return online;
}
