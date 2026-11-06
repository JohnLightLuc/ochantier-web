import { Bar, Pages, Pills, RelationTable, Timeline, stack, visit } from '@/components/promoters/RelationTabs';
import { FilterSelect } from '@/components/list/ListToolbar';
import { ProgressCell, StackCell, TextCell } from '@/components/list/Table';
import PayoutMethod from '@/components/payments/PayoutMethod';
import StatusBadge from '@/components/ui/StatusBadge';
import { Block, Chips, TileGrid } from '@/components/workers/blocks';
import { formatDate } from '@/lib/format';
import type { SalesAgent, SalesCan, SalesFieldOptions, SalesRelations } from '@/types/sales';
import { router } from '@inertiajs/react';
import { fcfa, type SalesActions } from './useSalesActions';

type Props = {
    agent: SalesAgent;
    relations: SalesRelations;
    options: SalesFieldOptions | null;
    can: SalesCan;
    actions: SalesActions;
};

const day = (value: string | null) => (value ? formatDate(value) : '—');

/** Mois proposés pour un objectif : le mois en cours et les cinq suivants. */
function nextMonths(current: string) {
    const [year, month] = current.split('-').map(Number);

    return Array.from({ length: 6 }, (_, i) => {
        const date = new Date(year, month - 1 + i, 1);
        const label = date.toLocaleDateString('fr-FR', {
            month: 'long',
            year: 'numeric',
        });

        return {
            value: `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`,
            label: label.charAt(0).toUpperCase() + label.slice(1),
        };
    });
}

