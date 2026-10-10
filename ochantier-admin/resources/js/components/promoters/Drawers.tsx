import { DrawerShell, MetaList, type PanelAction } from '@/components/overlays/SidePanel';
import Avatar from '@/components/ui/Avatar';
import StateBlock from '@/components/ui/StateBlock';
import StatusBadge from '@/components/ui/StatusBadge';
import { useAdminUi } from '@/contexts/AdminUiContext';
import type { DemoOffer, Promoter, PromoterDocument } from '@/types/promoters';
import { router } from '@inertiajs/react';

type DocumentDrawerProps = {
    promoter: Promoter;
    document: PromoterDocument;
    onClose: () => void;
    onValidate: () => void;
    onReject: () => void;
    onReplace: () => void;
    onAskUpdate: () => void;
};

/** Aperçu d’une pièce KYC et actions de vérification. */
export function DocumentDrawer({ promoter, document: d, onClose, onValidate, onReject, onReplace, onAskUpdate }: DocumentDrawerProps) {
    const status = d.status.value;
    const actions: PanelAction[] = [];
    if (d.hasFile) actions.push({ label: 'Télécharger', icon: 'download', onClick: () => (window.location.href = `/promoteurs/${promoter.slug}/documents/${d.id}/telechargement`) });
    if (d.canReject) actions.push({ label: 'Rejeter', icon: 'cancel', variant: 'danger-soft', onClick: onReject });
    if (d.canValidate) actions.push({ label: 'Valider', icon: 'check', variant: 'primary', onClick: onValidate });
    if (['missing', 'expired', 'rejected'].includes(status)) actions.push({ label: status === 'missing' ? 'Ajouter le document' : 'Remplacer', icon: 'upload_file', variant: 'primary', onClick: onReplace });

    const meta: [string, string][] = [
        ['N° / référence', d.reference],
        ['Ajouté le', d.addedAt],
        ['Expiration', d.expiresAt],
        ['Statut', d.status.label],
        ['Exigence', d.required ? 'Obligatoire' : 'Facultatif'],
    ];
    if (d.rejectionReason) meta.push(['Motif du rejet', d.rejectionReason]);

    return (
        <DrawerShell kicker={`Aperçu du document · ${promoter.name}`} title={d.label} onClose={onClose} actions={actions}>
            {status === 'expired' && <StateBlock kind="expired" compact text={`Expiré depuis le ${d.expiresAt}. Le promoteur doit transmettre une version à jour.`} actionLabel="Demander une mise à jour" onAction={onAskUpdate} />}
            {status === 'rejected' && (
                <div role="alert" style={{ padding: 'var(--space-3) var(--space-4)', borderRadius: 'var(--radius-lg)', background: 'var(--c-danger-bg)', color: 'var(--c-danger-fg)', fontSize: 'var(--fs-md)' }}>
                    <b>Document rejeté.</b> {d.rejectionReason}
                </div>
            )}
            {status === 'missing' && <StateBlock kind="missing" text="Ce document n’a pas encore été déposé par le promoteur." actionLabel="Ajouter le document" onAction={onReplace} />}
            {d.hasFile && (
                <div style={{ aspectRatio: '3 / 4', maxHeight: 420, borderRadius: 'var(--radius-xl)', border: '1px solid var(--c-line)', background: 'repeating-linear-gradient(135deg,var(--c-surface-3) 0 12px,var(--c-surface-2) 12px 24px)', display: 'grid', placeItems: 'center', textAlign: 'center', padding: 'var(--space-4)' }}>
                    <span style={{ fontFamily: 'ui-monospace,Menlo,monospace', fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>
                        aperçu PDF
                        <br />
                        {d.reference}
                    </span>
                </div>
            )}
            <MetaList items={meta} />
        </DrawerShell>
    );
}

type OfferDrawerProps = {
    promoter: Promoter;
    offer: DemoOffer;
    candidates: { name: string; detail: string; score: string }[];
    onClose: () => void;
};

/** Fiche d’une offre publiée par le promoteur. */
export function OfferDrawer({ promoter, offer: o, candidates, onClose }: OfferDrawerProps) {
    const ui = useAdminUi();
    const actions: PanelAction[] = [{ label: 'Voir dans Besoins & offres', icon: 'open_in_new', onClick: () => router.visit('/besoins') }];

    if (o.status === 'À valider') {
        actions.push({
            label: 'Valider l’offre',
            icon: 'check',
            variant: 'primary',
            onClick: () =>
                ui.confirm({
                    tone: 'success',
                    icon: 'verified',
                    title: 'Valider cette offre ?',
                    body: `« ${o.name} » sera publiée et visible par les ouvriers correspondant au métier ${o.trade}.`,
                    ok: 'Valider l’offre',
                    onConfirm: () => router.post(`/promoteurs/${promoter.slug}/offres/${o.id}/validation`, {}, { preserveScroll: true }),
                }),
        });
    }

    return (
        <DrawerShell kicker={`Fiche de l’offre · ${promoter.name}`} title={o.name} onClose={onClose} actions={actions}>
            <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2)' }}>
                <StatusBadge label={o.status} tone={o.statusTone} />
                <StatusBadge label={o.type} tone={o.typeTone} />
            </div>
            <MetaList
                items={[
                    ['Chantier', o.site],
                    ['Métier', o.trade],
                    ['Budget', o.budget],
                    ['Échéance', o.due],
                    ['Candidatures', String(o.applications)],
                ]}
            />
            <div>
                <h4 style={{ margin: '0 0 var(--space-2)', fontSize: 'var(--fs-lg)' }}>Candidatures</h4>
                {candidates.map((c) => (
                    <div key={c.name} style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-3)', padding: 'var(--space-2) 0', borderTop: '1px solid var(--c-line-soft)' }}>
                        <Avatar name={c.name} />
                        <span style={{ flex: 1 }}>
                            <b style={{ display: 'block' }}>{c.name}</b>
                            <span style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>{c.detail}</span>
                        </span>
                        <span style={{ padding: '2px var(--space-2)', borderRadius: 'var(--radius-sm)', background: 'var(--c-success-bg)', color: 'var(--c-success-fg)', fontWeight: 'var(--fw-bold)', fontSize: 'var(--fs-sm)' }}>{c.score}</span>
                    </div>
                ))}
                {o.applications > 3 && <div style={{ paddingTop: 'var(--space-2)', fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>+ {o.applications - 3} autres candidatures</div>}
                {o.applications === 0 && <StateBlock kind="nodata" compact icon="person_search" title="Aucune candidature" text="Aucun ouvrier n’a encore postulé à cette offre." />}
            </div>
        </DrawerShell>
    );
}
