import { PeriodBar } from '@/components/attendances/blocks';
import { FilterSelect } from '@/components/list/ListToolbar';
import Pagination from '@/components/list/Pagination';
import { Checkbox, PersonCell, RowActions, StackCell, TableFrame, TextCell, tdStyle, thStyle } from '@/components/list/Table';
import Icon from '@/components/ui/Icon';
import type { MenuItem } from '@/components/ui/Menu';
import StateBlock from '@/components/ui/StateBlock';
import StatusBadge from '@/components/ui/StatusBadge';
import { Block, TileGrid, Toolbar, type ToolbarAction } from '@/components/workers/blocks';
import { formatDate } from '@/lib/format';
import type { FilterDef, Paginated } from '@/types/attendances';
import type { PaymentFilters, PaymentPageProps, PaymentRow, PaymentSection, Segment, Tile } from '@/types/payments';
import { Link, router, usePage } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import { fcfa, type PaymentActions } from './usePaymentActions';

/** Navigation du module, avec le nombre d’éléments à traiter. */
export function PaymentNav({ sections }: { sections: PaymentSection[] }) {
    return (
        <nav aria-label="Sections du module Paiements" style={{ display: 'flex', gap: 'var(--space-1)', overflowX: 'auto', padding: 'var(--space-1)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-xl)', background: 'var(--c-surface)' }}>
            {sections.map((s) => (
                <Link key={s.href} href={s.href} aria-current={s.active ? 'page' : undefined} style={{ flex: 'none', display: 'inline-flex', alignItems: 'center', gap: 'var(--space-1_5)', height: 'var(--control-sm)', padding: '0 var(--space-3)', borderRadius: 'var(--radius-lg)', background: s.active ? 'var(--c-primary-50)' : 'transparent', color: s.active ? 'var(--c-primary)' : 'var(--c-ink-2)', fontWeight: 'var(--fw-semibold)', fontSize: 'var(--fs-sm)', whiteSpace: 'nowrap', textDecoration: 'none' }}>
                    <Icon name={s.icon} size="var(--icon-xs)" />
                    {s.label}
                    {s.count > 0 && <span style={{ minWidth: 18, padding: '0 6px', borderRadius: 'var(--radius-pill)', background: 'var(--c-warning-bg)', color: 'var(--c-warning-fg)', fontSize: 'var(--fs-xs)', fontWeight: 'var(--fw-bold)', lineHeight: '18px', textAlign: 'center' }}>{s.count}</span>}
                </Link>
            ))}
        </nav>
    );
}

/** Applique des filtres à la page courante (retour à la page 1). */
export function useApply(filters: PaymentFilters) {
    const { url } = usePage();
    const base = url.split('?')[0];

    return (changes: PaymentFilters, keepPage = false) => {
        const next: PaymentFilters = { ...filters, ...changes, ...(keepPage ? {} : { page: undefined }) };
        router.get(base, Object.fromEntries(Object.entries(next).filter(([, v]) => v)), { preserveState: true, preserveScroll: true, replace: true });
    };
}

export const queryOf = (filters: PaymentFilters) => new URLSearchParams(Object.entries(filters).filter(([k, v]) => v && k !== 'page') as [string, string][]).toString();

type LayoutProps = PaymentPageProps & {
    filterDefs?: FilterDef[];
    tiles?: Tile[];
    toolbar?: ToolbarAction[];
    before?: ReactNode;
    top?: ReactNode;
    search?: boolean;
    children: ReactNode;
};

/** Mise en page d’une page du module : navigation, période et recherche, contenu de tête, tuiles, filtres, actions, contenu. */
export function PaymentLayout({ sections, period, periods, filters, filterDefs = [], tiles, toolbar, before, top, search = true, children }: LayoutProps) {
    const apply = useApply(filters);

    return (
        <>
            <div data-component="PaymentsTop" style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)' }}>
                <PaymentNav sections={sections} />
                <PeriodBar period={period} periods={periods} query={search ? (filters.q ?? '') : undefined} onChange={(c) => apply(c)} />
            </div>
            {top}
            {tiles && <TileGrid tiles={tiles} />}
            <section style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)', background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-5)', minWidth: 0 }}>
                {(filterDefs.length > 0 || !!toolbar?.length) && (
                    <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2)', alignItems: 'center' }}>
                        {filterDefs.map((f) => (
                            <FilterSelect key={f.key} label={f.label} value={filters[f.key] ?? ''} options={f.options.map((o) => ({ value: o, label: o }))} onChange={(v) => apply({ [f.key]: v || undefined })} />
                        ))}
                        <span style={{ flex: 1 }} />
                        {toolbar && <Toolbar actions={toolbar} />}
                    </div>
                )}
                {before}
                {children}
            </section>
        </>
    );
}

