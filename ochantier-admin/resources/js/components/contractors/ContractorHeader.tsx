import Avatar from '@/components/ui/Avatar';
import Button from '@/components/ui/Button';
import Icon from '@/components/ui/Icon';
import Menu, { type MenuItem } from '@/components/ui/Menu';
import StatusBadge from '@/components/ui/StatusBadge';
import { useAdminUi } from '@/contexts/AdminUiContext';
import { formatDate, formatRating } from '@/lib/format';
import type { ContractorShowProps } from '@/types/contractors';
import { useState } from 'react';
import type { ContractorActions } from './useContractorActions';

type Props = Pick<ContractorShowProps, 'contractor' | 'documents' | 'can'> & { actions: ContractorActions };

/** En-tête de la fiche d’un professionnel : identité, type de contrôle, état du dossier et décisions possibles. */
export default function ContractorHeader({ contractor: c, documents, can, actions }: Props) {
    const ui = useAdminUi();
    const [moreOpen, setMoreOpen] = useState(false);
    const verified = c.state.value === 'verifie';

    const meta: [string, string, string][] = [
        ['location_on', 'Ville', c.contact.find(([k]) => k === 'Ville')?.[1] ?? '—'],
        ['construction', 'Métiers', c.trades.join(', ') || '—'],
        ['workspace_premium', 'Formule', c.plan.name],
        ['star', 'Note', c.rating.count ? `${formatRating(c.rating.average ?? 0)} · ${c.rating.count} avis` : 'Aucun avis'],
        ['event', c.validatedAt ? 'Vérifié le' : 'Soumis le', c.validatedAt ?? (c.submittedAt ? formatDate(c.submittedAt) : '—')],
    ];

    const moreItems: MenuItem[] = [
        {
            icon: 'content_copy',
            label: 'Copier la référence',
            onClick: () => {
                navigator.clipboard?.writeText(c.code).catch(() => undefined);
                ui.toast(`Référence ${c.code} copiée.`);
            },
        },
        ...(can.validate && c.can.reject ? [{ icon: 'person_cancel', label: 'Rejeter le dossier', danger: true, onClick: () => actions.reject(c) }] : []),
    ];

    return (
        <section data-component="ContractorProfileHeader" style={{ position: 'relative', display: 'flex', flexWrap: 'wrap', gap: 'var(--space-5)', alignItems: 'flex-start', background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-5)' }}>
            <Avatar name={c.name} size="lg" />
            <div style={{ flex: '1 1 360px', minWidth: 0 }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2_5)', flexWrap: 'wrap' }}>
                    <h2 style={{ margin: 0, fontSize: 'var(--fs-4xl)', fontWeight: 'var(--fw-bold)', letterSpacing: '-.4px' }}>{c.name}</h2>
                    <StatusBadge label={c.state.label} tone={c.state.tone} icon={verified ? 'verified' : undefined} />
                    <StatusBadge label={`${c.kind.label} · ${c.kind.check}`} tone="n" />
                </div>
                <div style={{ marginTop: 'var(--space-1)', fontSize: 'var(--fs-lg)', color: 'var(--c-muted)', fontVariantNumeric: 'tabular-nums' }}>{c.code}</div>
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
                {can.validate && c.can.corrections && <Button label="Demander un complément" icon="edit_document" variant="secondary" onClick={() => actions.complement(c, documents)} />}
                {can.validate && c.can.approve && <Button label="Valider le dossier" icon="verified" variant="primary" onClick={() => actions.validate(c)} />}
                {can.update && c.can.reactivate && <Button label="Réactiver le compte" icon="settings_backup_restore" variant="primary" onClick={() => actions.reactivate(c)} />}
                {can.update && c.can.suspend && <Button label="Suspendre" icon="block" variant="danger-soft" onClick={() => actions.suspend(c)} />}
                <Button icon="more_horiz" variant="secondary" aria-label="Plus d’actions" aria-expanded={moreOpen} onClick={() => setMoreOpen(!moreOpen)} />
                {moreOpen && <Menu items={moreItems} width={260} top="52px" right="0" onClose={() => setMoreOpen(false)} />}
            </div>
        </section>
    );
}
