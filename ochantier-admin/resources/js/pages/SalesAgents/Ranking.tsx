import Dialog from '@/components/overlays/Dialog';
import { FilterSelect } from '@/components/list/ListToolbar';
import { PersonCell, ProgressCell, StackCell, TextCell } from '@/components/list/Table';
import { decimal, fcfa, useSalesActions, type AgentRef } from '@/components/sales/useSalesActions';
import { Podium } from '@/components/sales/visuals';
import { AlertList, Block, DataTable, TileGrid, Toolbar, type ToolbarAction } from '@/components/workers/blocks';
import Icon from '@/components/ui/Icon';
import StatusBadge from '@/components/ui/StatusBadge';
import { useAdminUi } from '@/contexts/AdminUiContext';
import type { RankingProps, RankingRow } from '@/types/sales';
import { Head, router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';

type Filters = Partial<Record<'agency' | 'zone' | 'supervisor' | 'type' | 'performance', string>>;

const FILTERS: [keyof Filters, string][] = [
    ['agency', 'Agence'],
    ['zone', 'Zone'],
    ['supervisor', 'Responsable'],
    ['type', 'Type de commercial'],
    ['performance', 'Niveau de performance'],
];

const LEVELS = ['Excellent', 'Très bon', 'Satisfaisant', 'À surveiller', 'Insuffisant'];

/** Classement mensuel : podium, tableau complet, outils (comparaison, publication, clôture) et anciens classements. */
export default function SalesRanking(props: RankingProps) {
    const { month, label, closed, published, isCurrent, lastCalculation, referenceDate, rows, topSlug, months, history, weights } = props;
    const ui = useAdminUi();
    const actions = useSalesActions();
    const [filters, setFilters] = useState<Filters>({});
    const [year, mm] = month.split('-');
    const goTo = (m: string) => router.visit(`/commerciaux/classement/${m.replace('-', '/')}`);
    const url = `/commerciaux/classement/${year}/${mm}`;

    const value = (r: RankingRow, key: keyof Filters): string => (key === 'performance' ? r.performance.label : (r[key] ?? ''));
    const visible = rows.filter((r) => FILTERS.every(([key]) => !filters[key] || value(r, key) === filters[key]));
    const eligible = visible.filter((r) => r.eligible);
    const top = rows.find((r) => r.slug === topSlug) ?? null;
    const average = visible.length ? Math.round((visible.reduce((sum, r) => sum + r.score, 0) / visible.length) * 10) / 10 : 0;
    const options = (key: keyof Filters) => (key === 'performance' ? LEVELS : [...new Set(rows.map((r) => value(r, key)).filter(Boolean))].sort()).map((o) => ({ value: o, label: o }));

    const ref = (r: RankingRow): AgentRef => ({ slug: r.slug, name: r.name, code: r.code, status: r.status, pay: { bonusCotm: r.bonusCotm } });
    const scoreDrawer = (r: RankingRow) => actions.scoreDrawer(ref(r), r, { value: month, label, closed, isCurrent, reference: referenceDate }, weights);

    const compare = () => {
        const names = rows.map((r) => ({ value: r.slug, label: r.name }));
        actions.open(<CompareDialog rows={rows} names={names} label={label} onClose={actions.close} />);
    };

    const tools: ToolbarAction[] = [
        { label: 'Comparer', icon: 'compare_arrows', onClick: compare },
        {
            label: 'Pondérations',
            icon: 'tune',
            onClick: () =>
                ui.openPanel({
                    kicker: 'Calcul du score de performance',
                    title: 'Pondérations appliquées',
                    text: 'Score global sur 100 = somme des indicateurs normalisés sur 100 × pondération. Les pondérations seront paramétrables depuis Paramètres ; le calcul du module n’en dépend pas.',
                    list: weights.map((w) => ({ icon: 'percent', tone: 'b', name: w.label, sub: 'Indicateur normalisé sur 100', badge: `${w.weight} %`, badgeTone: 'b' })),
                    meta: [
                        ['Éligibilité', 'Commerciaux actifs sur la période ; suspendus ou désactivés visibles mais non éligibles au titre'],
                        ['Exclusions', 'Profils frauduleux, dupliqués, rejetés ou annulés'],
                        ['Recalcul', 'Automatique après chaque validation ou rejet important'],
                        ['Clôture', 'Fin de mois ; toute modification ultérieure exige une autorisation'],
                        ['Départage', '1. taux de validation · 2. profils validés · 3. qualité · 4. délais · 5. taux de rejet'],
                    ],
                }),
        },
        { label: 'Exporter', icon: 'download', onClick: () => (window.location.href = `${url}/export`) },
        ...(top ? [{ label: 'Bonus Commercial du mois', icon: 'workspace_premium', onClick: () => actions.bonus(ref(top), { cotm: true, month, monthLabel: label, closed }) }] : []),
        {
            label: published ? 'Masquer le résultat' : 'Publier le résultat',
            icon: published ? 'visibility_off' : 'campaign',
            onClick: () =>
                ui.confirm({
                    icon: published ? 'visibility_off' : 'campaign',
                    title: `${published ? 'Masquer' : 'Publier'} le classement de ${label} ?`,
                    body: published ? 'Le classement ne sera plus visible dans l’Application Commercial.' : 'Le podium et le Commercial du mois seront visibles dans l’Application Commercial. Les gains restent confidentiels.',
                    ok: published ? 'Masquer' : 'Publier',
                    onConfirm: () => router.post(`${url}/publication`, {}, { preserveScroll: true }),
                }),
        },
        ...(!closed
            ? [
                  {
                      label: 'Clôturer le mois',
                      icon: 'lock',
                      variant: 'primary' as const,
                      onClick: () =>
                          ui.confirm({
                              tone: 'warning',
                              icon: 'lock',
                              title: `Clôturer le classement de ${label} ?`,
                              body: `Le classement sera figé. Toute modification ultérieure exigera une autorisation administrative.${top ? ` ${top.name} sera désigné Commercial du mois.` : ''}`,
                              ok: 'Clôturer',
                              onConfirm: () => router.post(`${url}/cloture`, {}, { preserveScroll: true }),
                          }),
                  },
              ]
            : []),
    ];

    const alerts = [
        ...(!closed ? [{ tone: 'info', icon: 'hourglass_top', title: 'Classement provisoire', text: 'Le mois n’est pas clôturé ; les scores évoluent à chaque validation ou rejet.' }] : []),
        ...(closed && !published ? [{ tone: 'warning', icon: 'visibility_off', title: 'Résultat non publié', text: 'Le classement n’est pas visible dans l’Application Commercial.' }] : []),
    ];

    return (
        <>
            <Head title={`Classement · ${label}`} />
            <div data-component="CommercialRanking" style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-5)' }}>
                <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2_5)' }}>
                    <FilterSelect label="Mois" value={month} options={months} onChange={(m) => m && goTo(m)} />
                    {FILTERS.map(([key, text]) => (
                        <FilterSelect key={key} label={text} value={filters[key] ?? ''} options={options(key)} onChange={(v) => setFilters((f) => ({ ...f, [key]: v || undefined }))} />
                    ))}
                </div>

                <Toolbar actions={tools.map((t) => ({ variant: 'secondary', ...t }))} />
                <AlertList alerts={alerts} />

                <TileGrid
                    tiles={[
                        { label: 'Période', value: label, icon: 'calendar_month', tone: 'info', sub: closed ? 'Classement clôturé' : 'En cours · résultats provisoires' },
                        { label: 'Commerciaux classés', value: String(visible.length), icon: 'groups', tone: 'success', sub: 'Actifs sur la période' },
                        { label: 'Score moyen', value: `${decimal(average)} / 100`, icon: 'analytics', tone: 'warning', bar: average },
                        { label: 'Commercial du mois', value: top?.name ?? '—', icon: 'emoji_events', tone: 'orange', sub: published ? 'Résultat publié' : 'Résultat non publié' },
                        { label: 'Dernier calcul', value: lastCalculation, icon: 'update', tone: 'neutral', sub: 'Recalcul automatique après validation ou rejet' },
                    ]}
                />

                {eligible.length > 0 && <Podium rows={eligible} />}

                <DataTable
                    icon="leaderboard"
                    title={`Classement complet · ${label}`}
                    count={`${visible.length} commercial(aux)`}
                    minWidth={1600}
                    columns={['Rang', 'Évolution', 'Commercial', 'Agence / zone', 'Enregistrés', 'Complets', 'Validés', 'Taux de validation', 'Objectif atteint', 'Activité terrain', 'Qualité des dossiers', 'Score global', 'Gain du mois']}
                    empty={{ icon: 'leaderboard', title: 'Aucun commercial classé', text: 'Aucun résultat pour ces filtres.' }}
                    rows={visible.map((r) => ({
                        key: r.slug,
                        onOpen: () => router.visit(`/commerciaux/${r.slug}`),
                        cells: [
                            <TextCell strong>#{r.rank}</TextCell>,
                            <TextCell muted={!r.evolution}>{r.evolution == null ? 'Nouveau' : r.evolution > 0 ? `▲ ${r.evolution}` : r.evolution < 0 ? `▼ ${-r.evolution}` : '='}</TextCell>,
                            <PersonCell name={r.name} sub={`${r.code}${r.status.value !== 'active' ? ` · ${r.status.label}` : ''}`} />,
                            <StackCell main={r.agency} sub={r.zone} />,
                            <TextCell>{r.metrics.registered}</TextCell>,
                            <TextCell>{r.metrics.complete}</TextCell>,
                            <TextCell strong>{r.metrics.validated}</TextCell>,
                            <TextCell>{r.metrics.validationRate} %</TextCell>,
                            <ProgressCell value={r.metrics.objective} />,
                            <TextCell>{r.metrics.activity} / 100</TextCell>,
                            <TextCell>{r.metrics.quality} / 100</TextCell>,
                            <StatusBadge label={decimal(r.score)} tone={r.performance.tone} />,
                            <TextCell>{fcfa(r.gain)}</TextCell>,
                        ],
                        actions: [{ icon: 'calculate', label: 'Détail du calcul', onClick: () => scoreDrawer(r) }],
                        menu: [
                            { icon: 'open_in_new', label: 'Ouvrir la fiche', onClick: () => router.visit(`/commerciaux/${r.slug}`) },
                            { icon: 'workspace_premium', label: 'Attribuer un bonus', onClick: () => actions.bonus(ref(r), { cotm: r.slug === topSlug, month, monthLabel: label, closed }) },
                        ],
                    }))}
                />

                <Block icon="history" title="Anciens classements">
                    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,220px),1fr))', gap: 'var(--space-3)' }}>
                        {history.map((h) => (
                            <button key={h.month} type="button" onClick={() => goTo(h.month)} className="och-hover-card" style={{ display: 'flex', gap: 'var(--space-3)', alignItems: 'flex-start', textAlign: 'left', padding: 'var(--space-3) var(--space-4)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-lg)', background: 'var(--c-surface)', cursor: 'pointer', fontFamily: 'var(--font-sans)' }}>
                                <Icon name="emoji_events" color="var(--c-accent-600)" />
                                <span style={{ flex: 1, minWidth: 0 }}>
                                    <span style={{ display: 'block', fontSize: 'var(--fs-xs)', color: 'var(--c-muted)' }}>{h.label}</span>
                                    <b style={{ display: 'block', fontSize: 'var(--fs-md)' }}>{h.name ?? '—'}</b>
                                    {h.score != null && <span style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-ink-2)' }}>Score {decimal(h.score)} · {h.validated} profils validés</span>}
                                </span>
                                <StatusBadge label={h.closed ? 'Clôturé' : 'En cours'} tone={h.closed ? 'n' : 'b'} />
                            </button>
                        ))}
                    </div>
                </Block>
            </div>
            {actions.dialog}
        </>
    );
}

