import Pagination from '@/components/list/Pagination';
import { LocationCell, PersonCell, ProgressCell, RowActions, StackCell, TableFrame, TextCell, tdStyle, thStyle } from '@/components/list/Table';
import Button from '@/components/ui/Button';
import Icon from '@/components/ui/Icon';
import StateBlock from '@/components/ui/StateBlock';
import StatusBadge from '@/components/ui/StatusBadge';
import { Block, KeyValues } from '@/components/workers/blocks';
import { toneColors } from '@/lib/tone';
import type { BlockAction, BlockButton, BlockCell, BlockChart, BlockDonut, BlockLines, BlockPanel, BlockProfile, BlockSelect, BlockStack, BlockStep, BlockTab, BlockTable } from '@/types/blocks';
import { niceStep, smoothPath } from '@/lib/format';
import Menu, { type MenuItem } from '@/components/ui/Menu';
import { Link } from '@inertiajs/react';
import { useState } from 'react';

export type Run = (action: BlockAction | null | undefined) => void;

/** Liens rapides du module. */
export function BlockNav({ nav, label }: { nav: { label: string; icon: string; href: string; active: boolean }[]; label: string }) {
    return (
        <nav aria-label={label} style={{ display: 'flex', gap: 'var(--space-1)', overflowX: 'auto', padding: 'var(--space-1)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-xl)', background: 'var(--c-surface)' }}>
            {nav.map((n) => (
                <Link key={n.label} href={n.href} aria-current={n.active ? 'page' : undefined} style={{ flex: 'none', display: 'inline-flex', alignItems: 'center', gap: 'var(--space-1_5)', height: 'var(--control-sm)', padding: '0 var(--space-3)', borderRadius: 'var(--radius-lg)', background: n.active ? 'var(--c-primary-50)' : 'transparent', color: n.active ? 'var(--c-primary)' : 'var(--c-ink-2)', fontWeight: 'var(--fw-semibold)', fontSize: 'var(--fs-sm)', whiteSpace: 'nowrap', textDecoration: 'none' }}>
                    <Icon name={n.icon} size="var(--icon-xs)" />
                    {n.label}
                </Link>
            ))}
        </nav>
    );
}

/** Liste déroulante de la barre de filtres ; mise en évidence quand une valeur est choisie. */
export function BlockSelectInput({ select, onChange }: { select: BlockSelect; onChange: (value: string) => void }) {
    const active = select.value !== '';

    return (
        <label style={{ position: 'relative', flex: '1 1 160px', maxWidth: 240, display: 'flex' }}>
            <select
                value={select.value}
                onChange={(e) => onChange(e.target.value)}
                aria-label={select.label}
                className="och-filter"
                style={{ appearance: 'none', WebkitAppearance: 'none', width: '100%', height: 'var(--control-md)', padding: '0 var(--space-8) 0 var(--space-3)', border: `1px solid ${active ? 'var(--c-primary-400)' : 'var(--c-line-strong)'}`, borderRadius: 'var(--radius-lg)', background: active ? 'var(--c-primary-50)' : 'var(--c-surface)', fontSize: 'var(--fs-base)', color: 'var(--c-ink-2)', cursor: 'pointer', outline: 0 }}
            >
                {select.options.map((o) => (
                    <option key={o.value} value={o.value}>
                        {o.label}
                    </option>
                ))}
            </select>
            <Icon name="expand_more" color="var(--c-muted)" style={{ position: 'absolute', right: 'var(--space-2_5)', top: '50%', transform: 'translateY(-50%)', pointerEvents: 'none' }} />
        </label>
    );
}

/** Boutons d’action alignés à droite. */
export function ActionBar({ buttons, run }: { buttons: BlockButton[]; run: Run }) {
    if (!buttons.length) return null;

    return (
        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2)', justifyContent: 'flex-end' }}>
            {buttons.map((b) => (
                <Button key={b.label} label={b.label} icon={b.icon} size="sm" variant={b.variant} onClick={() => run(b.action)} />
            ))}
        </div>
    );
}