const COLUMNS = ['Référence', 'Date', 'Type', 'Promoteur · chantier', 'Ouvrier', 'Période', 'Montant brut', 'Bonus + indemnités', 'Retenues', 'Commission O’CHANTIER', 'Frais prestataire', 'Payé par le promoteur', 'Net ouvrier', 'Moyen', 'Paiement', 'Reversement', 'Commission', 'Rapprochement'];

/** Menu d’une ligne d’opération (reversement, relance, remboursement). */
export function paymentMenu(r: PaymentRow, actions: PaymentActions): MenuItem[] {
    return [
        ...(r.isDeposit ? [] : [{ icon: 'send', label: 'Reverser à l’ouvrier', onClick: () => actions.payout(r) }]),
        ...(r.canRetry ? [{ icon: 'refresh', label: 'Relancer le reversement', onClick: () => actions.retry(r.reference) }] : []),
        { icon: 'currency_exchange', label: 'Rembourser le promoteur', onClick: () => actions.refund(r.reference, r.promoterTotal) },
    ];
}

type TableProps = {
    title: string;
    count: string;
    page: Paginated<PaymentRow>;
    onPage: (p: number) => void;
    selected?: string[];
    onToggle?: (ref: string) => void;
    actions: PaymentActions;
    empty: { title: string; text: string };
};

