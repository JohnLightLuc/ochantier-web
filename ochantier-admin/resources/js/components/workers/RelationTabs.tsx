import { Bar, Pages, Pills, RelationTable, Timeline, stack, visit } from '@/components/promoters/RelationTabs';
import { RatingCell, StackCell, TextCell } from '@/components/list/Table';
import StatCard from '@/components/ui/StatCard';
import StateBlock from '@/components/ui/StateBlock';
import StatusBadge from '@/components/ui/StatusBadge';
import { plural } from '@/lib/format';
import type { WorkerAssignmentRow, WorkerRelations } from '@/types/workers';
import { Block, KeyValues } from './blocks';
import WorkerPaymentMethod from './WorkerPaymentMethod';

const assignmentCells = (a: WorkerAssignmentRow) => [
    <StackCell main={a.site} sub={a.promoter} strong />,
    <TextCell muted>{a.mission}</TextCell>,
    <TextCell>{a.trade}</TextCell>,
    <TextCell>{a.period}</TextCell>,
    <TextCell>{a.days}</TextCell>,
    <StackCell main={a.rate} sub={`Promoteur : ${a.promoterRate}`} />,
    <StatusBadge label={a.status.label} tone={a.status.tone} />,
];

const ASSIGNMENT_COLUMNS = ['Chantier', 'Mission', 'Poste', 'Période', 'Jours validés / pointés', 'Tarif net / jour', 'Statut'];

function Totals({ items }: { items: { label: string; value: string; tone: never | string; hint: string }[] }) {
    return (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(200px,1fr))', gap: 'var(--space-3)' }}>
            {items.map((t) => (
                <StatCard key={t.label} label={t.label} value={t.value} icon="payments" tone={t.tone as never} hint={t.hint} />
            ))}
        </div>
    );
}

