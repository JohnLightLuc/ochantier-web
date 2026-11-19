import Button from '@/components/ui/Button';
import Icon from '@/components/ui/Icon';
import StatusBadge from '@/components/ui/StatusBadge';
import { formatDate } from '@/lib/format';
import type { ContractorDocument } from '@/types/contractors';
import { useEffect, useState } from 'react';

type Props = {
    contractorName: string;
    /** Pièces déposées, dans l’ordre du dossier. */
    documents: ContractorDocument[];
    index: number;
    canDecide: boolean;
    onIndex: (index: number) => void;
    onConfirm: (d: ContractorDocument) => void;
    onAsk: (d: ContractorDocument) => void;
    onReject: (d: ContractorDocument) => void;
    onClose: () => void;
};

const SIDES: Record<string, string> = { recto: 'Recto', verso: 'Verso', page: 'Page' };

const ZOOMS = [0.5, 0.75, 1, 1.25, 1.5, 2, 3];

/**
 * Visionneuse des pièces KYB/KYC : aperçu (image ou PDF), zoom, rotation, recto/verso, passage d’une pièce à l’autre
 * et décisions sur la pièce (Confirmer, Demander un complément, Rejeter). Flèches du clavier pour naviguer.
 */
export default function DocumentViewer({ contractorName, documents, index, canDecide, onIndex, onConfirm, onAsk, onReject, onClose }: Props) {
    const document = documents[index];
    const [side, setSide] = useState(0);
    const [zoom, setZoom] = useState(2);
    const [rotation, setRotation] = useState(0);
    const file = document?.files[side] ?? document?.files[0];
    const isPdf = file?.mime === 'application/pdf';
    const decide = canDecide && document?.canDecide;

    useEffect(() => {
        setSide(0);
        setZoom(2);
        setRotation(0);
    }, [index]);

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (e.target instanceof HTMLInputElement || e.target instanceof HTMLTextAreaElement) return;
            if (e.key === 'Escape') onClose();
            if (e.key === 'ArrowRight' && index < documents.length - 1) onIndex(index + 1);
            if (e.key === 'ArrowLeft' && index > 0) onIndex(index - 1);
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [index, documents.length, onIndex, onClose]);

    if (!document) return null;

    const tool = (icon: string, label: string, onClick: () => void, disabled = false) => (
        <button
            type="button"
            aria-label={label}
            title={label}
            disabled={disabled}
            onClick={onClick}
            style={{ width: 'var(--control-sm)', height: 'var(--control-sm)', display: 'grid', placeItems: 'center', borderRadius: 'var(--radius-md)', border: '1px solid var(--c-on-dark-line)', background: 'transparent', color: 'var(--c-on-dark)', cursor: disabled ? 'default' : 'pointer', opacity: disabled ? 0.4 : 1 }}
        >
            <Icon name={icon} />
        </button>
    );

    return (
        <div data-component="DocumentViewer" role="dialog" aria-modal="true" aria-label={`Pièce : ${document.label}`} style={{ position: 'fixed', inset: 0, zIndex: 40, display: 'flex', flexDirection: 'column', background: 'rgba(8,40,31,.92)' }}>
            <header style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-3)', flexWrap: 'wrap', padding: 'var(--space-3) var(--space-5)', color: 'var(--c-on-dark)', borderBottom: '1px solid var(--c-on-dark-line)' }}>
                <div style={{ flex: '1 1 260px', minWidth: 0 }}>
                    <div style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-on-dark-muted)' }}>
                        Pièce {index + 1} / {documents.length} · {contractorName}
                    </div>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)', flexWrap: 'wrap' }}>
                        <b style={{ fontSize: 'var(--fs-xl)' }}>{document.label}</b>
                        <StatusBadge label={document.status.label} tone={document.status.tone} />
                    </div>
                </div>
                {document.files.length > 1 && (
                    <div role="tablist" style={{ display: 'flex', gap: 'var(--space-1)' }}>
                        {document.files.map((f, i) => (
                            <button key={f.url} type="button" role="tab" aria-selected={i === side} onClick={() => setSide(i)} style={{ height: 'var(--control-xs)', padding: '0 var(--space-3)', borderRadius: 'var(--radius-pill)', border: '1px solid var(--c-on-dark-line)', background: i === side ? 'var(--c-on-dark)' : 'transparent', color: i === side ? 'var(--c-primary)' : 'var(--c-on-dark)', fontWeight: 'var(--fw-semibold)', cursor: 'pointer' }}>
                                {SIDES[f.side] ?? f.side}
                            </button>
                        ))}
                    </div>
                )}
                <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-1_5)' }}>
                    {tool('zoom_out', 'Dézoomer', () => setZoom((z) => Math.max(0, z - 1)), isPdf || zoom === 0)}
                    <span style={{ minWidth: 48, textAlign: 'center', fontVariantNumeric: 'tabular-nums', fontSize: 'var(--fs-sm)' }}>{isPdf ? '—' : `${Math.round(ZOOMS[zoom] * 100)} %`}</span>
                    {tool('zoom_in', 'Zoomer', () => setZoom((z) => Math.min(ZOOMS.length - 1, z + 1)), isPdf || zoom === ZOOMS.length - 1)}
                    {tool('rotate_right', 'Faire pivoter', () => setRotation((r) => (r + 90) % 360), isPdf)}
                    {file && tool('open_in_new', 'Ouvrir dans un nouvel onglet', () => window.open(file.url, '_blank', 'noopener'))}
                    {tool('close', 'Fermer', onClose)}
                </div>
            </header>

            <div style={{ flex: 1, minHeight: 0, display: 'flex', alignItems: 'stretch' }}>
                <div style={{ display: 'grid', placeItems: 'center', padding: '0 var(--space-2)' }}>{tool('chevron_left', 'Pièce précédente', () => onIndex(index - 1), index === 0)}</div>
                <div style={{ flex: 1, minWidth: 0, overflow: 'auto', display: 'grid', placeItems: 'center', padding: 'var(--space-4)' }}>
                    {!file && <span style={{ color: 'var(--c-on-dark-muted)' }}>Aucun fichier pour cette pièce.</span>}
                    {file && isPdf && <iframe title={document.label} src={file.url} style={{ width: 'min(100%, 900px)', height: '100%', minHeight: 480, border: 0, borderRadius: 'var(--radius-lg)', background: 'var(--c-surface)' }} />}
                    {file && !isPdf && (
                        <img
                            src={file.url}
                            alt={`${document.label}${document.files.length > 1 ? ` · ${SIDES[file.side] ?? file.side}` : ''}`}
                            style={{ maxWidth: zoom <= 2 ? '100%' : 'none', maxHeight: zoom <= 2 ? '100%' : 'none', transform: `scale(${ZOOMS[zoom]}) rotate(${rotation}deg)`, transformOrigin: 'center', transition: 'transform var(--dur) var(--ease)', borderRadius: 'var(--radius-md)', boxShadow: 'var(--shadow-modal)', background: 'var(--c-surface)' }}
                        />
                    )}
                </div>
                <div style={{ display: 'grid', placeItems: 'center', padding: '0 var(--space-2)' }}>{tool('chevron_right', 'Pièce suivante', () => onIndex(index + 1), index === documents.length - 1)}</div>
            </div>

            <footer style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-3)', flexWrap: 'wrap', padding: 'var(--space-3) var(--space-5)', background: 'var(--c-surface)' }}>
                <div style={{ flex: '1 1 320px', minWidth: 0, display: 'flex', flexWrap: 'wrap', gap: 'var(--space-1) var(--space-4)', fontSize: 'var(--fs-md)', color: 'var(--c-muted)' }}>
                    {document.hint && <span>{document.hint}</span>}
                    {document.reference && <span>N° {document.reference}</span>}
                    <span>Déposée le {formatDate(document.addedAt)}</span>
                    {document.expiresAt && <span>Expire le {formatDate(document.expiresAt)}</span>}
                    {document.rejectionReason && <span style={{ color: 'var(--c-danger-fg)' }}>Motif : {document.rejectionReason}</span>}
                </div>
                {decide ? (
                    <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2)' }}>
                        <Button label="Rejeter" icon="cancel" variant="danger-soft" onClick={() => onReject(document)} />
                        <Button label="Demander un complément" icon="edit_document" variant="secondary" onClick={() => onAsk(document)} />
                        {document.status.value !== 'valide' && <Button label="Confirmer" icon="check" variant="primary" onClick={() => onConfirm(document)} />}
                    </div>
                ) : (
                    <span style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>{canDecide ? 'Pièce remplacée : aucune décision possible.' : 'Consultation seule : les décisions sont possibles quand le dossier est en vérification, avec le droit de vérifier les pièces.'}</span>
                )}
            </footer>
        </div>
    );
}