/** Tableau des opérations financières (toutes les composantes du montant et les quatre statuts). */
export function PaymentTable({ title, count, page, onPage, selected, onToggle, actions, empty }: TableProps) {
    const [menuFor, setMenuFor] = useState<string | null>(null);
    const open = (r: PaymentRow) => router.visit(`/paiements/operations/${r.reference}`);

    return (
        <Block icon="receipt_long" title={title} count={count}>
            {page.data.length === 0 ? (
                <StateBlock kind="nodata" icon="search_off" title={empty.title} text={empty.text} />
            ) : (
                <TableFrame minWidth={2300}>
                    <thead>
                        <tr style={{ background: 'var(--c-surface-3)' }}>
                            {onToggle && <th style={{ ...thStyle, width: 40 }} />}
                            {COLUMNS.map((c) => (
                                <th key={c} style={thStyle}>
                                    {c}
                                </th>
                            ))}
                            <th style={{ ...thStyle, textAlign: 'center' }}>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        {page.data.map((r) => (
                            <tr key={r.reference} onClick={() => open(r)} className="och-hover-soft" style={{ cursor: 'pointer', background: selected?.includes(r.reference) ? 'var(--c-primary-50)' : 'var(--c-surface)' }}>
                                {onToggle && (
                                    <td style={tdStyle}>
                                        <Checkbox checked={!!selected?.includes(r.reference)} onToggle={() => onToggle(r.reference)} label={`Sélectionner ${r.reference}`} />
                                    </td>
                                )}
                                <td style={tdStyle}>
                                    <TextCell strong>{r.reference}</TextCell>
                                </td>
                                <td style={tdStyle}>
                                    <TextCell>{formatDate(r.date)}</TextCell>
                                </td>
                                <td style={tdStyle}>
                                    <TextCell>{r.type}</TextCell>
                                </td>
                                <td style={tdStyle}>
                                    <StackCell main={r.promoter} sub={r.site} />
                                </td>
                                <td style={tdStyle}>{r.worker ? <PersonCell name={r.worker.name} sub={`${r.worker.code} · ${r.worker.trade}`} /> : <TextCell muted>—</TextCell>}</td>
                                <td style={tdStyle}>
                                    <TextCell>{r.period}</TextCell>
                                </td>
                                <td style={tdStyle}>
                                    <TextCell>{fcfa(r.gross)}</TextCell>
                                </td>
                                <td style={tdStyle}>
                                    <TextCell muted={!r.extras}>{r.extras ? `+${fcfa(r.extras)}` : '—'}</TextCell>
                                </td>
                                <td style={tdStyle}>
                                    <TextCell muted={!r.deduction}>{r.deduction ? `−${fcfa(r.deduction)}` : '—'}</TextCell>
                                </td>
                                <td style={tdStyle}>
                                    <StackCell main={fcfa(r.com)} sub={`P ${fcfa(r.comP)} · O ${fcfa(r.comO)}`} />
                                </td>
                                <td style={tdStyle}>
                                    <TextCell>{fcfa(r.fees)}</TextCell>
                                </td>
                                <td style={tdStyle}>
                                    <TextCell strong>{fcfa(r.promoterTotal)}</TextCell>
                                </td>
                                <td style={tdStyle}>
                                    <TextCell strong={!r.isDeposit} muted={r.isDeposit}>
                                        {r.isDeposit ? '—' : fcfa(r.net)}
                                    </TextCell>
                                </td>
                                <td style={tdStyle}>
                                    <TextCell>{r.method ?? '—'}</TextCell>
                                </td>
                                {[r.payStatus, r.payoutStatus, r.comStatus, r.reconciliation].map((b, i) => (
                                    <td key={i} style={tdStyle}>
                                        <StatusBadge label={b.label} tone={b.tone} />
                                    </td>
                                ))}
                                <RowActions
                                    actions={[
                                        { icon: 'percent', label: 'Détail de la commission', onClick: () => router.visit(`/paiements/operations/${r.reference}/commission`) },
                                        { icon: 'visibility', label: 'Consulter', onClick: () => open(r) },
                                    ]}
                                    menu={paymentMenu(r, actions)}
                                    menuOpen={menuFor === r.reference}
                                    onToggleMenu={() => setMenuFor(menuFor === r.reference ? null : r.reference)}
                                />
                            </tr>
                        ))}
                    </tbody>
                </TableFrame>
            )}
            {page.lastPage > 1 && <Pagination page={page.page} lastPage={page.lastPage} from={page.from} to={page.to} total={page.total} onPage={onPage} />}
        </Block>
    );
}

/** Barre segmentée avec légende (part de chaque composante). */
export function SegmentBar({ segment }: { segment: Segment }) {
    return (
        <Block icon="stacked_bar_chart" title={segment.title} count={segment.sub}>
            <div style={{ display: 'flex', alignItems: 'baseline', gap: 'var(--space-2)', marginBottom: 'var(--space-2)' }}>
                <b style={{ fontSize: 'var(--fs-xl)' }}>{fcfa(segment.total)}</b>
            </div>
            <div style={{ display: 'flex', height: 12, borderRadius: 'var(--radius-pill)', overflow: 'hidden', background: 'var(--c-line-soft)' }}>
                {segment.items
                    .filter((s) => s.value > 0)
                    .map((s) => (
                        <span key={s.label} title={`${s.label} : ${fcfa(s.value)} (${String(s.percent).replace('.', ',')} %)`} style={{ width: `${(s.value / Math.max(1, segment.total)) * 100}%`, background: s.color }} />
                    ))}
            </div>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,220px),1fr))', gap: 'var(--space-2) var(--space-4)', marginTop: 'var(--space-3)' }}>
                {segment.items.map((s) => (
                    <div key={s.label} style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)', fontSize: 'var(--fs-sm)' }}>
                        <span style={{ width: 10, height: 10, borderRadius: 'var(--radius-round)', background: s.color, flex: 'none' }} />
                        <span style={{ flex: 1, color: 'var(--c-ink-2)' }}>{s.label}</span>
                        <b>{fcfa(s.value)}</b>
                        <span style={{ color: 'var(--c-muted)' }}>{String(s.percent).replace('.', ',')} %</span>
                    </div>
                ))}
            </div>
        </Block>
    );
}
