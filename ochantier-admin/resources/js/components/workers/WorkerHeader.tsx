import Avatar from '@/components/ui/Avatar';
import Button from '@/components/ui/Button';
import Icon from '@/components/ui/Icon';
import Menu, { type MenuItem } from '@/components/ui/Menu';
import StatusBadge from '@/components/ui/StatusBadge';
import { useAdminUi } from '@/contexts/AdminUiContext';
import { formatDate, formatNumber, formatRating } from '@/lib/format';
import type { WorkerShowProps } from '@/types/workers';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import type { WorkerActions } from './useWorkerActions';

type Props = Pick<WorkerShowProps, 'worker' | 'banner' | 'documents' | 'demo' | 'options' | 'tab'> & { actions: WorkerActions };

/** En-tête de la fiche ouvrier : identité, statuts, actions et bandeau d’état. */
export default function WorkerHeader({ worker: w, banner, documents, demo, options, tab, actions }: Props) {
    const ui = useAdminUi();
    const [moreOpen, setMoreOpen] = useState(false);
    const base = `/ouvriers/${w.slug}`;
    const status = w.status.value;
    const archived = status === 'archived';
    const validated = status === 'validated';
    const pending = documents.filter((d) => d.status.value !== 'verified' && !(d.status.value === 'missing' && !d.required)).length;
    const busy = demo.assignments.some((a) => ['En cours', 'Confirmée', 'Acceptée'].includes(a.status));

    const meta: [string, string, string][] = [
        ['location_on', 'Localisation', `${w.commune ?? '—'}, ${w.city ?? ''}`],
        ['payments', 'Coût journalier', w.dailyRate ? `${formatNumber(w.dailyRate)} FCFA` : 'Non renseigné'],
        ['star', 'Note', `${formatRating(w.rating)} · ${w.reviewsCount} avis`],
        ['badge', 'Commercial référent', w.recruiter ?? '—'],
        ['event', 'Inscrit le', formatDate(w.registeredAt)],
    ];

    const disabled = (off: boolean, item: MenuItem): MenuItem => (off ? { ...item, onClick: () => ui.toast('Action indisponible pour ce profil.', { tone: 'info' }) } : item);
    const moreItems: MenuItem[] = [
        disabled(archived, { icon: 'event_busy', label: 'Déclarer une indisponibilité', onClick: () => actions.unavailability(w, demo.assignments) }),
        disabled(archived || !busy, { icon: 'swap_horiz', label: 'Demander un remplacement', onClick: () => actions.unavailability(w, demo.assignments, true) }),
        disabled(archived, { icon: 'upload_file', label: 'Ajouter un document', onClick: () => actions.addDocument(w) }),
        disabled(!validated, { icon: 'assignment_ind', label: 'Affecter à un chantier', onClick: () => actions.assign(w, options.sites) }),
        { icon: 'download', label: 'Exporter la fiche', onClick: () => (window.location.href = `${base}/fiche`) },
        {
            icon: 'content_copy',
            label: 'Copier l’identifiant',
            onClick: () => {
                navigator.clipboard?.writeText(w.code).catch(() => undefined);
                ui.toast(`Identifiant ${w.code} copié.`);
            },
        },
        ...(archived ? [] : [{ icon: 'archive', label: 'Archiver', danger: true, onClick: () => actions.archive(w) }]),
    ];

    return (
        <>
            <section data-component="WorkerProfileHeader" style={{ position: 'relative', display: 'flex', flexWrap: 'wrap', gap: 'var(--space-5)', alignItems: 'flex-start', background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-5)' }}>
                <Avatar name={w.name} size="lg" />
                <div style={{ flex: '1 1 360px', minWidth: 0 }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2_5)', flexWrap: 'wrap' }}>
                        <h2 style={{ margin: 0, fontSize: 'var(--fs-4xl)', fontWeight: 'var(--fw-bold)', letterSpacing: '-.4px' }}>{w.name}</h2>
                        <StatusBadge label={w.status.label} tone={w.status.tone} icon={validated ? 'verified' : undefined} />
                        <StatusBadge label={w.availability.label} tone={w.availability.tone} />
                    </div>
                    <div style={{ marginTop: 'var(--space-1)', fontSize: 'var(--fs-lg)', color: 'var(--c-ink-2)' }}>
                        {w.trade} · <span style={{ fontVariantNumeric: 'tabular-nums', color: 'var(--c-muted)' }}>{w.code}</span>
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
                    {(status === 'to_review' || status === 'in_review') && (
                        <>
                            <Button label="Rejeter le profil" icon="person_cancel" variant="danger-soft" onClick={() => actions.reject(w)} />
                            <Button label="Valider le profil" icon="verified" variant="primary" onClick={() => actions.validate(w, pending)} />
                        </>
                    )}
                    {(status === 'rejected' || status === 'incomplete') && <Button label="Valider le profil" icon="verified" variant="secondary" onClick={() => actions.validate(w, pending)} />}
                    {(status === 'suspended' || archived) && <Button label="Réactiver" icon="settings_backup_restore" variant="primary" onClick={() => actions.reactivate(w)} />}
                    {validated && (
                        <>
                            <Button label="Suspendre" icon="block" variant="danger-soft" onClick={() => actions.suspend(w)} />
                            <Button label="Affecter à un chantier" icon="assignment_ind" variant="primary" onClick={() => actions.assign(w, options.sites)} />
                        </>
                    )}
                    <Button icon="more_horiz" variant="secondary" aria-label="Plus d’actions" aria-expanded={moreOpen} onClick={() => setMoreOpen(!moreOpen)} />
                    {moreOpen && <Menu items={moreItems} width={270} top="52px" right="0" onClose={() => setMoreOpen(false)} />}
                </div>
            </section>

            {banner && (
                <div role="status" style={{ display: 'flex', gap: 'var(--space-3)', alignItems: 'center', flexWrap: 'wrap', padding: 'var(--space-3) var(--space-4)', borderRadius: 'var(--radius-lg)', background: `var(--c-${banner.tone}-bg)`, color: `var(--c-${banner.tone}-fg)` }}>
                    <Icon name={banner.icon} />
                    <span style={{ flex: 1, minWidth: 200, fontSize: 'var(--fs-md)' }}>
                        <b>{banner.title}</b> {banner.text}
                    </span>
                    {banner.showDocuments && tab !== 'identite-documents' && (
                        <button type="button" onClick={() => router.visit(`${base}/identite-documents`)} style={{ border: 0, background: 'transparent', padding: 0, color: 'inherit', fontWeight: 'var(--fw-bold)', cursor: 'pointer', textDecoration: 'underline', textUnderlineOffset: 2 }}>
                            Voir les documents
                        </button>
                    )}
                </div>
            )}
        </>
    );
}