/** Choix de deux ou trois commerciaux, puis comparaison dans le panneau latéral. */
function CompareDialog({ rows, names, label, onClose }: { rows: RankingRow[]; names: { value: string; label: string }[]; label: string; onClose: () => void }) {
    const ui = useAdminUi();
    const [picked, setPicked] = useState<string[]>(rows.slice(0, 2).map((r) => r.slug));
    const [third, setThird] = useState('');
    const [error, setError] = useState('');

    const submit = () => {
        const slugs = [...new Set([...picked, third].filter(Boolean))];
        if (slugs.length < 2) return setError('Choisissez deux commerciaux différents.');
        const sel = slugs.map((s) => rows.find((r) => r.slug === s)!);
        const row = (text: string, fn: (r: RankingRow) => string | number): [string, string] => [text, sel.map((r) => `${r.firstName} : ${fn(r)}`).join('  ·  ')];
        onClose();
        ui.openPanel({
            kicker: `Comparaison · ${label}`,
            title: sel.map((r) => r.name).join(' / '),
            list: sel.map((r) => ({ icon: 'person', tone: r.rank === 1 ? 'a' : 'b', name: r.name, sub: `Rang ${r.rank} · ${r.zone}`, badge: `${decimal(r.score)} / 100`, badgeTone: r.performance.tone, onClick: () => router.visit(`/commerciaux/${r.slug}`) })),
            meta: [
                row('Profils validés', (r) => r.metrics.validated),
                row('Taux de validation', (r) => `${r.metrics.validationRate} %`),
                row('Objectif atteint', (r) => `${r.metrics.objective} %`),
                row('Qualité des dossiers', (r) => r.metrics.quality),
                row('Activité terrain', (r) => r.metrics.activity),
                row('Respect des délais', (r) => r.metrics.delays),
                row('Gain du mois', (r) => fcfa(r.gain)),
            ],
        });
    };

    const select = (value: string, onChange: (v: string) => void, text: string, optional = false) => (
        <label style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-1)', fontSize: 'var(--fs-sm)', fontWeight: 'var(--fw-semibold)' }}>
            {text}
            <select value={value} onChange={(e) => onChange(e.target.value)} className="och-input" style={{ height: 'var(--control-md)', padding: '0 var(--space-2)', border: '1px solid var(--c-line-strong)', borderRadius: 'var(--radius-md)', fontFamily: 'var(--font-sans)' }}>
                {optional && <option value="">Aucun</option>}
                {names.map((n) => (
                    <option key={n.value} value={n.value}>
                        {n.label}
                    </option>
                ))}
            </select>
        </label>
    );

    return (
        <FormDialogShell title="Comparer des commerciaux" body={label} onClose={onClose} onSubmit={submit}>
            {select(picked[0], (v) => setPicked([v, picked[1]]), 'Commercial 1 *')}
            {select(picked[1], (v) => setPicked([picked[0], v]), 'Commercial 2 *')}
            {select(third, setThird, 'Commercial 3 (facultatif)', true)}
            {error && <span style={{ color: 'var(--c-danger-fg)', fontSize: 'var(--fs-sm)' }}>{error}</span>}
        </FormDialogShell>
    );
}

function FormDialogShell({ title, body, onClose, onSubmit, children }: { title: string; body: string; onClose: () => void; onSubmit: () => void; children: ReactNode }) {
    return (
        <Dialog title={title} body={body} icon="compare_arrows" okLabel="Comparer" onClose={onClose} onSubmit={onSubmit}>
            <div style={{ display: 'grid', gap: 'var(--space-3)' }}>{children}</div>
        </Dialog>
    );
}
