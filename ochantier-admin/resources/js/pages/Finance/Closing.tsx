import FormDialog from '@/components/overlays/FormDialog';
import { RelationTable, stack } from '@/components/promoters/RelationTabs';
import Button from '@/components/ui/Button';
import StatusBadge from '@/components/ui/StatusBadge';
import { AlertList, Block, KeyValues } from '@/components/workers/blocks';
import { useAdminUi } from '@/contexts/AdminUiContext';
import { fcfa } from '@/lib/format';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import type { ClosingMonth } from './Closings';

type Figures = {
    inflows: { missions: number; subscriptions: number; total: number; operator_fees: number };
    outflows: { workers: number; commercials: number; refunds: number; total: number };
    net_cash: number;
    commissions: { promoter_earned: number; worker_earned: number; earned: number; collected: number };
    revenue: number;
};

type Closing = ClosingMonth & {
    figures: Figures;
    warnings: string[];
    drift: { label: string; frozen: number; live: number }[];
    actions: { prepare: boolean; close: boolean; reopen: boolean };
};

type Can = { update: boolean; approve: boolean; reopen: boolean; export: boolean };

/** Clôture d’un mois : chiffres, points de vigilance, écart avec la photo figée et décisions. */
export default function FinanceClosing({ closing: c, can }: { closing: Closing; can: Can }) {
    const ui = useAdminUi();
    const [reopening, setReopening] = useState(false);
    const base = `/finance-reporting/clotures/${c.month}`;
    const f = c.figures;
    const closed = c.status.value === 'cloturee';

    return (
        <>
            <Head title={`Clôture ${c.month}`} />

            <div style={{ display: 'flex', justifyContent: 'flex-end', flexWrap: 'wrap', gap: 'var(--space-2)', marginTop: -6 }}>
                {can.export && <Button label="Exporter" icon="download" variant="secondary" onClick={() => (window.location.href = `${base}/export`)} />}
                {can.reopen && c.actions.reopen && <Button label="Rouvrir" icon="lock_open" variant="danger-soft" onClick={() => setReopening(true)} />}
                {can.update && c.actions.prepare && <Button label={c.status.value === 'en_cloture' ? 'Préparer de nouveau' : 'Préparer la clôture'} icon="fact_check" variant="secondary" onClick={() => router.post(`${base}/preparation`, {}, { preserveScroll: true })} />}
                {can.approve && c.actions.close && <Button label="Clôturer le mois" icon="lock" onClick={() => ui.confirm({ tone: 'warning', icon: 'lock', title: `Clôturer ${c.month} ?`, body: 'Les chiffres préparés sont figés. Seule la Direction pourra rouvrir ce mois.', ok: 'Clôturer', onConfirm: () => router.post(`${base}/cloture`, {}, { preserveScroll: true }) })} />}
            </div>

            <div style={stack}>
                {c.warnings.length > 0 && <AlertList alerts={c.warnings.map((text) => ({ tone: 'warning', icon: 'warning', title: 'À vérifier avant de clôturer', text }))} />}
                {c.drift.length > 0 && (
                    <Block icon="difference" title="Changements depuis la clôture">
                        <RelationTable columns={['Chiffre', 'Figé', 'Aujourd’hui', 'Écart']} empty={{ icon: 'difference', title: '', text: '' }} rows={c.drift.map((d) => ({ key: d.label, cells: [d.label, fcfa(d.frozen), fcfa(d.live), fcfa(d.live - d.frozen)] }))} />
                    </Block>
                )}

                <Block icon="lock_clock" title={c.month}>
                    <KeyValues
                        items={[
                            ['Statut', <StatusBadge label={c.status.label} tone={c.status.tone as never} />],
                            ['Chiffres', closed ? `Figés · version ${c.version}` : 'En direct'],
                            ['Préparé par', c.preparedBy],
                            ['Préparé le', c.preparedAt],
                            ['Clôturé par', c.closedBy],
                            ['Clôturé le', c.closedAt],
                            ['Motif de réouverture', c.reopenReason],
                        ]}
                    />
                </Block>

                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,340px),1fr))', gap: 'var(--space-4)' }}>
                    <Block icon="south_west" title="Reçu" count={fcfa(f.inflows.total)}>
                        <KeyValues
                            items={[
                                ['Paiements des missions', fcfa(f.inflows.missions)],
                                ['Abonnements', fcfa(f.inflows.subscriptions)],
                                ['Frais d’opérateur', fcfa(f.inflows.operator_fees)],
                            ]}
                        />
                    </Block>
                    <Block icon="north_east" title="Décaissé" count={fcfa(f.outflows.total)}>
                        <KeyValues
                            items={[
                                ['Reversements aux ouvriers', fcfa(f.outflows.workers)],
                                ['Versements aux commerciaux', fcfa(f.outflows.commercials)],
                                ['Remboursements', fcfa(f.outflows.refunds)],
                            ]}
                        />
                    </Block>
                    <Block icon="trending_up" title="Résultat" count={fcfa(f.revenue)}>
                        <KeyValues
                            items={[
                                ['Commissions acquises', fcfa(f.commissions.earned)],
                                ['Commissions encaissées', fcfa(f.commissions.collected)],
                                ['Solde de trésorerie', fcfa(f.net_cash)],
                            ]}
                        />
                    </Block>
                </div>
            </div>

            {reopening && <FormDialog title={`Rouvrir ${c.month} ?`} body="La photo figée reste au journal. Le mois devra être préparé et clôturé de nouveau." icon="lock_open" tone="danger" okLabel="Rouvrir" url={`${base}/reouverture`} onClose={() => setReopening(false)} fields={[{ name: 'reason', label: 'Motif', type: 'textarea', required: true }]} />}
        </>
    );
}
