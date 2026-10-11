import { PersonCell, ProgressCell, StackCell, TextCell, type RowAction } from '@/components/list/Table';
import { useNeedActions } from '@/components/needs/useNeedActions';
import Icon from '@/components/ui/Icon';
import type { MenuItem } from '@/components/ui/Menu';
import StatusBadge from '@/components/ui/StatusBadge';
import { DataTable, type ToolbarAction } from '@/components/workers/blocks';
import { formatRating } from '@/lib/format';
import type { AnyTone, Badge } from '@/types';
import type { Candidate, MissingRow } from '@/types/assignments';
import { router } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { fcfa } from './useAssignmentActions';

const years = (n: number) => `${n} an${n > 1 ? 's' : ''}`;

const openWorker = (slug: string | null) => (slug ? () => router.visit(`/ouvriers/${slug}`) : undefined);

type CandidateTableProps = {
    icon: string;
    title: string;
    count: string;
    rows: Candidate[];
    tools?: ToolbarAction[];
    /** Colonne « Poste » (propositions sur plusieurs métiers). */
    withTrade?: boolean;
    /** Expérience comparée à celle du profil remplacé. */
    compareTo?: number;
    action: (c: Candidate) => RowAction;
    menu: (c: Candidate) => MenuItem[];
    empty: { title: string; text: string };
};

/** Profils proposés par le Matching : propositions automatiques ou remplaçants, classés par score. */
export function CandidateTable({ icon, title, count, rows, tools, withTrade, compareTo, action, menu, empty }: CandidateTableProps) {
    return (
        <DataTable
            icon={icon}
            title={title}
            count={count}
            tools={tools}
            minWidth={withTrade ? 1150 : 1100}
            columns={['Ouvrier', ...(withTrade ? ['Poste'] : []), 'Commune', 'Expérience', 'Note', 'Coût journalier', 'Disponibilité', 'Score', 'Correspondance']}
            empty={{ icon: 'person_search', ...empty }}
            rows={rows.map((c) => ({
                key: `${c.offerId ?? ''}-${c.key}`,
                onOpen: openWorker(c.workerSlug),
                cells: [
                    <PersonCell key="p" name={c.name} sub={c.workerSlug ? 'Fiche ouvrier' : (c.code ?? c.trade)} />,
                    ...(withTrade ? [<TextCell key="t">{c.trade}</TextCell>] : []),
                    <TextCell key="c">{c.commune + (c.km ? ` · ${String(c.km).replace('.', ',')} km` : '')}</TextCell>,
                    <TextCell key="e">{years(c.experience) + (compareTo != null ? ` (vs ${compareTo})` : '')}</TextCell>,
                    <TextCell key="n">{formatRating(c.rating)} / 5</TextCell>,
                    <TextCell key="f">{fcfa(c.cost)}</TextCell>,
                    <TextCell key="d">{c.availability}</TextCell>,
                    <ProgressCell key="s" value={c.score} />,
                    <StatusBadge key="l" label={c.level.label} tone={c.level.tone} />,
                ],
                actions: [action(c)],
                menu: menu(c),
            }))}
        />
    );
}

/** Postes et ouvriers manquants : besoin initial et besoins ajoutés pendant l’exécution du chantier. */
export function MissingTable({ rows, refreshedAt, open }: { rows: MissingRow[]; refreshedAt?: string; open: boolean }) {
    const needActions = useNeedActions();
    const missing = rows.reduce((n, r) => n + r.rest, 0);
    const children = new Set(rows.filter((r) => r.child).map((r) => r.needSlug)).size;
    const follow = (slug: string) => router.visit(`/affectations/besoins/${slug}`);

    return (
        <>
            <DataTable
                icon="person_search"
                title="Postes et ouvriers manquants"
                count={`${missing ? `${missing} ouvrier(s) à rechercher` : 'Aucun ouvrier manquant'}${children ? ` · ${children} besoin(s) ajouté(s) en cours d’exécution` : ''} · actualisé automatiquement${refreshedAt ? ` (${refreshedAt})` : ''}`}
                minWidth={1000}
                columns={['Poste', 'Origine', 'Recherchés', 'Sélectionnés', 'Validés', 'Manquants', 'Couverture']}
                empty={{ icon: 'person_search', title: 'Aucun poste manquant', text: 'Les besoins ajoutés par le promoteur pendant l’exécution du chantier apparaîtront ici.' }}
                rows={rows.map((r) => ({
                    key: r.key,
                    onOpen: r.child ? () => (r.selected ? follow(r.needSlug) : router.visit(`/besoins/${r.needSlug}`)) : undefined,
                    cells: [
                        <StackCell key="p" main={r.trade} sub={r.sub} strong />,
                        <StatusBadge key="o" label={r.child ? 'Ajout en cours d’exécution' : 'Besoin initial'} tone={r.child ? 'p' : 'n'} />,
                        <TextCell key="q">{r.quantity}</TextCell>,
                        <TextCell key="s">{r.selected}</TextCell>,
                        <TextCell key="v">{r.validated}</TextCell>,
                        <TextCell key="m" strong={r.rest > 0} muted={!r.rest}>
                            {r.rest || '—'}
                        </TextCell>,
                        <ProgressCell key="c" value={Math.min(100, r.percent)} />,
                    ],
                    actions:
                        open && r.rest
                            ? [
                                  ...(r.matchOffer ? [{ icon: 'join_inner', label: 'Lancer le matching', onClick: () => router.visit(`/matching/besoins/${r.matchOffer}`) }] : []),
                                  ...(r.expired ? [{ icon: 'autorenew', label: 'Actualiser le besoin', onClick: () => needActions.refreshOffer(r.actionRef, r.expired!) }] : []),
                              ]
                            : [],
                    menu: r.child
                        ? [
                              { icon: 'assignment', label: `Voir le besoin ${r.needReference}`, onClick: () => router.visit(`/besoins/${r.needSlug}`) },
                              ...(r.selected ? [{ icon: 'event_available', label: 'Suivre ses ouvriers', onClick: () => follow(r.needSlug) }] : []),
                          ]
                        : [],
                }))}
            />
            {needActions.dialog}
        </>
    );
}

export type CardItem = { key: string | number; icon: string; kicker: string; title: string; sub?: string | null; badge: Badge };

/** Liste de cartes d’un panneau (progression, historique). */
export function CardList({ items, empty = 'Aucun événement.' }: { items: CardItem[]; empty?: string }) {
    if (!items.length) return <span style={{ fontSize: 'var(--fs-md)', color: 'var(--c-muted)' }}>{empty}</span>;

    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-2)' }}>
            {items.map((c) => (
                <div key={c.key} style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-3)', padding: 'var(--space-2_5) var(--space-3)', border: '1px solid var(--c-line-soft)', borderRadius: 'var(--radius-lg)' }}>
                    <Icon name={c.icon} color="var(--c-primary-600)" />
                    <div style={{ flex: 1, minWidth: 0 }}>
                        <div style={{ fontSize: 'var(--fs-xs)', color: 'var(--c-muted)' }}>{c.kicker}</div>
                        <div style={{ fontWeight: 'var(--fw-semibold)' }}>{c.title}</div>
                        {c.sub && <div style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)', overflowWrap: 'anywhere' }}>{c.sub}</div>}
                    </div>
                    <StatusBadge label={c.badge.label} tone={c.badge.tone as AnyTone} />
                </div>
            ))}
        </div>
    );
}

/** Deux panneaux côte à côte sous les tableaux. */
export function Panels({ children }: { children: ReactNode }) {
    return <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,420px),1fr))', gap: 'var(--space-4)' }}>{children}</div>;
}
