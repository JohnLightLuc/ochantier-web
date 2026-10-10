import { TableFrame, tdStyle, thStyle } from '@/components/list/Table';
import Button from '@/components/ui/Button';
import Icon from '@/components/ui/Icon';
import Panel from '@/components/ui/Panel';
import StatusBadge from '@/components/ui/StatusBadge';
import type { Promoter, PromoterDocument } from '@/types/promoters';
import { router } from '@inertiajs/react';
import type { CSSProperties } from 'react';

type Props = {
    promoter: Promoter;
    documents: PromoterDocument[];
    onAddDocument: (type?: string) => void;
    onValidate: (document: PromoterDocument) => void;
    onReject: (document: PromoterDocument) => void;
};

const iconButton = (enabled: boolean, color = 'var(--c-ink-2)'): CSSProperties => ({ width: 'var(--control-xs)', height: 'var(--control-xs)', border: 0, borderRadius: 'var(--radius-md)', background: 'transparent', cursor: enabled ? 'pointer' : 'not-allowed', opacity: enabled ? 1 : 0.3, display: 'grid', placeItems: 'center', color });

/** Informations légales de l’entreprise et documents KYC. */
export default function KycTab({ promoter: p, documents, onAddDocument, onValidate, onReject }: Props) {
    const base = `/promoteurs/${p.slug}`;
    const verified = documents.filter((d) => d.status.value === 'verified').length;
    const info: [string, string | null][] = [
        ['Raison sociale', p.name],
        ['Forme juridique', p.legalForm],
        ['N° d’immatriculation', p.rccm],
        ['N° de contribuable', p.ncc],
        ['Adresse du siège', p.headOffice],
        ['Secteur d’activité', p.sector],
        ['Représentant', p.representative],
        ['Fonction', p.representativeRole],
        ['Téléphone', p.phone],
        ['Adresse e-mail', p.email],
    ];

    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)' }}>
            <Panel icon="domain" title="Informations de l’entreprise" aside={<Button label="Modifier les informations" icon="edit" size="sm" variant="secondary" onClick={() => router.visit(`${base}/modifier/entreprise`)} />}>
                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(260px,1fr))', gap: '0 var(--space-6)' }}>
                    {info.map(([k, v]) => (
                        <div key={k} style={{ display: 'flex', gap: 'var(--space-3)', padding: 'var(--space-2_5) 0', borderTop: '1px solid var(--c-line-soft)' }}>
                            <span style={{ width: 170, flex: 'none', color: 'var(--c-muted)', fontSize: 'var(--fs-md)' }}>{k}</span>
                            <span style={{ flex: 1, minWidth: 0, fontWeight: 'var(--fw-semibold)', overflowWrap: 'anywhere' }}>{v || '—'}</span>
                        </div>
                    ))}
                </div>
            </Panel>

            <Panel
                icon="folder_shared"
                title="Documents KYC"
                aside={
                    <>
                        <span style={{ flex: 1, fontSize: 'var(--fs-md)', color: 'var(--c-muted)' }}>
                            {verified} / {documents.length} vérifiés · {documents.length - verified} à traiter
                        </span>
                        <Button label="Ajouter un document" icon="upload_file" size="sm" variant="primary" onClick={() => onAddDocument()} />
                    </>
                }
            >
                <TableFrame minWidth={820}>
                    <thead>
                        <tr style={{ background: 'var(--c-surface-3)' }}>
                            {['Document', 'N° / référence', 'Ajouté le', 'Expiration', 'Statut'].map((h) => (
                                <th key={h} style={thStyle}>
                                    {h}
                                </th>
                            ))}
                            <th style={{ ...thStyle, textAlign: 'center' }}>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        {documents.map((d) => {
                            const expired = d.status.value === 'expired';
                            return (
                                <tr key={d.id} className="och-hover-soft">
                                    <td style={tdStyle}>
                                        <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2_5)' }}>
                                            <Icon name="description" size="var(--icon-md)" color="var(--c-primary-600)" outlined />
                                            <div>
                                                <div style={{ fontWeight: 'var(--fw-semibold)' }}>{d.label}</div>
                                                <div style={{ fontSize: 'var(--fs-xs)', color: 'var(--c-muted)' }}>{d.required ? 'Obligatoire' : 'Facultatif'}</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td style={{ ...tdStyle, fontVariantNumeric: 'tabular-nums' }}>{d.reference}</td>
                                    <td style={{ ...tdStyle, whiteSpace: 'nowrap' }}>{d.addedAt}</td>
                                    <td style={{ ...tdStyle, whiteSpace: 'nowrap', color: expired ? 'var(--c-danger-fg)' : 'var(--c-ink-2)', fontWeight: expired ? 700 : 400 }}>{d.expiresAt}</td>
                                    <td style={tdStyle}>
                                        <StatusBadge label={d.status.label} tone={d.status.tone} icon={d.status.icon} />
                                    </td>
                                    <td style={{ ...tdStyle, padding: 'var(--space-2)' }}>
                                        {d.hasFile ? (
                                            <div style={{ display: 'flex', justifyContent: 'center', gap: 'var(--space-0_5)' }}>
                                                <button type="button" title="Aperçu" aria-label="Aperçu" className="och-hover" style={iconButton(true)} onClick={() => router.visit(`${base}/kyc/${d.id}`, { preserveScroll: true })}>
                                                    <Icon name="visibility" outlined />
                                                </button>
                                                <a href={`${base}/documents/${d.id}/telechargement`} title="Télécharger" aria-label="Télécharger" className="och-hover" style={iconButton(true)}>
                                                    <Icon name="download" />
                                                </a>
                                                <button type="button" title="Valider" aria-label="Valider" disabled={!d.canValidate} className="och-hover-success" style={iconButton(d.canValidate, 'var(--c-success-fg)')} onClick={() => onValidate(d)}>
                                                    <Icon name="check_circle" />
                                                </button>
                                                <button type="button" title="Rejeter" aria-label="Rejeter" disabled={!d.canReject} className="och-hover-danger" style={iconButton(d.canReject, 'var(--c-danger-fg)')} onClick={() => onReject(d)}>
                                                    <Icon name="cancel" />
                                                </button>
                                            </div>
                                        ) : (
                                            <div style={{ display: 'flex', justifyContent: 'center' }}>
                                                <Button label="Ajouter" icon="upload_file" size="sm" variant="secondary" onClick={() => onAddDocument(d.type)} />
                                            </div>
                                        )}
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </TableFrame>
            </Panel>
        </div>
    );
}
