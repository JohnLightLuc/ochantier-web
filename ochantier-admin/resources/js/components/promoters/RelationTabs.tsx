import { FilterChip } from '@/components/list/ListToolbar';
import { LocationCell, PersonCell, ProgressCell, RatingCell, RowActions, StackCell, TableFrame, TextCell, ThumbCell, tdStyle, thStyle, type RowAction } from '@/components/list/Table';
import type { MenuItem } from '@/components/ui/Menu';
import StateBlock from '@/components/ui/StateBlock';
import StatusBadge from '@/components/ui/StatusBadge';
import { useAdminUi } from '@/contexts/AdminUiContext';
import { formatNumber } from '@/lib/format';
import type { Promoter, PromoterRelations } from '@/types/promoters';
import { Link, router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';

type Row = { key: string; cells: ReactNode[]; href?: string; actions: RowAction[]; menu?: MenuItem[] };

type TableProps = {
    columns: string[];
    rows: Row[];
    empty: { icon: string; title: string; text: string };
};

/** Tableau d’un onglet de la fiche, avec actions par ligne. */
function RelationTable({ columns, rows, empty }: TableProps) {
    const [menuFor, setMenuFor] = useState<string | null>(null);

    if (rows.length === 0) return <StateBlock kind="nodata" icon={empty.icon} title={empty.title} text={empty.text} />;

    return (
        <TableFrame>
            <thead>
                <tr style={{ background: 'var(--c-surface-3)' }}>
                    {columns.map((c) => (
                        <th key={c} style={thStyle}>
                            {c}
                        </th>
                    ))}
                    <th style={{ ...thStyle, textAlign: 'center' }}>Actions</th>
                </tr>
            </thead>
            <tbody>
                {rows.map((r) => (
                    <tr key={r.key} onClick={r.href ? () => router.visit(r.href!, { preserveScroll: true }) : undefined} className="och-hover-soft" style={{ cursor: r.href ? 'pointer' : 'default', background: 'var(--c-surface)' }}>
                        {r.cells.map((cell, i) => (
                            <td key={i} style={tdStyle}>
                                {cell}
                            </td>
                        ))}
                        <RowActions actions={r.actions} menu={r.menu} menuOpen={menuFor === r.key} onToggleMenu={r.menu ? () => setMenuFor(menuFor === r.key ? null : r.key) : undefined} />
                    </tr>
                ))}
            </tbody>
        </TableFrame>
    );
}

/** Ligne de titre d’un onglet : sous-onglets éventuels, filtre de chantier et compteur. */
function TabBar({ count, chip, onClearChip, children }: { count: string; chip?: string | null; onClearChip?: () => void; children?: ReactNode }) {
    return (
        <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2_5)', flexWrap: 'wrap' }}>
            {children}
            {chip && onClearChip && <FilterChip label={`Chantier : ${chip}`} onClear={onClearChip} />}
            <span style={{ flex: 1, fontSize: 'var(--fs-md)', color: 'var(--c-muted)' }}>{count}</span>
        </div>
    );
}

const plural = (n: number, one: string, many: string) => `${n} ${n > 1 ? many : one}`;

type TabProps = { promoter: Promoter; relations: PromoterRelations; siteFilter: string | null };

/** Retire le filtre de chantier de l’URL courante. */
const clearSiteFilter = () => router.get(window.location.pathname, {}, { preserveScroll: true, preserveState: true, replace: true });

export function SitesTab({ promoter, relations }: TabProps) {
    const ui = useAdminUi();
    const base = `/promoteurs/${promoter.slug}`;
    const editElsewhere = () => ui.toast('L’édition du chantier se fait depuis le module Chantiers.', { tone: 'info' });

    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-3)' }}>
            <TabBar count={plural(relations.sites.length, 'chantier', 'chantiers')} />
            <RelationTable
                columns={['Chantier', 'Localisation', 'Type de construction', 'Période', 'Ouvriers', 'Progression', 'Statut']}
                empty={{ icon: 'construction', title: 'Aucun chantier', text: 'Ce promoteur n’a encore créé aucun chantier.' }}
                rows={relations.sites.map((c) => {
                    const href = `${base}/chantiers/${c.id}`;
                    const site = encodeURIComponent(c.name);
                    return {
                        key: c.id,
                        href,
                        cells: [<ThumbCell value={c.name} />, <LocationCell value={c.location} />, <TextCell>{c.type}</TextCell>, <TextCell>{c.period}</TextCell>, <TextCell>{c.workers}</TextCell>, <ProgressCell value={c.progress} />, <StatusBadge label={c.status} tone={c.statusTone} />],
                        actions: [
                            { icon: 'visibility', label: 'Voir le chantier', onClick: () => router.visit(href) },
                            { icon: 'edit', label: 'Modifier', onClick: editElsewhere },
                        ],
                        menu: [
                            { icon: 'assignment', label: 'Consulter les besoins', onClick: () => router.visit(`${base}/offres?chantier=${site}`) },
                            { icon: 'schedule', label: 'Consulter les pointages', onClick: () => router.visit('/pointages') },
                            { icon: 'payments', label: 'Consulter les paiements', onClick: () => router.visit(`${base}/paiements?chantier=${site}`) },
                        ],
                    };
                })}
            />
        </div>
    );
}

