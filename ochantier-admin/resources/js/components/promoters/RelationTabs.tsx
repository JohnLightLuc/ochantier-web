import { FilterChip } from '@/components/list/ListToolbar';
import Pagination from '@/components/list/Pagination';
import { LocationCell, PersonCell, RatingCell, RowActions, StackCell, TableFrame, TextCell, ThumbCell, tdStyle, thStyle, type RowAction } from '@/components/list/Table';
import Icon from '@/components/ui/Icon';
import StatCard from '@/components/ui/StatCard';
import StateBlock from '@/components/ui/StateBlock';
import StatusBadge from '@/components/ui/StatusBadge';
import { plural } from '@/lib/format';
import { toneColors } from '@/lib/tone';
import type { Promoter, PromoterActivityRow, PromoterRelations, TabPagination } from '@/types/promoters';
import { Link, router } from '@inertiajs/react';
import type { ReactNode } from 'react';

type Row = { key: string; cells: ReactNode[]; actions?: RowAction[] };

/** Tableau en lecture seule d’un onglet de la fiche. */
function RelationTable({ columns, rows, empty }: { columns: string[]; rows: Row[]; empty: { icon: string; title: string; text: string } }) {
    if (rows.length === 0) return <StateBlock kind="nodata" icon={empty.icon} title={empty.title} text={empty.text} />;
    const withActions = rows.some((r) => r.actions?.length);

    return (
        <TableFrame>
            <thead>
                <tr style={{ background: 'var(--c-surface-3)' }}>
                    {columns.map((c) => (
                        <th key={c} style={thStyle}>
                            {c}
                        </th>
                    ))}
                    {withActions && <th style={{ ...thStyle, textAlign: 'center' }}>Actions</th>}
                </tr>
            </thead>
            <tbody>
                {rows.map((r) => (
                    <tr key={r.key} className="och-hover-soft" style={{ background: 'var(--c-surface)' }}>
                        {r.cells.map((cell, i) => (
                            <td key={i} style={tdStyle}>
                                {cell}
                            </td>
                        ))}
                        {withActions && (r.actions?.length ? <RowActions actions={r.actions} /> : <td style={tdStyle} />)}
                    </tr>
                ))}
            </tbody>
        </TableFrame>
    );
}

/** Change les paramètres de l’URL de l’onglet (filtre, page) sans perdre la position. */
const visit = (params: Record<string, string | null>) => {
    const url = new URL(window.location.href);
    Object.entries(params).forEach(([key, value]) => (value === null ? url.searchParams.delete(key) : url.searchParams.set(key, value)));
    if (!('page' in params)) url.searchParams.delete('page');
    router.get(url.pathname + url.search, {}, { preserveScroll: true, preserveState: true, replace: true });
};

/** Filtres en pastilles (statut, auteur, sous-onglet). */
function Pills({ items }: { items: { label: string; count?: number; on: boolean; onClick: () => void }[] }) {
    return (
        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2)' }}>
            {items.map((item) => (
                <button
                    key={item.label}
                    type="button"
                    aria-pressed={item.on}
                    onClick={item.onClick}
                    style={{ display: 'inline-flex', alignItems: 'center', gap: 'var(--space-1_5)', height: 'var(--control-xs)', padding: '0 var(--space-3)', borderRadius: 'var(--radius-pill)', border: `1px solid ${item.on ? 'var(--c-primary)' : 'var(--c-line-strong)'}`, background: item.on ? 'var(--c-primary)' : 'var(--c-surface)', color: item.on ? 'var(--c-on-dark)' : 'var(--c-ink-2)', fontWeight: 'var(--fw-semibold)', fontSize: 'var(--fs-sm)', cursor: 'pointer' }}
                >
                    {item.label}
                    {item.count !== undefined && <span style={{ opacity: 0.75 }}>{item.count}</span>}
                </button>
            ))}
        </div>
    );
}

function Bar({ count, children }: { count: string; children?: ReactNode }) {
    return (
        <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2_5)', flexWrap: 'wrap' }}>
            {children}
            <span style={{ flex: 1, textAlign: 'right', fontSize: 'var(--fs-md)', color: 'var(--c-muted)' }}>{count}</span>
        </div>
    );
}

function Pages({ pagination }: { pagination: TabPagination }) {
    if (pagination.lastPage <= 1) return null;
    return <Pagination page={pagination.currentPage} lastPage={pagination.lastPage} from={pagination.from} to={pagination.to} total={pagination.total} onPage={(page) => visit({ page: String(page) })} />;
}

const stack = { display: 'flex', flexDirection: 'column', gap: 'var(--space-3)' } as const;

