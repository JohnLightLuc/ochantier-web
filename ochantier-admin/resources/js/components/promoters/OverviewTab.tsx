import Icon from '@/components/ui/Icon';
import Panel, { InfoRow, PanelLink } from '@/components/ui/Panel';
import StatusBadge from '@/components/ui/StatusBadge';
import type { Promoter, PromoterAlert, PromoterDocument } from '@/types/promoters';
import { Link } from '@inertiajs/react';

type Props = {
    promoter: Promoter;
    documents: PromoterDocument[];
    alerts: PromoterAlert[];
};

/** Vue générale : alertes, contact, entreprise, vérification, dossier et abonnement. */
export default function OverviewTab({ promoter: p, documents, alerts }: Props) {
    const base = `/promoteurs/${p.slug}`;
    const required = documents.filter((d) => d.required);
    const verified = required.filter((d) => d.status.value === 'verifie').length;

    return (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,340px),1fr))', gap: 'var(--space-4)' }}>
            {alerts.length > 0 && (
                <div style={{ gridColumn: '1 / -1', display: 'flex', flexDirection: 'column', gap: 'var(--space-2)' }}>
                    {alerts.map((a) => (
                        <div key={a.title + a.text} role="alert" style={{ display: 'flex', gap: 'var(--space-3)', alignItems: 'center', flexWrap: 'wrap', padding: 'var(--space-3) var(--space-4)', borderRadius: 'var(--radius-lg)', background: `var(--c-${a.tone}-bg)`, color: `var(--c-${a.tone}-fg)` }}>
                            <Icon name={a.icon} />
                            <span style={{ flex: 1, minWidth: 200, fontSize: 'var(--fs-md)' }}>
                                <b>{a.title}</b> · {a.text}
                            </span>
                            <Link href={a.href} preserveScroll style={{ color: 'inherit', fontWeight: 'var(--fw-bold)' }}>
                                {a.cta}
                            </Link>
                        </div>
                    ))}
                </div>
            )}

            <Panel icon="contact_page" title="Contact">
                <InfoRow icon="person" label={p.isCompany ? 'Dirigeant' : 'Nom'} value={p.representative + (p.representativeRole ? ` · ${p.representativeRole}` : '')} />
                <InfoRow icon="call" label="Téléphone" value={p.phone} />
                <InfoRow icon="mail" label="E-mail" value={p.email} />
                <InfoRow icon="location_on" label={p.isCompany ? 'Siège' : 'Adresse'} value={p.headOffice} />
            </Panel>

            {p.isCompany && (
                <Panel icon="domain" title="Entreprise" aside={<PanelLink href={`${base}/kyc`} label="Détails" />}>
                    <InfoRow icon="domain" label="Raison sociale" value={p.name} />
                    <InfoRow icon="gavel" label="Forme juridique" value={p.legalForm} />
                    <InfoRow icon="tag" label="RCCM" value={p.rccm} />
                    <InfoRow icon="receipt" label="NCC" value={p.ncc} />
                </Panel>
            )}

            <Panel icon="verified_user" title="Vérification" aside={<PanelLink href={`${base}/kyc`} label="Voir les pièces" />}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-3)', marginBottom: 'var(--space-3)' }}>
                    <b style={{ fontSize: 'var(--fs-3xl)' }}>
                        {verified} / {required.length}
                    </b>
                    <span style={{ color: 'var(--c-muted)', fontSize: 'var(--fs-sm)' }}>pièces obligatoires vérifiées</span>
                </div>
                <div style={{ height: 8, borderRadius: 'var(--radius-pill)', background: 'var(--c-line-soft)', overflow: 'hidden', marginBottom: 'var(--space-3)' }}>
                    <div style={{ height: '100%', background: 'var(--c-primary-500)', width: `${required.length ? (verified / required.length) * 100 : 0}%` }} />
                </div>
                {documents.map((d) => (
                    <div key={d.id} style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 'var(--space-2)', padding: 'var(--space-1_5) 0', fontSize: 'var(--fs-md)' }}>
                        <span>{d.label}</span>
                        <StatusBadge label={d.status.label} tone={d.status.tone} icon={d.status.icon ?? undefined} />
                    </div>
                ))}
                {p.validatedAt && (
                    <div style={{ marginTop: 'var(--space-2)', fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>
                        Validé le {p.validatedAt}
                        {p.validatedBy ? ` par ${p.validatedBy}` : ''}
                    </div>
                )}
            </Panel>

            <Panel icon="folder_shared" title="Dossier">
                <InfoRow icon="percent" label="Complétude" value={`${p.completion.percent} %`} />
                <InfoRow icon="pending_actions" label="Éléments manquants" value={p.completion.missing.length ? p.completion.missing.join(', ') : 'Aucun'} />
                <InfoRow icon="fact_check" label="Contrôle en cours" value={p.review.current ? `Demande n° ${p.review.current.number} · ${p.review.current.status}` : 'Aucun'} />
            </Panel>

            <Panel icon="workspace_premium" title="Abonnement">
                {p.subscription ? (
                    <>
                        <InfoRow icon="workspace_premium" label="Formule" value={p.subscription.plan} />
                        <InfoRow icon="info" label="Statut" value={p.subscription.status} />
                        <InfoRow icon="event" label="Fin de période" value={p.subscription.periodEnd} />
                    </>
                ) : (
                    <InfoRow icon="workspace_premium" label="Formule" value="Aucun abonnement en cours" />
                )}
                <InfoRow icon="group" label="Utilisateurs du compte" value={String(p.stats.users)} />
            </Panel>
        </div>
    );
}
