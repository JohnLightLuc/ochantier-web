import { FilterSelect } from '@/components/list/ListToolbar';
import { RelationTable } from '@/components/promoters/RelationTabs';
import { TextCell } from '@/components/list/Table';
import Button from '@/components/ui/Button';
import Icon from '@/components/ui/Icon';
import StatusBadge from '@/components/ui/StatusBadge';
import { useAdminUi } from '@/contexts/AdminUiContext';
import { formatDate } from '@/lib/format';
import type { SalesRankingProps } from '@/types/sales';
import { Head, router } from '@inertiajs/react';

/** Classement mensuel : rangs sur les ouvriers validés, clôture puis publication. */
export default function SalesRanking({ month, label, closedAt, publishedAt, canClose, canPublish, top, rows, months, can }: SalesRankingProps) {
    const ui = useAdminUi();
    const post = (url: string) => router.post(url, { month }, { preserveScroll: true });

    const close = () =>
        ui.confirm({
            tone: 'warning',
            icon: 'lock',
            title: `Clôturer ${label} ?`,
            body: 'Les rangs seront figés définitivement. Une validation ultérieure ne les changera plus.',
            ok: 'Clôturer',
            onConfirm: () => post('/commerciaux/classement/cloture'),
        });

    const publish = () =>
        ui.confirm({
            icon: 'campaign',
            title: `Publier ${label} ?`,
            body: 'Les commerciaux classés verront ce classement dans l’application et recevront une notification.',
            ok: 'Publier',
            onConfirm: () => post('/commerciaux/classement/publication'),
        });

    const state = publishedAt ? ['Publié', 'g'] : closedAt ? ['Clôturé', 'b'] : ['Provisoire', 'a'];

    return (
        <>
            <Head title={`Classement · ${label}`} />
            <div
                style={{
                    display: 'flex',
                    flexWrap: 'wrap',
                    alignItems: 'center',
                    gap: 'var(--space-2_5)',
                }}
            >
                <div style={{ width: 240 }}>
                    <FilterSelect label="Mois" value={month} options={months} onChange={(mois) => mois && router.get('/commerciaux/classement', { mois })} />
                </div>
                <StatusBadge label={state[0]} tone={state[1] as never} />
                <span style={{ flex: 1 }} />
                {can.export && <Button label="Exporter" icon="download" variant="secondary" onClick={() => (window.location.href = `/commerciaux/classement/export?mois=${month}`)} />}
                {can.validate && canClose && <Button label="Clôturer" icon="lock" variant="primary" onClick={close} />}
                {can.validate && canPublish && <Button label="Publier" icon="campaign" variant="primary" onClick={publish} />}
            </div>

            {top && (
                <section
                    style={{
                        display: 'flex',
                        alignItems: 'center',
                        gap: 'var(--space-3)',
                        background: 'var(--c-surface)',
                        border: '1px solid var(--c-line)',
                        borderRadius: 'var(--radius-2xl)',
                        padding: 'var(--space-5)',
                    }}
                >
                    <Icon name="workspace_premium" size="var(--icon-xl)" color="var(--c-accent-600)" />
                    <div>
                        <div
                            style={{
                                fontSize: 'var(--fs-sm)',
                                color: 'var(--c-muted)',
                            }}
                        >
                            Commercial du mois · {label}
                        </div>
                        <b style={{ fontSize: 'var(--fs-2xl)' }}>{top.name}</b> · {top.validated} ouvrier(s) validé(s)
                    </div>
                </section>
            )}

            <section
                style={{
                    background: 'var(--c-surface)',
                    border: '1px solid var(--c-line)',
                    borderRadius: 'var(--radius-2xl)',
                    padding: 'var(--space-5)',
                    minWidth: 0,
                }}
            >
                <RelationTable
                    columns={['Rang', 'Commercial', 'Matricule', 'Ouvriers validés']}
                    empty={{
                        icon: 'leaderboard',
                        title: 'Aucun commercial classé',
                        text: 'Le classement apparaîtra dès qu’un commercial sera actif.',
                    }}
                    rows={rows.map((r) => ({
                        key: r.commercial_id,
                        cells: [<TextCell strong>{r.rank}</TextCell>, <TextCell strong>{r.name}</TextCell>, <TextCell>{r.employee_number}</TextCell>, <TextCell>{r.validated}</TextCell>],
                        actions: [
                            {
                                icon: 'visibility',
                                label: 'Voir la fiche',
                                onClick: () => router.visit(`/commerciaux/${r.commercial_id}`),
                            },
                        ],
                    }))}
                />
                {closedAt && (
                    <p
                        style={{
                            margin: 'var(--space-3) 0 0',
                            fontSize: 'var(--fs-sm)',
                            color: 'var(--c-muted)',
                        }}
                    >
                        Clôturé le {formatDate(closedAt)}
                        {publishedAt ? ` · publié le ${formatDate(publishedAt)}` : ''}.
                    </p>
                )}
            </section>
        </>
    );
}