/** Onglets en lecture seule : chantiers, offres, paiements, avis et litiges, activité. */
export default function RelationTabs({ promoter, relations }: { promoter: Promoter; relations: PromoterRelations }) {
    const base = `/promoteurs/${promoter.slug}`;

    switch (relations.kind) {
        case 'sites':
            return (
                <div style={stack}>
                    <Bar count={plural(relations.pagination.total, 'chantier', 'chantiers')}>
                        <Pills items={relations.filters.map((f) => ({ label: f.label, count: f.count, on: f.value === relations.status, onClick: () => visit({ statut: f.value }) }))} />
                    </Bar>
                    <RelationTable
                        columns={['Chantier', 'Localisation', 'Type', 'Période', 'Responsable', 'Offres', 'Statut']}
                        empty={relations.status ? { icon: 'construction', title: 'Aucun chantier pour ce statut', text: 'Choisissez « Tous » pour voir tous les chantiers du promoteur.' } : { icon: 'construction', title: 'Aucun chantier', text: 'Ce promoteur n’a encore créé aucun chantier.' }}
                        rows={relations.rows.map((s) => ({
                            key: s.id,
                            cells: [
                                <div>
                                    <ThumbCell value={s.title} />
                                    <div style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)', marginTop: 2 }}>{s.reference}</div>
                                </div>,
                                <LocationCell value={s.location} />,
                                <TextCell>{s.type}</TextCell>,
                                <StackCell main={s.period} sub={s.schedule ?? undefined} />,
                                <TextCell muted={!s.responsible}>{s.responsible ?? '—'}</TextCell>,
                                <TextCell>{s.offers}</TextCell>,
                                <StatusBadge label={s.status.label} tone={s.status.tone} />,
                            ],
                            actions: [
                                { icon: 'assignment', label: 'Voir les offres du chantier', onClick: () => router.visit(`${base}/offres?chantier=${s.id}`) },
                                { icon: 'payments', label: 'Voir l’échéancier du chantier', onClick: () => router.visit(`${base}/paiements?chantier=${s.id}`) },
                            ],
                        }))}
                    />
                    <Pages pagination={relations.pagination} />
                </div>
            );

        case 'offers':
            return (
                <div style={stack}>
                    <Bar count={plural(relations.pagination.total, 'offre', 'offres')}>{relations.site && <FilterChip label={`Chantier : ${relations.site.title}`} onClear={() => visit({ chantier: null })} />}</Bar>
                    <RelationTable
                        columns={['Offre', 'Chantier', 'Besoins', 'Type', 'Propositions', 'Montant', 'Période', 'Statut']}
                        empty={relations.site ? { icon: 'assignment', title: 'Aucune offre pour ce chantier', text: 'Retirez le filtre pour voir toutes les offres du promoteur.' } : { icon: 'assignment', title: 'Aucune offre', text: 'Ce promoteur n’a encore demandé aucun ouvrier.' }}
                        rows={relations.rows.map((o) => ({
                            key: o.id,
                            cells: [
                                <StackCell main={o.title} sub={o.reference} strong />,
                                <TextCell>{o.site?.title ?? '—'}</TextCell>,
                                <StackCell main={o.needs} sub={plural(o.workers, 'ouvrier', 'ouvriers')} />,
                                <TextCell>{o.type}</TextCell>,
                                <TextCell>{o.proposals}</TextCell>,
                                <TextCell strong>{o.amount}</TextCell>,
                                <TextCell>{o.period}</TextCell>,
                                <StatusBadge label={o.status.label} tone={o.status.tone} />,
                            ],
                        }))}
                    />
                    <Pages pagination={relations.pagination} />
                </div>
            );

        case 'payments':
            return (
                <div style={stack}>
                    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(200px,1fr))', gap: 'var(--space-3)' }}>
                        {relations.totals.map((t) => (
                            <StatCard key={t.label} label={t.label} value={t.value} icon="payments" tone={t.tone} hint={t.hint} />
                        ))}
                    </div>
                    <Bar count={plural(relations.rows.length, 'ligne', 'lignes')}>{relations.site && <FilterChip label={`Chantier : ${relations.site.title}`} onClear={() => visit({ chantier: null })} />}</Bar>
                    <RelationTable
                        columns={['Échéance', 'Chantier', 'Ouvriers', 'Montant', 'Date', 'Moyen', 'Statut']}
                        empty={relations.site ? { icon: 'receipt_long', title: 'Aucun paiement pour ce chantier', text: 'Retirez le filtre pour voir tout l’échéancier.' } : { icon: 'receipt_long', title: 'Aucun paiement', text: 'Aucun acompte ni échéance pour ce promoteur.' }}
                        rows={relations.rows.map((p) => ({
                            key: p.key,
                            cells: [
                                <StackCell main={p.title} sub={p.reference ?? p.kind} strong />,
                                <TextCell>{p.site?.title ?? '—'}</TextCell>,
                                <TextCell muted={!p.who}>{p.who ?? '—'}</TextCell>,
                                <TextCell strong>{p.amount}</TextCell>,
                                <TextCell>{p.paidAt ? `Payé le ${p.paidAt}` : (p.when ?? '—')}</TextCell>,
                                <TextCell muted={!p.method}>{p.method ?? '—'}</TextCell>,
                                <StatusBadge label={p.status.label} tone={p.status.tone} />,
                            ],
                            actions: p.receipt ? [{ icon: 'receipt', label: 'Ouvrir le reçu', onClick: () => window.open(p.receipt!, '_blank', 'noopener') }] : [],
                        }))}
                    />
                </div>
            );

        case 'ratings':
        case 'disputes':
            return <ReviewsTab base={base} relations={relations} />;

        case 'activity':
            return <ActivityTab relations={relations} />;
    }
}

