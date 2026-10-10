import Icon from '@/components/ui/Icon';
import Panel, { InfoRow, LinkRow, PanelLink } from '@/components/ui/Panel';
import StateBlock from '@/components/ui/StateBlock';
import StatusBadge from '@/components/ui/StatusBadge';
import { formatNumber } from '@/lib/format';
import type { Promoter, PromoterActivity, PromoterAlert, PromoterDocument, PromoterRelations } from '@/types/promoters';
import { Link } from '@inertiajs/react';

type Props = {
    promoter: Promoter;
    documents: PromoterDocument[];
    activities: PromoterActivity[];
    relations: PromoterRelations;
    alerts: PromoterAlert[];
    onAddDocument: (type: string) => void;
};

const OPEN_OFFER_STATUSES = ['Ouverte', 'Candidatures reçues', 'À valider'];

/** Vue générale : alertes, contact, entreprise, conformité KYC et derniers éléments liés. */
export default function OverviewTab({ promoter: p, documents, activities, relations, alerts, onAddDocument }: Props) {
    const base = `/promoteurs/${p.slug}`;
    const verified = documents.filter((d) => d.status.value === 'verified').length;
    const openOffers = relations.offers.filter((o) => OPEN_OFFER_STATUSES.includes(o.status));

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
                            {a.href ? (
                                <Link href={a.href} preserveScroll style={{ color: 'inherit', fontWeight: 'var(--fw-bold)' }}>
                                    {a.cta}
                                </Link>
                            ) : (
                                <button type="button" onClick={() => onAddDocument(a.addDocument!)} style={{ border: 0, background: 'transparent', padding: 0, color: 'inherit', fontWeight: 'var(--fw-bold)', cursor: 'pointer', textDecoration: 'underline', textUnderlineOffset: 2 }}>
                                    {a.cta}
                                </button>
                            )}
                        </div>
                    ))}
                </div>
            )}

            <Panel icon="contact_page" title="Contact">
                <InfoRow icon="person" label="Représentant" value={p.representative + (p.representativeRole ? ` · ${p.representativeRole}` : '')} />
                <InfoRow icon="call" label="Téléphone" value={p.phone} />
                <InfoRow icon="mail" label="E-mail" value={p.email} />
                <InfoRow icon="location_on" label="Siège" value={p.headOffice} />
            </Panel>

            <Panel icon="domain" title="Entreprise" aside={<PanelLink href={`${base}/kyc`} label="Détails" />}>
                <InfoRow icon="domain" label="Raison sociale" value={p.name} />
                <InfoRow icon="gavel" label="Forme juridique" value={p.legalForm} />
                <InfoRow icon="tag" label="RCCM" value={p.rccm} />
                <InfoRow icon="category" label="Secteur" value={p.sector} />
            </Panel>

            <Panel icon="verified_user" title="Conformité KYC" aside={<PanelLink href={`${base}/kyc`} label="Voir les documents" />}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-3)', marginBottom: 'var(--space-3)' }}>
                    <b style={{ fontSize: 'var(--fs-3xl)' }}>
                        {verified} / {documents.length}
                    </b>
                    <span style={{ color: 'var(--c-muted)', fontSize: 'var(--fs-sm)' }}>documents vérifiés</span>
                </div>
                <div style={{ height: 8, borderRadius: 'var(--radius-pill)', background: 'var(--c-line-soft)', overflow: 'hidden', marginBottom: 'var(--space-3)' }}>
                    <div style={{ height: '100%', background: 'var(--c-primary-500)', width: `${documents.length ? (verified / documents.length) * 100 : 0}%` }} />
                </div>
                {documents.map((d) => (
                    <div key={d.id} style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 'var(--space-2)', padding: 'var(--space-1_5) 0', fontSize: 'var(--fs-md)' }}>
                        <span>{d.label}</span>
                        <StatusBadge label={d.status.label} tone={d.status.tone} icon={d.status.icon} />
                    </div>
                ))}
            </Panel>

            <Panel icon="construction" title="Chantiers récents" aside={<PanelLink href={`${base}/chantiers`} label="Voir tous les chantiers" />}>
                {relations.sites.slice(0, 3).map((c) => (
                    <LinkRow
                        key={c.id}
                        href={`${base}/chantiers/${c.id}`}
                        title={c.name}
                        sub={
                            <span style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)', marginTop: 'var(--space-1)' }}>
                                {c.location} · {c.progress} %
                                <span style={{ width: 70, height: 6, borderRadius: 'var(--radius-pill)', background: 'var(--c-line-soft)', overflow: 'hidden', display: 'inline-block' }}>
                                    <span style={{ display: 'block', height: '100%', background: c.progress < 50 ? 'var(--c-accent-600)' : 'var(--c-primary-500)', width: `${c.progress}%` }} />
                                </span>
                            </span>
                        }
                        aside={<StatusBadge label={c.status} tone={c.statusTone} />}
                    />
                ))}
                {relations.sites.length === 0 && <StateBlock kind="nodata" compact icon="construction" title="Aucun chantier" text="Ce promoteur n’a encore créé aucun chantier." />}
            </Panel>

            <Panel icon="receipt_long" title="Dernières transactions" aside={<PanelLink href={`${base}/paiements`} label="Voir tous les paiements" />}>
                {relations.payments.slice(0, 3).map((x) => (
                    <LinkRow key={x.ref} href={`${base}/paiements/${x.ref}`} title={<span style={{ fontVariantNumeric: 'tabular-nums' }}>{formatNumber(x.amount)} FCFA</span>} sub={`${x.ref} · ${x.date}`} aside={<StatusBadge label={x.status} tone={x.statusTone} />} />
                ))}
                {relations.payments.length === 0 && <StateBlock kind="nodata" compact icon="receipt_long" title="Aucun paiement" text="Aucune transaction n’a encore été enregistrée." />}
            </Panel>

            <Panel icon="assignment" title="Besoins ouverts" aside={<PanelLink href={`${base}/offres`} label="Voir toutes les offres" />}>
                {openOffers.slice(0, 3).map((o) => (
                    <LinkRow key={o.id} href={`${base}/offres/${o.id}`} title={o.name} sub={`${o.site} · ${o.trade}`} aside={<StatusBadge label={o.status} tone={o.statusTone} />} />
                ))}
                {openOffers.length === 0 && <StateBlock kind="nodata" compact icon="assignment" title="Aucun besoin ouvert" text="Aucune offre n’est actuellement ouverte aux candidatures." />}
            </Panel>

            <Panel icon="history" iconOutlined title="Activité récente" aside={<PanelLink href={`${base}/activite`} label="Voir toute l’activité" />}>
                {activities.slice(0, 4).map((e) => (
                    <div key={e.id} style={{ display: 'flex', gap: 'var(--space-2_5)', padding: 'var(--space-2) 0', borderTop: '1px solid var(--c-line-soft)' }}>
                        <span style={{ width: 'var(--control-xs)', height: 'var(--control-xs)', flex: 'none', borderRadius: 'var(--radius-round)', display: 'grid', placeItems: 'center', background: `var(--c-${e.tone}-bg)` }}>
                            <Icon name={e.icon} size="var(--icon-xs)" color={`var(--c-${e.tone}-fg)`} />
                        </span>
                        <span style={{ flex: 1, minWidth: 0, fontSize: 'var(--fs-md)' }}>
                            <b>{e.author}</b> {e.action}
                            <span style={{ display: 'block', fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>
                                {e.day} · {e.time}
                            </span>
                        </span>
                    </div>
                ))}
                {activities.length === 0 && <StateBlock kind="nodata" compact icon="history" title="Aucune activité" text="Les actions sur ce compte apparaîtront ici." />}
            </Panel>
        </div>
    );
}
