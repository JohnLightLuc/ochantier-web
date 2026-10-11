import { FilterSelect } from '@/components/list/ListToolbar';
import StateBlock from '@/components/ui/StateBlock';
import { Toolbar, type ToolbarAction } from '@/components/workers/blocks';
import type { ListProps, PaymentFilters } from '@/types/payments';
import { router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { PaymentTable, queryOf, useApply } from './blocks';
import { fcfa, type PaymentActions } from './usePaymentActions';

type Props = ListProps & {
    title: string;
    periodLabel: string;
    filters: PaymentFilters;
    actions: PaymentActions;
    exportView: string;
    selectable?: boolean;
    extraTools?: ToolbarAction[];
};

/** Liste d’opérations : filtres (et « Plus de filtres »), tri, export, sélection et création d’un lot de reversements. */
export default function OperationList({ title, periodLabel, filters, filterDefs, filterCount, sorts, payments, totals, hasData, actions, exportView, selectable = false, extraTools = [] }: Props) {
    const { url } = usePage();
    const apply = useApply(filters);
    const [selected, setSelected] = useState<string[]>([]);
    const more = !!filters.plus;
    const active = Object.entries(filters).filter(([k, v]) => v && !['page', 'periode', 'du', 'au', 'tri', 'plus'].includes(k)).length;
    const chosen = payments.data.filter((r) => selected.includes(r.reference));

    useEffect(() => setSelected([]), [payments]);

    const tools: ToolbarAction[] = [
        ...extraTools,
        { label: more ? 'Moins de filtres' : `Plus de filtres (${filterCount})`, icon: 'tune', onClick: () => apply({ plus: more ? undefined : '1' }, true) },
        { label: `Réinitialiser${active ? ` (${active})` : ''}`, icon: 'filter_alt_off', onClick: () => router.get(url.split('?')[0], filters.periode ? { periode: filters.periode } : {}, { preserveScroll: true }) },
        { label: 'Exporter', icon: 'download', onClick: () => (window.location.href = `/paiements/export/${exportView}?${queryOf(filters)}`) },
        ...(selectable && chosen.length
            ? [
                  { label: `Créer un lot (${chosen.length})`, icon: 'stacks', variant: 'primary' as const, onClick: () => actions.batch(chosen, () => setSelected([])) },
                  { label: 'Exporter la sélection', icon: 'download', onClick: () => (window.location.href = `/paiements/export/operations?refs=${chosen.map((r) => r.reference).join(',')}`) },
                  { label: 'Vider la sélection', icon: 'close', onClick: () => setSelected([]) },
              ]
            : selectable
              ? [{ label: 'Sélectionner la page', icon: 'select_all', onClick: () => setSelected(payments.data.map((r) => r.reference)) }]
              : []),
    ];

    return (
        <>
            <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2)' }}>
                {filterDefs.map((f) => (
                    <FilterSelect key={f.key} label={f.label} value={filters[f.key] ?? ''} options={f.options.map((o) => ({ value: o, label: o }))} onChange={(v) => apply({ [f.key]: v || undefined })} />
                ))}
                <FilterSelect label="Trier : date" value={filters.tri && filters.tri !== 'date' ? filters.tri : ''} options={Object.entries(sorts).filter(([k]) => k !== 'date').map(([value, label]) => ({ value, label: `Trier : ${label.toLowerCase()}` }))} onChange={(v) => apply({ tri: v || undefined }, true)} />
            </div>
            <Toolbar actions={tools} />
            {hasData ? (
                <PaymentTable
                    title={`${title} · ${periodLabel}`}
                    count={`${payments.from}–${payments.to} sur ${payments.total} · brut ${fcfa(totals.gross)} · commissions ${fcfa(totals.com)} · net ouvriers ${fcfa(totals.net)}${chosen.length ? ` · ${chosen.length} sélectionnée(s)` : ''}`}
                    page={payments}
                    onPage={(p) => apply({ page: String(p) }, true)}
                    selected={selectable ? selected : undefined}
                    onToggle={selectable ? (ref) => setSelected((s) => (s.includes(ref) ? s.filter((x) => x !== ref) : [...s, ref])) : undefined}
                    actions={actions}
                    empty={{ title: 'Aucun résultat', text: 'Aucune opération ne correspond aux filtres.' }}
                />
            ) : (
                <StateBlock kind="nodata" icon="info" title="Aucune opération" text="Les opérations apparaîtront après la clôture des premières journées de pointage." />
            )}
        </>
    );
}
