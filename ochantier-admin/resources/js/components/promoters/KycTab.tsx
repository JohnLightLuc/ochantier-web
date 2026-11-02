import { TableFrame, tdStyle, thStyle } from '@/components/list/Table';
import Button from '@/components/ui/Button';
import Icon from '@/components/ui/Icon';
import Panel from '@/components/ui/Panel';
import StatusBadge from '@/components/ui/StatusBadge';
import type { Promoter, PromoterDocument, PromoterReview } from '@/types/promoters';
import { router } from '@inertiajs/react';
import type { CSSProperties } from 'react';

type Props = {
    promoter: Promoter;
    documents: PromoterDocument[];
    onValidate: (document: PromoterDocument) => void;
    onReject: (document: PromoterDocument) => void;
    onUpload?: () => void;
};

const iconButton = (enabled: boolean, color = 'var(--c-ink-2)'): CSSProperties => ({ width: 'var(--control-xs)', height: 'var(--control-xs)', border: 0, borderRadius: 'var(--radius-md)', background: 'transparent', cursor: enabled ? 'pointer' : 'not-allowed', opacity: enabled ? 1 : 0.3, display: 'grid', placeItems: 'center', color });

function InfoGrid({ rows }: { rows: [string, string | null][] }) {
    return (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(260px,1fr))', gap: '0 var(--space-6)' }}>
            {rows.map(([k, v]) => (
                <div key={k} style={{ display: 'flex', gap: 'var(--space-3)', padding: 'var(--space-2_5) 0', borderTop: '1px solid var(--c-line-soft)' }}>
                    <span style={{ width: 170, flex: 'none', color: 'var(--c-muted)', fontSize: 'var(--fs-md)' }}>{k}</span>
                    <span style={{ flex: 1, minWidth: 0, fontWeight: 'var(--fw-semibold)', overflowWrap: 'anywhere' }}>{v || '—'}</span>
                </div>
            ))}
        </div>
    );
}