/** Onglets en lecture seule de la fiche ouvrier. */
export default function WorkerRelationTabs({ worker, relations }: { worker: string; relations: WorkerRelations }) {
    switch (relations.kind) {
        case 'availability':
            return (
                <div style={stack}>
                    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,360px),1fr))', gap: 'var(--space-4)' }}>
                        <Block icon="event_available" title="Disponibilité">
                            <KeyValues
                                items={[
                                    ['Statut', relations.status ? <StatusBadge label={relations.status.label} tone={relations.status.tone} /> : '—'],
                                    ['Disponible à partir du', relations.availableFrom ?? '—'],
                                    ['Mission en cours', relations.current ? `${relations.current.site} · ${relations.current.period}` : 'Aucune'],
                                    ['Missions à venir', String(relations.upcoming.length)],
                                    ['Indisponibilités en cours', String(relations.openUnavailabilities)],
                                ]}
                            />
                        </Block>
                    </div>
                    <RelationTable
                        columns={ASSIGNMENT_COLUMNS}
                        empty={{ icon: 'event_available', title: 'Aucune mission prévue', text: 'L’ouvrier n’a aucune mission confirmée ni en cours.' }}
                        rows={[...(relations.current ? [relations.current] : []), ...relations.upcoming].map((a) => ({ key: a.id, cells: assignmentCells(a) }))}
                    />
                    {relations.declarations.length > 0 && (
                        <RelationTable
                            columns={['Disponibilité déclarée', 'Période', 'Source', 'Commentaire']}
                            empty={{ icon: 'event', title: '', text: '' }}
                            rows={relations.declarations.map((d) => ({ key: d.id, cells: [<StatusBadge label={d.status.label} tone={d.status.tone} />, <TextCell>{d.period}</TextCell>, <TextCell>{d.source}</TextCell>, <TextCell muted={!d.comment}>{d.comment ?? '—'}</TextCell>] }))}
                        />
                    )}
                </div>
            );

        case 'assignments':
            return (
                <div style={stack}>
                    <Bar count={plural(relations.pagination.total, 'affectation', 'affectations')}>
                        <Pills items={relations.filters.map((f) => ({ label: f.label, count: f.count, on: f.value === relations.status, onClick: () => visit({ statut: f.value }) }))} />
                    </Bar>
                    <RelationTable
                        columns={ASSIGNMENT_COLUMNS}
                        empty={relations.status ? { icon: 'assignment_ind', title: 'Aucune affectation pour ce statut', text: 'Choisissez « Toutes » pour voir toutes les affectations.' } : { icon: 'assignment_ind', title: 'Aucune affectation', text: 'L’ouvrier n’a encore été affecté à aucun chantier.' }}
                        rows={relations.rows.map((a) => ({ key: a.id, cells: assignmentCells(a) }))}
                    />
                    <Pages pagination={relations.pagination} />
                </div>
            );

        case 'attendance':
            return (
                <div style={stack}>
                    <Totals items={relations.summary} />
                    <Bar count={plural(relations.pagination.total, 'journée', 'journées')}>
                        <Pills items={relations.filters.map((f) => ({ label: f.label, count: f.count, on: f.value === relations.status, onClick: () => visit({ statut: f.value }) }))} />
                    </Bar>
                    <RelationTable
                        columns={['Date', 'Chantier', 'Arrivée', 'Départ', 'Durée', 'Validation', 'Statut']}
                        empty={{ icon: 'schedule', title: 'Aucun pointage', text: 'Aucune journée n’a encore été pointée pour cet ouvrier.' }}
                        rows={relations.rows.map((d) => ({
                            key: d.id,
                            cells: [
                                <TextCell strong>{d.date}</TextCell>,
                                <TextCell>{d.site}</TextCell>,
                                <StackCell main={d.arrival} sub={d.outOfZone ? 'Hors zone' : undefined} color={d.outOfZone ? 'var(--c-danger-fg)' : undefined} />,
                                <TextCell>{d.departure}</TextCell>,
                                <TextCell>{d.duration}</TextCell>,
                                <StackCell main={d.validation} sub={d.observation ?? undefined} />,
                                <StatusBadge label={d.status.label} tone={d.status.tone} />,
                            ],
                        }))}
                    />
                    <Pages pagination={relations.pagination} />
                </div>
            );

        case 'earnings':
            return (
                <div style={stack}>
                    <Totals items={relations.totals} />
                    {relations.paymentMethod && <WorkerPaymentMethod worker={worker} method={relations.paymentMethod} />}
                    <RelationTable
                        columns={['Chantier', 'Mission', 'Période', 'Jours validés', 'Tarif net / jour', 'Gains', 'Versé', 'Restant', 'Statut']}
                        empty={{ icon: 'savings', title: 'Aucun gain', text: 'Les gains apparaissent dès qu’une journée de travail est validée par le promoteur.' }}
                        rows={relations.rows.map((r) => ({
                            key: r.id,
                            cells: [
                                <TextCell strong>{r.site}</TextCell>,
                                <TextCell muted>{r.mission}</TextCell>,
                                <TextCell>{r.period}</TextCell>,
                                <TextCell>{r.days}</TextCell>,
                                <TextCell>{r.rate}</TextCell>,
                                <TextCell strong>{r.earned}</TextCell>,
                                <TextCell>{r.paid}</TextCell>,
                                <TextCell>{r.remaining}</TextCell>,
                                <StatusBadge label={r.status.label} tone={r.status.tone} />,
                            ],
                        }))}
                    />
                    <Block icon="account_balance_wallet" title="Reversements" count={plural(relations.payouts.length, 'reversement', 'reversements')}>
                        {relations.payouts.length === 0 ? (
                            <span style={{ fontSize: 'var(--fs-md)', color: 'var(--c-muted)' }}>Aucun reversement enregistré pour cet ouvrier.</span>
                        ) : (
                            <RelationTable
                                columns={['Référence', 'Chantier', 'Période', 'Brut', 'Commission', 'Net', 'Statut']}
                                empty={{ icon: 'payments', title: '', text: '' }}
                                rows={relations.payouts.map((p) => ({ key: p.id, cells: [<TextCell strong>{p.reference}</TextCell>, <TextCell>{p.site}</TextCell>, <TextCell>{p.period}</TextCell>, <TextCell>{p.gross}</TextCell>, <TextCell>{p.commission}</TextCell>, <TextCell strong>{p.net}</TextCell>, <StatusBadge label={p.status.label} tone={p.status.tone} />] }))}
                            />
                        )}
                    </Block>
                </div>
            );

        case 'ratings':
            return (
                <div style={stack}>
                    <Bar count={`${relations.count} avis publié${relations.count > 1 ? 's' : ''}${relations.hidden ? ` · ${relations.hidden} masqué${relations.hidden > 1 ? 's' : ''}` : ''}`} />
                    {relations.count > 0 && (
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
                    <RelationTable
                        columns={['Note', 'Commentaire', 'Chantier', 'Auteur', 'Date', 'Statut']}
                        empty={{ icon: 'reviews', title: 'Aucun avis', text: 'L’ouvrier n’a encore reçu aucun avis de promoteur.' }}
                        rows={relations.rows.map((r) => ({
                            key: r.id,
                            cells: [
                                <RatingCell value={`${r.score} / 5`} />,
                                <span style={{ display: 'block', maxWidth: 320, whiteSpace: 'normal' }}>{r.comment}</span>,
                                <TextCell>{r.site}</TextCell>,
                                <TextCell>{r.author}</TextCell>,
                                <TextCell>{r.date}</TextCell>,
                                <div>
                                    <StatusBadge label={r.status.label} tone={r.status.tone} />
                                    {r.moderation && <div style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)', marginTop: 2 }}>{r.moderation}</div>}
                                </div>,
                            ],
                        }))}
                    />
                </div>
            );

        case 'replacements':
            return (
                <div style={stack}>
                    <Bar count={plural(relations.unavailabilities.length, 'indisponibilité', 'indisponibilités')} />
                    <RelationTable
                        columns={['Chantier', 'Moment', 'Date', 'Durée estimée', 'Motif', 'Remplacement', 'Statut']}
                        empty={{ icon: 'event_busy', title: 'Aucune indisponibilité', text: 'Aucune indisponibilité n’a été déclarée pour cet ouvrier.' }}
                        rows={relations.unavailabilities.map((u) => ({
                            key: u.id,
                            cells: [
                                <TextCell strong>{u.site}</TextCell>,
                                <TextCell>{u.moment}</TextCell>,
                                <TextCell>{u.date}</TextCell>,
                                <TextCell>{u.duration}</TextCell>,
                                <StackCell main={u.reason} sub={u.urgent ? 'Urgent' : undefined} color={u.urgent ? 'var(--c-danger-fg)' : undefined} />,
                                <TextCell>{u.replacement}</TextCell>,
                                <StatusBadge label={u.status.label} tone={u.status.tone} />,
                            ],
                        }))}
                    />
                    {relations.replacedOthers.length > 0 && (
                        <Block icon="swap_horiz" title="Remplacements effectués par l’ouvrier" count={plural(relations.replacedOthers.length, 'remplacement', 'remplacements')}>
                            <RelationTable
                                columns={['Chantier', 'Ouvrier remplacé', 'Prise de poste', 'Statut']}
                                empty={{ icon: 'swap_horiz', title: '', text: '' }}
                                rows={relations.replacedOthers.map((r) => ({ key: r.id, cells: [<TextCell strong>{r.site}</TextCell>, <TextCell>{r.replaced}</TextCell>, <TextCell>{r.handover}</TextCell>, <StatusBadge label={r.status.label} tone={r.status.tone} />] }))}
                            />
                        </Block>
                    )}
                </div>
            );

        case 'history':
            return relations.rows.length || relations.origin ? (
                <Timeline
                    rows={relations.rows}
                    pagination={relations.pagination}
                    filters={[{ label: 'Tous', value: null }, ...relations.origins]}
                    active={relations.origin}
                    param="origine"
                    emptyText="Aucun événement enregistré pour cet ouvrier."
                />
            ) : (
                <StateBlock kind="empty" icon="history" title="Aucun événement" text="Aucun événement enregistré pour cet ouvrier." />
            );
    }
}