const STEP_COLORS: Record<BlockStep['state'], [string, string, string]> = {
    done: ['var(--c-success-bg)', 'var(--c-success-fg)', 'var(--c-success)'],
    cur: ['var(--c-primary-50)', 'var(--c-primary)', 'var(--c-primary-400)'],
    fail: ['var(--c-danger-bg)', 'var(--c-danger-fg)', 'var(--c-danger)'],
    warn: ['var(--c-warning-bg)', 'var(--c-warning-fg)', 'var(--c-warning)'],
    todo: ['var(--c-surface)', 'var(--c-muted)', 'var(--c-line-strong)'],
};

/** Parcours en étapes (cascade de marge, cycle d’une opération, ancienneté, statuts de clôture). */
export function FlowSteps({ steps, run }: { steps: BlockStep[]; run: Run }) {
    return (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,170px),1fr))', gap: 'var(--space-2)' }}>
            {steps.map((s) => {
                const [bg, fg, bd] = STEP_COLORS[s.state];
                return (
                    <button
                        key={s.label}
                        type="button"
                        onClick={() => run(s.action)}
                        disabled={!s.action}
                        className={s.action ? 'och-hover-card' : undefined}
                        style={{ display: 'flex', gap: 'var(--space-2_5)', alignItems: 'flex-start', padding: 'var(--space-3)', border: `1.5px solid ${bd}`, borderRadius: 'var(--radius-lg)', background: bg, color: fg, cursor: s.action ? 'pointer' : 'default', textAlign: 'left', font: 'inherit', minWidth: 0 }}
                    >
                        <Icon name={s.icon} />
                        <span style={{ minWidth: 0 }}>
                            <b style={{ display: 'block', fontWeight: s.state === 'cur' ? 700 : 600, fontSize: 'var(--fs-md)' }}>{s.label}</b>
                            {s.sub && <span style={{ display: 'block', fontSize: 'var(--fs-sm)', color: 'var(--c-ink-2)', overflowWrap: 'anywhere' }}>{s.sub}</span>}
                        </span>
                    </button>
                );
            })}
        </div>
    );
}

/** Barre empilée avec légende (montant et part de chaque composante). */
export function StackBar({ stack }: { stack: BlockStack }) {
    const total = stack.segments.reduce((a, s) => a + s.value, 0);

    return (
        <Block icon="stacked_bar_chart" title={stack.title} count={stack.sub}>
            <b style={{ display: 'block', fontSize: 'var(--fs-xl)', marginBottom: 'var(--space-2)' }}>{stack.total}</b>
            <div style={{ display: 'flex', height: 12, borderRadius: 'var(--radius-pill)', overflow: 'hidden', background: 'var(--c-line-soft)' }}>
                {stack.segments
                    .filter((s) => s.value > 0)
                    .map((s) => (
                        <span key={s.label} title={`${s.label} : ${s.text}`} style={{ width: `${(s.value / Math.max(1, total)) * 100}%`, background: s.color }} />
                    ))}
            </div>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,220px),1fr))', gap: 'var(--space-2) var(--space-4)', marginTop: 'var(--space-3)' }}>
                {stack.segments.map((s) => (
                    <div key={s.label} style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)', fontSize: 'var(--fs-sm)' }}>
                        <span style={{ width: 10, height: 10, borderRadius: 'var(--radius-round)', background: s.color, flex: 'none' }} />
                        <span style={{ flex: 1, color: 'var(--c-ink-2)' }}>{s.label}</span>
                        <b style={{ whiteSpace: 'nowrap' }}>{s.text}</b>
                        {s.percent && <span style={{ color: 'var(--c-muted)', whiteSpace: 'nowrap' }}>{s.percent}</span>}
                    </div>
                ))}
            </div>
        </Block>
    );
}

