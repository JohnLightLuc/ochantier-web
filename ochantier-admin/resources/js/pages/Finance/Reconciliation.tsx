import FormDialog from '@/components/overlays/FormDialog';
import { RelationTable, stack } from '@/components/promoters/RelationTabs';
import Button from '@/components/ui/Button';
import StatusBadge from '@/components/ui/StatusBadge';
import { Block, KeyValues } from '@/components/workers/blocks';
import { fcfa } from '@/lib/format';
import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import type { ReconciliationRun } from './Reconciliations';

type Item = {
    id: string;
    type: { value: string; label: string };
    status: { value: string; label: string; tone: string };
    reference: string | null;
    transaction: { id: string; reference: string; direction: string } | null;
    provider_amount: number | null;
    platform_amount: number | null;
    resolution: string | null;
    resolved_by: string | null;
    resolved_at: string | null;
};

const amount = (value: number | null) => (value === null ? '—' : fcfa(value));

/** Fiche d’un rapprochement : résultat du relevé et écarts à traiter avec un motif. */
export default function FinanceReconciliation({ run: r, can }: { run: ReconciliationRun & { items: Item[] }; can: { update: boolean } }) {
    const [decision, setDecision] = useState<{ item: Item; action: 'acceptation' | 'resolution' } | null>(null);
    const s = r.summary;

    return (
        <>
            <Head title={`Rapprochement ${r.provider}`} />

            <div style={stack}>
                <Block icon="sync_alt" title={`${r.provider} · ${r.period.from} au ${r.period.to}`}>
                    <KeyValues
                        items={[
                            ['Lignes du relevé', String(s?.file_rows ?? 0)],
                            ['Montant du relevé', fcfa(s?.file_amount ?? 0)],
                            ['Opérations rapprochées', String(s?.matched ?? 0)],
                            ['Montant rapproché', fcfa(s?.matched_amount ?? 0)],
                            ['Écarts relevés', String(s?.discrepancies ?? 0)],
                            ['Écarts ouverts', String(r.openItems)],
                            ['Importé le', r.importedAt],
                            ['Par', r.by],
                        ]}
                    />
                </Block>

                <Block icon="rule" title="Écarts" count={`${r.items.length}`}>
                    <RelationTable
                        columns={['Type', 'Référence', 'Opération', 'Relevé', 'Plateforme', 'Statut', 'Motif', '']}
                        empty={{ icon: 'verified', title: 'Aucun écart', text: 'Toutes les lignes du relevé concordent.' }}
                        rows={r.items.map((i) => ({
                            key: i.id,
                            cells: [
                                i.type.label,
                                i.reference ?? '—',
                                i.transaction ? <Link href={i.transaction.direction === 'encaissement' ? `/paiements/${i.transaction.id}` : '/paiements'}>{i.transaction.reference}</Link> : '—',
                                amount(i.provider_amount),
                                amount(i.platform_amount),
                                <StatusBadge label={i.status.label} tone={i.status.tone as never} />,
                                i.resolution ?? '—',
                                can.update && i.status.value === 'ouvert' ? (
                                    <div style={{ display: 'flex', gap: 'var(--space-1)' }}>
                                        <Button label="Accepter" size="sm" variant="secondary" onClick={() => setDecision({ item: i, action: 'acceptation' })} />
                                        <Button label="Résoudre" size="sm" onClick={() => setDecision({ item: i, action: 'resolution' })} />
                                    </div>
                                ) : (
                                    (i.resolved_by ?? '')
                                ),
                            ],
                        }))}
                    />
                </Block>
            </div>

            {decision && (
                <FormDialog
                    title={decision.action === 'acceptation' ? 'Accepter cet écart ?' : 'Marquer cet écart comme résolu ?'}
                    body={decision.action === 'acceptation' ? 'L’écart est justifié et reste tel quel.' : 'L’écart est corrigé. Cette action ne modifie aucun montant : la correction se fait dans Paiements.'}
                    icon="rule"
                    tone="warning"
                    okLabel="Enregistrer"
                    url={`/finance-reporting/rapprochements/${r.id}/ecarts/${decision.item.id}/${decision.action}`}
                    onClose={() => setDecision(null)}
                    fields={[{ name: 'reason', label: 'Motif', type: 'textarea', required: true }]}
                />
            )}
        </>
    );
}