export function OffersTab({ promoter, relations, siteFilter }: TabProps) {
    const base = `/promoteurs/${promoter.slug}`;
    const offers = relations.offers.filter((o) => !siteFilter || o.site === siteFilter);

    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-3)' }}>
            <TabBar count={`${plural(offers.length, 'besoin', 'besoins')} et offres`} chip={siteFilter} onClearChip={clearSiteFilter} />
            <RelationTable
                columns={['Besoin / Offre', 'Chantier', 'Métier', 'Type', 'Candidatures', 'Budget', 'Échéance', 'Statut']}
                empty={siteFilter ? { icon: 'assignment', title: 'Aucun besoin pour ce chantier', text: 'Retirez le filtre pour voir tous les besoins du promoteur.' } : { icon: 'assignment', title: 'Aucun besoin', text: 'Ce promoteur n’a encore publié aucun besoin.' }}
                rows={offers.map((o) => {
                    const href = `${base}/offres/${o.id}`;
                    return {
                        key: o.id,
                        href,
                        cells: [
                            <TextCell strong>{o.name}</TextCell>,
                            <TextCell>{o.site}</TextCell>,
                            <TextCell>{o.trade}</TextCell>,
                            <StatusBadge label={o.type} tone={o.typeTone} />,
                            <TextCell>{plural(o.applications, 'candidat', 'candidats')}</TextCell>,
                            <TextCell>{o.budget}</TextCell>,
                            <TextCell>{o.due}</TextCell>,
                            <StatusBadge label={o.status} tone={o.statusTone} />,
                        ],
                        actions: [{ icon: 'visibility', label: 'Voir l’offre', onClick: () => router.visit(href, { preserveScroll: true }) }],
                    };
                })}
            />
        </div>
    );
}

export function PaymentsTab({ promoter, relations, siteFilter }: TabProps) {
    const base = `/promoteurs/${promoter.slug}`;
    const payments = relations.payments.filter((x) => !siteFilter || x.site === siteFilter);
    const processing = payments.filter((x) => x.status === 'En traitement');

    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-3)' }}>
            <TabBar count={plural(payments.length, 'transaction', 'transactions')} chip={siteFilter} onClearChip={clearSiteFilter} />
            {processing.length > 0 && (
                <StateBlock
                    kind="partial"
                    compact
                    title="Données partielles"
                    text={`${plural(processing.length, 'transaction', 'transactions')} en cours de synchronisation avec l’opérateur (${processing.map((x) => x.method).join(', ')}). Montants et statuts susceptibles d’évoluer.`}
                />
            )}
            <RelationTable
                columns={['Référence', 'Chantier', 'Type de paiement', 'Moyen', 'Montant', 'Commission', 'Date', 'Statut']}
                empty={siteFilter ? { icon: 'receipt_long', title: 'Aucun paiement pour ce chantier', text: 'Retirez le filtre pour voir toutes les transactions.' } : { icon: 'receipt_long', title: 'Aucun paiement', text: 'Aucune transaction n’a encore été enregistrée pour ce promoteur.' }}
                rows={payments.map((x) => {
                    const href = `${base}/paiements/${x.ref}`;
                    return {
                        key: x.ref,
                        href,
                        cells: [
                            <TextCell muted>{x.ref}</TextCell>,
                            <TextCell>{x.site}</TextCell>,
                            <TextCell>{x.type}</TextCell>,
                            <TextCell>{x.method}</TextCell>,
                            <TextCell strong>{formatNumber(x.amount)} FCFA</TextCell>,
                            <TextCell muted={!x.commission}>{x.commission ? `${formatNumber(x.commission)} FCFA` : '—'}</TextCell>,
                            <TextCell>{x.date}</TextCell>,
                            <StatusBadge label={x.status} tone={x.statusTone} />,
                        ],
                        actions: [
                            { icon: 'visibility', label: 'Voir le détail', onClick: () => router.visit(href) },
                            { icon: 'receipt', label: 'Télécharger le reçu', onClick: () => (window.location.href = `${href}/recu`) },
                        ],
                    };
                })}
            />
        </div>
    );
}