/** Histogramme : valeur affichée au-dessus de chaque barre, valeurs négatives en rouge, info-bulle au survol. */
export function BarsBlock({ chart }: { chart: BlockChart }) {
    const [hover, setHover] = useState<number | null>(null);
    const max = Math.max(1, ...chart.bars.map((b) => Math.abs(b.value)));
    const color = chart.color ?? 'var(--c-primary-500)';

    return (
        <Block icon="bar_chart" title={chart.title} count={chart.sub}>
            {chart.bars.length === 0 ? (
                <StateBlock kind="forbidden" compact title="Données indisponibles" text={chart.sub} />
            ) : (
                <>
                    <div style={{ height: 160, display: 'flex', alignItems: 'flex-end', gap: 'var(--space-2)', borderBottom: '1px solid var(--c-line-strong)' }}>
                        {chart.bars.map((b, i) => {
                            const on = hover === i;
                            return (
                                <div
                                    key={b.label}
                                    tabIndex={0}
                                    aria-label={`${b.label} : ${b.text}`}
                                    title={`${b.label} : ${b.text}`}
                                    onMouseEnter={() => setHover(i)}
                                    onMouseLeave={() => setHover(null)}
                                    onFocus={() => setHover(i)}
                                    onBlur={() => setHover(null)}
                                    style={{ flex: 1, minWidth: 0, height: '100%', display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'flex-end', gap: 'var(--space-1)', borderRadius: 'var(--radius-sm)', background: on ? 'var(--c-surface-3)' : 'transparent', outline: 0 }}
                                >
                                    <span style={{ fontSize: 'var(--fs-2xs)', color: 'var(--c-ink-2)', fontWeight: 'var(--fw-semibold)', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis', maxWidth: '100%' }}>{b.text}</span>
                                    <span style={{ width: '100%', maxWidth: 34, height: Math.max(3, (Math.abs(b.value) / max) * 110), borderRadius: 'var(--radius-xs) var(--radius-xs) 0 0', background: b.value < 0 ? 'var(--c-danger)' : color }} />
                                </div>
                            );
                        })}
                    </div>
                    <div style={{ display: 'flex', gap: 'var(--space-2)', marginTop: 'var(--space-1_5)' }}>
                        {chart.bars.map((b) => (
                            <span key={b.label} style={{ flex: 1, minWidth: 0, textAlign: 'center', fontSize: 'var(--fs-2xs)', color: 'var(--c-muted)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                                {b.label}
                            </span>
                        ))}
                    </div>
                </>
            )}
        </Block>
    );
}

/** Panneau : cartes cliquables, lignes « libellé · valeur » ou fil d’événements. */
export function PanelBlock({ panel, run }: { panel: BlockPanel; run: Run }) {
    return (
        <Block icon={panel.icon} title={panel.title} full tools={panel.button ? [{ label: panel.button.label, icon: panel.button.icon, onClick: () => run(panel.button?.action) }] : undefined}>
            {panel.kind === 'kv' && <KeyValues items={panel.items ?? []} />}
            {panel.kind === 'feed' &&
                (panel.feed ?? []).map((f, i) => (
                    <div key={i} style={{ display: 'flex', gap: 'var(--space-3)', alignItems: 'center', padding: 'var(--space-2_5) 0', borderTop: '1px solid var(--c-line-soft)' }}>
                        <span style={{ width: 'var(--control-sm)', height: 'var(--control-sm)', flex: 'none', borderRadius: 'var(--radius-round)', display: 'grid', placeItems: 'center', background: 'var(--c-surface-3)', color: 'var(--c-ink-2)' }}>
                            <Icon name={f.icon} />
                        </span>
                        <span style={{ flex: 1, minWidth: 0 }}>
                            <b>{f.title}</b> {f.text}
                            {f.when && <span style={{ display: 'block', fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>{f.when}</span>}
                        </span>
                    </div>
                ))}
            {panel.kind === 'cards' && (
                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill,minmax(min(100%,250px),1fr))', gap: 'var(--space-3)' }}>
                    {(panel.cards ?? []).map((c) => {
                        const { bg, fg } = toneColors(c.tone, 'neutral');
                        return (
                            <button key={`${c.kicker}${c.title}`} type="button" onClick={() => run(c.action)} disabled={!c.action} className={c.action ? 'och-hover-card' : undefined} style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-1_5)', padding: 'var(--space-3) var(--space-4)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-lg)', background: 'var(--c-surface)', cursor: c.action ? 'pointer' : 'default', textAlign: 'left', font: 'inherit', color: 'inherit', minWidth: 0 }}>
                                <span style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)', width: '100%' }}>
                                    <span style={{ width: 30, height: 30, flex: 'none', borderRadius: 'var(--radius-md)', display: 'grid', placeItems: 'center', background: bg, color: fg }}>
                                        <Icon name={c.icon} size="var(--icon-xs)" />
                                    </span>
                                    <span style={{ flex: 1, minWidth: 0, fontSize: 'var(--fs-xs)', color: 'var(--c-muted)', textTransform: 'uppercase', letterSpacing: '.04em', fontWeight: 'var(--fw-bold)' }}>{c.kicker}</span>
                                    <StatusBadge label={c.badge} tone={c.tone} />
                                </span>
                                <b style={{ fontSize: 'var(--fs-base)' }}>{c.title}</b>
                                <span style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>{c.sub}</span>
                            </button>
                        );
                    })}
                </div>
            )}
        </Block>
    );
}

function Cell({ cell }: { cell: BlockCell }) {
    switch (cell.k) {
        case 'stk':
            return <StackCell main={cell.v} sub={cell.sub ?? undefined} />;
        case 'bdg':
            return <StatusBadge label={cell.v} tone={cell.tn} />;
        case 'prog':
            return <ProgressCell value={cell.v} />;
        case 'per':
            return <PersonCell name={cell.v} sub={cell.sub ?? undefined} />;
        case 'loc':
            return <LocationCell value={cell.v} />;
        default:
            return (
                <TextCell strong={cell.b} muted={cell.m}>
                    {cell.v}
                </TextCell>
            );
    }
}

/** Tableau titré : cellules typées, ouverture de ligne, actions de ligne et pagination. */
export function TableBlock({ table, run, onPage }: { table: BlockTable; run: Run; onPage: (page: number) => void }) {
    const p = table.pagination;
    const hasActions = table.rows.some((r) => r.acts.length > 0 || !!r.menu?.length);
    const [menuFor, setMenuFor] = useState<string | null>(null);
    const menu = (items: { icon: string; label: string; action: BlockAction }[]): MenuItem[] => items.map((m) => ({ icon: m.icon, label: m.label, onClick: () => run(m.action) }));

    return (
        <Block icon={table.icon} title={table.title} count={table.count}>
            {table.rows.length === 0 ? (
                <StateBlock kind="nodata" icon={table.empty.icon} title={table.empty.title} text={table.empty.text} />
            ) : (
                <TableFrame minWidth={table.minWidth}>
                    <thead>
                        <tr style={{ background: 'var(--c-surface-3)' }}>
                            {table.columns.map((c) => (
                                <th key={c} style={thStyle}>
                                    {c}
                                </th>
                            ))}
                            {hasActions && <th style={{ ...thStyle, textAlign: 'center' }}>Actions</th>}
                        </tr>
                    </thead>
                    <tbody>
                        {table.rows.map((r) => (
                            <tr key={r.key} onClick={r.open ? () => run(r.open) : undefined} className="och-hover-soft" style={{ cursor: r.open ? 'pointer' : 'default', background: 'var(--c-surface)' }}>
                                {r.cells.map((c, i) => (
                                    <td key={i} style={tdStyle}>
                                        <Cell cell={c} />
                                    </td>
                                ))}
                                {hasActions && (
                                    <RowActions
                                        actions={r.acts.map((a) => ({ icon: a.icon, label: a.label, onClick: () => run(a.action) }))}
                                        menu={r.menu?.length ? menu(r.menu) : undefined}
                                        menuOpen={menuFor === r.key}
                                        onToggleMenu={r.menu?.length ? () => setMenuFor(menuFor === r.key ? null : r.key) : undefined}
                                    />
                                )}
                            </tr>
                        ))}
                    </tbody>
                </TableFrame>
            )}
            {p && p.lastPage > 1 && <Pagination page={p.page} lastPage={p.lastPage} from={p.from} to={p.to} total={p.total} onPage={onPage} />}
        </Block>
    );
}

/** Onglets d’une fiche ou d’une vue, avec compteur des éléments à traiter. */
export function TabBar({ tabs, label }: { tabs: BlockTab[]; label: string }) {
    return (
        <nav aria-label={label} style={{ display: 'flex', gap: 'var(--space-1)', overflowX: 'auto', borderBottom: '1px solid var(--c-line)' }}>
            {tabs.map((t) => (
                <Link key={t.label} href={t.href} preserveScroll aria-current={t.active ? 'page' : undefined} style={{ flex: 'none', display: 'inline-flex', alignItems: 'center', gap: 'var(--space-1_5)', padding: 'var(--space-2_5) var(--space-3)', borderBottom: `2px solid ${t.active ? 'var(--c-primary)' : 'transparent'}`, color: t.active ? 'var(--c-primary)' : 'var(--c-muted)', fontWeight: 'var(--fw-semibold)', fontSize: 'var(--fs-md)', whiteSpace: 'nowrap', textDecoration: 'none' }}>
                    {t.label}
                    {t.count > 0 && <span style={{ minWidth: 18, padding: '0 6px', borderRadius: 'var(--radius-pill)', background: t.active ? 'var(--c-primary)' : 'var(--c-warning-bg)', color: t.active ? 'var(--c-on-dark)' : 'var(--c-warning-fg)', fontSize: 'var(--fs-xs)', fontWeight: 'var(--fw-bold)', lineHeight: '18px', textAlign: 'center' }}>{t.count}</span>}
                </Link>
            ))}
        </nav>
    );
}

/** En-tête de fiche : titre, statut, informations clés, actions principales et menu « Plus d’actions ». */
export function ProfileHeader({ profile, run }: { profile: BlockProfile; run: Run }) {
    const [open, setOpen] = useState(false);

    return (
        <section style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-3)', background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-5)' }}>
            <div style={{ display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: 'var(--space-2_5)' }}>
                <h2 style={{ margin: 0, fontSize: 'var(--fs-2xl)', flex: '1 1 320px', minWidth: 0 }}>{profile.title}</h2>
                <StatusBadge label={profile.status.label} tone={profile.status.tone} />
                {profile.tag && <StatusBadge label={profile.tag.label} tone={profile.tag.tone} />}
            </div>
            {profile.sub && <div style={{ color: 'var(--c-muted)', fontSize: 'var(--fs-md)' }}>{profile.sub}</div>}
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,200px),1fr))', gap: 'var(--space-2) var(--space-4)' }}>
                {profile.meta.map((m) => (
                    <div key={m.label} style={{ display: 'flex', gap: 'var(--space-2)', alignItems: 'flex-start', minWidth: 0 }}>
                        <Icon name={m.icon} size="var(--icon-xs)" color="var(--c-primary-600)" />
                        <span style={{ minWidth: 0 }}>
                            <span style={{ display: 'block', fontSize: 'var(--fs-xs)', color: 'var(--c-muted)' }}>{m.label}</span>
                            <b style={{ fontSize: 'var(--fs-md)', overflowWrap: 'anywhere' }}>{m.value || '—'}</b>
                        </span>
                    </div>
                ))}
            </div>
            {(profile.buttons.length > 0 || profile.menu.length > 0) && (
                <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2)', alignItems: 'center', position: 'relative' }}>
                    {profile.buttons.map((b) => (
                        <Button key={b.label} label={b.label} icon={b.icon} size="sm" variant={b.variant} onClick={() => run(b.action)} />
                    ))}
                    {profile.menu.length > 0 && (
                        <div style={{ position: 'relative' }}>
                            <Button label="Plus d’actions" icon="more_horiz" size="sm" variant="secondary" aria-expanded={open} onClick={() => setOpen(!open)} />
                            {open && <Menu items={profile.menu.map((m) => ({ icon: m.icon, label: m.label, onClick: () => run(m.action) }))} onClose={() => setOpen(false)} top="calc(100% + var(--space-1))" right="auto" />}
                        </div>
                    )}
                </div>
            )}
        </section>
    );
}

