import Avatar from '@/components/ui/Avatar';
import Button from '@/components/ui/Button';
import Icon from '@/components/ui/Icon';
import Menu from '@/components/ui/Menu';
import StatusBadge from '@/components/ui/StatusBadge';
import { useAdminUi } from '@/contexts/AdminUiContext';
import type { Promoter } from '@/types/promoters';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import type { usePromoterActions } from './usePromoterActions';

type Props = {
    promoter: Promoter;
    unverifiedRequired: number;
    actions: ReturnType<typeof usePromoterActions>;
};

/** En-tête de la fiche : identité, statuts, méta et actions sur le compte. */
export default function PromoterHeader({ promoter: p, unverifiedRequired, actions }: Props) {
    const ui = useAdminUi();
    const [moreOpen, setMoreOpen] = useState(false);
    const base = `/promoteurs/${p.slug}`;

    const meta = [
        ['category', 'Type de compte', p.accountType],
        ['location_on', 'Localisation', p.location],
        ['event', 'Inscrit le', p.registeredAt],
        ['history', 'Dernière activité', p.lastActiveAt ?? '—'],
    ];

    const moreItems = [
        { icon: 'upload_file', label: 'Ajouter un document KYC', onClick: () => actions.addDocument(p) },
        { icon: 'domain', label: 'Modifier les informations de l’entreprise', onClick: () => router.visit(`${base}/modifier/entreprise`) },
        { icon: 'history', label: 'Voir l’activité', onClick: () => router.visit(`${base}/activite`) },
        {
            icon: 'content_copy',
            label: 'Copier l’identifiant',
            onClick: () => {
                navigator.clipboard?.writeText(p.slug).catch(() => undefined);
                ui.toast(`Identifiant « ${p.slug} » copié.`);
            },
        },
    ];

    return (
        <>
            <section data-component="EntityHeader" style={{ position: 'relative', display: 'flex', flexWrap: 'wrap', gap: 'var(--space-5)', alignItems: 'flex-start', background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-5)' }}>
                <Avatar name={p.name} kind="org" size="lg" />
                <div style={{ flex: '1 1 360px', minWidth: 0 }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2_5)', flexWrap: 'wrap' }}>
                        <h2 style={{ margin: 0, fontSize: 'var(--fs-4xl)', fontWeight: 'var(--fw-bold)', letterSpacing: '-.4px' }}>{p.name}</h2>
                        <StatusBadge label={p.status.label} tone={p.status.tone} />
                        <StatusBadge label={`KYC · ${p.kyc.label}`} tone={p.kyc.tone} icon={p.kyc.icon} />
                    </div>
                    <div style={{ marginTop: 'var(--space-1)', fontSize: 'var(--fs-lg)', color: 'var(--c-ink-2)' }}>
                        {p.representative} · {p.representativeRole || 'Représentant'}
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
                    <Button label="Modifier" icon="edit" variant="secondary" onClick={() => router.visit(`${base}/modifier`)} />
                    <Button label="Envoyer un message" icon="send" variant="secondary" onClick={() => actions.notify(p)} />
                    <Button label="Exporter la fiche" icon="download" variant="secondary" onClick={() => (window.location.href = `${base}/fiche`)} />
                    {p.status.value === 'active' && <Button label="Suspendre le compte" icon="block" variant="danger-soft" onClick={() => actions.suspend(p)} />}
                    {p.status.value === 'suspended' && <Button label="Réactiver le compte" icon="settings_backup_restore" variant="primary" onClick={() => actions.reactivate(p)} />}
                    {p.status.value === 'pending' && <Button label="Valider le compte" icon="verified" variant="primary" onClick={() => actions.validate(p, unverifiedRequired)} />}
                    <Button icon="more_horiz" variant="secondary" aria-label="Plus d’actions" aria-expanded={moreOpen} onClick={() => setMoreOpen(!moreOpen)} />
                    {moreOpen && <Menu items={moreItems} width={260} top="52px" right="0" onClose={() => setMoreOpen(false)} />}
                </div>
            </section>

            {p.suspension && (
                <div role="alert" style={{ display: 'flex', gap: 'var(--space-3)', alignItems: 'center', padding: 'var(--space-3) var(--space-4)', borderRadius: 'var(--radius-lg)', background: 'var(--c-danger-bg)', color: 'var(--c-danger-fg)' }}>
                    <Icon name="block" />
                    <span style={{ flex: 1, fontSize: 'var(--fs-md)' }}>
                        <b>Compte suspendu.</b> Depuis le {p.suspension.date} · Motif : {p.suspension.reason || 'non précisé'}
                    </span>
                </div>
            )}
        </>
    );
}