/** Onglets terrain de la fiche commercial : ouvriers suivis, prospects, zones et objectifs. */
export default function SalesRelationTabs({ agent: a, relations, options, can, actions }: Props) {
    const editable = a.status.value !== 'termine';

    if (relations.kind === 'workers') {
        const colleagues = options?.colleagues ?? [];

        return (
            <div style={stack}>
                <Bar count={`${relations.pagination.total} ouvrier(s)`}>
                    <Pills
                        items={relations.filters.map((f) => ({
                            label: f.label,
                            count: f.count,
                            on: f.value === relations.status,
                            onClick: () => visit({ statut: f.value }),
                        }))}
                    />
                </Bar>
                {can.update && relations.pagination.total > 0 && (
                    <div>
                        <button
                            type="button"
                            onClick={() => actions.transfer(a, colleagues)}
                            style={{
                                border: 0,
                                background: 'transparent',
                                padding: 0,
                                color: 'var(--c-primary)',
                                fontWeight: 'var(--fw-semibold)',
                                cursor: 'pointer',
                            }}
                        >
                            Transférer tout le portefeuille
                        </button>
                    </div>
                )}
                <RelationTable
                    columns={['Ouvrier', 'Métier', 'Commune', 'Statut', 'Transmis le']}
                    empty={{
                        icon: 'engineering',
                        title: 'Aucun ouvrier suivi',
                        text: 'Les ouvriers transmis par ce commercial apparaîtront ici.',
                    }}
                    rows={relations.rows.map((w) => ({
                        key: w.id,
                        cells: [<StackCell main={w.name} sub={w.code} strong />, <TextCell muted={!w.trade}>{w.trade ?? '—'}</TextCell>, <TextCell muted={!w.commune}>{w.commune ?? '—'}</TextCell>, <StatusBadge label={w.status.label} tone={w.status.tone} />, <TextCell>{day(w.submittedAt)}</TextCell>],
                        actions: [
                            {
                                icon: 'visibility',
                                label: 'Voir la fiche',
                                onClick: () => router.visit(`/ouvriers/${w.id}`),
                            },
                            ...(can.update
                                ? [
                                      {
                                          icon: 'move_down',
                                          label: 'Transférer',
                                          onClick: () =>
                                              actions.transfer(a, colleagues, {
                                                  id: w.id,
                                                  name: w.name,
                                              }),
                                      },
                                  ]
                                : []),
                        ],
                    }))}
                />
                <Pages pagination={relations.pagination} />
                {relations.transfers.length > 0 && (
                    <Block icon="swap_horiz" title="Derniers transferts">
                        <RelationTable
                            columns={['Date', 'Ouvrier', 'De', 'Vers', 'Motif', 'Par']}
                            empty={{ icon: 'swap_horiz', title: '', text: '' }}
                            rows={relations.transfers.map((t) => ({
                                key: t.id,
                                cells: [<TextCell>{day(t.at)}</TextCell>, <TextCell strong>{t.worker.full_name}</TextCell>, <TextCell>{t.from ?? '—'}</TextCell>, <TextCell>{t.to ?? '—'}</TextCell>, <TextCell>{t.reason}</TextCell>, <TextCell>{t.by}</TextCell>],
                            }))}
                        />
                    </Block>
                )}
            </div>
        );
    }

    if (relations.kind === 'prospects') {
        return (
            <div style={stack}>
                <Bar count={`${relations.pagination.total} prospect(s)`}>
                    <Pills
                        items={relations.filters.map((f) => ({
                            label: f.label,
                            count: f.count,
                            on: f.value === relations.status,
                            onClick: () => visit({ statut: f.value }),
                        }))}
                    />
                </Bar>
                <RelationTable
                    columns={['Prospect', 'Métier pressenti', 'Commune', 'Statut', 'Avancement', 'Dernière relance', 'Ajouté le']}
                    empty={{
                        icon: 'person_search',
                        title: 'Aucun prospect',
                        text: 'Les prospects saisis dans l’application apparaîtront ici.',
                    }}
                    rows={relations.rows.map((p) => ({
                        key: p.id,
                        cells: [
                            <StackCell main={p.name} sub={p.phone ?? undefined} strong />,
                            <TextCell muted={!p.trade}>{p.trade ?? '—'}</TextCell>,
                            <TextCell muted={!p.commune}>{p.commune ?? '—'}</TextCell>,
                            <StatusBadge label={p.status.label} tone={p.status.tone} />,
                            <ProgressCell value={p.percent} />,
                            <TextCell muted={!p.lastFollowupAt}>{day(p.lastFollowupAt)}</TextCell>,
                            <TextCell>{day(p.createdAt)}</TextCell>,
                        ],
                    }))}
                />
                <Pages pagination={relations.pagination} />
            </div>
        );
    }

    if (relations.kind === 'zones') {
        const secondary = a.zoneList.filter((z) => !z.primary);

        return (
            <div style={stack}>
                <Block
                    icon="location_on"
                    title="Zones attribuées"
                    action={
                        can.update && editable && options
                            ? {
                                  label: 'Attribuer',
                                  icon: 'add',
                                  onClick: () => actions.addZone(a, options.communes),
                              }
                            : null
                    }
                >
                    <div
                        style={{
                            display: 'flex',
                            flexDirection: 'column',
                            gap: 'var(--space-3)',
                        }}
                    >
                        <div>
                            <b>Zone principale : </b>
                            {a.zone ?? 'Aucune'}
                        </div>
                        <Chips
                            values={secondary.map((z) => z.name)}
                            empty="Aucune zone secondaire."
                            onRemove={
                                can.update && editable
                                    ? (name) =>
                                          actions.removeZone(
                                              a,
                                              secondary.find((z) => z.name === name)!,
                                          )
                                    : undefined
                            }
                        />
                    </div>
                </Block>
                <Block icon="edit_location_alt" title="Demandes de changement">
                    <RelationTable
                        columns={['Reçue le', 'Communes demandées', 'Statut', 'Décision']}
                        empty={{
                            icon: 'edit_location_alt',
                            title: 'Aucune demande',
                            text: 'Les demandes envoyées depuis l’application apparaîtront ici.',
                        }}
                        rows={relations.requests.map((r) => ({
                            key: r.id,
                            cells: [<TextCell>{day(r.createdAt)}</TextCell>, <TextCell>{r.communes.map((c) => c.name).join(', ')}</TextCell>, <StatusBadge label={r.status.label} tone={r.status.tone} />, <StackCell main={r.comment ?? '—'} sub={r.decidedBy ? `${r.decidedBy} · ${day(r.decidedAt)}` : undefined} />],
                            actions:
                                can.validate && r.status.value === 'en_attente'
                                    ? [
                                          ...(editable
                                              ? [
                                                    {
                                                        icon: 'check_circle',
                                                        label: 'Accepter',
                                                        onClick: () => actions.acceptZoneRequest(a, r),
                                                    },
                                                ]
                                              : []),
                                          {
                                              icon: 'cancel',
                                              label: 'Refuser',
                                              onClick: () => actions.refuseZoneRequest(a, r),
                                          },
                                      ]
                                    : [],
                        }))}
                    />
                </Block>
            </div>
        );
    }

    if (relations.kind === 'history') {
        return <Timeline rows={relations.rows} pagination={relations.pagination} filters={[]} active={null} param="origine" emptyText="Aucune action enregistrée sur cette fiche." />;
    }

    if (relations.kind === 'earnings') {
        return (
            <div style={stack}>
                <div style={{ maxWidth: 260 }}>
                    <FilterSelect label="Mois" value={relations.month} options={relations.months} onChange={(mois) => visit({ mois: mois || null })} />
                </div>
                <TileGrid
                    tiles={[
                        {
                            label: 'Commissions confirmées',
                            value: fcfa(relations.totals.confirmed),
                            icon: 'verified',
                            tone: 'success',
                        },
                        {
                            label: 'Déjà versées',
                            value: fcfa(relations.totals.paid),
                            icon: 'account_balance_wallet',
                            tone: 'info',
                        },
                        {
                            label: 'En attente de validation',
                            value: fcfa(relations.totals.pending),
                            icon: 'hourglass_top',
                            tone: 'warning',
                        },
                    ]}
                />
                <Block icon="payments" title={`Commissions · ${relations.monthLabel}`}>
                    <RelationTable
                        columns={['Ouvrier', 'Date', 'Montant', 'Statut']}
                        empty={{
                            icon: 'payments',
                            title: 'Aucune commission',
                            text: 'Aucune commission sur ce mois.',
                        }}
                        rows={relations.history.map((e) => ({
                            key: e.id,
                            cells: [<TextCell strong>{e.worker}</TextCell>, <TextCell muted={!e.date}>{e.date ?? '—'}</TextCell>, <TextCell>{fcfa(e.amount)}</TextCell>, <StatusBadge label={e.status.label} tone={e.status.tone} />],
                        }))}
                    />
                </Block>
                <Block
                    icon="tune"
                    title="Primes et retenues"
                    action={
                        can.proposeAdjustment
                            ? {
                                  label: 'Proposer',
                                  icon: 'add',
                                  onClick: () => actions.adjustment(a),
                              }
                            : null
                    }
                >
                    <RelationTable
                        columns={['Date', 'Nature', 'Montant', 'Motif', 'Proposée par', 'Statut']}
                        empty={{
                            icon: 'tune',
                            title: 'Aucun ajustement',
                            text: 'Les primes et retenues apparaîtront ici.',
                        }}
                        rows={relations.adjustments.map((x) => ({
                            key: x.id,
                            cells: [<TextCell>{day(x.grantedOn)}</TextCell>, <TextCell strong>{x.kind.label}</TextCell>, <TextCell>{fcfa(x.amount)}</TextCell>, <TextCell>{x.reason}</TextCell>, <TextCell>{x.proposedBy}</TextCell>, <StatusBadge label={x.status.label} tone={x.status.tone} />],
                            actions:
                                can.approveAdjustment && x.canDecide
                                    ? [
                                          {
                                              icon: 'check_circle',
                                              label: 'Approuver',
                                              onClick: () => actions.approveAdjustment(a, x),
                                          },
                                          {
                                              icon: 'cancel',
                                              label: 'Refuser',
                                              onClick: () => actions.refuseAdjustment(a, x),
                                          },
                                      ]
                                    : [],
                        }))}
                    />
                </Block>
                {relations.paymentMethod && <PayoutMethod base={`/paiements/commerciaux/${a.id}/numero`} method={relations.paymentMethod} empty="Aucun : ce commercial ne peut pas recevoir de versement." />}
                <Block icon="receipt_long" title="Versements">
                    <RelationTable
                        columns={['Référence', 'Période', 'Montant', 'Statut', 'Payé le']}
                        empty={{
                            icon: 'receipt_long',
                            title: 'Aucun versement',
                            text: 'Les versements se préparent dans Paiements, une fois le mois terminé.',
                        }}
                        rows={relations.payouts.map((p) => ({
                            key: p.id,
                            cells: [<TextCell strong>{p.reference}</TextCell>, <TextCell>{`${day(p.start)} → ${day(p.end)}`}</TextCell>, <TextCell>{fcfa(p.amount)}</TextCell>, <StatusBadge label={p.status.label} tone={p.status.tone} />, <TextCell muted={!p.paidAt}>{day(p.paidAt)}</TextCell>],
                        }))}
                    />
                </Block>
            </div>
        );
    }

    const months = nextMonths(relations.currentMonth);

    return (
        <div style={stack}>
            <Block
                icon="flag"
                title="Objectifs mensuels"
                count="Ouvriers validés"
                action={
                    can.update && editable
                        ? {
                              label: 'Fixer un objectif',
                              icon: 'add',
                              onClick: () => actions.objective(a, months),
                          }
                        : null
                }
            >
                <RelationTable
                    columns={['Mois', 'Objectif', 'Réalisé', 'Avancement', 'Commentaire']}
                    empty={{
                        icon: 'flag',
                        title: 'Aucun objectif',
                        text: 'Fixez un objectif pour qu’il apparaisse dans l’application du commercial.',
                    }}
                    rows={relations.rows.map((o) => ({
                        key: o.month,
                        cells: [<TextCell strong>{o.label}</TextCell>, <TextCell>{o.target}</TextCell>, <TextCell>{o.achieved}</TextCell>, <ProgressCell value={o.percent} />, <TextCell muted={!o.comment}>{o.comment ?? '—'}</TextCell>],
                        actions:
                            can.update && editable && o.canEdit
                                ? [
                                      {
                                          icon: 'edit',
                                          label: 'Modifier',
                                          onClick: () => actions.objective(a, months, o),
                                      },
                                  ]
                                : [],
                    }))}
                />
            </Block>
            <Block
                icon="rate_review"
                title="Appréciations"
                count="Internes"
                action={
                    can.update
                        ? {
                              label: 'Ajouter',
                              icon: 'add',
                              onClick: () => actions.appreciation(a),
                          }
                        : null
                }
            >
                <RelationTable
                    columns={['Date', 'Appréciation', 'Auteur']}
                    empty={{
                        icon: 'rate_review',
                        title: 'Aucune appréciation',
                        text: 'Ces notes ne sont jamais montrées au commercial.',
                    }}
                    rows={relations.appreciations.map((x) => ({
                        key: x.id,
                        cells: [<TextCell>{day(x.date)}</TextCell>, <TextCell>{x.body}</TextCell>, <TextCell>{x.author}</TextCell>],
                    }))}
                />
            </Block>
        </div>
    );
}
