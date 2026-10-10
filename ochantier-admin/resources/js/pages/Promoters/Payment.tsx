import Button from '@/components/ui/Button';
import Icon from '@/components/ui/Icon';
import Panel, { InfoRow } from '@/components/ui/Panel';
import StateBlock from '@/components/ui/StateBlock';
import StatusBadge from '@/components/ui/StatusBadge';
import { useAdminUi } from '@/contexts/AdminUiContext';
import { formatNumber } from '@/lib/format';
import type { DemoPayment } from '@/types/promoters';
import { Head, router } from '@inertiajs/react';

type Props = {
    promoter: { slug: string; name: string; representative: string; phone: string };
    payment: DemoPayment;
    split: { commission: number; fee: number; net: number };
    history: { label: string; when: string; icon: string; color: string }[];
};

/** Détail d’une transaction : parties, répartition financière et historique des statuts. */
export default function PromoterPayment({ promoter, payment: x, split, history }: Props) {
    const ui = useAdminUi();
    const base = `/promoteurs/${promoter.slug}`;
    const url = `${base}/paiements/${x.ref}`;
    const money = (n: number) => `${formatNumber(n)} FCFA`;
    const refund = x.type === 'Remboursement';
    const toEscrow = x.beneficiary === 'O’CHANTIER';

    const authorize = () =>
        ui.confirm({
            tone: 'success',
            icon: 'verified',
            title: 'Autoriser ce paiement ?',
            body: `${money(x.amount)} seront versés à ${x.beneficiary} via ${x.method}.`,
            ok: 'Autoriser',
            onConfirm: () => router.post(`${url}/autorisation`, {}, { preserveScroll: true }),
        });

    const splitRows: [string, string, string, number][] = [
        ['Montant brut', money(x.amount), 'transparent', 400],
        ['Commission O’CHANTIER (8 %)', split.commission ? `− ${money(split.commission)}` : '—', 'var(--c-accent-600)', 400],
        ['Frais opérateur (1 %)', split.fee ? `− ${money(split.fee)}` : '—', 'var(--c-info)', 400],
        ['Net versé au bénéficiaire', money(split.net), 'var(--c-primary-500)', 700],
    ];

    return (
        <>
            <Head title={`Paiement ${x.ref}`} />
            <div>
                <Button label={`Retour aux paiements de ${promoter.name}`} icon="arrow_back" variant="ghost" onClick={() => router.visit(`${base}/paiements`)} />
            </div>

            <section style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-5)', alignItems: 'center', background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-5)' }}>
                <span style={{ width: 'var(--control-lg)', height: 'var(--control-lg)', flex: 'none', borderRadius: 'var(--radius-xl)', display: 'grid', placeItems: 'center', background: 'var(--c-primary-100)' }}>
                    <Icon name="receipt_long" size="var(--icon-lg)" color="var(--c-primary-600)" />
                </span>
                <div style={{ flex: '1 1 300px', minWidth: 0 }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2_5)', flexWrap: 'wrap' }}>
                        <h2 style={{ margin: 0, fontSize: 'var(--fs-3xl)', fontVariantNumeric: 'tabular-nums' }}>{x.ref}</h2>
                        <StatusBadge label={x.status} tone={x.statusTone} />
                    </div>
                    <div style={{ marginTop: 'var(--space-1)', fontSize: 'var(--fs-md)', color: 'var(--c-muted)' }}>
                        {x.type} · {x.method} · {x.date} · {x.site}
                    </div>
                </div>
                <div style={{ textAlign: 'right' }}>
                    <div style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>Montant</div>
                    <div style={{ fontSize: 'var(--fs-4xl)', fontWeight: 'var(--fw-bold)', whiteSpace: 'nowrap', fontVariantNumeric: 'tabular-nums' }}>
                        {formatNumber(x.amount)} <span style={{ fontSize: 'var(--fs-lg)' }}>FCFA</span>
                    </div>
                </div>
                <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2)', width: '100%', justifyContent: 'flex-end' }}>
                    {x.status === 'Échoué' && <Button label="Relancer le paiement" icon="refresh" variant="secondary" onClick={() => router.post(`${url}/relance`, {}, { preserveScroll: true })} />}
                    {x.status === 'À autoriser' && <Button label="Autoriser le paiement" icon="verified" variant="accent" onClick={authorize} />}
                    <Button label="Télécharger le reçu" icon="download" variant="primary" onClick={() => (window.location.href = `${url}/recu`)} />
                </div>
            </section>

            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,320px),1fr))', gap: 'var(--space-4)' }}>
                <Panel variant="section" icon="north_east" title="Payeur">
                    <InfoRow icon="domain" label="Payeur" value={promoter.name} labelWidth={120} />
                    <InfoRow icon="person" label="Ordonnateur" value={promoter.representative} labelWidth={120} />
                    <InfoRow icon="call" label="Téléphone" value={promoter.phone} labelWidth={120} />
                    <InfoRow icon="account_balance_wallet" label="Compte débité" value={x.method === 'Virement' ? 'SGBCI •••• 4471' : `${x.method} •••• 1063`} labelWidth={120} />
                </Panel>

                <Panel variant="section" icon="south_west" title="Bénéficiaire">
                    <InfoRow icon="person" label="Bénéficiaire" value={x.beneficiary} labelWidth={120} />
                    <InfoRow icon="construction" label="Chantier" value={x.site} labelWidth={120} />
                    <InfoRow icon="category" label="Nature" value={x.type} labelWidth={120} />
                    <InfoRow icon="account_balance_wallet" label="Compte crédité" value={toEscrow ? 'Compte séquestre O’CHANTIER' : x.method === 'Virement' ? 'Ecobank •••• 2290' : `${x.method} •••• 5518`} labelWidth={120} />
                </Panel>

                <Panel variant="section" icon="pie_chart" title="Répartition financière">
                    {refund ? (
                        <StateBlock kind="forbidden" compact title="Accès restreint" text="La répartition des remboursements est réservée au profil Finance." />
                    ) : (
                        <>
                            <div style={{ display: 'flex', height: 12, borderRadius: 'var(--radius-pill)', overflow: 'hidden', background: 'var(--c-line-soft)', marginBottom: 'var(--space-3)' }}>
                                {[
                                    [split.net, 'var(--c-primary-500)'],
                                    [split.commission, 'var(--c-accent-600)'],
                                    [split.fee, 'var(--c-info)'],
                                ].map(([value, color], i) => (
                                    <div key={i} style={{ height: '100%', background: color as string, width: `${((value as number) / x.amount) * 100}%` }} />
                                ))}
                            </div>
                            {splitRows.map(([label, value, color, weight]) => (
                                <div key={label} style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2_5)', padding: 'var(--space-2) 0', borderTop: '1px solid var(--c-line-soft)' }}>
                                    <span style={{ width: 10, height: 10, borderRadius: 'var(--radius-round)', background: color }} />
                                    <span style={{ flex: 1, color: 'var(--c-ink-2)' }}>{label}</span>
                                    <span style={{ fontWeight: weight, fontVariantNumeric: 'tabular-nums', whiteSpace: 'nowrap' }}>{value}</span>
                                </div>
                            ))}
                        </>
                    )}
                </Panel>

                <Panel variant="section" icon="history" iconOutlined title="Historique des statuts">
                    {history.map((h) => (
                        <div key={h.label} style={{ display: 'flex', gap: 'var(--space-3)', padding: 'var(--space-2) 0' }}>
                            <Icon name={h.icon} color={h.color} />
                            <span style={{ flex: 1 }}>
                                <b style={{ display: 'block', fontWeight: 'var(--fw-semibold)' }}>{h.label}</b>
                                <span style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>{h.when}</span>
                            </span>
                        </div>
                    ))}
                </Panel>
            </div>
        </>
    );
}
