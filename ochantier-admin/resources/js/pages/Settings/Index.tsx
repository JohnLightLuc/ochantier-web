import FormDialog, { type DialogField } from '@/components/overlays/FormDialog';
import { RelationTable, stack } from '@/components/promoters/RelationTabs';
import Button from '@/components/ui/Button';
import StatusBadge from '@/components/ui/StatusBadge';
import { AlertList, Block, KeyValues } from '@/components/workers/blocks';
import { useAdminUi } from '@/contexts/AdminUiContext';
import { Head, router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';

type Field = { key: string; label: string; type: string; min: number | null; max: number | null; unit: string | null; value: string | number | null };

type Rate = { id: string; code: string; label: string; value: number; status: { value: string; label: string }; effective_from: string | null; effective_to: string | null; reason: string; by: string | null; by_id: string; approved_by: string | null };

type Props = {
    groups: { title: string; fields: Field[] }[];
    pricing: { current: { code: string; label: string; value: number | null }[]; pending: Rate[]; history: Rate[] };
    matching: { version: number | null; weights: { key: string; label: string; value: number }[]; radius: number; radius_max: number; tolerance_max: number; reason: string | null };
    trades: { id: string; code: string; label: string; active: boolean; workers: number }[];
    integrations: { runtime: { label: string; value: string; warning: boolean }[]; providers: { id: string; provider: string; service: string; environment: string; status: string; expires_on: string | null; last_tested_at: string | null; last_test_ok: boolean | null }[] };
    history: { id: string; label: string; old: unknown; new: unknown; reason: string | null; by: string | null; date: string | null }[];
    can: { update: boolean; approve: boolean; admins: boolean; audit: boolean };
    adminId: string | null;
};

const REASON: DialogField = { name: 'reason', label: 'Motif', type: 'textarea', required: true };

const percent = (value: number | null) => (value === null ? '—' : `${String(value).replace('.', ',')} %`);

const shown = (value: unknown) => (value === null || value === undefined ? '—' : String(value));

/** Paramètres : uniquement ce que la plateforme applique réellement. */
export default function SettingsIndex({ groups, pricing, matching, trades, integrations, history, can, adminId }: Props) {
    const ui = useAdminUi();
    const [dialog, setDialog] = useState<ReactNode>(null);
    const close = () => setDialog(null);
    const warnings = integrations.runtime.filter((r) => r.warning);

    const editField = (f: Field) =>
        setDialog(
            <FormDialog
                title={f.label}
                body="Le changement s’applique tout de suite et reste dans l’historique."
                icon="tune"
                okLabel="Enregistrer"
                url="/parametres/valeurs"
                extra={{ key: f.key }}
                initial={{ value: shown(f.value) === '—' ? '' : String(f.value) }}
                onClose={close}
                fields={[{ name: 'value', label: f.unit ? `Valeur (${f.unit})` : 'Valeur', type: f.type === 'int' ? 'number' : 'text', required: true, full: true }, REASON]}
            />,
        );

    const proposeRate = (code?: string) =>
        setDialog(
            <FormDialog
                title="Proposer un taux"
                body="Une autre personne doit le valider. Les missions déjà confirmées gardent leur taux figé."
                icon="percent"
                okLabel="Proposer"
                url="/parametres/taux"
                initial={{ code: code ?? 'Y' }}
                onClose={close}
                fields={[
                    { name: 'code', label: 'Taux', type: 'select', required: true, options: pricing.current.map((c) => ({ value: c.code, label: c.label })) },
                    { name: 'value', label: 'Nouvelle valeur (%)', type: 'number', required: true },
                    { name: 'effective_from', label: 'Date d’effet', type: 'date', required: true },
                    REASON,
                ]}
            />,
        );

    const refuseRate = (r: Rate) => setDialog(<FormDialog title="Refuser cette proposition ?" icon="cancel" tone="danger" okLabel="Refuser" url={`/parametres/taux/${r.id}/refus`} onClose={close} fields={[REASON]} />);

    const editMatching = () =>
        setDialog(
            <FormDialog
                title="Nouveau barème de matching"
                body="La somme des pondérations doit faire 100 %. Les prochains matchings utilisent ce barème, et les résultats passés gardent le leur."
                icon="balance"
                okLabel="Enregistrer le barème"
                url="/parametres/matching"
                initial={{ radius: String(matching.radius), radius_max: String(matching.radius_max), tolerance_max: String(matching.tolerance_max), ...Object.fromEntries(matching.weights.map((w) => [`weight_${w.key}`, String(w.value)])) }}
                onClose={close}
                fields={[
                    ...matching.weights.map((w): DialogField => ({ name: `weight_${w.key}`, label: `${w.label} (%)`, type: 'number', required: true })),
                    { name: 'radius', label: 'Rayon prioritaire (km)', type: 'number', required: true },
                    { name: 'radius_max', label: 'Rayon maximal (km)', type: 'number', required: true },
                    { name: 'tolerance_max', label: 'Dépassement de budget toléré (%)', type: 'number', required: true },
                    REASON,
                ]}
            />,
        );

    const tradeForm = (t?: Props['trades'][number]) =>
        setDialog(<FormDialog title={t ? `Renommer ${t.label}` : 'Ajouter un métier'} icon="handyman" okLabel="Enregistrer" url={t ? `/parametres/metiers/${t.id}` : '/parametres/metiers'} method={t ? 'put' : 'post'} initial={t ? { label: t.label } : {}} onClose={close} fields={[{ name: 'label', label: 'Métier', required: true, full: true }]} />);

    return (
        <>
            <Head title="Paramètres" />

            <div style={{ display: 'flex', justifyContent: 'flex-end', flexWrap: 'wrap', gap: 'var(--space-2)', marginTop: -6 }}>
                {can.admins && <Button label="Utilisateurs & rôles" icon="group" variant="secondary" onClick={() => router.visit('/parametres/utilisateurs')} />}
                {can.audit && <Button label="Journal d’audit" icon="history" variant="secondary" onClick={() => router.visit('/parametres/audit')} />}
            </div>

            <div style={stack}>
                {warnings.length > 0 && <AlertList alerts={warnings.map((w) => ({ tone: 'warning', icon: 'warning', title: w.label, text: w.value }))} />}

                <Block icon="percent" title="Commissions" action={can.update ? { label: 'Proposer un taux', icon: 'add', onClick: () => proposeRate() } : null}>
                    <KeyValues items={pricing.current.map((c): [string, ReactNode] => [c.label, percent(c.value)])} />
                    {pricing.pending.length > 0 && (
                        <div style={{ marginTop: 'var(--space-3)' }}>
                            <RelationTable
                                columns={['Taux', 'Valeur', 'Date d’effet', 'Motif', 'Proposé par', '']}
                                empty={{ icon: 'percent', title: '', text: '' }}
                                rows={pricing.pending.map((r) => ({
                                    key: r.id,
                                    cells: [
                                        r.label,
                                        percent(r.value),
                                        r.effective_from ?? '—',
                                        r.reason,
                                        r.by ?? '—',
                                        can.approve ? (
                                            <div style={{ display: 'flex', gap: 'var(--space-1)' }}>
                                                <Button label="Refuser" size="sm" variant="secondary" onClick={() => refuseRate(r)} />
                                                {r.by_id !== adminId && <Button label="Valider" size="sm" onClick={() => ui.confirm({ tone: 'warning', icon: 'percent', title: `Valider ${r.label} à ${percent(r.value)} ?`, body: 'Ce taux s’appliquera aux nouveaux devis à partir de sa date d’effet.', ok: 'Valider', onConfirm: () => router.post(`/parametres/taux/${r.id}/validation`, {}, { preserveScroll: true }) })} />}
                                            </div>
                                        ) : (
                                            'À valider'
                                        ),
                                    ],
                                }))}
                            />
                        </div>
                    )}
                </Block>

                <Block icon="balance" title={`Barème de matching${matching.version ? ` · version ${matching.version}` : ''}`} action={can.update ? { label: 'Modifier', icon: 'edit', onClick: editMatching } : null}>
                    <KeyValues
                        items={[
                            ...matching.weights.map((w): [string, ReactNode] => [w.label, `${w.value} %`]),
                            ['Rayon prioritaire', `${matching.radius} km`],
                            ['Rayon maximal', `${matching.radius_max} km`],
                            ['Dépassement de budget toléré', `${matching.tolerance_max} %`],
                        ]}
                    />
                </Block>

                {groups.map((g) => (
                    <Block key={g.title} icon="tune" title={g.title}>
                        <RelationTable
                            columns={['Réglage', 'Valeur', '']}
                            empty={{ icon: 'tune', title: '', text: '' }}
                            rows={g.fields.map((f) => ({ key: f.key, cells: [f.label, `${shown(f.value)}${f.unit ? ` ${f.unit}` : ''}`, can.update ? <Button label="Modifier" size="sm" variant="secondary" onClick={() => editField(f)} /> : ''] }))}
                        />
                    </Block>
                ))}

                <Block icon="handyman" title="Métiers" count={`${trades.filter((t) => t.active).length} proposés`} action={can.update ? { label: 'Ajouter', icon: 'add', onClick: () => tradeForm() } : null}>
                    <RelationTable
                        columns={['Métier', 'Ouvriers', 'Statut', '']}
                        empty={{ icon: 'handyman', title: 'Aucun métier', text: '' }}
                        rows={trades.map((t) => ({
                            key: t.id,
                            cells: [
                                t.label,
                                String(t.workers),
                                <StatusBadge label={t.active ? 'Proposé' : 'Retiré'} tone={t.active ? 'g' : 'n'} />,
                                can.update ? (
                                    <div style={{ display: 'flex', gap: 'var(--space-1)' }}>
                                        <Button label="Renommer" size="sm" variant="secondary" onClick={() => tradeForm(t)} />
                                        <Button label={t.active ? 'Retirer' : 'Proposer'} size="sm" variant="secondary" onClick={() => router.post(`/parametres/metiers/${t.id}/statut`, {}, { preserveScroll: true })} />
                                    </div>
                                ) : (
                                    ''
                                ),
                            ],
                        }))}
                    />
                </Block>

                <Block icon="power" title="Connexions">
                    <KeyValues items={integrations.runtime.map((r): [string, ReactNode] => [r.label, r.value])} />
                    {integrations.providers.length > 0 && (
                        <div style={{ marginTop: 'var(--space-3)' }}>
                            <RelationTable columns={['Prestataire', 'Service', 'Environnement', 'Statut', 'Dernier test']} empty={{ icon: 'power', title: '', text: '' }} rows={integrations.providers.map((p) => ({ key: p.id, cells: [p.provider, p.service, p.environment, p.status, p.last_tested_at ?? '—'] }))} />
                        </div>
                    )}
                </Block>

                <Block icon="history" title="Historique des taux">
                    <RelationTable columns={['Taux', 'Valeur', 'Du', 'Au', 'Statut', 'Motif', 'Validé par']} empty={{ icon: 'history', title: 'Aucun historique', text: '' }} rows={pricing.history.map((r) => ({ key: r.id, cells: [r.label, percent(r.value), r.effective_from ?? '—', r.effective_to ?? '—', r.status.label, r.reason, r.approved_by ?? '—'] }))} />
                </Block>

                <Block icon="history" title="Historique des réglages">
                    <RelationTable columns={['Date', 'Réglage', 'Avant', 'Après', 'Motif', 'Par']} empty={{ icon: 'history', title: 'Aucune modification', text: 'Les changements apparaissent ici.' }} rows={history.map((h) => ({ key: h.id, cells: [h.date ?? '—', h.label, shown(h.old), shown(h.new), h.reason ?? '—', h.by ?? '—'] }))} />
                </Block>
            </div>

            {dialog}
        </>
    );
}
