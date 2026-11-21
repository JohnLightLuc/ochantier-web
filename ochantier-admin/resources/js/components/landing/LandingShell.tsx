import FormDialog from '@/components/overlays/FormDialog';
import Button from '@/components/ui/Button';
import Icon from '@/components/ui/Icon';
import { useAdminUi } from '@/contexts/AdminUiContext';
import type { LandingShellProps } from '@/types/landing';
import { Link, router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';

export const tabHref = (key: string) => (key ? `/landing-page/${key}` : '/landing-page');

/**
 * Cadre commun aux onglets de la Landing Page : onglets selon les droits et bandeau des modifications du site non
 * publiées (publier, tout annuler).
 */
export default function LandingShell({ tab, tabs, draft, children }: LandingShellProps & { children: ReactNode }) {
    return (
        <>
            {draft && draft.changes.length > 0 && <DraftBanner draft={draft} onContent={tab === 'contenu'} />}

            <section style={{ background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-2) var(--space-5) var(--space-5)', minWidth: 0 }}>
                <div role="tablist" style={{ display: 'flex', gap: 'var(--space-1)', borderBottom: '1px solid var(--c-line)', marginBottom: 'var(--space-5)', overflowX: 'auto' }}>
                    {tabs.map((t) => {
                        const on = t.key === tab;
                        return (
                            <Link
                                key={t.key}
                                href={tabHref(t.key)}
                                role="tab"
                                aria-selected={on}
                                className="och-hover-tab"
                                style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)', height: 'var(--control-md)', padding: '0 var(--space-4)', borderBottom: `2.5px solid ${on ? 'var(--c-primary)' : 'transparent'}`, marginBottom: -1, fontWeight: 'var(--fw-semibold)', fontSize: 'var(--fs-base)', color: on ? 'var(--c-primary)' : 'var(--c-muted)', whiteSpace: 'nowrap', textDecoration: 'none' }}
                            >
                                <Icon name={t.icon} size="var(--icon-sm)" />
                                {t.label}
                                {!!t.count && <span style={{ minWidth: 22, padding: '1px var(--space-1_5)', borderRadius: 'var(--radius-pill)', fontSize: 'var(--fs-xs)', textAlign: 'center', background: on ? 'var(--c-primary)' : 'var(--c-warning-bg)', color: on ? 'var(--c-on-dark)' : 'var(--c-warning-fg)' }}>{t.count}</span>}
                            </Link>
                        );
                    })}
                </div>
                {children}
            </section>
        </>
    );
}

function DraftBanner({ draft, onContent }: { draft: NonNullable<LandingShellProps['draft']>; onContent: boolean }) {
    const ui = useAdminUi();
    const [publishing, setPublishing] = useState(false);
    const names = draft.changes.map((c) => c.label).join(', ');
    const first = draft.changes.find((c) => c.key !== 'layout');

    return (
        <div role="status" style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-3)', flexWrap: 'wrap', padding: 'var(--space-3) var(--space-4)', borderRadius: 'var(--radius-xl)', background: 'var(--c-warning-bg)', color: 'var(--c-warning-fg)' }}>
            <Icon name="edit_note" />
            <div style={{ flex: 1, minWidth: 240 }}>
                <div style={{ fontWeight: 'var(--fw-bold)' }}>
                    {draft.changes.length} modification{draft.changes.length > 1 ? 's' : ''} non publiée{draft.changes.length > 1 ? 's' : ''}
                    {draft.restoredFrom ? ` · version ${draft.restoredFrom} restaurée` : ''}
                </div>
                <div style={{ fontSize: 'var(--fs-md)' }}>
                    {names}. Le site affiche toujours la version {draft.liveNumber}
                    {draft.updatedBy ? ` · brouillon modifié par ${draft.updatedBy}${draft.updatedAt ? ` le ${draft.updatedAt}` : ''}` : ''}.
                    {draft.errors > 0 ? ` ${draft.errors} erreur${draft.errors > 1 ? 's bloquent' : ' bloque'} la publication.` : ''}
                </div>
            </div>
            {!onContent && <Button label="Voir les modifications" icon="visibility" size="sm" variant="ghost" onClick={() => router.visit(`/landing-page/contenu/${first?.key ?? 'cover'}`)} />}
            {draft.canEdit && (
                <Button
                    label="Tout annuler"
                    icon="undo"
                    size="sm"
                    variant="secondary"
                    onClick={() =>
                        ui.confirm({
                            tone: 'danger',
                            icon: 'undo',
                            title: 'Annuler toutes les modifications ?',
                            body: `Le brouillon revient à la version en ligne (v${draft.liveNumber}). Modifications perdues : ${names}.`,
                            ok: 'Tout annuler',
                            onConfirm: () => router.post('/landing-page/brouillon/annuler', {}, { preserveScroll: true }),
                        })
                    }
                />
            )}
            {draft.canEdit && (
                <Button
                    label="Publier"
                    icon="publish"
                    size="sm"
                    variant="primary"
                    onClick={() => (draft.errors > 0 ? router.visit(`/landing-page/contenu/${draft.firstError ?? 'cover'}`) : setPublishing(true))}
                    title={draft.errors > 0 ? 'Corrigez d’abord les erreurs bloquantes' : undefined}
                />
            )}
            {publishing && (
                <FormDialog
                    title={`Publier la version ${draft.liveNumber + 1} ?`}
                    body={`Modifications : ${names}. Le site est mis à jour pour tous les visiteurs dans les minutes qui suivent.`}
                    icon="publish"
                    tone="success"
                    okLabel="Publier"
                    url="/landing-page/publier"
                    onClose={() => setPublishing(false)}
                    fields={[{ name: 'note', label: 'Note de version', type: 'textarea', full: true, placeholder: 'Ex. Mise à jour des tarifs' }]}
                />
            )}
        </div>
    );
}