function ReviewSummary({ review, title }: { review: PromoterReview; title: string }) {
    return (
        <div style={{ padding: 'var(--space-3) 0', borderTop: '1px solid var(--c-line-soft)', fontSize: 'var(--fs-md)' }}>
            <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2)', alignItems: 'center' }}>
                <b>{title}</b>
                <StatusBadge label={review.status} tone="n" />
                <span style={{ color: 'var(--c-muted)' }}>
                    Soumis le {review.submittedAt ?? '—'}
                    {review.assignedTo ? ` · pris en charge par ${review.assignedTo}` : ''}
                    {review.decidedBy ? ` · décision de ${review.decidedBy} le ${review.decidedAt}` : ''}
                </span>
            </div>
            {review.reason && <div style={{ marginTop: 'var(--space-1_5)' }}>Motif : {review.reason}</div>}
            {review.corrections.length > 0 && (
                <ul style={{ margin: 'var(--space-1_5) 0 0', paddingLeft: 'var(--space-5)' }}>
                    {review.corrections.map((c, i) => (
                        <li key={i}>
                            <b>{c.label ?? 'Profil'}</b> : {c.reason}
                            {c.resolved ? ' (corrigé)' : ''}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

/** Identité, entreprise, pièces du dossier et historique des contrôles. */
export default function KycTab({ promoter: p, documents, onValidate, onReject, onUpload }: Props) {
    const base = `/promoteurs/${p.slug}`;
    const required = documents.filter((d) => d.required);
    const verified = required.filter((d) => d.status.value === 'verifie').length;
    const identity: [string, string | null][] = [
        ['Nom', p.representative],
        ['Téléphone', p.phone],
        ['Adresse e-mail', p.email],
        ['Type de compte', p.accountType],
        ['Adresse', p.isCompany ? null : p.headOffice],
        ['Commune', p.location],
    ];

    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)' }}>
            {p.isCompany && (
                <Panel icon="domain" title="Informations de l’entreprise">
                    <InfoGrid
                        rows={[
                            ['Raison sociale', p.name],
                            ['Forme juridique', p.legalForm],
                            ['N° RCCM', p.rccm],
                            ['N° NCC', p.ncc],
                            ['Siège social', p.headOffice],
                            ['Dirigeant', p.representative],
                            ['Fonction', p.representativeRole],
                        ]}
                    />
                </Panel>
            )}

            <Panel icon="badge" title={p.isCompany ? 'Dirigeant' : 'Identité'}>
                <InfoGrid rows={identity} />
            </Panel>

            <Panel
                icon="folder_shared"
                title="Pièces du dossier"
                aside={
                    <>
                        <span style={{ flex: 1, fontSize: 'var(--fs-md)', color: 'var(--c-muted)' }}>
                            {verified} / {required.length} pièces obligatoires vérifiées
                        </span>
                        {onUpload && <Button label="Ajouter une pièce" icon="upload_file" variant="secondary" onClick={onUpload} />}
                    </>
                }
            >
                <TableFrame minWidth={820}>
                    <thead>
                        <tr style={{ background: 'var(--c-surface-3)' }}>
                            {['Pièce', 'N°', 'Ajoutée le', 'Expiration', 'Statut'].map((h) => (
                                <th key={h} style={thStyle}>
                                    {h}
                                </th>
                            ))}
                            <th style={{ ...thStyle, textAlign: 'center' }}>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        {documents.map((d) => {
                            const expired = d.status.value === 'expire';
                            return (
                                <tr key={d.id} className="och-hover-soft">
                                    <td style={tdStyle}>
                                        <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2_5)' }}>
                                            <Icon name="description" size="var(--icon-md)" color="var(--c-primary-600)" outlined />
                                            <div>
                                                <div style={{ fontWeight: 'var(--fw-semibold)' }}>{d.label}</div>
                                                <div style={{ fontSize: 'var(--fs-xs)', color: 'var(--c-muted)' }}>
                                                    {d.kind && d.kind !== d.label ? `${d.kind} · ` : ''}
                                                    {d.required ? 'Obligatoire' : 'Facultatif'}
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td style={{ ...tdStyle, fontVariantNumeric: 'tabular-nums' }}>{d.reference}</td>
                                    <td style={{ ...tdStyle, whiteSpace: 'nowrap' }}>{d.addedAt}</td>
                                    <td style={{ ...tdStyle, whiteSpace: 'nowrap', color: expired ? 'var(--c-danger-fg)' : 'var(--c-ink-2)', fontWeight: expired ? 700 : 400 }}>{d.expiresAt}</td>
                                    <td style={tdStyle}>
                                        <StatusBadge label={d.status.label} tone={d.status.tone} icon={d.status.icon ?? undefined} />
                                    </td>
                                    <td style={{ ...tdStyle, padding: 'var(--space-2)' }}>
                                        {d.hasFile ? (
                                            <div style={{ display: 'flex', justifyContent: 'center', gap: 'var(--space-0_5)' }}>
                                                <button type="button" title="Aperçu" aria-label="Aperçu" className="och-hover" style={iconButton(true)} onClick={() => router.visit(`${base}/kyc/${d.id}`, { preserveScroll: true })}>
                                                    <Icon name="visibility" outlined />
                                                </button>
                                                <a href={d.files[0].url} target="_blank" rel="noreferrer" title="Ouvrir le fichier" aria-label="Ouvrir le fichier" className="och-hover" style={iconButton(true)}>
                                                    <Icon name="open_in_new" />
                                                </a>
                                                <button type="button" title="Valider" aria-label="Valider" disabled={!d.canValidate} className="och-hover-success" style={iconButton(d.canValidate, 'var(--c-success-fg)')} onClick={() => onValidate(d)}>
                                                    <Icon name="check_circle" />
                                                </button>
                                                <button type="button" title="Rejeter" aria-label="Rejeter" disabled={!d.canReject} className="och-hover-danger" style={iconButton(d.canReject, 'var(--c-danger-fg)')} onClick={() => onReject(d)}>
                                                    <Icon name="cancel" />
                                                </button>
                                            </div>
                                        ) : (
                                            <div style={{ textAlign: 'center', fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>À fournir par le promoteur</div>
                                        )}
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </TableFrame>
            </Panel>

            {(p.review.current || p.review.history.length > 0) && (
                <Panel icon="fact_check" title="Contrôles du dossier">
                    {p.review.current && <ReviewSummary review={p.review.current} title={`Demande n° ${p.review.current.number}`} />}
                    {p.review.history.map((r) => (
                        <ReviewSummary key={r.number} review={r} title={`Demande n° ${r.number}`} />
                    ))}
                </Panel>
            )}
        </div>
    );
}