function ReviewsTab({ base, relations }: { base: string; relations: Extract<PromoterRelations, { kind: 'ratings' | 'disputes' }> }) {
    const disputes = relations.kind === 'disputes';
    const subTabs: [string, string, boolean][] = [
        ['Avis', `${base}/avis`, !disputes],
        ['Litiges', `${base}/avis?vue=litiges`, disputes],
    ];

    return (
        <div style={stack}>
            <Bar count={disputes ? plural(relations.pagination.total, 'litige', 'litiges') : `${relations.count} avis publié${relations.count > 1 ? 's' : ''}${relations.hidden ? ` · ${relations.hidden} masqué${relations.hidden > 1 ? 's' : ''}` : ''}`}>
                <div style={{ display: 'flex', padding: 3, borderRadius: 'var(--radius-lg)', background: 'var(--c-surface-3)', border: '1px solid var(--c-line)' }}>
                    {subTabs.map(([label, href, on]) => (
                        <Link
                            key={label}
                            href={href}
                            preserveScroll
                            style={{ display: 'flex', alignItems: 'center', height: 'var(--control-xs)', padding: '0 var(--space-4)', borderRadius: 'var(--radius-md)', background: on ? 'var(--c-surface)' : 'transparent', color: on ? 'var(--c-primary)' : 'var(--c-muted)', boxShadow: on ? 'var(--shadow-xs)' : 'none', fontWeight: 'var(--fw-semibold)', textDecoration: 'none' }}
                        >
                            {label}
                        </Link>
                    ))}
                </div>
            </Bar>

            {relations.kind === 'ratings' && relations.count > 0 && (
                <div style={{ display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: 'var(--space-6)', padding: 'var(--space-4)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-xl)' }}>
                    <div>
                        <div style={{ fontSize: 'var(--fs-3xl)', fontWeight: 'var(--fw-bold)' }}>{relations.average} ★</div>
                        <div style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>Moyenne des avis publiés</div>
                    </div>
                    <div style={{ flex: 1, minWidth: 220, display: 'flex', flexDirection: 'column', gap: 4 }}>
                        {relations.distribution.map((d) => (
                            <div key={d.score} style={{ display: 'grid', gridTemplateColumns: '28px 1fr 32px', alignItems: 'center', gap: 'var(--space-2)', fontSize: 'var(--fs-sm)' }}>
                                <span>{d.score} ★</span>
                                <div style={{ height: 7, borderRadius: 'var(--radius-xs)', background: 'var(--c-line-soft)', overflow: 'hidden' }}>
                                    <div style={{ height: '100%', width: `${d.percent}%`, background: 'var(--c-accent-600)' }} />
                                </div>
                                <span style={{ color: 'var(--c-muted)', textAlign: 'right' }}>{d.count}</span>
                            </div>
                        ))}
                    </div>
                </div>
            )}

            {relations.kind === 'disputes' ? (
                <>
                    <RelationTable
                        columns={['Référence', 'Motif', 'Chantier', 'Ouvert par', 'Urgence', 'Ouverture', 'Responsable', 'Statut']}
                        empty={{ icon: 'gavel', title: 'Aucun litige', text: 'Aucun litige n’a été ouvert sur les chantiers de ce promoteur.' }}
                        rows={relations.rows.map((l) => ({
                            key: l.id,
                            cells: [
                                <TextCell strong>{l.reference}</TextCell>,
                                <StackCell main={l.category} sub={l.description.length > 60 ? `${l.description.slice(0, 60)}…` : l.description} />,
                                <TextCell>{l.site}</TextCell>,
                                <TextCell>{l.openedBy}</TextCell>,
                                l.urgency ? <StatusBadge label={l.urgency.label} tone={l.urgency.tone} /> : <TextCell muted>—</TextCell>,
                                <TextCell>{l.date}</TextCell>,
                                <TextCell muted={l.owner === 'Non assigné'}>{l.owner}</TextCell>,
                                <StatusBadge label={l.status.label} tone={l.status.tone} />,
                            ],
                        }))}
                    />
                    <Pages pagination={relations.pagination} />
                </>
            ) : (
                <RelationTable
                    columns={['Ouvrier / équipe évalué(e)', 'Note', 'Commentaire', 'Auteur', 'Date', 'Statut']}
                    empty={{ icon: 'reviews', title: 'Aucun avis', text: 'Aucun ouvrier ni aucune équipe n’a encore été évalué sur les chantiers de ce promoteur.' }}
                    rows={relations.rows.map((a) => ({
                        key: a.id,
                        cells: [
                            <PersonCell name={a.name} sub={a.subtitle ?? undefined} kind={a.team ? 'team' : 'person'} />,
                            <RatingCell value={`${a.score} / 5`} />,
                            <span style={{ display: 'block', maxWidth: 320, whiteSpace: 'normal' }}>{a.comment}</span>,
                            <TextCell muted={!a.author}>{a.author ?? '—'}</TextCell>,
                            <TextCell>{a.date}</TextCell>,
                            <div>
                                <StatusBadge label={a.status.label} tone={a.status.tone} />
                                {a.moderation && <div style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)', marginTop: 2 }}>{a.moderation}</div>}
                            </div>,
                        ],
                    }))}
                />
            )}
        </div>
    );
}

