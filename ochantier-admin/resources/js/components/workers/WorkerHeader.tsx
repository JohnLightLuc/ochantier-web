import Avatar from '@/components/ui/Avatar';
import Button from '@/components/ui/Button';
import Icon from '@/components/ui/Icon';
import Menu, { type MenuItem } from '@/components/ui/Menu';
import StatusBadge from '@/components/ui/StatusBadge';
import { useAdminUi } from '@/contexts/AdminUiContext';
import { formatDate, formatNumber, formatRating } from '@/lib/format';
import type { WorkerShowProps } from '@/types/workers';
import { useState } from 'react';
import type { WorkerActions } from './useWorkerActions';

type Props = Pick<WorkerShowProps, 'worker' | 'documents' | 'correctionFields' | 'can'> & { actions: WorkerActions };

/** En-tête de la fiche ouvrier : identité, statuts et décisions possibles. */
export default function WorkerHeader({ worker: w, documents, correctionFields, can, actions }: Props) {
    const ui = useAdminUi();
    const [moreOpen, setMoreOpen] = useState(false);
    const validated = w.status.value === 'valide';

    const meta: [string, string, string][] = [
        ['location_on', 'Localisation', [w.neighborhood, w.commune].filter(Boolean).join(', ') || '—'],
        ['payments', 'Coût journalier', w.dailyRate ? `${formatNumber(w.dailyRate)} FCFA` : 'Non renseigné'],
        ['star', 'Note', w.reviewsCount ? `${formatRating(w.rating)} · ${w.reviewsCount} avis` : 'Aucun avis'],
        ['badge', 'Commercial référent', w.recruiter ?? '—'],
        ['event', 'Transmis le', formatDate(w.registeredAt)],
    ];

    const moreItems: MenuItem[] = [
        {
            icon: 'content_copy',
            label: 'Copier la référence',
            onClick: () => {
                navigator.clipboard?.writeText(w.code).catch(() => undefined);
                ui.toast(`Référence ${w.code} copiée.`);
            },
        },
        ...(can.validate && w.can.exclude ? [{ icon: 'person_off', label: 'Exclure de la plateforme', danger: true, onClick: () => actions.exclude(w) }] : []),
    ];

    return (
        <section data-component="WorkerProfileHeader" style={{ position: 'relative', display: 'flex', flexWrap: 'wrap', gap: 'var(--space-5)', alignItems: 'flex-start', background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-5)' }}>
            <Avatar name={w.name} size="lg" />
            <div style={{ flex: '1 1 360px', minWidth: 0 }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2_5)', flexWrap: 'wrap' }}>
                    <h2 style={{ margin: 0, fontSize: 'var(--fs-4xl)', fontWeight: 'var(--fw-bold)', letterSpacing: '-.4px' }}>{w.name}</h2>
                    <StatusBadge label={w.status.label} tone={w.status.tone} icon={validated ? 'verified' : undefined} />
                    {validated && <StatusBadge label={w.availability.label} tone={w.availability.tone} />}
                </div>
                <div style={{ marginTop: 'var(--space-1)', fontSize: 'var(--fs-lg)', color: 'var(--c-ink-2)' }}>
                    {w.trade}
                    {w.level ? ` · ${w.level}` : ''} · <span style={{ fontVariantNumeric: 'tabular-nums', color: 'var(--c-muted)' }}>{w.code}</span>
                </div>
                <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2) var(--space-5)', marginTop: 'var(--space-3)' }}>
                    {meta.map(([icon, label, value]) => (
                        <span key={label} style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-1_5)', fontSize: 'var(--fs-md)', color: 'var(--c-muted)' }}>
                            <Icon name={icon} size="var(--icon-xs)" color="var(--c-primary-600)" />
                            {label} : <b style={{ color: 'var(--c-ink)', fontWeight: 'var(--fw-semibold)' }}>{value}</b>
                        </span>
                    ))}
                </div>
            </div>
            <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2)', alignItems: 'center', position: 'relative' }}>
                {can.validate && w.review.canDecide && (
                    <>
                        <Button label="Refuser" icon="person_cancel" variant="danger-soft" onClick={() => actions.reject(w)} />
                        <Button label="Demander des corrections" icon="edit_document" variant="secondary" onClick={() => actions.corrections(w, documents, correctionFields)} />
                        <Button
                            label="Valider le dossier"
                            icon="verified"
                            variant="primary"
                            onClick={() => (w.review.canApprove ? actions.validate(w) : ui.toast(w.review.approvalRefusal ?? 'Le dossier ne peut pas encore être validé.', { tone: 'error' }))}
                        />
                    </>
                )}
                {can.update && w.can.reactivate && <Button label="Réactiver" icon="settings_backup_restore" variant="primary" onClick={() => actions.reactivate(w)} />}
                {can.update && w.can.suspend && <Button label="Suspendre" icon="block" variant="danger-soft" onClick={() => actions.suspend(w)} />}
                <Button icon="more_horiz" variant="secondary" aria-label="Plus d’actions" aria-expanded={moreOpen} onClick={() => setMoreOpen(!moreOpen)} />
                {moreOpen && <Menu items={moreItems} width={270} top="52px" right="0" onClose={() => setMoreOpen(false)} />}
            </div>
        </section>
    );
}
