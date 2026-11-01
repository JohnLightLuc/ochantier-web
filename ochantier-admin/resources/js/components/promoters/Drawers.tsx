import { DrawerShell, MetaList, type PanelAction } from '@/components/overlays/SidePanel';
import StateBlock from '@/components/ui/StateBlock';
import type { Promoter, PromoterDocument } from '@/types/promoters';

type DocumentDrawerProps = {
    promoter: Promoter;
    document: PromoterDocument;
    onClose: () => void;
    onValidate: () => void;
    onReject: () => void;
    onAskUpdate: () => void;
};

const SIDES: Record<string, string> = { recto: 'Recto', verso: 'Verso', page: 'Document' };

/** Aperçu d’une pièce du dossier (images affichées, PDF ouverts dans un nouvel onglet) et actions de vérification. */
export function DocumentDrawer({ promoter, document: d, onClose, onValidate, onReject, onAskUpdate }: DocumentDrawerProps) {
    const status = d.status.value;
    const actions: PanelAction[] = [];
    if (d.canReject) actions.push({ label: 'Rejeter', icon: 'cancel', variant: 'danger-soft', onClick: onReject });
    if (d.canValidate) actions.push({ label: 'Valider', icon: 'check', variant: 'primary', onClick: onValidate });

    const meta: [string, string][] = [
        ['Type', d.kind ?? d.label],
        ['N°', d.reference],
        ['Ajoutée le', d.addedAt],
        ['Expiration', d.expiresAt],
        ['Statut', d.status.label],
        ['Exigence', d.required ? 'Obligatoire' : 'Facultatif'],
    ];
    if (d.rejectionReason) meta.push(['Motif du rejet', d.rejectionReason]);

    return (
        <DrawerShell kicker={`Aperçu de la pièce · ${promoter.name}`} title={d.label} onClose={onClose} actions={actions}>
            {status === 'expire' && <StateBlock kind="expired" compact text={`Expirée depuis le ${d.expiresAt}. Le promoteur doit transmettre une version à jour.`} actionLabel="Demander une mise à jour" onAction={onAskUpdate} />}
            {status === 'refuse' && (
                <div role="alert" style={{ padding: 'var(--space-3) var(--space-4)', borderRadius: 'var(--radius-lg)', background: 'var(--c-danger-bg)', color: 'var(--c-danger-fg)', fontSize: 'var(--fs-md)' }}>
                    <b>Pièce rejetée.</b> {d.rejectionReason}
                </div>
            )}
            {!d.hasFile && <StateBlock kind="missing" text="Cette pièce n’a pas encore été déposée par le promoteur." />}
            {d.files.map((file) => (
                <figure key={file.url} style={{ margin: 0 }}>
                    <figcaption style={{ marginBottom: 'var(--space-1_5)', fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>
                        {SIDES[file.side] ?? file.side} ·{' '}
                        <a href={file.url} target="_blank" rel="noreferrer">
                            {file.name}
                        </a>
                    </figcaption>
                    {file.mime.startsWith('image/') ? (
                        <img src={file.url} alt={`${d.label} · ${SIDES[file.side] ?? file.side}`} style={{ width: '100%', maxHeight: 420, objectFit: 'contain', borderRadius: 'var(--radius-xl)', border: '1px solid var(--c-line)', background: 'var(--c-surface-2)' }} />
                    ) : (
                        <a href={file.url} target="_blank" rel="noreferrer" style={{ display: 'grid', placeItems: 'center', height: 160, borderRadius: 'var(--radius-xl)', border: '1px solid var(--c-line)', background: 'var(--c-surface-2)', fontWeight: 'var(--fw-semibold)' }}>
                            Ouvrir le PDF
                        </a>
                    )}
                </figure>
            ))}
            <MetaList items={meta} />
        </DrawerShell>
    );
}
