import { PaymentLayout } from '@/components/payments/blocks';
import { fcfa } from '@/components/payments/usePaymentActions';
import Dialog from '@/components/overlays/Dialog';
import { TextCell } from '@/components/list/Table';
import StatusBadge from '@/components/ui/StatusBadge';
import { DataTable } from '@/components/workers/blocks';
import { formatDate } from '@/lib/format';
import type { Badge } from '@/types';
import type { PaymentPageProps } from '@/types/payments';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

type Line = { reference: string | null; worker: string | null; net: number };
type Batch = { reference: string; executedAt: string; author: string; net: number; commission: number; status: Badge; results: (Line & { result: string })[]; blocked: Line[] };

/** Lots de reversements : résultat de chaque opération (réussie, échouée, bloquée). */
export default function PaymentsBatches(props: PaymentPageProps & { batches: Batch[] }) {
    const [open, setOpen] = useState<Batch | null>(null);

    return (
        <>
            <Head title="Lots de reversements" />
            <PaymentLayout
                {...props}
                search={false}
                toolbar={[
                    { label: 'À reverser', icon: 'savings', onClick: () => router.visit('/paiements/a-reverser') },
                    { label: 'Historique des reversements', icon: 'history', onClick: () => router.visit('/paiements/reversements') },
                    { label: 'Lots', icon: 'stacks', variant: 'primary', onClick: () => undefined },
                    { label: 'Préparer un lot', icon: 'add', onClick: () => router.visit('/paiements/a-reverser') },
                ]}
            >
                <DataTable
                    icon="stacks"
                    title="Lots de reversements"
                    count={`${props.batches.length} lot(s)`}
                    minWidth={1100}
                    columns={['Lot', 'Date', 'Auteur', 'Opérations', 'Réussies', 'Échouées', 'Bloquées', 'Net reversé', 'Commissions', 'Statut']}
                    empty={{ icon: 'stacks', title: 'Aucun lot', text: 'Sélectionnez des opérations dans « À reverser » pour créer un lot.' }}
                    rows={props.batches.map((b) => ({
                        key: b.reference,
                        onOpen: () => setOpen(b),
                        cells: [
                            <TextCell key="r" strong>
                                {b.reference}
                            </TextCell>,
                            <TextCell key="d">{`${formatDate(b.executedAt)} ${b.executedAt.slice(11)}`}</TextCell>,
                            <TextCell key="a">{b.author}</TextCell>,
                            <TextCell key="n">{b.results.length}</TextCell>,
                            <TextCell key="s">{b.results.filter((x) => x.result === 'Réussi').length}</TextCell>,
                            <TextCell key="f">{b.results.filter((x) => x.result === 'Échoué').length}</TextCell>,
                            <TextCell key="b">{b.blocked.length}</TextCell>,
                            <TextCell key="net" strong>
                                {fcfa(b.net)}
                            </TextCell>,
                            <TextCell key="c">{fcfa(b.commission)}</TextCell>,
                            <StatusBadge key="st" label={b.status.label} tone={b.status.tone} />,
                        ],
                        actions: [{ icon: 'visibility', label: 'Détail', onClick: () => setOpen(b) }],
                    }))}
                />
            </PaymentLayout>
            {open && (
                <Dialog title={open.reference} body={`Exécuté le ${formatDate(open.executedAt)} par ${open.author} · net reversé ${fcfa(open.net)}`} icon="stacks" okLabel="Fermer" onClose={() => setOpen(null)} onSubmit={() => setOpen(null)}>
                    <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-2)' }}>
                        {[...open.results.map((r) => ({ ...r, badge: r.result })), ...open.blocked.map((r) => ({ ...r, badge: 'Bloquée' }))].map((r) => (
                            <div key={`${r.reference}-${r.badge}`} style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-3)', padding: 'var(--space-2) var(--space-3)', border: '1px solid var(--c-line-soft)', borderRadius: 'var(--radius-lg)' }}>
                                <div style={{ flex: 1 }}>
                                    <div style={{ fontWeight: 'var(--fw-semibold)' }}>{`${r.reference} · ${r.worker ?? ''}`}</div>
                                    <div style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>{r.badge === 'Bloquée' ? 'Bloquée avant exécution' : fcfa(r.net)}</div>
                                </div>
                                <StatusBadge label={r.badge} tone={r.badge === 'Réussi' ? 'g' : r.badge === 'Échoué' ? 'r' : 'n'} />
                            </div>
                        ))}
                    </div>
                </Dialog>
            )}
        </>
    );
}
