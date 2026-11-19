import Dialog from '@/components/overlays/Dialog';
import Field, { TextArea, TextInput } from '@/components/ui/Field';
import type { ProRule } from '@/types/contractors';
import { useForm } from '@inertiajs/react';

/** Champ du formulaire : la clé de l’API, points remplacés (quotes.service_fee_percent → quotes_service_fee_percent). */
const field = (key: string) => key.replaceAll('.', '_');

/**
 * « Règles & commissions » d’O’CHANTIER PRO : frais de service des devis, commission des offres contractuelles
 * (figée sur chaque offre à sa publication), offres comparées et délais de réponse. Seules les valeurs modifiées
 * sont enregistrées, avec le motif, dans l’historique des paramètres.
 */
export default function ProRulesDialog({ rules, onClose }: { rules: ProRule[]; onClose: () => void }) {
    const form = useForm<Record<string, string>>({ ...Object.fromEntries(rules.map((r) => [field(r.key), r.value === null ? '' : String(r.value)])), reason: '' });
    const errors = form.errors as Record<string, string | undefined>;

    return (
        <Dialog
            title="Règles & commissions"
            body="Une nouvelle commission ne s’applique qu’aux offres publiées ensuite : chaque offre garde le taux de sa publication. Les changements sont tracés dans l’historique des paramètres."
            icon="tune"
            wide
            okLabel="Enregistrer"
            processing={form.processing}
            onClose={onClose}
            onSubmit={() => form.post('/entrepreneurs/regles', { preserveScroll: true, onSuccess: onClose })}
        >
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,260px),1fr))', gap: 'var(--space-3)' }}>
                {rules.map((r) => (
                    <Field key={r.key} label={`${r.label}${r.unit ? ` (${r.unit})` : ''}`} error={errors[field(r.key)]} hint={r.min !== null && r.max !== null ? `Entre ${r.min} et ${r.max}` : undefined}>
                        <TextInput type="number" inputMode="numeric" min={r.min ?? undefined} max={r.max ?? undefined} value={form.data[field(r.key)]} invalid={!!errors[field(r.key)]} onChange={(e) => form.setData(field(r.key), e.target.value)} />
                    </Field>
                ))}
            </div>
            <Field label="Motif de la modification *" error={errors.reason}>
                <TextArea value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} rows={2} maxLength={500} invalid={!!errors.reason} placeholder="Ex. Nouvelle grille tarifaire O’CHANTIER PRO au 1er décembre." />
            </Field>
        </Dialog>
    );
}