export function ReviewsTab({ promoter, relations, disputes }: TabProps & { disputes: boolean }) {
    const base = `/promoteurs/${promoter.slug}`;
    const moderate = (id: string, status: 'Publié' | 'Masqué') => router.patch(`${base}/avis/${id}`, { status }, { preserveScroll: true });
    const subTabs: [string, number, string, boolean][] = [
        ['Avis', relations.reviews.length, `${base}/avis`, !disputes],
        ['Litiges', relations.disputes.length, `${base}/litiges`, disputes],
    ];

    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-3)' }}>
            <TabBar count={disputes ? plural(relations.disputes.length, 'litige', 'litiges') : `${relations.reviews.length} avis`}>
                <div style={{ display: 'flex', padding: 3, borderRadius: 'var(--radius-lg)', background: 'var(--c-surface-3)', border: '1px solid var(--c-line)' }}>
                    {subTabs.map(([label, count, href, on]) => (
                        <Link
                            key={label}
                            href={href}
                            preserveScroll
                            style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-1_5)', height: 'var(--control-xs)', padding: '0 var(--space-4)', borderRadius: 'var(--radius-md)', background: on ? 'var(--c-surface)' : 'transparent', color: on ? 'var(--c-primary)' : 'var(--c-muted)', boxShadow: on ? 'var(--shadow-xs)' : 'none', fontWeight: 'var(--fw-semibold)', textDecoration: 'none' }}
                        >
                            {label}
                            <span style={{ fontSize: 'var(--fs-xs)', color: 'var(--c-muted)' }}>{count}</span>
                        </Link>
                    ))}
                </div>
            </TabBar>

            {disputes ? (
                <RelationTable
                    columns={['Référence', 'Parties concernées', 'Chantier', 'Motif', 'Priorité', 'Ouverture', 'Responsable', 'Statut']}
                    empty={{ icon: 'gavel', title: 'Aucun litige', text: 'Aucun litige n’a été ouvert avec ce promoteur.' }}
                    rows={relations.disputes.map((l) => ({
                        key: l.ref,
                        href: '/avis-litiges',
                        cells: [
                            <TextCell link>{l.ref}</TextCell>,
                            <StackCell main={promoter.name} sub={`↔ ${l.party}`} />,
                            <TextCell>{l.site}</TextCell>,
                            <TextCell>{l.reason}</TextCell>,
                            <StatusBadge label={l.priority} tone={l.priorityTone} />,
                            <TextCell>{l.date}</TextCell>,
                            <TextCell>{l.owner}</TextCell>,
                            <StatusBadge label={l.status} tone={l.statusTone} />,
                        ],
                        actions: [{ icon: 'open_in_new', label: 'Ouvrir dans Avis & litiges', onClick: () => router.visit('/avis-litiges') }],
                    }))}
                />
            ) : (
                <RelationTable
                    columns={['Ouvrier / équipe évalué(e)', 'Chantier', 'Note', 'Commentaire', 'Date', 'Modération']}
                    empty={{ icon: 'reviews', title: 'Aucun avis', text: 'Ce promoteur n’a encore évalué aucun ouvrier ni aucune équipe.' }}
                    rows={relations.reviews.map((a) => ({
                        key: a.id,
                        cells: [
                            <PersonCell name={a.author} sub={a.detail} kind={a.author.startsWith('Équipe') ? 'team' : 'person'} />,
                            <TextCell>{a.site}</TextCell>,
                            <RatingCell value={`${a.rating} / 5`} />,
                            <TextCell>{a.comment}</TextCell>,
                            <TextCell>{a.date}</TextCell>,
                            <StatusBadge label={a.status} tone={a.statusTone} />,
                        ],
                        actions: [
                            { icon: 'check', label: 'Publier', onClick: () => moderate(a.id, 'Publié') },
                            { icon: 'visibility_off', label: 'Masquer', onClick: () => moderate(a.id, 'Masqué') },
                        ],
                    }))}
                />
            )}
        </div>
    );
}