function ActivityTab({ relations }: { relations: Extract<PromoterRelations, { kind: 'activity' }> }) {
    const groups = relations.rows.reduce<{ day: string; items: PromoterActivityRow[] }[]>((acc, e) => {
        const last = acc[acc.length - 1];
        if (last && last.day === e.day) last.items.push(e);
        else acc.push({ day: e.day, items: [e] });
        return acc;
    }, []);
    const filters: [string, 'admin' | 'promoteur' | null][] = [
        ['Tous', null],
        ['Administrateurs', 'admin'],
        ['Promoteur', 'promoteur'],
    ];

    return (
        <div style={stack}>
            <Bar count={plural(relations.pagination.total, 'événement', 'événements')}>
                <Pills items={filters.map(([label, value]) => ({ label, on: value === relations.author, onClick: () => visit({ auteur: value }) }))} />
            </Bar>

            {groups.map((g) => (
                <div key={g.day}>
                    <div style={{ fontSize: 'var(--fs-sm)', fontWeight: 'var(--fw-bold)', color: 'var(--c-muted)', textTransform: 'uppercase', letterSpacing: '.06em', marginBottom: 'var(--space-2)' }}>{g.day}</div>
                    {g.items.map((e) => (
                        <div key={e.id} style={{ display: 'grid', gridTemplateColumns: '52px 36px 1fr', gap: 'var(--space-3)', alignItems: 'start', padding: 'var(--space-2_5) 0', borderTop: '1px solid var(--c-line-soft)' }}>
                            <span style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)', fontVariantNumeric: 'tabular-nums', paddingTop: 'var(--space-1_5)' }}>{e.time}</span>
                            <span style={{ width: 'var(--control-sm)', height: 'var(--control-sm)', borderRadius: 'var(--radius-round)', display: 'grid', placeItems: 'center', background: toneColors(e.tone).bg }}>
                                <Icon name={e.icon} color={toneColors(e.tone).fg} />
                            </span>
                            <div style={{ minWidth: 0 }}>
                                <div style={{ fontSize: 'var(--fs-base)' }}>
                                    <b>{e.author}</b> {e.action}
                                </div>
                                <div style={{ display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: 'var(--space-2)', marginTop: 'var(--space-1)', fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>
                                    <StatusBadge label={e.role ? `${e.kind.label} · ${e.role}` : e.kind.label} tone={e.kind.tone} />
                                    {e.target && <span>{e.target}</span>}
                                </div>
                            </div>
                        </div>
                    ))}
                </div>
            ))}

            {groups.length === 0 && <StateBlock kind="empty" icon="history" title="Aucun événement" text={relations.author ? 'Aucun événement pour ce filtre.' : 'Aucune action enregistrée pour ce promoteur.'} />}
            <Pages pagination={relations.pagination} />
        </div>
    );
}