/** Courbes lissées sur un axe commun, avec info-bulle détaillée par période au survol. */
export function LinesBlock({ chart }: { chart: BlockLines }) {
    const [hover, setHover] = useState<number | null>(null);
    const n = chart.labels.length;
    const max = Math.max(1, ...chart.series.flatMap((s) => s.values));
    const step = niceStep((max * 1.05) / 4);
    const top = step * 4;
    const x = (i: number) => (n > 1 ? (i / (n - 1)) * 100 : 50);
    const y = (v: number) => 100 - (v / top) * 100;
    const axis = (v: number) => (top >= 1e6 ? `${String(Math.round((v / 1e6) * 10) / 10).replace('.', ',')} M` : top >= 1e3 ? `${Math.round(v / 1e3)} k` : String(v));

    return (
        <Block icon="show_chart" title={chart.title} count={chart.sub}>
            <div style={{ display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: 'var(--space-2) var(--space-5)', marginBottom: 'var(--space-3)' }}>
                {chart.series.map((s) => (
                    <span key={s.label} style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)', fontSize: 'var(--fs-sm)', color: 'var(--c-ink-2)' }}>
                        <span style={{ width: 10, height: 10, borderRadius: 'var(--radius-round)', background: s.color }} />
                        {s.label}
                    </span>
                ))}
                {chart.note && (
                    <span style={{ marginLeft: 'auto', fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>
                        {chart.note.label} <b style={{ color: 'var(--c-ink)', fontSize: 'var(--fs-lg)' }}>{chart.note.value}</b>
                    </span>
                )}
            </div>
            {n === 0 ? (
                <StateBlock kind="nodata" compact title="Aucune donnée" text="Aucune opération sur la période et les filtres." />
            ) : (
                <div style={{ display: 'flex', gap: 'var(--space-2_5)' }}>
                    <div style={{ display: 'flex', flexDirection: 'column', justifyContent: 'space-between', height: 180, margin: '-7px 0', fontSize: 'var(--fs-xs)', color: 'var(--c-muted)', textAlign: 'right', width: 44, flex: 'none' }}>
                        {[4, 3, 2, 1, 0].map((k) => (
                            <span key={k}>{axis(step * k)}</span>
                        ))}
                    </div>
                    <div style={{ flex: 1, minWidth: 0 }}>
                        <div style={{ position: 'relative', height: 180, backgroundImage: 'linear-gradient(var(--c-line-soft) 1px,transparent 1px)', backgroundSize: '100% 25%', borderBottom: '1px solid var(--c-line-strong)' }} onMouseLeave={() => setHover(null)}>
                            <svg viewBox="0 0 100 100" preserveAspectRatio="none" style={{ position: 'absolute', inset: 0, width: '100%', height: '100%', overflow: 'visible' }} aria-hidden>
                                {chart.series.map((s) => (
                                    <path key={s.label} d={smoothPath(s.values.map((v, i) => [x(i), y(v)]))} fill="none" stroke={s.color} strokeWidth={2.5} vectorEffect="non-scaling-stroke" />
                                ))}
                                {hover !== null && <line x1={x(hover)} x2={x(hover)} y1={0} y2={100} stroke="var(--c-line-strong)" strokeDasharray="3 3" vectorEffect="non-scaling-stroke" />}
                            </svg>
                            {chart.series.map((s) =>
                                s.values.map((v, i) => <span key={`${s.label}${i}`} style={{ position: 'absolute', left: `${x(i)}%`, top: `${y(v)}%`, width: hover === i ? 10 : 7, height: hover === i ? 10 : 7, borderRadius: 'var(--radius-round)', background: s.color, border: '2px solid var(--c-surface)', transform: 'translate(-50%,-50%)', pointerEvents: 'none' }} />),
                            )}
                            {chart.labels.map((label, i) => (
                                <div key={label} tabIndex={0} aria-label={`${label} : ${chart.series.map((s) => `${s.label} ${s.texts[i]}`).join(', ')}`} onMouseEnter={() => setHover(i)} onFocus={() => setHover(i)} onBlur={() => setHover(null)} style={{ position: 'absolute', top: 0, bottom: 0, left: `${Math.max(0, x(i) - 50 / Math.max(1, n - 1))}%`, width: `${100 / Math.max(1, n - 1)}%`, outline: 0 }} />
                            ))}
                            {hover !== null && (
                                <div role="tooltip" style={{ position: 'absolute', left: `${x(hover)}%`, top: 8, transform: x(hover) < 18 ? 'translateX(8px)' : x(hover) > 82 ? 'translateX(calc(-100% - 8px))' : 'translateX(-50%)', zIndex: 3, pointerEvents: 'none', padding: 'var(--space-2) var(--space-3)', borderRadius: 'var(--radius-md)', background: 'var(--c-primary-strong)', color: 'var(--c-on-dark)', boxShadow: 'var(--shadow-popover)', whiteSpace: 'nowrap', fontSize: 'var(--fs-sm)' }}>
                                    <b style={{ display: 'block', marginBottom: 'var(--space-1)' }}>{chart.labels[hover]}</b>
                                    {[...chart.series, ...chart.extra].filter((s) => s.texts[hover]).map((s) => (
                                        <div key={s.label} style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-1_5)' }}>
                                            <span style={{ width: 8, height: 8, borderRadius: 'var(--radius-round)', background: s.color }} />
                                            <span style={{ color: 'var(--c-on-dark-muted)' }}>{s.label}</span>
                                            <b style={{ marginLeft: 'auto', paddingLeft: 'var(--space-3)' }}>{s.texts[hover]}</b>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>
                        <div style={{ display: 'flex', justifyContent: 'space-between', marginTop: 'var(--space-1_5)' }}>
                            {chart.labels.map((label) => (
                                <span key={label} style={{ fontSize: 'var(--fs-2xs)', color: 'var(--c-muted)', whiteSpace: 'nowrap' }}>
                                    {label}
                                </span>
                            ))}
                        </div>
                    </div>
                </div>
            )}
        </Block>
    );
}

/** Anneau de répartition : survol d’un segment (ou de sa légende) pour afficher sa valeur au centre. */
export function DonutBlock({ donut }: { donut: BlockDonut }) {
    const [hover, setHover] = useState<number | null>(null);
    const total = donut.segments.reduce((a, s) => a + s.value, 0);
    let acc = 0;
    const stops = donut.segments.map((s, i) => {
        const from = acc;
        acc += total ? (s.value / total) * 100 : 0;
        const end = Math.max(from, acc - 0.4);
        const color = hover === null || hover === i ? s.color : `color-mix(in oklch, ${s.color} 35%, var(--c-surface))`;
        return `${color} ${from}% ${end}%, var(--c-surface) ${end}% ${acc}%`;
    });
    const current = hover !== null ? donut.segments[hover] : null;

    return (
        <Block icon="donut_large" title={donut.title} count={donut.sub}>
            <div style={{ display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: 'var(--space-5)' }}>
                <div style={{ position: 'relative', width: 190, height: 190, flex: 'none', borderRadius: 'var(--radius-round)', background: total ? `conic-gradient(${stops.join(',')})` : 'var(--c-line-soft)' }}>
                    <div style={{ position: 'absolute', inset: 34, borderRadius: 'var(--radius-round)', background: 'var(--c-surface)', display: 'grid', placeItems: 'center', textAlign: 'center' }}>
                        <div>
                            <div style={{ fontSize: 'var(--fs-xs)', color: current ? current.color : 'var(--c-muted)', fontWeight: 'var(--fw-semibold)' }}>{current ? current.label : 'Total'}</div>
                            <b style={{ fontSize: 'var(--fs-3xl)' }}>{current ? current.text : donut.total}</b>
                            <div style={{ fontSize: 'var(--fs-xs)', color: 'var(--c-muted)' }}>{current ? `${current.percent} des ${donut.totalLabel}` : donut.totalLabel}</div>
                        </div>
                    </div>
                </div>
                <div style={{ flex: '1 1 220px', display: 'flex', flexDirection: 'column', gap: 'var(--space-1)' }}>
                    {donut.segments.map((s, i) => (
                        <div key={s.label} onMouseEnter={() => setHover(i)} onMouseLeave={() => setHover(null)} style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)', padding: 'var(--space-1_5) var(--space-2)', borderRadius: 'var(--radius-md)', background: hover === i ? 'var(--c-hover)' : 'transparent', opacity: hover === null || hover === i ? 1 : 0.5, fontSize: 'var(--fs-sm)' }}>
                            <span style={{ width: 10, height: 10, borderRadius: 'var(--radius-round)', background: s.color, flex: 'none' }} />
                            <span style={{ flex: 1 }}>{s.label}</span>
                            <b>{s.text}</b>
                            <span style={{ color: 'var(--c-muted)', width: 44, textAlign: 'right' }}>{s.percent}</span>
                        </div>
                    ))}
                </div>
            </div>
        </Block>
    );
}
