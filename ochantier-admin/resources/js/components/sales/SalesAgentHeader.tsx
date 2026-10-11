import Avatar from '@/components/ui/Avatar';
import Button from '@/components/ui/Button';
import Icon from '@/components/ui/Icon';
import Menu, { type MenuItem } from '@/components/ui/Menu';
import StatusBadge from '@/components/ui/StatusBadge';
import { useAdminUi } from '@/contexts/AdminUiContext';
import { formatDate } from '@/lib/format';
import type { SalesShowProps } from '@/types/sales';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import type { SalesActions } from './useSalesActions';

type Props = Pick<SalesShowProps, 'agent' | 'banner' | 'current' | 'options'> & { actions: SalesActions };

/** En-tête de la fiche commercial : identité, statuts, actions selon le statut du compte et bandeau d’état. */
export default function SalesAgentHeader({ agent: a, banner, current, options, actions }: Props) {
    const ui = useAdminUi();
    const [moreOpen, setMoreOpen] = useState(false);
    const base = `/commerciaux/${a.slug}`;
    const status = a.status.value;
    const archived = a.isArchived;

    const meta: [string, string, string][] = [
        ['call', 'Téléphone', a.phone],
        ['mail', 'E-mail', a.email],
        ['storefront', 'Agence', a.agencyLabel],
        ['location_on', 'Zone principale', a.zone],
        ['insights', 'Performance', current ? `${current.performance.label} · rang ${current.rank}` : '—'],
        ['supervisor_account', 'Responsable', a.supervisor ?? '—'],
        ['event', 'Inscrit le', formatDate(a.startedAt)],
    ];

    const disabled = (off: boolean, item: MenuItem): MenuItem => (off ? { ...item, onClick: () => ui.toast('Action indisponible pour ce compte.', { tone: 'info' }) } : item);
    const moreItems: MenuItem[] = [
        disabled(status !== 'active', { icon: 'lock_reset', label: 'Réinitialiser l’accès', onClick: () => actions.resetAccess(a) }),
        disabled(archived || a.isPreActivation, { icon: 'event_busy', label: 'Rendre indisponible', onClick: () => actions.unavailability(a, options.colleagues) }),
        disabled(archived || !a.secondaryZones.length, { icon: 'move_down', label: 'Transférer une zone', onClick: () => actions.transferZone(a, options.colleagues) }),
        { icon: 'mail', label: 'Aperçu de l’e-mail d’invitation', onClick: () => actions.emailPreview(a) },
        { icon: 'download', label: 'Exporter la fiche', onClick: () => (window.location.href = `${base}/fiche`) },
        {
            icon: 'content_copy',
            label: 'Copier l’identifiant',
            onClick: () => {
                navigator.clipboard?.writeText(a.code).catch(() => undefined);
                ui.toast(`Identifiant ${a.code} copié.`);
            },
        },
        { icon: 'key', label: 'Voir les accès du compte', onClick: () => router.visit(`${base}/acces-securite`) },
        disabled(archived, { icon: 'admin_panel_settings', label: 'Modifier les accès', onClick: () => router.visit(`${base}/modifier/4`) }),
        ...(status !== 'deactivated' && !archived ? [{ icon: 'person_off', label: 'Désactiver', danger: true, onClick: () => actions.deactivate(a) }] : []),
        ...(status === 'deactivated' || status === 'suspended' ? [{ icon: 'archive', label: 'Archiver', danger: true, onClick: () => actions.archive(a) }] : []),
        { icon: 'delete_forever', label: 'Supprimer le compte', danger: true, onClick: () => actions.destroy(a) },
    ];

    const bannerAction = banner?.action === 'invite' ? ['Envoyer l’invitation', () => actions.invite(a)] : banner?.action === 'email' ? ['Voir l’e-mail', () => actions.emailPreview(a)] : banner?.action === 'activate' ? ['Activer manuellement', () => actions.activate(a)] : null;

    return (
        <>
            <section data-component="SalesAgentProfileHeader" style={{ position: 'relative', display: 'flex', flexWrap: 'wrap', gap: 'var(--space-5)', alignItems: 'flex-start', background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-5)' }}>
                <Avatar name={a.name} size="lg" />
                <div style={{ flex: '1 1 360px', minWidth: 0 }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2_5)', flexWrap: 'wrap' }}>
                        <h2 style={{ margin: 0, fontSize: 'var(--fs-4xl)', fontWeight: 'var(--fw-bold)', letterSpacing: '-.4px' }}>{a.name}</h2>
                        <StatusBadge label={a.status.label} tone={a.status.tone} icon={status === 'active' ? 'verified' : undefined} />
                        <StatusBadge label={a.availability.label} tone={a.availability.tone} />
                    </div>
                    <div style={{ marginTop: 'var(--space-1)', fontSize: 'var(--fs-lg)', color: 'var(--c-ink-2)' }}>
                        {a.type} · <span style={{ fontVariantNumeric: 'tabular-nums', color: 'var(--c-muted)' }}>{a.code}</span>
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
                    {!archived && <Button label="Modifier" icon="edit" variant="secondary" onClick={() => router.visit(`${base}/modifier`)} />}
                    {(status === 'draft' || status === 'invited') && <Button label={status === 'draft' ? 'Envoyer l’invitation' : 'Renvoyer l’invitation'} icon="forward_to_inbox" variant="primary" onClick={() => actions.invite(a)} />}
                    {status === 'pending_activation' && (
                        <>
                            <Button label="Renvoyer l’invitation" icon="forward_to_inbox" variant="secondary" onClick={() => actions.invite(a)} />
                            <Button label="Activer" icon="how_to_reg" variant="primary" onClick={() => actions.activate(a)} />
                        </>
                    )}
                    {status === 'active' && (
                        <>
                            <Button label="Suspendre" icon="block" variant="danger-soft" onClick={() => actions.suspend(a)} />
                            <Button label="Réaffecter une zone" icon="move_down" variant="primary" onClick={() => actions.addZone(a, options.communes)} />
                        </>
                    )}
                    {(status === 'suspended' || status === 'deactivated') && <Button label="Réactiver" icon="settings_backup_restore" variant="primary" onClick={() => actions.reactivate(a)} />}
                    <Button icon="more_horiz" variant="secondary" aria-label="Plus d’actions" aria-expanded={moreOpen} onClick={() => setMoreOpen(!moreOpen)} />
                    {moreOpen && <Menu items={moreItems} width={280} top="52px" right="0" onClose={() => setMoreOpen(false)} />}
                </div>
            </section>

            {banner && (
                <div role="status" style={{ display: 'flex', gap: 'var(--space-3)', alignItems: 'center', flexWrap: 'wrap', padding: 'var(--space-3) var(--space-4)', borderRadius: 'var(--radius-lg)', background: `var(--c-${banner.tone}-bg)`, color: `var(--c-${banner.tone}-fg)` }}>
                    <Icon name={banner.icon} />
                    <span style={{ flex: 1, minWidth: 200, fontSize: 'var(--fs-md)' }}>
                        <b>{banner.title}</b> {banner.text}
                    </span>
                    {bannerAction && (
                        <button type="button" onClick={bannerAction[1] as () => void} style={{ border: 0, background: 'transparent', padding: 0, color: 'inherit', fontWeight: 'var(--fw-bold)', cursor: 'pointer', textDecoration: 'underline', textUnderlineOffset: 2 }}>
                            {bannerAction[0] as string}
                        </button>
                    )}
                </div>
            )}
        </>
    );
}
